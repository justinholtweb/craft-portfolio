<?php

namespace justinholtweb\portfolio\models;

use Craft;
use craft\base\FieldInterface;
use craft\base\Model;
use craft\models\CategoryGroup;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\TagGroup;
use craft\validators\HandleValidator;
use justinholtweb\portfolio\Plugin;

/**
 * A portfolio that exists: a section, an entry type, and a map from role to field.
 *
 * Everything is held by **UID**, never by handle or ID. Handles get renamed and IDs differ between
 * environments; a UID survives both, so renaming `portfolioClient` to `clientName` in the CP
 * leaves every template that asks for the `client` role working.
 *
 * This model is what lives in project config under `portfolio.portfolios.<uid>`.
 */
class Portfolio extends Model
{
    /**
     * A template root: path segments of letters, digits, `_` and `-`, nothing else.
     *
     * It becomes a directory under `templates/` that the starter templates are written into, a
     * site URL rule, and a string inside the Twig that gets written — so `..`, a leading slash or
     * a quote would be a path out of `templates/`, a broken route, or code in somebody's site.
     */
    public const TEMPLATE_ROOT_PATTERN = '/^[a-zA-Z0-9_\-]+(\/[a-zA-Z0-9_\-]+)*$/';

    public string $uid = '';
    public string $name = '';
    public string $handle = '';

    public string $sectionUid = '';
    public string $entryTypeUid = '';
    public ?string $categoryGroupUid = null;
    public ?string $tagGroupUid = null;

    /** role => field UID */
    public array $roles = [];

    public string $templateRoot = 'portfolio';
    public string $sectionType = Section::TYPE_STRUCTURE;

    /** Sort order in the CP list. */
    public int $sortOrder = 0;

    /** Set when the portfolio was created by adopting an existing section rather than building one. */
    public bool $adopted = false;

    /**
     * UIDs of the section, entry type, groups and fields this plugin created for the portfolio.
     *
     * Teardown deletes only these. Anything the build *reused* was the site's before the portfolio
     * existed, and stays the site's — "never edit what you did not create" covers deleting too.
     *
     * @var string[]
     */
    public array $created = [];

    /** Whether the plugin created the thing with this UID, and so may remove it. */
    public function wasCreated(?string $uid): bool
    {
        return $uid !== null && $uid !== '' && in_array($uid, $this->created, true);
    }

    private ?Section $_section = null;
    private ?EntryType $_entryType = null;

    // ------------------------------------------------------------------ the model behind it

    public function getSection(): ?Section
    {
        if ($this->_section === null && $this->sectionUid !== '') {
            foreach (Craft::$app->getEntries()->getAllSections() as $section) {
                if ($section->uid === $this->sectionUid) {
                    $this->_section = $section;
                    break;
                }
            }
        }

        return $this->_section;
    }

    public function getEntryType(): ?EntryType
    {
        if ($this->_entryType === null && $this->entryTypeUid !== '') {
            foreach (Craft::$app->getEntries()->getAllEntryTypes() as $entryType) {
                if ($entryType->uid === $this->entryTypeUid) {
                    $this->_entryType = $entryType;
                    break;
                }
            }
        }

        return $this->_entryType;
    }

    public function getCategoryGroup(): ?CategoryGroup
    {
        if ($this->categoryGroupUid === null) {
            return null;
        }

        foreach (Craft::$app->getCategories()->getAllGroups() as $group) {
            if ($group->uid === $this->categoryGroupUid) {
                return $group;
            }
        }

        return null;
    }

    public function getTagGroup(): ?TagGroup
    {
        if ($this->tagGroupUid === null) {
            return null;
        }

        return Craft::$app->getTags()->getTagGroupByUid($this->tagGroupUid);
    }

    // ------------------------------------------------------------------ roles

    public function hasRole(string $role): bool
    {
        return isset($this->roles[$role]) && $this->roles[$role] !== '';
    }

