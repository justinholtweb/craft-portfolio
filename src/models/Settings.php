<?php

namespace justinholtweb\portfolio\models;

use craft\base\Model;

/**
 * Plugin settings.
 *
 * Nothing here is `required` — a required plugin setting makes `savePluginSettings()` fail
 * wholesale, so a fresh install cannot save *any* setting until that one is filled in. Values are
 * validated for correctness when present and defaulted otherwise.
 */
class Settings extends Model
{
    /** Which portfolio `craft.portfolio.*` means when no portfolio is named. Empty = the first one. */
    public string $defaultPortfolio = '';

    /** Serve `/<root>/tag/<slug>` archives for portfolios that have a tag group. */
    public bool $tagArchives = true;

    /** Template, relative to a portfolio's template root, that renders a tag archive. */
    public string $tagTemplate = 'tag';

    /** Emit schema.org CreativeWork JSON-LD on portfolio item pages. (Pro) */
    public bool $jsonLd = false;

    // ------------------------------------------------------------------ grid defaults (Pro)

    /** `grid`, `masonry` or `list`. */
    public string $gridLayout = 'grid';

    public int $gridColumns = 3;

    public bool $gridFilterable = true;

    public bool $gridLightbox = true;

    /** Image transform width, in pixels, for grid cards. */
    public int $gridImageWidth = 800;

    /**
     * Whether the grid inlines its own CSS and JS.
     *
     * Off is for sites that would rather copy the CSS into their own stylesheet — the markup and
     * class names stay identical either way.
     */
    public bool $gridAssets = true;

    public function rules(): array
    {
        return [
            [['gridLayout'], 'in', 'range' => ['grid', 'masonry', 'list']],
            [['gridColumns'], 'integer', 'min' => 1, 'max' => 6],
            [['gridImageWidth'], 'integer', 'min' => 100, 'max' => 4000],
            [['tagTemplate'], 'match', 'pattern' => '/^[a-zA-Z0-9_\-\/]+$/', 'message' => 'The tag template must be a template path.'],
            [['defaultPortfolio'], 'string'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'defaultPortfolio' => 'Default portfolio',
            'tagArchives' => 'Tag archives',
            'tagTemplate' => 'Tag archive template',
            'jsonLd' => 'Structured data',
            'gridLayout' => 'Default grid layout',
            'gridColumns' => 'Default columns',
            'gridFilterable' => 'Filtering on by default',
            'gridLightbox' => 'Lightbox on by default',
            'gridImageWidth' => 'Card image width',
            'gridAssets' => 'Inline the grid CSS and JS',
        ];
    }
}
