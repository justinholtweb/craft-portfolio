<?php

namespace justinholtweb\portfolio\twig;

use craft\elements\db\CategoryQuery;
use craft\elements\db\EntryQuery;
use craft\elements\db\TagQuery;
use craft\elements\Entry;
use justinholtweb\portfolio\models\Portfolio;
use justinholtweb\portfolio\models\Role;
use justinholtweb\portfolio\Plugin;
use Twig\Markup;
use yii\base\BaseObject;

/**
 * `craft.portfolio.*`
 *
 * A template never names a field. It names a *role*, or it asks for items and gets an ordinary
 * entry query back. Which is the difference between a portfolio that survives a field rename and
 * one that goes quietly blank.
 *
 * Method names here are deliberately never a `getX()`/`x()` pair: Twig resolves `foo.bar` to a
 * method called exactly `bar()` **before** it tries `getBar()`, and a collision between the two
 * re-enters until the C stack blows — a segfault, not a catchable error.
 */
class PortfolioVariable extends BaseObject
{
    /** Set when this variable was narrowed with `of()`. */
    public ?Portfolio $portfolio = null;

    /**
     * Narrows everything that follows to one portfolio: `craft.portfolio.of('caseStudies').items()`.
     *
     * Under Lite there is only ever one portfolio, so nothing has to name it.
     */
    public function of(Portfolio|string $portfolio): self
    {
        return new self([
            'portfolio' => Plugin::getInstance()->portfolios->resolve($portfolio),
        ]);
    }

    /** Every portfolio on the site. @return Portfolio[] */
    public function all(): array
    {
        return array_values(Plugin::getInstance()->portfolios->getAllPortfolios());
    }

    /** The portfolio in play — the one bound with `of()`, or the default. */
    public function current(Portfolio|string|null $portfolio = null): ?Portfolio
    {
        if ($portfolio !== null) {
            return Plugin::getInstance()->portfolios->resolve($portfolio);
        }

        return $this->portfolio ?? Plugin::getInstance()->portfolios->getDefaultPortfolio();
    }

    /** Whether this site has a portfolio at all — for a template that has to degrade. */
    public function exists(Portfolio|string|null $portfolio = null): bool
    {
        return $this->current($portfolio) !== null;
    }

    // ------------------------------------------------------------------ items

    /**
     * A query for portfolio items.
     *
     * ```twig
     * {% for item in craft.portfolio.items({ category: 'branding', limit: 12 }).all() %}
     * ```
     *
     * Anything the plugin does not recognise is passed to the entry query under its own name, so
     * `limit`, `search`, `with`, `orderBy` and the rest all work as they normally would.
     */
    public function items(array $criteria = []): EntryQuery
    {
        $portfolio = $this->current($criteria['portfolio'] ?? null);
        $plugin = Plugin::getInstance();

        if ($portfolio === null) {
            return Entry::find()->id(false);
        }

        return $plugin->query->items($portfolio, $criteria);
    }

    /** One item by slug. */
    public function item(string $slug, Portfolio|string|null $portfolio = null): ?Entry
    {
        $resolved = $this->current($portfolio);

        if ($resolved === null) {
            return null;
        }

        return Plugin::getInstance()->query->items($resolved, ['slug' => $slug])->one();
    }

    // ------------------------------------------------------------------ taxonomy

    public function categories(array $criteria = []): CategoryQuery
    {
        $portfolio = $this->current($criteria['portfolio'] ?? null);
        unset($criteria['portfolio']);

        if ($portfolio === null) {
            return \craft\elements\Category::find()->id(false);
        }

        return Plugin::getInstance()->query->categories($portfolio, $criteria);
    }

    public function tags(array $criteria = []): TagQuery
    {
        $portfolio = $this->current($criteria['portfolio'] ?? null);
        unset($criteria['portfolio']);

        if ($portfolio === null) {
            return \craft\elements\Tag::find()->id(false);
        }

        return Plugin::getInstance()->query->tags($portfolio, $criteria);
    }

