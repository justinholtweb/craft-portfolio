<?php

namespace justinholtweb\portfolio\models;

use Craft;
use craft\base\Model;
use craft\fields\Assets;
use craft\fields\Categories as CategoriesField;
use craft\fields\Date;
use craft\fields\Link;
use craft\fields\PlainText;
use craft\fields\Tags as TagsField;
use craft\helpers\StringHelper;
use craft\models\Section;

/**
 * The content model a portfolio wants to have.
 *
 * A blueprint is entirely declarative and holds no Craft state — it can be built, edited and
 * validated without touching project config. `services\Builder` is the only thing that turns one
 * into real sections and fields.
 */
class Blueprint extends Model
{
    /** Display name, e.g. "Portfolio" or "Case Studies". */
    public string $name = 'Portfolio';

    /** Handle. Section, entry type, fields and groups are all named from this. */
    public string $handle = 'portfolio';

    /** `Section::TYPE_STRUCTURE` or `Section::TYPE_CHANNEL`. */
    public string $sectionType = Section::TYPE_STRUCTURE;

    /** Site UIDs the section should be enabled for. Empty means every site. */
    public array $siteUids = [];

    /** URI format for a portfolio item. */
    public string $uriFormat = '';

    /** URI format for a category archive. */
    public string $categoryUriFormat = '';

    /** Template root the starter templates live under, e.g. `portfolio`. */
    public string $templateRoot = '';

    /** Roles to build fields for. Anything not listed is simply not part of this portfolio. */
    public array $roles = [];

    /** Volume UID the asset fields should point at. Null means every volume the user can see. */
    public ?string $assetVolumeUid = null;

    /** Whether to use a CKEditor field for the description when that plugin is installed. */
    public bool $useCkeditor = true;

    /** Existing field handles to adopt instead of creating, keyed by role. (Pro: adopt flow.) */
    public array $adoptFields = [];

    public function init(): void
    {
        parent::init();

        if ($this->roles === []) {
            $this->roles = Role::all();
        }

        $this->fillDerived();
    }

    /**
     * Fills in anything the caller left blank, from the handle.
     *
     * Done here rather than in the getters so the CP can show the admin exactly what it is about
     * to create, and let them edit it, rather than surprising them at build time.
     */
    public function fillDerived(): void
    {
        $base = $this->baseUri();

        if ($this->templateRoot === '') {
            $this->templateRoot = $base;
        }

        if ($this->uriFormat === '') {
            $this->uriFormat = "$base/{slug}";
        }

        if ($this->categoryUriFormat === '') {
            $this->categoryUriFormat = "$base/category/{slug}";
        }
    }

    /** The kebab-cased handle, used as the URI and template root, e.g. `case-studies`. */
    public function baseUri(): string
    {
        return StringHelper::toKebabCase($this->handle ?: 'portfolio');
    }

    public function sectionHandle(): string
    {
        return $this->handle;
    }

    public function entryTypeHandle(): string
    {
        return $this->handle . 'Item';
    }

    public function entryTypeName(): string
    {
        return Craft::t('portfolio', '{name} Item', ['name' => $this->name]);
    }

    public function categoryGroupHandle(): string
    {
        return $this->handle . 'Categories';
    }

    public function categoryGroupName(): string
    {
        return Craft::t('portfolio', '{name} Categories', ['name' => $this->name]);
    }

    public function tagGroupHandle(): string
    {
        return $this->handle . 'Tags';
    }

    public function tagGroupName(): string
    {
        return Craft::t('portfolio', '{name} Tags', ['name' => $this->name]);
    }

    public function entryTemplate(): string
    {
        return $this->templateRoot . '/_entry';
    }

