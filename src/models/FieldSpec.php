<?php

namespace justinholtweb\portfolio\models;

use craft\base\Model;

/**
 * One field a blueprint wants to exist: the role it plays, the handle and name to give it, the
 * field class, and the settings that class needs.
 *
 * A spec is a *wish*. It says nothing about whether the field exists yet — that is the builder's
 * job to work out, and the builder never edits a field it did not create.
 */
class FieldSpec extends Model
{
    /** The role this field plays. @see Role */
    public string $role = '';

    /** Field handle to create, e.g. `portfolioClient`. */
    public string $handle = '';

    /** Field name shown in the CP. */
    public string $name = '';

    /** Instructions shown under the field. */
    public string $instructions = '';

    /** Fully-qualified field class, e.g. `craft\fields\PlainText`. */
    public string $type = '';

    /** Settings assigned to the field instance after construction. */
    public array $settings = [];

    /** Which field-layout tab this belongs in. */
    public string $tab = 'Content';

    /**
     * Whether the field is searchable. Text roles are; relations are not, because Craft indexes
     * related element titles into the owner and that is rarely what anybody wanted.
     */
    public bool $searchable = false;

    /**
     * Placeholder for a source that can only be resolved once the thing it points at exists —
     * the category group and tag group are created in the same build as the fields that select
     * from them.
     *
     * Keyed by the settings key to fill; the value names what to wait for.
     * e.g. `['source' => 'categoryGroup']`
     */
    public array $deferredSources = [];

    public function rules(): array
    {
        return [
            [['role', 'handle', 'name', 'type'], 'required'],
            [['handle'], 'match', 'pattern' => '/^[a-z][a-zA-Z0-9]*$/', 'message' => 'Field handles must start with a letter and contain only letters and numbers.'],
            [['role'], function(string $attribute) {
                if (!Role::exists($this->$attribute)) {
                    $this->addError($attribute, "Unknown role “{$this->$attribute}”.");
                }
            }],
        ];
    }
}