    /**
     * Categories that actually have items, with counts — a filter bar in one call.
     *
     * ```twig
     * {% for filter in craft.portfolio.filters() %}
     *   <a href="...">{{ filter.category.title }} ({{ filter.count }})</a>
     * {% endfor %}
     * ```
     */
    public function filters(array $criteria = []): array
    {
        $portfolio = $this->current($criteria['portfolio'] ?? null);
        unset($criteria['portfolio']);

        if ($portfolio === null) {
            return [];
        }

        return Plugin::getInstance()->query->filters($portfolio, $criteria);
    }

    // ------------------------------------------------------------------ neighbours

    /** @return Entry[] */
    public function related(Entry $entry, int $limit = 3, Portfolio|string|null $portfolio = null): array
    {
        $resolved = $this->forEntry($entry, $portfolio);

        return $resolved === null ? [] : Plugin::getInstance()->query->related($resolved, $entry, $limit);
    }

    public function next(Entry $entry, Portfolio|string|null $portfolio = null): ?Entry
    {
        $resolved = $this->forEntry($entry, $portfolio);

        return $resolved === null ? null : Plugin::getInstance()->query->next($resolved, $entry);
    }

    public function prev(Entry $entry, Portfolio|string|null $portfolio = null): ?Entry
    {
        $resolved = $this->forEntry($entry, $portfolio);

        return $resolved === null ? null : Plugin::getInstance()->query->prev($resolved, $entry);
    }

    // ------------------------------------------------------------------ roles

    /**
     * The value of a role on an entry: `craft.portfolio.field(entry, 'featuredImage')`.
     *
     * Returns null rather than throwing when the portfolio has no field for that role, so a
     * template written against the full field set still renders against a trimmed-down portfolio.
     */
    public function field(Entry $entry, string $role, Portfolio|string|null $portfolio = null): mixed
    {
        $handle = $this->handleFor($role, $entry, $portfolio);

        return $handle === null ? null : $entry->getFieldValue($handle);
    }

    /** The current field handle for a role, for templates that want to build their own query. */
    public function handleFor(string $role, ?Entry $entry = null, Portfolio|string|null $portfolio = null): ?string
    {
        $resolved = $entry !== null
            ? $this->forEntry($entry, $portfolio)
            : $this->current($portfolio);

        return $resolved?->handleForRole($role);
    }

    /** Whether a portfolio has a field for a role at all. */
    public function has(string $role, Portfolio|string|null $portfolio = null): bool
    {
        return $this->current($portfolio)?->hasRole($role) === true;
    }

    /** Every role name, for a template that wants to iterate them. */
    public function roles(): array
    {
        return Role::all();
    }

    // ------------------------------------------------------------------ rendering

    /**
     * A ready-made, filterable grid of portfolio items. **Pro.**
     *
     * ```twig
     * {{ craft.portfolio.grid({ layout: 'masonry', columns: 4, lightbox: true }) }}
     * ```
     */
    public function grid(array $options = []): Markup
    {
        $portfolio = $this->current($options['portfolio'] ?? null);

        return Plugin::getInstance()->renderer->grid($portfolio, $options);
    }

    /**
     * schema.org CreativeWork structured data for one item. **Pro.**
     *
     * Emitted from the template rather than injected into the response, so it lands inside
     * whatever caching the site already has and nothing rewrites a prepared page body.
     */
    public function jsonld(?Entry $entry = null, Portfolio|string|null $portfolio = null): Markup
    {
        return Plugin::getInstance()->jsonld->render($entry, $this->current($portfolio));
    }

    // ------------------------------------------------------------------ internals

    /**
     * The portfolio an entry belongs to.
     *
     * Preferred over the default portfolio, because a template rendering a related-items strip on
     * a Pro site with three portfolios must not silently answer from the wrong one.
     */
    private function forEntry(Entry $entry, Portfolio|string|null $portfolio): ?Portfolio
    {
        if ($portfolio !== null) {
            return Plugin::getInstance()->portfolios->resolve($portfolio);
        }

        return Plugin::getInstance()->portfolios->getPortfolioForEntry($entry)
            ?? $this->current();
    }
}
