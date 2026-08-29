<?php

namespace justinholtweb\portfolio\models;

use craft\base\Model;

/**
 * One thing a build intends to do, and what the site already has to say about it.
 *
 * The point of naming every item before anything is written is that building a content model is
 * not undoable in any way an author would recognise. Nobody should find out what a plugin created
 * by reading the diff afterwards.
 */
class PlanItem extends Model
{
    public const KIND_SECTION = 'section';
    public const KIND_ENTRY_TYPE = 'entryType';
    public const KIND_FIELD = 'field';
    public const KIND_CATEGORY_GROUP = 'categoryGroup';
    public const KIND_TAG_GROUP = 'tagGroup';

    /** Will be created. */
    public const ACTION_CREATE = 'create';

    /** Already exists with this handle and the right type — the build will point at it, untouched. */
    public const ACTION_REUSE = 'reuse';

    /** Already exists with this handle but is the wrong type. The build stops. */
    public const ACTION_CONFLICT = 'conflict';

    public string $kind = '';
    public string $handle = '';
    public string $label = '';
    public string $action = self::ACTION_CREATE;
    public string $note = '';

    /** The role, for field items. */
    public ?string $role = null;

    /** UID of the existing thing, when reusing. */
    public ?string $existingUid = null;

    public function isCreate(): bool
    {
        return $this->action === self::ACTION_CREATE;
    }

    public function isReuse(): bool
    {
        return $this->action === self::ACTION_REUSE;
    }

    public function isConflict(): bool
    {
        return $this->action === self::ACTION_CONFLICT;
    }
}
