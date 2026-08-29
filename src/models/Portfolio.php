<?php

namespace justinholtweb\portfolio\models;

use Craft;
use craft\base\FieldInterface;
use craft\base\Model;
use craft\models\CategoryGroup;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\TagGroup;

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
        ]);
    }

    public function rules(): array
    {
        return [
            [['name', 'handle', 'sectionUid', 'entryTypeUid'], 'required'],
        ];
    }
}
