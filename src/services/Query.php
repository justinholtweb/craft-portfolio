<?php

namespace justinholtweb\portfolio\services;

use Craft;
use craft\base\Component;
use craft\db\Query as DbQuery;
use craft\db\Table;
use craft\elements\Category;
use craft\elements\db\CategoryQuery;
use craft\elements\db\ElementQueryInterface;
use craft\elements\db\EntryQuery;
use craft\elements\db\TagQuery;
use craft\elements\ElementCollection;
use craft\elements\Entry;
use craft\elements\Tag;
use craft\models\Section;
use justinholtweb\portfolio\models\Portfolio;
use justinholtweb\portfolio\models\Role;

/**
 * Every read the front end makes.
 *
 * Nothing here knows a field handle. Each query starts from the portfolio's role map and asks it
 * for the handle *now*, so templates written on day one keep working after the site renames a
 * field, and the same template works against a portfolio the plugin built and one it adopted.
 */
class Query extends Component
{
    /**
     * A query for a portfolio's items, with the plugin's own criteria applied and everything else
     * handed straight to the entry query.
     *
     * Returns a query rather than results on purpose: a template that wants `.all()`, `.one()`,
     * pagination or a further `.limit()` should have them, and `{% cache %}` should see an
     * ordinary entry query.
     */
    public function items(Portfolio $portfolio, array $criteria = []): EntryQuery
    {
        $section = $portfolio->getSection();

        $query = Entry::find();

        if ($section !== null) {
            $query->sectionId($section->id);
        } else {
            // The section is gone. An empty result is the only honest answer — never every entry
            // on the site.
            $query->id(false);
        }

        $entryType = $portfolio->getEntryType();

        if ($entryType !== null && $section !== null && count($section->getEntryTypes()) > 1) {
            $query->typeId($entryType->id);
        }

        $order = $this->defaultOrder($portfolio);

        if ($order !== null) {
            $query->orderBy($order);
        }

        $this->applyCriteria($portfolio, $query, $criteria);

        return $query;
    }

    /**
     * The order archives use unless the template says otherwise.
     *
     * A structure section is ordered by whatever the author dragged it into — that is the whole
     * reason they chose a structure. Otherwise the completed date is the meaningful one, falling
     * back to post date when the portfolio has no date field.
     */
    private function defaultOrder(Portfolio $portfolio): ?array
    {
        if ($portfolio->sectionType === Section::TYPE_STRUCTURE) {
            // Left alone deliberately. Craft already orders a single-structure entry query by
            // `structureelements.lft`, and setting an explicit `orderBy` here would *replace* that
            // — silently throwing away the order the author dragged the section into, which is the
            // whole reason they picked a structure.
            return null;
        }

        $dateHandle = $portfolio->handleForRole(Role::COMPLETED_DATE);

        if ($dateHandle !== null) {
            return [$dateHandle => SORT_DESC, 'postDate' => SORT_DESC];
        }

        return ['postDate' => SORT_DESC];
    }

    /**
     * Applies the plugin's criteria, then passes anything it does not recognise to the entry query
     * under its own name — so `{ limit: 10, search: 'rebrand', with: ['featuredImage'] }` all work
     * without the plugin having to enumerate Craft's query API.
     */
    private function applyCriteria(Portfolio $portfolio, EntryQuery $query, array $criteria): void
    {
        foreach ($criteria as $key => $value) {
            switch ($key) {
                case 'category':
                case 'categories':
                    $this->relateTo($portfolio, $query, Role::CATEGORIES, $value, Category::class);
                    break;

                case 'tag':
                case 'tags':
                    $this->relateTo($portfolio, $query, Role::TAGS, $value, Tag::class);
                    break;

                case 'client':
                    $handle = $portfolio->handleForRole(Role::CLIENT);

                    if ($handle !== null) {
                        $query->$handle($value);
                    }
                    break;

                case 'portfolio':
                    // Consumed by the caller when resolving which portfolio this is.
                    break;

                default:
                    if ($query->hasMethod($key)) {
                        $query->$key($value);
                    }
                    break;
            }
        }
    }

    /**
     * Narrows a query to items related through a role's field.
     *
     * `andRelatedTo` rather than `relatedTo`, so asking for a category *and* a tag means both, not
     * either — which is what a filter UI means when it has two things selected.
     */
    private function relateTo(Portfolio $portfolio, EntryQuery $query, string $role, mixed $value, string $elementType): void
    {
        $field = $portfolio->fieldForRole($role);

        if ($field === null || $value === null || $value === '' || $value === []) {
            return;
        }

        $targets = $this->resolveTargets($portfolio, $role, $value, $elementType);

        if ($targets === []) {
            // Asked to filter by something that does not exist. Empty, not unfiltered — an archive
            // for a deleted category showing every project is worse than showing none.
            $query->id(false);
            return;
        }

        $query->andRelatedTo([
            'targetElement' => $targets,
            'field' => $field->handle,
        ]);
    }

    /**
     * Turns whatever a template passed — a slug, an id, an element, or a list of any of those —
     * into elements.
     */
    private function resolveTargets(Portfolio $portfolio, string $role, mixed $value, string $elementType): array
    {
        $values = is_array($value) ? $value : [$value];
        $targets = [];
        $slugs = [];
        $ids = [];

        foreach ($values as $one) {
            if ($one instanceof Category || $one instanceof Tag) {
                $targets[] = $one;
            } elseif (is_numeric($one)) {
                $ids[] = (int)$one;
            } elseif (is_string($one) && $one !== '') {
                $slugs[] = $one;
            }
        }

        if ($slugs !== [] || $ids !== []) {
            $lookup = $role === Role::CATEGORIES
                ? $this->categories($portfolio)
                : $this->tags($portfolio);

            if ($slugs !== []) {
                foreach ((clone $lookup)->slug($slugs)->all() as $element) {
                    $targets[] = $element;
                }
            }

            if ($ids !== []) {
                foreach ((clone $lookup)->id($ids)->all() as $element) {
                    $targets[] = $element;
                }
            }
        }

        return $targets;
    }