    public function categoryTemplate(): string
    {
        return $this->templateRoot . '/category';
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function wantsCategoryGroup(): bool
    {
        return $this->hasRole(Role::CATEGORIES);
    }

    public function wantsTagGroup(): bool
    {
        return $this->hasRole(Role::TAGS);
    }

    /**
     * The field handle for a role — the adopted one if the admin picked an existing field,
     * otherwise a handle derived from the portfolio handle so two portfolios never collide.
     */
    public function fieldHandle(string $role): string
    {
        if (isset($this->adoptFields[$role]) && $this->adoptFields[$role] !== '') {
            return $this->adoptFields[$role];
        }

        return $this->handle . ucfirst($role);
    }

    /**
     * @return FieldSpec[] keyed by role, in field-layout order.
     */
    public function fieldSpecs(): array
    {
        $specs = [];

        foreach ($this->roles as $role) {
            $spec = $this->fieldSpec($role);

            if ($spec !== null) {
                $specs[$role] = $spec;
            }
        }

        return $specs;
    }

    public function fieldSpec(string $role): ?FieldSpec
    {
        if (!$this->hasRole($role)) {
            return null;
        }

        $handle = $this->fieldHandle($role);
        $name = Role::label($role);
        $instructions = Role::description($role);

        [$type, $settings, $tab, $searchable, $deferred] = match ($role) {
            Role::SUMMARY => [
                PlainText::class,
                ['multiline' => true, 'initialRows' => 3, 'charLimit' => 255],
                'Content', true, [],
            ],
            Role::DESCRIPTION => [
                $this->descriptionFieldType(),
                $this->descriptionFieldSettings(),
                'Content', true, [],
            ],
            Role::FEATURED_IMAGE => [
                Assets::class,
                $this->assetSettings(1),
                'Content', false, [],
            ],
            Role::GALLERY => [
                Assets::class,
                $this->assetSettings(null),
                'Content', false, [],
            ],
            Role::CLIENT => [
                PlainText::class,
                [],
                'Details', true, [],
            ],
            Role::COMPLETED_DATE => [
                Date::class,
                ['showDate' => true, 'showTime' => false],
                'Details', false, [],
            ],
            Role::PROJECT_URL => [
                Link::class,
                ['types' => ['url'], 'showLabelField' => true],
                'Details', false, [],
            ],
            Role::CATEGORIES => [
                CategoriesField::class,
                ['branchLimit' => null, 'maintainHierarchy' => true],
                'Details', false, ['source' => 'categoryGroup'],
            ],
            Role::TAGS => [
                TagsField::class,
                [],
                'Details', false, ['source' => 'tagGroup'],
            ],
            default => [null, [], 'Content', false, []],
        };

        if ($type === null) {
            return null;
        }

        return new FieldSpec([
            'role' => $role,
            'handle' => $handle,
            'name' => $name,
            'instructions' => $instructions,
            'type' => $type,
            'settings' => $settings,
            'tab' => $tab,
            'searchable' => $searchable,
            'deferredSources' => $deferred,
        ]);
    }

    /**
     * CKEditor when the site has it, plain text otherwise.
     *
     * A rich-text field is what everyone actually wants here, but taking a hard dependency on
     * `craftcms/ckeditor` to install a portfolio would be rude, so the blueprint degrades.
     */
    public function descriptionFieldType(): string
    {
        if ($this->useCkeditor && self::ckeditorAvailable()) {
            return 'craft\\ckeditor\\Field';
        }

        return PlainText::class;
    }

    public static function ckeditorAvailable(): bool
    {
        return class_exists('craft\\ckeditor\\Field')
            && Craft::$app->getPlugins()->isPluginEnabled('ckeditor');
    }

    private function descriptionFieldSettings(): array
    {
        if ($this->descriptionFieldType() === PlainText::class) {
            return ['multiline' => true, 'initialRows' => 12];
        }

        $settings = [
            'toolbar' => ['heading', '|', 'bold', 'italic', 'link', '|', 'bulletedList', 'numberedList', '|', 'blockQuote', 'image'],
            'headingLevels' => [2, 3, 4],
        ];

        if ($this->assetVolumeUid !== null) {
            $settings['availableVolumes'] = ['volume:' . $this->assetVolumeUid];
            $settings['defaultUploadLocationVolume'] = 'volume:' . $this->assetVolumeUid;
        }

        return $settings;
    }

    private function assetSettings(?int $maxRelations): array
    {
        $settings = [
            'restrictFiles' => true,
            'allowedKinds' => ['image'],
            'maxRelations' => $maxRelations,
            'viewMode' => 'large',
            'allowUploads' => true,
        ];

        if ($this->assetVolumeUid !== null) {
            $settings['sources'] = ['volume:' . $this->assetVolumeUid];
            $settings['defaultUploadLocationSource'] = 'volume:' . $this->assetVolumeUid;
        } else {
            $settings['sources'] = '*';
        }

        return $settings;
    }

    public function rules(): array
    {
        return [
            [['name', 'handle', 'sectionType', 'uriFormat', 'templateRoot'], 'required'],
            [['handle'], 'match', 'pattern' => '/^[a-z][a-zA-Z0-9]*$/', 'message' => Craft::t('portfolio', 'The handle must start with a lowercase letter and contain only letters and numbers.')],
            [['sectionType'], 'in', 'range' => [Section::TYPE_STRUCTURE, Section::TYPE_CHANNEL]],
            [['templateRoot'], 'match', 'pattern' => Portfolio::TEMPLATE_ROOT_PATTERN, 'message' => Craft::t('portfolio', 'The template root must be a path inside the templates folder, like `work` or `work/projects`.')],
            [['roles'], function(string $attribute) {
                foreach ($this->$attribute as $role) {
                    if (!Role::exists($role)) {
                        $this->addError($attribute, "Unknown role “{$role}”.");
                    }
                }
            }, 'skipOnEmpty' => false],
        ];
    }
}
