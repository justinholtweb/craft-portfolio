<?php

namespace justinholtweb\portfolio\services;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\helpers\Json;
use craft\helpers\Template;
use DateTimeInterface;
use justinholtweb\portfolio\models\Edition;
use justinholtweb\portfolio\models\Portfolio;
use justinholtweb\portfolio\models\Role;
use justinholtweb\portfolio\Plugin;
use Twig\Markup;

/**
 * schema.org `CreativeWork` for a portfolio item. **Pro.**
 *
 * Emitted from the template — `{{ craft.portfolio.jsonld(entry) }}` — rather than injected into a
 * prepared response. Injection means rewriting a response body, restamping `content-length`, and
 * being careful not to touch `feed.rss.twig`; a portfolio item page is a template the developer
 * already owns, so none of that is worth buying.
 */
class Jsonld extends Component
{
    public function render(?Entry $entry = null, ?Portfolio $portfolio = null): Markup
    {
        $data = $this->data($entry, $portfolio);

        if ($data === null) {
            return Template::raw('');
        }

        return Template::raw(sprintf(
            '<script type="application/ld+json">%s</script>',
            Json::encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ));
    }

    /**
     * The structured data as an array, for a site that would rather merge it into its own graph
     * than emit a second script tag.
     */
    public function data(?Entry $entry = null, ?Portfolio $portfolio = null): ?array
    {
        $plugin = Plugin::getInstance();

        if (!Edition::allowsJsonLd($plugin->isPro()) || !$plugin->getSettings()->jsonLd) {
            return null;
        }

        $entry ??= $this->matchedEntry();

        if ($entry === null) {
            return null;
        }

        $portfolio ??= $plugin->portfolios->getPortfolioForEntry($entry);

        if ($portfolio === null) {
            return null;
        }

        $data = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'CreativeWork',
            'name' => $entry->title,
            'url' => $entry->getUrl(),
            'description' => $this->plainText($portfolio, $entry, Role::SUMMARY),
            'datePublished' => $this->date($entry->postDate),
            'dateModified' => $this->date($entry->dateUpdated),
            'dateCreated' => $this->roleDate($portfolio, $entry, Role::COMPLETED_DATE),
            'image' => $this->images($portfolio, $entry),
            'genre' => $this->titles($portfolio, $entry, Role::CATEGORIES),
            'keywords' => implode(', ', $this->titles($portfolio, $entry, Role::TAGS)),
            'sameAs' => $this->projectUrl($portfolio, $entry),
        ], fn($value) => $value !== null && $value !== '' && $value !== []);

        // “The Organization on whose behalf the creator was working” is exactly what a client is.
        $client = $this->plainText($portfolio, $entry, Role::CLIENT);

        if ($client !== null && $client !== '') {
            $data['sourceOrganization'] = [
                '@type' => 'Organization',
                'name' => $client,
            ];
        }

        return $data;
    }

    private function matchedEntry(): ?Entry
    {
        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest()) {
            return null;
        }

        $element = Craft::$app->getUrlManager()->getMatchedElement();

        return $element instanceof Entry ? $element : null;
    }

    private function plainText(Portfolio $portfolio, Entry $entry, string $role): ?string
    {
        $handle = $portfolio->handleForRole($role);

        if ($handle === null) {
            return null;
        }

        $value = $entry->getFieldValue($handle);

        if ($value === null) {
            return null;
        }

        $text = trim(strip_tags((string)$value));

        return $text !== '' ? $text : null;
    }

    private function roleDate(Portfolio $portfolio, Entry $entry, string $role): ?string
    {
        $handle = $portfolio->handleForRole($role);

        return $handle === null ? null : $this->date($entry->getFieldValue($handle));
    }

    private function date(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format(DATE_ATOM) : null;
    }

    /** @return string[] */
    private function images(Portfolio $portfolio, Entry $entry): array
    {
        $urls = [];

        foreach ([Role::FEATURED_IMAGE, Role::GALLERY] as $role) {
            $handle = $portfolio->handleForRole($role);

            if ($handle === null) {
                continue;
            }

            foreach ($this->elements($entry, $handle) as $asset) {
                if ($asset instanceof Asset) {
                    $url = $asset->getUrl();

                    if ($url !== null) {
                        $urls[] = $url;
                    }
                }
            }
        }

        return array_values(array_unique($urls));
    }

    /** @return string[] */
    private function titles(Portfolio $portfolio, Entry $entry, string $role): array
    {
        $handle = $portfolio->handleForRole($role);

        if ($handle === null) {
            return [];
        }

        $titles = [];

        foreach ($this->elements($entry, $handle) as $element) {
            $titles[] = (string)$element->title;
        }

        return $titles;
    }

    private function projectUrl(Portfolio $portfolio, Entry $entry): ?string
    {
        $handle = $portfolio->handleForRole($role = Role::PROJECT_URL);

        if ($handle === null) {
            return null;
        }

        $value = $entry->getFieldValue($handle);

        if ($value === null) {
            return null;
        }

        // A Link field stringifies to its URL; a plain text fallback already is one.
        $url = trim((string)$value);

        return $url !== '' ? $url : null;
    }

    /**
     * The elements a relation field holds, whether the value arrived as a query or as an
     * eager-loaded collection.
     *
     * Never `instanceof Traversable` — every Craft element is one, because `yii\base\Model`
     * implements `IteratorAggregate`, so a generic "iterate anything" helper silently walks an
     * element's *attribute values* instead of the element.
     */
    private function elements(Entry $entry, string $handle): array
    {
        $value = $entry->getFieldValue($handle);

        if ($value instanceof \craft\elements\ElementCollection) {
            return $value->all();
        }

        if ($value instanceof \craft\elements\db\ElementQueryInterface) {
            return $value->all();
        }

        return [];
    }
}
