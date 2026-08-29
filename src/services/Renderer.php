<?php

namespace justinholtweb\portfolio\services;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\Template;
use justinholtweb\portfolio\models\Edition;
use justinholtweb\portfolio\models\Portfolio;
use justinholtweb\portfolio\models\Role;
use justinholtweb\portfolio\Plugin;
use Twig\Markup;

/**
 * `craft.portfolio.grid()` — a whole portfolio grid from one tag. **Pro.**
 *
 * The CSS and JS are inlined into the returned markup, once per request, rather than registered
 * through the view. `View::registerCss()` only reaches the page if the template calls `{{ head() }}`
 * and `{{ endBody() }}`, and plenty of real templates do not — a component whose styles silently
 * fail to arrive on some sites is worse than one that carries them.
 *
 * Every option is read with `?? null`. devMode turns on Twig's `strict_variables`, so a bare read
 * of a key the caller omitted throws in development and works in production, which is exactly the
 * wrong way round.
 */
class Renderer extends Component
{
    private bool $assetsEmitted = false;

    public function grid(?Portfolio $portfolio, array $options = []): Markup
    {
        $plugin = Plugin::getInstance();

        if (!Edition::allowsGrid($plugin->isPro())) {
            return Template::raw($this->upgradeNotice());
        }

        if ($portfolio === null) {
            return Template::raw($this->comment('no portfolio to render — build one in Settings → Portfolio'));
        }

        $settings = $plugin->getSettings();

        $layout = $this->oneOf($options['layout'] ?? $settings->gridLayout, ['grid', 'masonry', 'list'], 'grid');
        $columns = (int)($options['columns'] ?? $settings->gridColumns);
        $columns = max(1, min(6, $columns));
        $filterable = (bool)($options['filterable'] ?? $settings->gridFilterable);
        $lightbox = (bool)($options['lightbox'] ?? $settings->gridLightbox);
        $imageWidth = max(100, (int)($options['imageWidth'] ?? $settings->gridImageWidth));
        $extraClass = (string)($options['class'] ?? '');

        $criteria = $options['criteria'] ?? [];

        foreach (['category', 'categories', 'tag', 'tags', 'client', 'limit', 'orderBy', 'search'] as $key) {
            if (array_key_exists($key, $options)) {
                $criteria[$key] = $options[$key];
            }
        }

        $items = $plugin->query->items($portfolio, $criteria)->all();
        $filters = $filterable ? $plugin->query->filters($portfolio, $criteria) : [];

        $html = $this->assets($settings->gridAssets);
        $html .= $this->gridHtml($portfolio, $items, $filters, [
            'layout' => $layout,
            'columns' => $columns,
            'lightbox' => $lightbox,
            'imageWidth' => $imageWidth,
            'class' => $extraClass,
        ]);

        return Template::raw($html);
    }

    // ------------------------------------------------------------------ markup

    /**
     * @param Entry[] $items
     * @param array<int, array{category: \craft\elements\Category, count: int}> $filters
     */
    private function gridHtml(Portfolio $portfolio, array $items, array $filters, array $options): string
    {
        $classes = ['pf-grid', 'pf-grid--' . $options['layout']];

        if ($options['class'] !== '') {
            $classes[] = $options['class'];
        }

        $out = Html::beginTag('div', [
            'class' => $classes,
            'style' => ['--pf-grid-columns' => $options['columns']],
            'data' => [
                'pf-grid' => '1',
                'pf-lightbox' => $options['lightbox'] ? '1' : '0',
            ],
        ]);

        if ($filters !== []) {
            $out .= $this->filtersHtml($filters);
        }

        $out .= Html::beginTag('ul', ['class' => 'pf-grid__items', 'role' => 'list']);

        foreach ($items as $item) {
            $out .= $this->itemHtml($portfolio, $item, $options);
        }

        $out .= Html::endTag('ul');

        $out .= Html::tag('p', Craft::t('portfolio', 'Nothing here yet.'), [
            'class' => 'pf-grid__empty',
            'data' => ['pf-empty' => '1'],
            'hidden' => $items !== [],
        ]);

        // Read out when filtering changes the count; invisible otherwise.
        $out .= Html::tag('p', '', [
            'class' => 'pf-grid__status',
            'data' => ['pf-status' => '1'],
            'role' => 'status',
            'aria-live' => 'polite',
            'style' => ['position' => 'absolute', 'width' => '1px', 'height' => '1px', 'overflow' => 'hidden', 'clip' => 'rect(0 0 0 0)'],
        ]);

        return $out . Html::endTag('div');
    }

