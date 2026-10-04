<?php

namespace justinholtweb\portfolio\services;

use Craft;
use craft\base\Component;
use craft\base\FieldInterface;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\Tag;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\models\CategoryGroup;
use craft\models\CategoryGroup_SiteSettings;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\TagGroup;
use justinholtweb\portfolio\models\Blueprint;
use justinholtweb\portfolio\models\BuildPlan;
use justinholtweb\portfolio\models\BuildResult;
use justinholtweb\portfolio\models\FieldSpec;
use justinholtweb\portfolio\models\PlanItem;
use justinholtweb\portfolio\models\Portfolio;
use justinholtweb\portfolio\models\Role;
use justinholtweb\portfolio\Plugin;
use Throwable;

/**
 * Turns a blueprint into a real content model.
 *
 * Two rules, and everything else follows from them:
 *
 * 1. **Plan before you write.** `plan()` reports every section, entry type, field and group as
 *    *create*, *reuse* or *conflict* without touching anything. Building a content model is not
 *    undoable in any sense an author would recognise, so nobody should discover what happened by
 *    reading a project-config diff.
 * 2. **Never edit what you did not create.** A handle that is already taken by something of the
 *    right type is *reused as it is* — its settings are left completely alone. A handle taken by
 *    the wrong type is a conflict and stops the build. The plugin will not silently reshape a
 *    field the site has been using for two years because the handle happened to match.
 *
 * A consequence worth stating: `build()` is safe to run twice. The second run creates nothing.
 */
class Builder extends Component
{
    // ------------------------------------------------------------------ planning

    /**
     * Works out what building this blueprint would do, without writing anything.
     *
     * Also used after the fact, against a built portfolio, to find drift: everything should come
     * back *reuse*, and anything that comes back *create* has been deleted since.
     */
    public function plan(Blueprint $blueprint): BuildPlan
    {
        $blueprint->fillDerived();

        $plan = new BuildPlan(['blueprint' => $blueprint]);

        if (!$blueprint->validate()) {
            foreach ($blueprint->getErrors() as $errors) {
                foreach ($errors as $error) {
                    $plan->blockers[] = $error;
                }
            }
        }

        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            $plan->blockers[] = Craft::t('portfolio', 'Admin changes are disabled in this environment, so the content model cannot be changed here. Build the portfolio in development and deploy the project config.');
        }

        $this->planSection($plan, $blueprint);
        $this->planEntryType($plan, $blueprint);

        if ($blueprint->wantsCategoryGroup()) {
            $this->planCategoryGroup($plan, $blueprint);
        }

        if ($blueprint->wantsTagGroup()) {
            $this->planTagGroup($plan, $blueprint);
        }

        foreach ($blueprint->fieldSpecs() as $spec) {
            $this->planField($plan, $spec);
        }

