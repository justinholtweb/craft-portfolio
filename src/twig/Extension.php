<?php

namespace justinholtweb\portfolio\twig;

use craft\elements\Entry;
use justinholtweb\portfolio\Plugin;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Filters and functions for reading a portfolio by role.
 *
 * `entry|portfolioField('client')` reads better in a card partial than the equivalent variable
 * call, and a card partial is where role lookups actually happen.
 */
class Extension extends AbstractExtension
{
    public function getName(): string
    {
        return 'Portfolio';
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('portfolioField', [$this, 'fieldValue']),
            new TwigFilter('portfolioHandle', [$this, 'fieldHandle']),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('portfolioField', [$this, 'fieldValue']),
        ];
    }

    /** `{{ entry|portfolioField('featuredImage') }}` */
    public function fieldValue(mixed $entry, string $role): mixed
    {
        if (!$entry instanceof Entry) {
            return null;
        }

        $portfolio = Plugin::getInstance()->portfolios->getPortfolioForEntry($entry);
        $handle = $portfolio?->handleForRole($role);

        return $handle === null ? null : $entry->getFieldValue($handle);
    }

    /** `{{ entry|portfolioHandle('client') }}` — the handle, for building a query. */
    public function fieldHandle(mixed $entry, string $role): ?string
    {
        if (!$entry instanceof Entry) {
            return null;
        }

        return Plugin::getInstance()->portfolios->getPortfolioForEntry($entry)?->handleForRole($role);
    }
}