    private function filtersHtml(array $filters): string
    {
        $out = Html::beginTag('div', ['class' => 'pf-grid__filters', 'role' => 'group', 'aria-label' => Craft::t('portfolio', 'Filter by category')]);

        $out .= Html::button(Craft::t('portfolio', 'All'), [
            'type' => 'button',
            'class' => 'pf-grid__filter',
            'aria-pressed' => 'true',
            'data' => ['pf-filter' => ''],
        ]);

        foreach ($filters as $filter) {
            $label = Html::encode($filter['category']->title)
                . ' ' . Html::tag('span', (string)$filter['count'], ['class' => 'pf-grid__filter-count']);

            $out .= Html::button($label, [
                'type' => 'button',
                'class' => 'pf-grid__filter',
                'aria-pressed' => 'false',
                'data' => ['pf-filter' => $filter['category']->slug],
            ]);
        }

        return $out . Html::endTag('div');
    }

    private function itemHtml(Portfolio $portfolio, Entry $item, array $options): string
    {
        $featured = $this->firstAsset($portfolio, $item, Role::FEATURED_IMAGE);
        $summary = $this->text($portfolio, $item, Role::SUMMARY);
        $client = $this->text($portfolio, $item, Role::CLIENT);
        $slugs = $this->categorySlugs($portfolio, $item);

        $gallery = $options['lightbox'] ? $this->galleryPayload($portfolio, $item) : [];

        $out = Html::beginTag('li', [
            'class' => 'pf-grid__item',
            'data' => [
                'pf-item' => '1',
                'pf-categories' => implode(' ', $slugs),
            ],
        ]);

        $linkOptions = [
            'class' => 'pf-grid__card',
            'href' => $item->getUrl(),
        ];

        if ($gallery !== []) {
            $linkOptions['data'] = ['pf-gallery' => Json::encode($gallery)];
        }

        $out .= Html::beginTag('a', $linkOptions);

        if ($featured !== null) {
            $url = $featured->getUrl(['width' => $options['imageWidth'], 'height' => (int)round($options['imageWidth'] * 0.75), 'mode' => 'crop']);

            $out .= Html::tag('img', '', [
                'class' => 'pf-grid__image',
                'src' => $url,
                'alt' => $featured->alt ?: $item->title,
                'loading' => 'lazy',
                'width' => $options['imageWidth'],
                'height' => (int)round($options['imageWidth'] * 0.75),
            ]);
        } else {
            $out .= Html::tag('span', '', ['class' => 'pf-grid__image pf-grid__image--empty', 'aria-hidden' => 'true']);
        }

        $body = Html::tag('span', Html::encode($item->title), ['class' => 'pf-grid__title']);

        if ($client !== null) {
            $body .= Html::tag('span', Html::encode($client), ['class' => 'pf-grid__client']);
        }

        if ($summary !== null) {
            $body .= Html::tag('span', Html::encode($summary), ['class' => 'pf-grid__summary']);
        }

        $out .= Html::tag('span', $body, ['class' => 'pf-grid__body']);

        return $out . Html::endTag('a') . Html::endTag('li');
    }

    // ------------------------------------------------------------------ values

    private function firstAsset(Portfolio $portfolio, Entry $item, string $role): ?Asset
    {
        foreach ($this->assetsFor($portfolio, $item, $role) as $asset) {
            return $asset;
        }

        return null;
    }