    /** The field playing a role, or null if this portfolio has no field for it. */
    public function fieldForRole(string $role): ?FieldInterface
    {
        if (!$this->hasRole($role)) {
            return null;
        }

        return Craft::$app->getFields()->getFieldByUid($this->roles[$role]);
    }

    /**
     * The *current* handle of the field playing a role.
     *
     * This is the only correct way to reach a value: `entry.{{ handleForRole('client') }}`. Never
     * hardcode a handle, because the site owns it and may rename it at any time.
     */
    public function handleForRole(string $role): ?string
    {
        return $this->fieldForRole($role)?->handle;
    }

    /** Every role this portfolio actually has a live field for, in canonical order. */
    public function liveRoles(): array
    {
        $live = [];

        foreach (Role::all() as $role) {
            if ($this->fieldForRole($role) !== null) {
                $live[] = $role;
            }
        }

        return $live;
    }

    /**
     * Roles that were configured but whose field has since been deleted.
     *
     * Surfaced on the status screen — a missing field is the single most likely reason a portfolio
     * template goes blank, and it is otherwise completely silent.
     */
    public function brokenRoles(): array
    {
        $broken = [];

        foreach ($this->roles as $role => $uid) {
            if ($uid !== '' && Craft::$app->getFields()->getFieldByUid($uid) === null) {
                $broken[] = $role;
            }
        }

        return $broken;
    }

    // ------------------------------------------------------------------ config

    public function toConfig(): array
    {
        $roles = [];

        foreach (Role::all() as $role) {
            if (isset($this->roles[$role]) && $this->roles[$role] !== '') {
                $roles[$role] = $this->roles[$role];
            }
        }

        return [
            'name' => $this->name,
            'handle' => $this->handle,
            'section' => $this->sectionUid,
            'entryType' => $this->entryTypeUid,
            'categoryGroup' => $this->categoryGroupUid,
            'tagGroup' => $this->tagGroupUid,
            'roles' => $roles,
            'templateRoot' => $this->templateRoot,
            'sectionType' => $this->sectionType,
            'sortOrder' => $this->sortOrder,
            'adopted' => $this->adopted,
            'created' => array_values(array_unique($this->created)),
        ];
    }

    public static function fromConfig(string $uid, array $config): self
    {
        return new self([
            'uid' => $uid,
            'name' => $config['name'] ?? 'Portfolio',
            'handle' => $config['handle'] ?? 'portfolio',
            'sectionUid' => $config['section'] ?? '',
            'entryTypeUid' => $config['entryType'] ?? '',
            'categoryGroupUid' => $config['categoryGroup'] ?? null,
            'tagGroupUid' => $config['tagGroup'] ?? null,
            'roles' => $config['roles'] ?? [],
            'templateRoot' => $config['templateRoot'] ?? 'portfolio',
            'sectionType' => $config['sectionType'] ?? Section::TYPE_STRUCTURE,
            'sortOrder' => (int)($config['sortOrder'] ?? 0),
            'adopted' => (bool)($config['adopted'] ?? false),
            'created' => array_values($config['created'] ?? []),
        ]);
    }

    public function rules(): array
    {
        return [
            [['name', 'handle', 'sectionUid', 'entryTypeUid', 'templateRoot'], 'required'],
            [['handle'], HandleValidator::class],
            [['handle'], function(string $attribute) {
                foreach (Plugin::getInstance()->portfolios->getAllPortfolios() as $other) {
                    if ($other->handle === $this->handle && $other->uid !== $this->uid) {
                        $this->addError($attribute, Craft::t('portfolio', 'Another portfolio already uses the handle “{handle}”.', ['handle' => $this->handle]));
                    }
                }
            }],
            [['templateRoot'], 'match', 'pattern' => self::TEMPLATE_ROOT_PATTERN, 'message' => Craft::t('portfolio', 'The template root must be a path inside the templates folder, like `work` or `work/projects`.')],
        ];
    }
}
