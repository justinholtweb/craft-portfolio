<?php

namespace justinholtweb\portfolio\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\helpers\StringHelper;
use craft\models\Section;
use justinholtweb\portfolio\models\Edition;
use justinholtweb\portfolio\models\Portfolio;
use justinholtweb\portfolio\Plugin;
use yii\base\Event;

/**
 * The register of portfolios: which sections are portfolios, and which field plays which role.
 *
 * Portfolios live in **project config** rather than a database table. That is the whole reason
 * there is no install migration and nothing to garbage-collect: a portfolio is a *description of
 * the content model*, it belongs next to the sections and fields it describes, and it deploys with
 * them. It also means uninstalling the plugin cannot orphan anything.
 */
class Portfolios extends Component
{
    public const CONFIG_KEY = 'portfolio';
    public const CONFIG_PORTFOLIOS_KEY = self::CONFIG_KEY . '.portfolios';

    /** @var Portfolio[]|null */
    private ?array $_portfolios = null;

    // ------------------------------------------------------------------ reading

    /** @return Portfolio[] keyed by UID, in sort order. */
    public function getAllPortfolios(): array
    {
        if ($this->_portfolios !== null) {
            return $this->_portfolios;
        }

        $configs = Craft::$app->getProjectConfig()->get(self::CONFIG_PORTFOLIOS_KEY) ?? [];
        $portfolios = [];

        foreach ($configs as $uid => $config) {
            if (is_array($config)) {
                $portfolios[$uid] = Portfolio::fromConfig($uid, $config);
            }
        }

        uasort($portfolios, fn(Portfolio $a, Portfolio $b) => [$a->sortOrder, $a->name] <=> [$b->sortOrder, $b->name]);

        return $this->_portfolios = $portfolios;
    }

    public function getPortfolioByUid(string $uid): ?Portfolio
    {
        return $this->getAllPortfolios()[$uid] ?? null;
    }

    public function getPortfolioByHandle(string $handle): ?Portfolio
    {
        foreach ($this->getAllPortfolios() as $portfolio) {
            if ($portfolio->handle === $handle) {
                return $portfolio;
            }
        }

        return null;
    }

    public function getPortfolioBySectionUid(string $sectionUid): ?Portfolio
    {
        foreach ($this->getAllPortfolios() as $portfolio) {
            if ($portfolio->sectionUid === $sectionUid) {
                return $portfolio;
            }
        }

        return null;
    }

    /**
     * The portfolio an entry belongs to, if any.
     *
     * Used by the JSON-LD hook and by `entry|portfolioField(...)`, both of which are handed an
     * entry and have to work out the role map from it.
     */
    public function getPortfolioForEntry(Entry $entry): ?Portfolio
    {
        $section = $entry->getSection();

        if ($section === null) {
            return null;
        }

        return $this->getPortfolioBySectionUid($section->uid);
    }

    /**
     * The portfolio meant when a template names none.
     *
     * The configured default if there is one and it still exists, otherwise the first — which is
     * the only portfolio there is under Lite, so a Lite site never has to name anything.
     */
    public function getDefaultPortfolio(): ?Portfolio
    {
        $handle = Plugin::getInstance()->getSettings()->defaultPortfolio;

        if ($handle !== '') {
            $portfolio = $this->getPortfolioByHandle($handle);

            if ($portfolio !== null) {
                return $portfolio;
            }
        }

        return array_values($this->getAllPortfolios())[0] ?? null;
    }

    /**
     * Resolve a portfolio from whatever a template passed: a handle, a Portfolio, or nothing.
     */
    public function resolve(Portfolio|string|null $portfolio = null): ?Portfolio
    {
        if ($portfolio instanceof Portfolio) {
            return $portfolio;
        }

        if (is_string($portfolio) && $portfolio !== '') {
            return $this->getPortfolioByHandle($portfolio);
        }

        return $this->getDefaultPortfolio();
    }

    // ------------------------------------------------------------------ editions

    /** Whether another portfolio may be created under the active edition. */
    public function canAddPortfolio(): bool
    {
        $max = Edition::maxPortfolios(Plugin::getInstance()->isPro());

        return $max === null || count($this->getAllPortfolios()) < $max;
    }

    public function portfolioLimitMessage(): string
    {
        return Craft::t('portfolio', 'Portfolio Lite manages one portfolio. Upgrade to Pro for as many as you like.');
    }

    // ------------------------------------------------------------------ writing

    public function savePortfolio(Portfolio $portfolio, bool $runValidation = true): bool
    {
        if ($runValidation && !$portfolio->validate()) {
            return false;
        }

        $isNew = $portfolio->uid === '';

        if ($isNew) {
            $portfolio->uid = StringHelper::UUID();

            if (!$this->canAddPortfolio()) {
                $portfolio->addError('handle', $this->portfolioLimitMessage());
                return false;
            }
        }

        if ($portfolio->sortOrder === 0) {
            $portfolio->sortOrder = count($this->getAllPortfolios()) + 1;
        }

        Craft::$app->getProjectConfig()->set(
            self::CONFIG_PORTFOLIOS_KEY . '.' . $portfolio->uid,
            $portfolio->toConfig(),
            "Save the “{$portfolio->handle}” portfolio",
        );

        $this->clearCaches();

        return true;
    }

    /**
     * Forgets a portfolio.
     *
     * The section, entry type, fields and groups are deliberately left alone — they are the site's
     * content model now, and they hold real entries. Taking the schema away as well is
     * `services\Builder::teardown()`, which is a separate, explicit, typed-confirmation act.
     */
    public function deletePortfolio(Portfolio $portfolio): bool
    {
        if ($portfolio->uid === '') {
            return false;
        }

        Craft::$app->getProjectConfig()->remove(
            self::CONFIG_PORTFOLIOS_KEY . '.' . $portfolio->uid,
            "Delete the “{$portfolio->handle}” portfolio",
        );

        $this->clearCaches();

        return true;
    }

    // ------------------------------------------------------------------ project config handlers

    /**
     * Project config changed under `portfolio.portfolios.*`.
     *
     * There is no derived data to rebuild — a portfolio *is* its config — so this only has to drop
     * the memo. Which is the point of keeping it config-only: the coalescing trap where a path
     * added and removed in one request fires no removal handler cannot bite when nothing is
     * derived from the path in the first place.
     */
    public function handleChangedPortfolio(Event $event): void
    {
        $this->clearCaches();
    }

    public function handleDeletedPortfolio(Event $event): void
    {
        $this->clearCaches();
    }

    public function clearCaches(): void
    {
        $this->_portfolios = null;
    }

    // ------------------------------------------------------------------ helpers

    /** Sections not already claimed by a portfolio — the candidates for adoption. @return Section[] */
    public function adoptableSections(): array
    {
        $claimed = [];

        foreach ($this->getAllPortfolios() as $portfolio) {
            $claimed[$portfolio->sectionUid] = true;
        }

        return array_values(array_filter(
            Craft::$app->getEntries()->getAllSections(),
            fn(Section $section) => !isset($claimed[$section->uid]) && $section->type !== Section::TYPE_SINGLE,
        ));
    }
}