        return $plan;
    }

    private function planSection(BuildPlan $plan, Blueprint $blueprint): void
    {
        $handle = $blueprint->sectionHandle();
        $existing = Craft::$app->getEntries()->getSectionByHandle($handle);

        $item = new PlanItem([
            'kind' => PlanItem::KIND_SECTION,
            'handle' => $handle,
            'label' => $blueprint->name,
        ]);

        if ($existing === null) {
            $item->action = PlanItem::ACTION_CREATE;
            $item->note = Craft::t('portfolio', 'A {type} section at {uri}', [
                'type' => $blueprint->sectionType,
                'uri' => $blueprint->uriFormat,
            ]);
        } elseif ($existing->type === Section::TYPE_SINGLE) {
            $item->action = PlanItem::ACTION_CONFLICT;
            $item->existingUid = $existing->uid;
            $item->note = Craft::t('portfolio', 'A Single section already uses this handle. A portfolio needs a channel or structure.');
        } else {
            $item->action = PlanItem::ACTION_REUSE;
            $item->existingUid = $existing->uid;
            $item->note = Craft::t('portfolio', 'This section already exists and will be used exactly as it is.');
        }

        $plan->add($item);
    }

    private function planEntryType(BuildPlan $plan, Blueprint $blueprint): void
    {
        $handle = $blueprint->entryTypeHandle();
        $existing = Craft::$app->getEntries()->getEntryTypeByHandle($handle);

        $item = new PlanItem([
            'kind' => PlanItem::KIND_ENTRY_TYPE,
            'handle' => $handle,
            'label' => $blueprint->entryTypeName(),
        ]);

        if ($existing === null) {
            $item->action = PlanItem::ACTION_CREATE;
            $item->note = Craft::t('portfolio', 'With a field layout holding the fields below.');
        } else {
            $item->action = PlanItem::ACTION_REUSE;
            $item->existingUid = $existing->uid;
            $item->note = Craft::t('portfolio', 'This entry type already exists. Its field layout will be left alone.');
        }

        $plan->add($item);
    }

    private function planCategoryGroup(BuildPlan $plan, Blueprint $blueprint): void
    {
        $handle = $blueprint->categoryGroupHandle();
        $existing = Craft::$app->getCategories()->getGroupByHandle($handle);

        $item = new PlanItem([
            'kind' => PlanItem::KIND_CATEGORY_GROUP,
            'handle' => $handle,
            'label' => $blueprint->categoryGroupName(),
        ]);

        if ($existing === null) {
            $item->action = PlanItem::ACTION_CREATE;
            $item->note = Craft::t('portfolio', 'Archives at {uri}', ['uri' => $blueprint->categoryUriFormat]);
        } else {
            $item->action = PlanItem::ACTION_REUSE;
            $item->existingUid = $existing->uid;
            $item->note = Craft::t('portfolio', 'This category group already exists and will be used as it is.');
        }

        $plan->add($item);
    }

    private function planTagGroup(BuildPlan $plan, Blueprint $blueprint): void
    {
        $handle = $blueprint->tagGroupHandle();
        $existing = Craft::$app->getTags()->getTagGroupByHandle($handle);

        $item = new PlanItem([
            'kind' => PlanItem::KIND_TAG_GROUP,
            'handle' => $handle,
            'label' => $blueprint->tagGroupName(),
        ]);

        if ($existing === null) {
            $item->action = PlanItem::ACTION_CREATE;
        } else {
            $item->action = PlanItem::ACTION_REUSE;
            $item->existingUid = $existing->uid;
            $item->note = Craft::t('portfolio', 'This tag group already exists and will be used as it is.');
        }

        $plan->add($item);
    }

    private function planField(BuildPlan $plan, FieldSpec $spec): void
    {
        $existing = Craft::$app->getFields()->getFieldByHandle($spec->handle);

        $item = new PlanItem([
            'kind' => PlanItem::KIND_FIELD,
            'handle' => $spec->handle,
            'label' => $spec->name,
            'role' => $spec->role,
        ]);

        if ($existing === null) {
            $item->action = PlanItem::ACTION_CREATE;
            $item->note = $this->fieldTypeLabel($spec->type);
        } elseif ($existing::class === $spec->type) {
            $item->action = PlanItem::ACTION_REUSE;
            $item->existingUid = $existing->uid;
            $item->note = Craft::t('portfolio', 'An existing {type} field with this handle will be used as it is. Its settings are not touched.', [
                'type' => $this->fieldTypeLabel($spec->type),
            ]);
        } else {
            $item->action = PlanItem::ACTION_CONFLICT;
            $item->existingUid = $existing->uid;
            $item->note = Craft::t('portfolio', 'A field with this handle exists and is of type {actual}, not {wanted}. Rename one of them, or point this role at a different field.', [
                'actual' => $this->fieldTypeLabel($existing::class),
                'wanted' => $this->fieldTypeLabel($spec->type),
            ]);
        }

        $plan->add($item);
    }

    private function fieldTypeLabel(string $class): string
    {
        if (is_subclass_of($class, FieldInterface::class) || method_exists($class, 'displayName')) {
            /** @var string $label */
            $label = $class::displayName();
            return $label;
        }

        return $class;
    }

    // ------------------------------------------------------------------ building

    /**
     * Creates whatever the blueprint asks for and does not already exist, then registers the
     * result as a portfolio.
     *
     * Order is not arbitrary: the taxonomy groups come first because the category and tag fields
     * need their UIDs as a source; the fields come before the entry type because the field layout
     * references them; the entry type comes before the section.
     */
    public function build(Blueprint $blueprint): BuildResult
    {
        $result = new BuildResult();
        $plan = $this->plan($blueprint);

        if ($plan->isBlocked()) {
            foreach ($plan->blockers as $blocker) {
                $result->addProblem($blocker);
            }

            foreach ($plan->conflicts() as $conflict) {
                $result->addProblem(sprintf('%s “%s”: %s', ucfirst($conflict->kind), $conflict->handle, $conflict->note));
            }

            return $result;
        }

        $portfolios = Plugin::getInstance()->portfolios;

        if (!$portfolios->canAddPortfolio() && $portfolios->getPortfolioByHandle($blueprint->handle) === null) {
            $result->addProblem($portfolios->portfolioLimitMessage());
            return $result;
        }

        try {
            $categoryGroup = $blueprint->wantsCategoryGroup()
                ? $this->ensureCategoryGroup($blueprint, $result)
                : null;

            $tagGroup = $blueprint->wantsTagGroup()
                ? $this->ensureTagGroup($blueprint, $result)
                : null;

            $fields = $this->ensureFields($blueprint, $categoryGroup, $tagGroup, $result);
            $entryType = $this->ensureEntryType($blueprint, $fields, $result);
            $section = $this->ensureSection($blueprint, $entryType, $result);
        } catch (Throwable $e) {
            Craft::error('Portfolio build failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString(), Plugin::LOG_CATEGORY);
            $result->addProblem(Craft::t('portfolio', 'The build failed: {message}', ['message' => $e->getMessage()]));

            return $result;
        }

        $roles = [];

        foreach ($fields as $role => $field) {
            $roles[$role] = $field->uid;
        }

        $portfolio = $portfolios->getPortfolioByHandle($blueprint->handle) ?? new Portfolio();
        $portfolio->name = $blueprint->name;
        $portfolio->handle = $blueprint->handle;
        $portfolio->sectionUid = $section->uid;
        $portfolio->entryTypeUid = $entryType->uid;
        $portfolio->categoryGroupUid = $categoryGroup?->uid;
        $portfolio->tagGroupUid = $tagGroup?->uid;
        $portfolio->roles = $roles;
        $portfolio->templateRoot = $blueprint->templateRoot;
        $portfolio->sectionType = $blueprint->sectionType;
        // Merged, not replaced: rebuilding to add a role must not forget what the first build made.
        $portfolio->created = array_values(array_unique(array_merge(
            $portfolio->created,
            $this->createdUids($plan, $section, $entryType, $categoryGroup, $tagGroup, $fields),
        )));

        if (!$portfolios->savePortfolio($portfolio)) {
            foreach ($portfolio->getErrors() as $errors) {
                foreach ($errors as $error) {
                    $result->addProblem($error);
                }
            }

            return $result;
        }

        $result->portfolio = $portfolio;
        $result->success = !$result->hasProblems();

        return $result;
    }

    /**
     * UIDs of everything the plan said to create, now that it exists.
     *
     * Read off the plan rather than the ensure* methods because the plan is the record of what was
     * there *before* this build — the same thing the admin was shown and agreed to.
     *
     * @param FieldInterface[] $fields keyed by role
     * @return string[]
     */
    private function createdUids(BuildPlan $plan, Section $section, EntryType $entryType, ?CategoryGroup $categoryGroup, ?TagGroup $tagGroup, array $fields): array
    {
        $uids = [];

        foreach ($plan->creations() as $item) {
            $uids[] = match ($item->kind) {
                PlanItem::KIND_SECTION => $section->uid,
                PlanItem::KIND_ENTRY_TYPE => $entryType->uid,
                PlanItem::KIND_CATEGORY_GROUP => $categoryGroup?->uid,
                PlanItem::KIND_TAG_GROUP => $tagGroup?->uid,
                PlanItem::KIND_FIELD => $item->role !== null ? ($fields[$item->role] ?? null)?->uid : null,
                default => null,
            };
        }

        return array_values(array_filter($uids));
    }

    // ------------------------------------------------------------------ creating each piece

    /**
     * @return FieldInterface[] keyed by role
     */
    private function ensureFields(Blueprint $blueprint, ?CategoryGroup $categoryGroup, ?TagGroup $tagGroup, BuildResult $result): array
    {
        $fieldsService = Craft::$app->getFields();
        $fields = [];

        foreach ($blueprint->fieldSpecs() as $role => $spec) {
            $existing = $fieldsService->getFieldByHandle($spec->handle);

            if ($existing !== null) {
                $fields[$role] = $existing;
                $result->addReused(Craft::t('portfolio', 'Field “{handle}”', ['handle' => $spec->handle]));
                continue;
            }

            $settings = $spec->settings;

            // The category and tag fields need a source that only exists as of a moment ago.
            foreach ($spec->deferredSources as $key => $what) {
                $uid = match ($what) {
                    'categoryGroup' => $categoryGroup?->uid,
                    'tagGroup' => $tagGroup?->uid,
                    default => null,
                };

                if ($uid === null) {
                    continue 2;
                }

                $settings[$key] = 'group:' . $uid;
            }

            /** @var FieldInterface $field */
            $field = new $spec->type();
            $field->name = $spec->name;
            $field->handle = $spec->handle;
            $field->instructions = $spec->instructions;
            $field->searchable = $spec->searchable;

            foreach ($settings as $key => $value) {
                if (property_exists($field, $key)) {
                    $field->$key = $value;
                }
            }

            if (!$fieldsService->saveField($field)) {
                throw new BuildException($this->modelErrors($field, Craft::t('portfolio', 'Could not save the “{handle}” field.', ['handle' => $spec->handle])));
            }

            $fields[$role] = $field;
            $result->addCreated(Craft::t('portfolio', 'Field “{handle}” ({type})', [
                'handle' => $spec->handle,
                'type' => $this->fieldTypeLabel($spec->type),
            ]));
        }

        return $fields;
    }

    /**
     * @param FieldInterface[] $fields keyed by role
     */
    private function ensureEntryType(Blueprint $blueprint, array $fields, BuildResult $result): EntryType
    {
        $existing = Craft::$app->getEntries()->getEntryTypeByHandle($blueprint->entryTypeHandle());

        if ($existing !== null) {
            $result->addReused(Craft::t('portfolio', 'Entry type “{handle}”', ['handle' => $existing->handle]));
            return $existing;
        }

        $entryType = new EntryType([
            'name' => $blueprint->entryTypeName(),
            'handle' => $blueprint->entryTypeHandle(),
            'hasTitleField' => true,
            'showSlugField' => true,
            'icon' => 'briefcase',
        ]);

        $entryType->setFieldLayout($this->buildFieldLayout($blueprint, $fields));

        if (!Craft::$app->getEntries()->saveEntryType($entryType)) {
            throw new BuildException($this->modelErrors($entryType, Craft::t('portfolio', 'Could not save the entry type.')));
        }

        $result->addCreated(Craft::t('portfolio', 'Entry type “{handle}”', ['handle' => $entryType->handle]));

        return $entryType;
    }

    /**
     * @param FieldInterface[] $fields keyed by role
     */
    private function buildFieldLayout(Blueprint $blueprint, array $fields): FieldLayout
    {
        $layout = new FieldLayout(['type' => Entry::class]);

        $byTab = [];

        foreach ($blueprint->fieldSpecs() as $role => $spec) {
            if (!isset($fields[$role])) {
                continue;
            }

            $byTab[$spec->tab][] = new CustomField($fields[$role]);
        }

        $tabs = [];
        $sortOrder = 1;

        // Title always leads the first tab; an author looking at a blank entry should meet the
        // same furniture they meet everywhere else in Craft.
        $first = true;

        foreach (['Content', 'Details'] as $tabName) {
            $elements = $byTab[$tabName] ?? [];

            if ($first) {
                array_unshift($elements, new EntryTitleField());
                $first = false;
            } elseif ($elements === []) {
                continue;
            }

            $tabs[] = new FieldLayoutTab([
                'layout' => $layout,
                'name' => Craft::t('portfolio', $tabName),
                'sortOrder' => $sortOrder++,
                'elements' => $elements,
            ]);
        }

        $layout->setTabs($tabs);

        return $layout;
    }

    private function ensureSection(Blueprint $blueprint, EntryType $entryType, BuildResult $result): Section
    {
        $entries = Craft::$app->getEntries();
        $existing = $entries->getSectionByHandle($blueprint->sectionHandle());

        if ($existing !== null) {
            // The section is the site's, not ours. If it does not yet offer the portfolio entry
            // type, add it — that is additive and is the one thing a portfolio genuinely needs —
            // but leave every other setting exactly as the site has it.
            $entryTypes = $existing->getEntryTypes();
            $has = false;

            foreach ($entryTypes as $type) {
                if ($type->uid === $entryType->uid) {
                    $has = true;
                    break;
                }
            }

            if (!$has) {
                $entryTypes[] = $entryType;
                $existing->setEntryTypes($entryTypes);

                if (!$entries->saveSection($existing)) {
                    throw new BuildException($this->modelErrors($existing, Craft::t('portfolio', 'Could not add the entry type to the existing section.')));
                }

                $result->addCreated(Craft::t('portfolio', 'Added entry type “{handle}” to the existing “{section}” section', [
                    'handle' => $entryType->handle,
                    'section' => $existing->handle,
                ]));
            } else {
                $result->addReused(Craft::t('portfolio', 'Section “{handle}”', ['handle' => $existing->handle]));
            }

            return $existing;
        }

        $section = new Section([
            'name' => $blueprint->name,
            'handle' => $blueprint->sectionHandle(),
            'type' => $blueprint->sectionType,
            'enableVersioning' => true,
            'defaultPlacement' => Section::DEFAULT_PLACEMENT_BEGINNING,
        ]);

        if ($blueprint->sectionType === Section::TYPE_STRUCTURE) {
            $section->maxLevels = 1;
        }

        $section->setSiteSettings($this->sectionSiteSettings($blueprint));
        $section->setEntryTypes([$entryType]);

        if (!$entries->saveSection($section)) {
            throw new BuildException($this->modelErrors($section, Craft::t('portfolio', 'Could not save the section.')));
        }

        $result->addCreated(Craft::t('portfolio', 'Section “{handle}” at {uri}', [
            'handle' => $section->handle,
            'uri' => $blueprint->uriFormat,
        ]));

        return $section;
    }

    private function ensureCategoryGroup(Blueprint $blueprint, BuildResult $result): CategoryGroup
    {
        $categories = Craft::$app->getCategories();
        $existing = $categories->getGroupByHandle($blueprint->categoryGroupHandle());

        if ($existing !== null) {
            $result->addReused(Craft::t('portfolio', 'Category group “{handle}”', ['handle' => $existing->handle]));
            return $existing;
        }

        $group = new CategoryGroup([
            'name' => $blueprint->categoryGroupName(),
            'handle' => $blueprint->categoryGroupHandle(),
            'maxLevels' => 2,
            'defaultPlacement' => CategoryGroup::DEFAULT_PLACEMENT_END,
        ]);

        $group->setFieldLayout(new FieldLayout(['type' => Category::class]));
        $group->setSiteSettings($this->categorySiteSettings($blueprint));

        if (!$categories->saveGroup($group)) {
            throw new BuildException($this->modelErrors($group, Craft::t('portfolio', 'Could not save the category group.')));
        }

        $result->addCreated(Craft::t('portfolio', 'Category group “{handle}” with archives at {uri}', [
            'handle' => $group->handle,
            'uri' => $blueprint->categoryUriFormat,
        ]));

        return $group;
    }

    private function ensureTagGroup(Blueprint $blueprint, BuildResult $result): TagGroup
    {
        $tags = Craft::$app->getTags();
        $existing = $tags->getTagGroupByHandle($blueprint->tagGroupHandle());

        if ($existing !== null) {
            $result->addReused(Craft::t('portfolio', 'Tag group “{handle}”', ['handle' => $existing->handle]));
            return $existing;
        }

        $group = new TagGroup([
            'name' => $blueprint->tagGroupName(),
            'handle' => $blueprint->tagGroupHandle(),
        ]);

        $group->setFieldLayout(new FieldLayout(['type' => Tag::class]));

        if (!$tags->saveTagGroup($group)) {
            throw new BuildException($this->modelErrors($group, Craft::t('portfolio', 'Could not save the tag group.')));
        }

        $result->addCreated(Craft::t('portfolio', 'Tag group “{handle}”', ['handle' => $group->handle]));

        return $group;
    }

    // ------------------------------------------------------------------ sites

    /** @return int[] */
    private function siteIds(Blueprint $blueprint): array
    {
        $sites = Craft::$app->getSites();

        if ($blueprint->siteUids === []) {
            return array_map(fn($site) => $site->id, $sites->getAllSites());
        }

        $ids = [];

        foreach ($blueprint->siteUids as $uid) {
            $site = $sites->getSiteByUid($uid);

            if ($site !== null) {
                $ids[] = $site->id;
            }
        }

        // A section with no sites cannot be saved, and silently building an unreachable one is
        // worse than falling back to the primary site.
        return $ids !== [] ? $ids : [$sites->getPrimarySite()->id];
    }

    /** @return Section_SiteSettings[] */
    private function sectionSiteSettings(Blueprint $blueprint): array
    {
        $settings = [];

        foreach ($this->siteIds($blueprint) as $siteId) {
            $settings[$siteId] = new Section_SiteSettings([
                'siteId' => $siteId,
                'enabledByDefault' => true,
                'hasUrls' => true,
                'uriFormat' => $blueprint->uriFormat,
                'template' => $blueprint->entryTemplate(),
            ]);
        }

        return $settings;
    }

    /** @return CategoryGroup_SiteSettings[] */
    private function categorySiteSettings(Blueprint $blueprint): array
    {
        $settings = [];

        foreach ($this->siteIds($blueprint) as $siteId) {
            $settings[$siteId] = new CategoryGroup_SiteSettings([
                'siteId' => $siteId,
                'hasUrls' => true,
                'uriFormat' => $blueprint->categoryUriFormat,
                'template' => $blueprint->categoryTemplate(),
            ]);
        }

        return $settings;
    }

    private function modelErrors(object $model, string $prefix): string
    {
        $messages = [];

        if (method_exists($model, 'getErrors')) {
            foreach ($model->getErrors() as $attribute => $errors) {
                foreach ((array)$errors as $error) {
                    $messages[] = "$attribute: $error";
                }
            }
        }

        return $messages === [] ? $prefix : $prefix . ' ' . implode(' ', $messages);
    }

    // ------------------------------------------------------------------ drift

    /**
     * Re-plans a built portfolio against the live content model, so the CP can say what has been
     * deleted or renamed underneath it.
     *
     * The blueprint is rebuilt from the portfolio's *current* field handles, not from the handles
     * the blueprint originally asked for — otherwise a deliberate rename reads as damage.
     */
    public function driftFor(Portfolio $portfolio): array
    {
        $problems = [];

        if ($portfolio->getSection() === null) {
            $problems[] = Craft::t('portfolio', 'The section this portfolio points at no longer exists.');
        }

        if ($portfolio->getEntryType() === null) {
            $problems[] = Craft::t('portfolio', 'The entry type this portfolio points at no longer exists.');
        }

        foreach ($portfolio->brokenRoles() as $role) {
            $problems[] = Craft::t('portfolio', 'The field for the {role} role has been deleted.', [
                'role' => Role::label($role),
            ]);
        }

        if ($portfolio->categoryGroupUid !== null && $portfolio->getCategoryGroup() === null) {
            $problems[] = Craft::t('portfolio', 'The category group this portfolio points at no longer exists.');
        }

        if ($portfolio->tagGroupUid !== null && $portfolio->getTagGroup() === null) {
            $problems[] = Craft::t('portfolio', 'The tag group this portfolio points at no longer exists.');
        }

        return $problems;
    }

    // ------------------------------------------------------------------ teardown

    /**
     * What removing a portfolio's schema would destroy.
     *
     * Deleting a section in Craft deletes its entries — there is no version of this that keeps
     * them, so the honest thing is to count them and say so before anybody types yes.
     */
    public function teardownImpact(Portfolio $portfolio): array
    {
        // Only what teardown() will actually delete: anything the build reused stays.
        $section = $portfolio->getSection();
        $section = $portfolio->wasCreated($section?->uid) ? $section : null;
        $group = $portfolio->getCategoryGroup();
        $group = $portfolio->wasCreated($group?->uid) ? $group : null;

        $entries = $section !== null
            ? Entry::find()->sectionId($section->id)->status(null)->siteId('*')->unique()->count()
            : 0;

        $categories = $group !== null
            ? Category::find()->groupId($group->id)->status(null)->siteId('*')->unique()->count()
            : 0;

        $tagGroup = $portfolio->getTagGroup();
        $tagGroup = $portfolio->wasCreated($tagGroup?->uid) ? $tagGroup : null;

        $tags = $tagGroup !== null
            ? Tag::find()->groupId($tagGroup->id)->status(null)->siteId('*')->unique()->count()
            : 0;

        return [
            'section' => $section?->name,
            'entries' => (int)$entries,
            'categories' => (int)$categories,
            'tags' => (int)$tags,
            'fields' => array_values(array_filter(array_map(
                fn(string $role) => $portfolio->wasCreated($portfolio->roles[$role]) ? $portfolio->handleForRole($role) : null,
                array_keys($portfolio->roles),
            ))),
        ];
    }

    /**
     * Removes the content model a portfolio was built on, entries and all.
     *
     * Never called by anything the plugin does on its own — not by uninstall, not by
     * `deletePortfolio()`. Only an explicit, confirmed request gets here.
     *
     * Only what the plugin created is removed — anything the build reused stays, the same rule
     * build() follows. Fields are also kept when something else is using them: a field the site
     * later added to another entry type is the site's field now, whoever created it.
     */
    public function teardown(Portfolio $portfolio): BuildResult
    {
        $result = new BuildResult();

        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            $result->addProblem(Craft::t('portfolio', 'Admin changes are disabled in this environment.'));
            return $result;
        }

        // An adopted section is one the site built by hand, often years of entries ago. The
        // plugin did not create it, so it does not get to delete it — forgetting is the way out.
        if ($portfolio->adopted) {
            $result->addProblem(Craft::t('portfolio', 'This portfolio adopted a section the site already had, so its content model is not the plugin’s to remove. Forget the portfolio instead; the section stays as it is.'));
            return $result;
        }

        $entries = Craft::$app->getEntries();
        $section = $portfolio->getSection();

        try {
            if ($section !== null && !$portfolio->wasCreated($section->uid)) {
                $result->addReused(Craft::t('portfolio', 'Kept the “{handle}” section and its entries. The site had it before this portfolio.', ['handle' => $section->handle]));
            } elseif ($section !== null) {
                $entries->deleteSection($section);
                $result->addCreated(Craft::t('portfolio', 'Deleted the “{handle}” section and its entries', ['handle' => $section->handle]));
            }

            $entryType = $portfolio->getEntryType();

            if ($entryType !== null && !$portfolio->wasCreated($entryType->uid)) {
                $result->addReused(Craft::t('portfolio', 'Kept the “{handle}” entry type. The site had it before this portfolio.', ['handle' => $entryType->handle]));
            } elseif ($entryType !== null) {
                $entries->deleteEntryType($entryType);
                $result->addCreated(Craft::t('portfolio', 'Deleted the “{handle}” entry type', ['handle' => $entryType->handle]));
            }

            foreach ($portfolio->roles as $role => $uid) {
                $field = Craft::$app->getFields()->getFieldByUid($uid);

                if ($field === null) {
                    continue;
                }

                if (!$portfolio->wasCreated($field->uid)) {
                    $result->addReused(Craft::t('portfolio', 'Kept the “{handle}” field. The site had it before this portfolio.', ['handle' => $field->handle]));
                    continue;
                }

                if ($this->fieldIsUsedElsewhere($field, $portfolio)) {
                    $result->addReused(Craft::t('portfolio', 'Kept the “{handle}” field. It is used by another field layout.', ['handle' => $field->handle]));
                    continue;
                }

                Craft::$app->getFields()->deleteField($field);
                $result->addCreated(Craft::t('portfolio', 'Deleted the “{handle}” field', ['handle' => $field->handle]));
            }

            $group = $portfolio->getCategoryGroup();

            if ($group !== null && !$portfolio->wasCreated($group->uid)) {
                $result->addReused(Craft::t('portfolio', 'Kept the “{handle}” category group. The site had it before this portfolio.', ['handle' => $group->handle]));
            } elseif ($group !== null) {
                Craft::$app->getCategories()->deleteGroup($group);
                $result->addCreated(Craft::t('portfolio', 'Deleted the “{handle}” category group', ['handle' => $group->handle]));
            }

            $tagGroup = $portfolio->getTagGroup();

            if ($tagGroup !== null && !$portfolio->wasCreated($tagGroup->uid)) {
                $result->addReused(Craft::t('portfolio', 'Kept the “{handle}” tag group. The site had it before this portfolio.', ['handle' => $tagGroup->handle]));
            } elseif ($tagGroup !== null) {
                Craft::$app->getTags()->deleteTagGroup($tagGroup);
                $result->addCreated(Craft::t('portfolio', 'Deleted the “{handle}” tag group', ['handle' => $tagGroup->handle]));
            }
        } catch (Throwable $e) {
            Craft::error('Portfolio teardown failed: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
            $result->addProblem(Craft::t('portfolio', 'The removal failed part way: {message}', ['message' => $e->getMessage()]));

            return $result;
        }

        Plugin::getInstance()->portfolios->deletePortfolio($portfolio);

        $result->success = !$result->hasProblems();

        return $result;
    }

    /**
     * Whether a field appears in any field layout other than this portfolio's entry type.
     *
     * Craft 5 keeps field layouts as JSON in `fieldlayouts.config`, so this cannot be a query —
     * it walks the layouts, which is why the answer is memoized by the caller's single pass.
     */
    private function fieldIsUsedElsewhere(FieldInterface $field, Portfolio $portfolio): bool
    {
        $ourLayoutId = $portfolio->getEntryType()?->fieldLayoutId;

        foreach (Craft::$app->getFields()->getAllLayouts() as $layout) {
            if ($layout->id !== null && $layout->id === $ourLayoutId) {
                continue;
            }

            foreach ($layout->getCustomFields() as $layoutField) {
                if ($layoutField->uid === $field->uid) {
                    return true;
                }
            }
        }

        return false;
    }
}