    // ------------------------------------------------------------------ taxonomy

    /**
     * The portfolio's categories.
     *
     * `status('enabled')`, never `'live'` — that status is entry-only, and an element query asked
     * for a status its type does not have returns nothing at all rather than erroring, which
     * presents as an empty filter bar with no explanation.
     */
    public function categories(Portfolio $portfolio, array $criteria = []): CategoryQuery
    {
        $group = $portfolio->getCategoryGroup();

        $query = Category::find()->status('enabled');

        if ($group !== null) {
            $query->groupId($group->id);
        } else {
            $query->id(false);
        }

        foreach ($criteria as $key => $value) {
            if ($query->hasMethod($key)) {
                $query->$key($value);
            }
        }

        return $query;
    }

    public function tags(Portfolio $portfolio, array $criteria = []): TagQuery
    {
        $group = $portfolio->getTagGroup();

        $query = Tag::find()->status('enabled');

        if ($group !== null) {
            $query->groupId($group->id);
        } else {
            $query->id(false);
        }

        foreach ($criteria as $key => $value) {
            if ($query->hasMethod($key)) {
                $query->$key($value);
            }
        }

        return $query;
    }

    /**
     * Categories that actually have items, with counts — everything a filter bar needs.
     *
     * One relations query rather than one count query per category: a filter bar is rendered on
     * every archive page, and N+1 there is the difference between a page and a slow page.
     *
     * @return array<int, array{category: Category, count: int}>
     */
    public function filters(Portfolio $portfolio, array $criteria = []): array
    {
        $field = $portfolio->fieldForRole(Role::CATEGORIES);
        $categories = $this->categories($portfolio)->all();

        if ($field === null || $categories === []) {
            return [];
        }

        $entryIds = $this->items($portfolio, $criteria)->limit(null)->ids();

        if ($entryIds === []) {
            return [];
        }

        $counts = (new DbQuery())
            ->select(['targetId', 'total' => 'COUNT(DISTINCT [[sourceId]])'])
            ->from(Table::RELATIONS)
            ->where(['fieldId' => $field->id, 'sourceId' => $entryIds])
            ->groupBy(['targetId'])
            ->pairs();

        $filters = [];

        foreach ($categories as $category) {
            $count = (int)($counts[$category->id] ?? 0);

            if ($count > 0) {
                $filters[] = ['category' => $category, 'count' => $count];
            }
        }

        return $filters;
    }

    // ------------------------------------------------------------------ neighbours and relatives

    public function next(Portfolio $portfolio, Entry $entry): ?Entry
    {
        $next = $entry->getNext($this->items($portfolio));

        return $next instanceof Entry ? $next : null;
    }

    public function prev(Portfolio $portfolio, Entry $entry): ?Entry
    {
        $prev = $entry->getPrev($this->items($portfolio));

        return $prev instanceof Entry ? $prev : null;
    }

    /**
     * Other items sharing categories or tags with this one, most-shared first.
     *
     * The ranking is done in PHP rather than SQL because "related" here means *how many terms
     * overlap*, and a `relatedTo` query can only answer whether any do. At portfolio scale — tens
     * or hundreds of projects, not millions — one query plus a sort is the right trade.
     *
     * @return Entry[]
     */
    public function related(Portfolio $portfolio, Entry $entry, int $limit = 3): array
    {
        $termIds = [];
        $fieldIds = [];

        foreach ([Role::CATEGORIES, Role::TAGS] as $role) {
            $field = $portfolio->fieldForRole($role);

            if ($field === null) {
                continue;
            }

            $fieldIds[] = $field->id;

            foreach ($this->relatedIds($entry, $field->handle) as $id) {
                $termIds[] = $id;
            }
        }

        if ($termIds === [] || $fieldIds === []) {
            return [];
        }

        $rows = (new DbQuery())
            ->select(['sourceId', 'shared' => 'COUNT(DISTINCT [[targetId]])'])
            ->from(Table::RELATIONS)
            ->where(['fieldId' => $fieldIds, 'targetId' => $termIds])
            ->andWhere(['not', ['sourceId' => $entry->id]])
            ->groupBy(['sourceId'])
            ->orderBy(['shared' => SORT_DESC])
            ->limit($limit * 4)
            ->pairs();

        if ($rows === []) {
            return [];
        }

        $candidates = $this->items($portfolio, ['id' => array_keys($rows), 'limit' => null])->all();

        // The relations table knows nothing about sections, statuses or sites, so the ranking is
        // applied to what the portfolio query allowed through, not the other way round.
        usort($candidates, function(Entry $a, Entry $b) use ($rows) {
            return [$rows[$b->id] ?? 0, $b->id] <=> [$rows[$a->id] ?? 0, $a->id];
        });

        return array_slice($candidates, 0, $limit);
    }

    /**
     * The IDs a relation field holds on an element.
     *
     * A relation field's value is an element *query* normally and an `ElementCollection` once it
     * has been eager-loaded, and the two disagree about what `ids()` returns — an array from one,
     * a collection from the other. Templates eager-load as they please, so both have to work.
     *
     * @return int[]
     */
    private function relatedIds(Entry $entry, string $handle): array
    {
        $value = $entry->getFieldValue($handle);

        if ($value instanceof ElementCollection) {
            return array_map('intval', $value->ids()->all());
        }

        if ($value instanceof ElementQueryInterface) {
            return array_map('intval', $value->ids());
        }

        return [];
    }
}
