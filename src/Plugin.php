<?php

namespace justinholtweb\portfolio;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\ProjectConfig;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use justinholtweb\portfolio\models\Portfolio;
use justinholtweb\portfolio\models\Settings;
use justinholtweb\portfolio\services\Builder;
use justinholtweb\portfolio\services\Jsonld;
use justinholtweb\portfolio\services\Portfolios;
use justinholtweb\portfolio\services\Query;
use justinholtweb\portfolio\services\Renderer;
use justinholtweb\portfolio\services\StarterTemplates;
use justinholtweb\portfolio\twig\Extension;
use justinholtweb\portfolio\twig\PortfolioVariable;
use yii\base\Event;

/**
 * Portfolio — builds a portfolio's content model, then gets out of the way of the front end.
 *
 * @property-read Portfolios $portfolios
 * @property-read Builder $builder
 * @property-read Query $query
 * @property-read Renderer $renderer
 * @property-read StarterTemplates $starter
 * @property-read Jsonld $jsonld
 * @property-read Settings $settings
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    public const PERMISSION_VIEW = 'portfolio:viewPortfolios';
    public const PERMISSION_MANAGE = 'portfolio:managePortfolios';

    /** Log category used by everything in the plugin. */
    public const LOG_CATEGORY = 'portfolio';

    /**
     * No schema version bumps and no install migration: a portfolio lives entirely in project
     * config, so there are no tables to create and nothing to migrate.
     */
    public string $schemaVersion = '1.0.0';

    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public static function editions(): array
    {
        return [
            self::EDITION_LITE,
            self::EDITION_PRO,
        ];
    }

    public static function config(): array
    {
        return [
            'components' => [
                'portfolios' => Portfolios::class,
                'builder' => Builder::class,
                'query' => Query::class,
                'renderer' => Renderer::class,
                'starter' => StarterTemplates::class,
                'jsonld' => Jsonld::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerProjectConfigHandlers();
        $this->registerCpRoutes();
        $this->registerSiteRoutes();
        $this->registerPermissions();
        $this->registerTwig();
    }

    /** Whether the Pro feature set is available. Every edition check goes through here. */
    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO, '>=');
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('portfolio', 'Portfolio');

        $item['subnav'] = [
            'portfolios' => [
                'label' => Craft::t('portfolio', 'Portfolios'),
                'url' => 'portfolio',
            ],
        ];

        if (Craft::$app->getUser()->getIsAdmin()) {
            $item['subnav']['settings'] = [
                'label' => Craft::t('portfolio', 'Settings'),
                'url' => 'settings/plugins/portfolio',
            ];
        }

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('portfolio/settings', [
            'settings' => $this->getSettings(),
            'plugin' => $this,
            'portfolios' => $this->portfolios->getAllPortfolios(),
        ]);
    }

    // ------------------------------------------------------------------ wiring

    private function registerProjectConfigHandlers(): void
    {
        $path = Portfolios::CONFIG_PORTFOLIOS_KEY . '.{uid}';

        Craft::$app->getProjectConfig()
            ->onAdd($path, [$this->portfolios, 'handleChangedPortfolio'])
            ->onUpdate($path, [$this->portfolios, 'handleChangedPortfolio'])
            ->onRemove($path, [$this->portfolios, 'handleDeletedPortfolio']);

        // Everything a portfolio points at lives in project config too, and a rebuild reorders it.
        Event::on(ProjectConfig::class, ProjectConfig::EVENT_AFTER_APPLY_CHANGES, function() {
            $this->portfolios->clearCaches();
        });
    }

    private function registerCpRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules['portfolio'] = 'portfolio/portfolios/index';
            $event->rules['portfolio/new'] = 'portfolio/portfolios/build';
            $event->rules['portfolio/adopt'] = 'portfolio/portfolios/adopt';
            $event->rules['portfolio/<portfolioUid:[^\/]+>'] = 'portfolio/portfolios/edit';
        });
    }

    /**
     * Tag archives.
     *
     * Craft tags have no URIs of their own — unlike categories, there is nothing in project config
     * to give them one — so a portfolio that wants `/work/tag/branding` needs a route. The rule
     * hands the slug to the template rather than routing through a controller, so the page is an
     * ordinary cacheable template render with nothing of the plugin in the request path.
     */
    private function registerSiteRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_SITE_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $settings = $this->getSettings();

            if (!$settings->tagArchives) {
                return;
            }

            foreach ($this->portfolios->getAllPortfolios() as $portfolio) {
                // The root is spliced into a URL rule pattern; one hand-edited into project config
                // with regex characters in it would take the whole rule set down with it.
                if ($portfolio->tagGroupUid === null || !preg_match(Portfolio::TEMPLATE_ROOT_PATTERN, $portfolio->templateRoot)) {
                    continue;
                }

                $root = $portfolio->templateRoot;
                $template = $root . '/' . $settings->tagTemplate;

                $event->rules[$root . '/tag/<slug:{slug}>'] = [
                    'template' => $template,
                    'variables' => ['portfolioHandle' => $portfolio->handle],
                ];
            }
        });
    }

    private function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function(RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => Craft::t('portfolio', 'Portfolio'),
                'permissions' => [
                    self::PERMISSION_VIEW => [
                        'label' => Craft::t('portfolio', 'View portfolios'),
                        'nested' => [
                            self::PERMISSION_MANAGE => [
                                'label' => Craft::t('portfolio', 'Build and remove portfolios'),
                                'info' => Craft::t('portfolio', 'Changing the content model also requires admin access and allowAdminChanges.'),
                            ],
                        ],
                    ],
                ],
            ];
        });
    }

    private function registerTwig(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            /** @var CraftVariable $variable */
            $variable = $event->sender;
            $variable->set('portfolio', PortfolioVariable::class);
        });

        Craft::$app->getView()->registerTwigExtension(new Extension());
    }
}
