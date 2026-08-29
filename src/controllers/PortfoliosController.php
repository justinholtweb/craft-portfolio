<?php

namespace justinholtweb\portfolio\controllers;

use Craft;
use craft\helpers\StringHelper;
use craft\models\Section;
use craft\web\Controller;
use justinholtweb\portfolio\models\Blueprint;
use justinholtweb\portfolio\models\Edition;
use justinholtweb\portfolio\models\Portfolio;
use justinholtweb\portfolio\models\Role;
use justinholtweb\portfolio\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The control panel side: list, build, adopt, inspect, remove.
 *
 * Building is a two-step: the form posts, a plan comes back for the admin to read, and only a
 * second post carrying `confirm` writes anything. Nothing about a content model should be created
 * by a single click on a form the person has not seen the consequences of.
 */
class PortfoliosController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_VIEW);

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $portfolios = $plugin->portfolios->getAllPortfolios();

        $drift = [];

        foreach ($portfolios as $portfolio) {
            $problems = $plugin->builder->driftFor($portfolio);

            if ($problems !== []) {
                $drift[$portfolio->uid] = $problems;
            }
        }

        return $this->renderTemplate('portfolio/_index', [
            'portfolios' => $portfolios,
            'drift' => $drift,
            'plugin' => $plugin,
            'canAdd' => $plugin->portfolios->canAddPortfolio(),
            'canAdopt' => Edition::allowsAdoption($plugin->isPro()) && $plugin->portfolios->adoptableSections() !== [],
            'limitMessage' => $plugin->portfolios->portfolioLimitMessage(),
        ]);
    }

    /**
     * The build wizard.
     *
     * GET renders the form. POST plans it and shows what would happen. POST with `confirm` builds.
     */
    public function actionBuild(): Response
    {
        $this->requireSchemaAccess();

        $plugin = Plugin::getInstance();
        $request = Craft::$app->getRequest();

        $blueprint = $request->getIsPost()
            ? $this->blueprintFromPost()
            : new Blueprint();

        $plan = null;

        if ($request->getIsPost()) {
            $plan = $plugin->builder->plan($blueprint);

            if ($request->getBodyParam('confirm') && !$plan->isBlocked()) {
                $result = $plugin->builder->build($blueprint);

                if ($result->success && $result->portfolio !== null) {
                    if ($request->getBodyParam('writeTemplates')) {
                        $plugin->starter->write($result->portfolio, false);
                    }

                    Craft::$app->getSession()->setNotice(Craft::t('portfolio', 'Portfolio built — {summary}.', [
                        'summary' => $result->summary(),
                    ]));

                    return $this->redirect('portfolio/' . $result->portfolio->uid);
                }

                foreach ($result->problems as $problem) {
                    Craft::$app->getSession()->setError($problem);
                }
            }
        }

        return $this->renderTemplate('portfolio/_build', [
            'blueprint' => $blueprint,
            'plan' => $plan,
            'canAdd' => $plugin->portfolios->canAddPortfolio()
                || $plugin->portfolios->getPortfolioByHandle($blueprint->handle) !== null,
            'limitMessage' => $plugin->portfolios->portfolioLimitMessage(),
            'roles' => Role::all(),
            'roleLabels' => $this->roleLabels(),
            'roleDescriptions' => $this->roleDescriptions(),
            'volumes' => $this->volumeOptions(),
            'siteOptions' => $this->siteOptions(),
            'sectionTypes' => [
                ['value' => Section::TYPE_STRUCTURE, 'label' => Craft::t('portfolio', 'Structure — orderable by hand')],
                ['value' => Section::TYPE_CHANNEL, 'label' => Craft::t('portfolio', 'Channel — ordered by date')],
            ],
            'ckeditor' => Blueprint::ckeditorAvailable(),
        ]);
    }

    /**
     * Adopting a section the site already has. **Pro.**
     *
     * No content model is created — the admin maps existing fields onto roles, and the portfolio
     * is the map. This is the path for a site that built its portfolio by hand years ago and wants
     * the query API without rebuilding anything.
     */
    public function actionAdopt(): Response
    {
        $this->requireSchemaAccess();

        $plugin = Plugin::getInstance();

        if (!Edition::allowsAdoption($plugin->isPro())) {
            throw new ForbiddenHttpException(Craft::t('portfolio', 'Adopting an existing section requires Portfolio Pro.'));
        }

        $request = Craft::$app->getRequest();
        $sections = $plugin->portfolios->adoptableSections();

        if ($request->getIsPost()) {
            $this->requirePostRequest();

            $sectionUid = (string)$request->getBodyParam('sectionUid');
            $section = null;

            foreach ($sections as $candidate) {
                if ($candidate->uid === $sectionUid) {
                    $section = $candidate;
                    break;
                }
            }

            if ($section === null) {
                throw new NotFoundHttpException('No such section.');
            }

            $entryTypes = $section->getEntryTypes();
            $entryTypeUid = (string)$request->getBodyParam('entryTypeUid');
            $entryType = null;

            foreach ($entryTypes as $candidate) {
                if ($candidate->uid === $entryTypeUid) {
                    $entryType = $candidate;
                    break;
                }
            }

            $entryType ??= $entryTypes[0] ?? null;

            if ($entryType === null) {
                Craft::$app->getSession()->setError(Craft::t('portfolio', 'That section has no entry types.'));
                return $this->redirect('portfolio/adopt');
            }

            $roles = [];

            foreach ((array)$request->getBodyParam('roles', []) as $role => $fieldUid) {
                if (Role::exists($role) && is_string($fieldUid) && $fieldUid !== '') {
                    $roles[$role] = $fieldUid;
                }
            }

            $portfolio = new Portfolio([
                'name' => (string)($request->getBodyParam('name') ?: $section->name),
                'handle' => (string)($request->getBodyParam('handle') ?: $section->handle),
                'sectionUid' => $section->uid,
                'entryTypeUid' => $entryType->uid,
                'roles' => $roles,
                'templateRoot' => (string)($request->getBodyParam('templateRoot') ?: StringHelper::toKebabCase($section->handle)),
                'sectionType' => (string)$section->type,
                'adopted' => true,
                'categoryGroupUid' => $this->groupUidBehind($roles[Role::CATEGORIES] ?? null, 'category'),
                'tagGroupUid' => $this->groupUidBehind($roles[Role::TAGS] ?? null, 'tag'),
            ]);

            if ($plugin->portfolios->savePortfolio($portfolio)) {
                Craft::$app->getSession()->setNotice(Craft::t('portfolio', 'Section adopted.'));
                return $this->redirect('portfolio/' . $portfolio->uid);
            }

            foreach ($portfolio->getErrors() as $errors) {
                foreach ($errors as $error) {
                    Craft::$app->getSession()->setError($error);
                }
            }
        }

        return $this->renderTemplate('portfolio/_adopt', [
            'sections' => $sections,
            'roles' => Role::all(),
            'roleLabels' => $this->roleLabels(),
            'roleDescriptions' => $this->roleDescriptions(),
            'fields' => Craft::$app->getFields()->getAllFields(),
        ]);
    }

    public function actionEdit(string $portfolioUid): Response
    {
        $plugin = Plugin::getInstance();
        $portfolio = $plugin->portfolios->getPortfolioByUid($portfolioUid);

        if ($portfolio === null) {
            throw new NotFoundHttpException('No such portfolio.');
        }

        return $this->renderTemplate('portfolio/_edit', [
            'portfolio' => $portfolio,
            'plugin' => $plugin,
            'drift' => $plugin->builder->driftFor($portfolio),
            'roles' => Role::all(),
            'roleLabels' => $this->roleLabels(),
            'impact' => $plugin->builder->teardownImpact($portfolio),
            'templatesExist' => $plugin->starter->anyExist($portfolio),
            'templatesPath' => $plugin->starter->templatesPath(),
            'templatesWritable' => $plugin->starter->rootIsWritable(),
        ]);
    }

    public function actionWriteTemplates(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $plugin = Plugin::getInstance();
        $portfolio = $plugin->portfolios->getPortfolioByUid((string)Craft::$app->getRequest()->getBodyParam('portfolioUid'));

        if ($portfolio === null) {
            throw new NotFoundHttpException('No such portfolio.');
        }

        $force = (bool)Craft::$app->getRequest()->getBodyParam('force');
        $results = $plugin->starter->write($portfolio, $force);

        $written = count(array_filter($results, fn(array $r) => $r['status'] === 'written'));
        $skipped = count(array_filter($results, fn(array $r) => $r['status'] === 'skipped'));
        $failed = array_filter($results, fn(array $r) => $r['status'] === 'failed');

        if ($failed !== []) {
            Craft::$app->getSession()->setError(reset($failed)['note']);
        } else {
            Craft::$app->getSession()->setNotice(Craft::t('portfolio', '{written} written, {skipped} left alone.', [
                'written' => $written,
                'skipped' => $skipped,
            ]));
        }

        return $this->redirect('portfolio/' . $portfolio->uid);
    }

    /** Forgets the portfolio. The content model and its entries are untouched. */
    public function actionForget(): Response
    {
        $this->requirePostRequest();
        $this->requireSchemaAccess();

        $plugin = Plugin::getInstance();
        $portfolio = $plugin->portfolios->getPortfolioByUid((string)Craft::$app->getRequest()->getBodyParam('portfolioUid'));

        if ($portfolio === null) {
            throw new NotFoundHttpException('No such portfolio.');
        }

        $plugin->portfolios->deletePortfolio($portfolio);

        Craft::$app->getSession()->setNotice(Craft::t('portfolio', 'Portfolio removed. The section, fields and entries are all still there.'));

        return $this->redirect('portfolio');
    }

    /** Deletes the content model, entries and all. Requires the handle typed back. */
    public function actionRemoveSchema(): Response
    {
        $this->requirePostRequest();
        $this->requireSchemaAccess();

        $plugin = Plugin::getInstance();
        $request = Craft::$app->getRequest();
        $portfolio = $plugin->portfolios->getPortfolioByUid((string)$request->getBodyParam('portfolioUid'));

        if ($portfolio === null) {
            throw new NotFoundHttpException('No such portfolio.');
        }

        if ((string)$request->getBodyParam('confirmHandle') !== $portfolio->handle) {
            Craft::$app->getSession()->setError(Craft::t('portfolio', 'The handle did not match, so nothing was removed.'));
            return $this->redirect('portfolio/' . $portfolio->uid);
        }

        $result = $plugin->builder->teardown($portfolio);

        if ($result->success) {
            Craft::$app->getSession()->setNotice(Craft::t('portfolio', 'The content model was removed.'));
            return $this->redirect('portfolio');
        }

        foreach ($result->problems as $problem) {
            Craft::$app->getSession()->setError($problem);
        }

        return $this->redirect('portfolio/' . $portfolio->uid);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Changing the content model means writing project config, which Craft restricts to admins
     * with `allowAdminChanges`. Saying so here beats a confusing failure four screens later.
     */
    private function requireSchemaAccess(): void
    {
        $this->requirePermission(Plugin::PERMISSION_MANAGE);
        $this->requireAdmin();
    }

    private function blueprintFromPost(): Blueprint
    {
        $request = Craft::$app->getRequest();

        $roles = array_values(array_filter(
            (array)$request->getBodyParam('roles', []),
            fn($role) => is_string($role) && Role::exists($role),
        ));

        $blueprint = new Blueprint([
            'name' => (string)$request->getBodyParam('name', 'Portfolio'),
            'handle' => (string)$request->getBodyParam('handle', 'portfolio'),
            'sectionType' => (string)$request->getBodyParam('sectionType', Section::TYPE_STRUCTURE),
            'roles' => $roles !== [] ? $roles : Role::all(),
            'siteUids' => array_values(array_filter((array)$request->getBodyParam('siteUids', []))),
            'assetVolumeUid' => $request->getBodyParam('assetVolumeUid') ?: null,
            'useCkeditor' => (bool)$request->getBodyParam('useCkeditor', true),
        ]);

        // Only override the derived values when the admin actually edited them; an empty box means
        // “work it out from the handle”, not “make this blank”.
        foreach (['uriFormat', 'categoryUriFormat', 'templateRoot'] as $attribute) {
            $value = trim((string)$request->getBodyParam($attribute, ''));

            if ($value !== '') {
                $blueprint->$attribute = $value;
            }
        }

        $blueprint->fillDerived();

        return $blueprint;
    }

    private function volumeOptions(): array
    {
        $options = [['value' => '', 'label' => Craft::t('portfolio', 'Any volume the user can see')]];

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            $options[] = ['value' => $volume->uid, 'label' => $volume->name];
        }

        return $options;
    }

    private function siteOptions(): array
    {
        $options = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $options[] = ['value' => $site->uid, 'label' => $site->name];
        }

        return $options;
    }

    /**
     * The group a relation field points at, so an adopted portfolio still knows where its
     * categories and tags live.
     */
    private function groupUidBehind(?string $fieldUid, string $kind): ?string
    {
        if ($fieldUid === null) {
            return null;
        }

        $field = Craft::$app->getFields()->getFieldByUid($fieldUid);
        $source = $field->source ?? null;

        if (!is_string($source) || !str_starts_with($source, 'group:')) {
            return null;
        }

        return substr($source, 6);
    }

    /** @return array<string, string> */
    private function roleLabels(): array
    {
        $labels = [];

        foreach (Role::all() as $role) {
            $labels[$role] = Role::label($role);
        }

        return $labels;
    }

    /** @return array<string, string> */
    private function roleDescriptions(): array
    {
        $descriptions = [];

        foreach (Role::all() as $role) {
            $descriptions[$role] = Role::description($role);
        }

        return $descriptions;
    }
}