    /** @return Asset[] */
    private function assetsFor(Portfolio $portfolio, Entry $item, string $role): array
    {
        $handle = $portfolio->handleForRole($role);

        if ($handle === null) {
            return [];
        }

        $value = $item->getFieldValue($handle);

        if ($value instanceof \craft\elements\ElementCollection) {
            $all = $value->all();
        } elseif ($value instanceof \craft\elements\db\ElementQueryInterface) {
            $all = $value->all();
        } else {
            return [];
        }

        return array_values(array_filter($all, fn($a) => $a instanceof Asset));
    }

    /** @return array<int, array{url: string, alt: string, caption: string}> */
    private function galleryPayload(Portfolio $portfolio, Entry $item): array
    {
        $payload = [];

        foreach ([Role::FEATURED_IMAGE, Role::GALLERY] as $role) {
            foreach ($this->assetsFor($portfolio, $item, $role) as $asset) {
                $url = $asset->getUrl(['width' => 1800]);

                if ($url === null) {
                    continue;
                }

                $payload[$url] = [
                    'url' => $url,
                    'alt' => (string)($asset->alt ?: ''),
                    'caption' => $item->title,
                ];
            }
        }

        return array_values($payload);
    }

    private function text(Portfolio $portfolio, Entry $item, string $role): ?string
    {
        $handle = $portfolio->handleForRole($role);

        if ($handle === null) {
            return null;
        }

        $value = $item->getFieldValue($handle);

        if ($value === null) {
            return null;
        }

        $text = trim(strip_tags((string)$value));

        return $text !== '' ? $text : null;
    }

    /** @return string[] */
    private function categorySlugs(Portfolio $portfolio, Entry $item): array
    {
        $handle = $portfolio->handleForRole(Role::CATEGORIES);

        if ($handle === null) {
            return [];
        }

        $value = $item->getFieldValue($handle);

        if ($value instanceof \craft\elements\ElementCollection) {
            $all = $value->all();
        } elseif ($value instanceof \craft\elements\db\ElementQueryInterface) {
            $all = $value->all();
        } else {
            return [];
        }

        return array_values(array_filter(array_map(fn($c) => (string)$c->slug, $all)));
    }

    // ------------------------------------------------------------------ assets

    /**
     * The CSS and JS, once per request.
     *
     * A page with three grids on it should not carry three copies, and a page with none should
     * carry neither.
     */
    private function assets(bool $enabled): string
    {
        if (!$enabled || $this->assetsEmitted) {
            return '';
        }

        $this->assetsEmitted = true;

        $css = @file_get_contents(__DIR__ . '/../resources/portfolio.css');
        $js = @file_get_contents(__DIR__ . '/../resources/portfolio.js');

        $out = '';

        if ($css !== false) {
            $out .= '<style>' . $css . '</style>';
        }

        if ($js !== false) {
            $out .= '<script>' . $js . '</script>';
        }

        return $out;
    }

    private function oneOf(mixed $value, array $allowed, string $fallback): string
    {
        $value = is_string($value) ? $value : '';

        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function comment(string $message): string
    {
        return '<!-- Portfolio: ' . str_replace('--', '—', $message) . ' -->';
    }

    /**
     * What Lite renders where the grid would be.
     *
     * A comment in production so nothing appears on a live page, and a visible note in devMode so
     * the developer is not left wondering why their tag output nothing at all.
     */
    private function upgradeNotice(): string
    {
        $message = 'craft.portfolio.grid() needs Portfolio Pro. The query API — craft.portfolio.items() — is in Lite and does everything the grid does, with your own markup.';

        if (Craft::$app->getConfig()->getGeneral()->devMode) {
            return Html::tag('div', Html::encode($message), [
                'style' => [
                    'border' => '1px dashed currentColor',
                    'border-radius' => '6px',
                    'padding' => '1rem',
                    'font' => '14px/1.5 system-ui, sans-serif',
                    'opacity' => '.7',
                ],
            ]);
        }

        return $this->comment($message);
    }
}
