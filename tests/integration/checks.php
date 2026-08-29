<?php
/**
 * Portfolio integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-portfolio/tests/integration/checks.php
 *
 * Idempotent and self-cleaning: every section, entry type, field and group it creates it deletes
 * again, whether the run passes or not, and it sweeps up strays from a run that died half way.
 * Everything it touches is handled `portfolioTest*`, which is deliberate — this harness has forty
 * plugins in it and a stray fixture needs to be obvious at a glance.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\Tag;
use craft\fields\Assets;
use craft\fields\PlainText;
use craft\models\Section;
use justinholtweb\portfolio\models\Blueprint;
use justinholtweb\portfolio\models\PlanItem;
use justinholtweb\portfolio\models\Portfolio;
use justinholtweb\portfolio\models\Role;
use justinholtweb\portfolio\Plugin;
use justinholtweb\portfolio\services\StarterTemplates;
use justinholtweb\portfolio\twig\PortfolioVariable;

const HANDLE = 'portfolioTest';

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";
            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n      " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function heading(string $text): void
{
    echo "\n$text\n";
}

function plugin(): Plugin
{
    return Plugin::getInstance();
}

/** Removes anything a previous run left behind. Safe to call when there is nothing to remove. */
function sweep(): void
{
    $plugin = plugin();

    foreach ($plugin->portfolios->getAllPortfolios() as $portfolio) {
        if (str_starts_with($portfolio->handle, HANDLE)) {
            $plugin->builder->teardown($portfolio);
        }
    }

    $entries = Craft::$app->getEntries();

    foreach ($entries->getAllSections() as $section) {
        if (str_starts_with($section->handle, HANDLE)) {
            $entries->deleteSection($section);
        }
    }

    foreach ($entries->getAllEntryTypes() as $entryType) {
        if (str_starts_with($entryType->handle, HANDLE)) {
            $entries->deleteEntryType($entryType);
        }
    }

    foreach (Craft::$app->getFields()->getAllFields() as $field) {
        if (str_starts_with((string)$field->handle, HANDLE)) {
            Craft::$app->getFields()->deleteField($field);
        }
    }

    foreach (Craft::$app->getCategories()->getAllGroups() as $group) {
        if (str_starts_with($group->handle, HANDLE)) {
            Craft::$app->getCategories()->deleteGroup($group);
        }
    }

    foreach (Craft::$app->getTags()->getAllTagGroups() as $group) {
        if (str_starts_with($group->handle, HANDLE)) {
            Craft::$app->getTags()->deleteTagGroup($group);
        }
    }

    $plugin->portfolios->clearCaches();
}

function blueprint(array $overrides = []): Blueprint
{
    return new Blueprint(array_merge([
        'name' => 'Portfolio Test',
        'handle' => HANDLE,
        'sectionType' => Section::TYPE_STRUCTURE,
    ], $overrides));
}

function setEdition(string $edition): void
{
    Craft::$app->getPlugins()->switchEdition('portfolio', $edition);
}

$originalEdition = plugin()->edition;
$startedAt = microtime(true);

echo "\nPortfolio — integration checks\n";
echo "Craft " . Craft::$app->getVersion() . ", Portfolio " . plugin()->getVersion() . " ($originalEdition)\n";

sweep();
setEdition('pro');

try {
    // ------------------------------------------------------------------ source hygiene

    heading('Source');

    check('no unbraced interpolation runs into a curly quote', function() {
        // `"the “$field” field"` interpolates a variable called `$field”`, because PHP allows
        // bytes ≥ 0x80 in identifiers and `”` is three of them. You get an undefined-variable
        // warning and an empty string, and nothing but a reader ever notices. This family has
        // shipped that bug more than once, so it is a check rather than a note.
        $offenders = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src'));

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            foreach (file($file->getPathname()) as $number => $line) {
                if (preg_match('/\$[A-Za-z_][A-Za-z0-9_]*[\x{201C}\x{201D}]/u', $line)) {
                    $offenders[] = $file->getFilename() . ':' . ($number + 1);
                }
            }
        }

        return $offenders === [] ? true : implode(', ', $offenders);
    });

    // ------------------------------------------------------------------ planning

    heading('Planning');

    $plan = plugin()->builder->plan(blueprint());

    check('a clean slate plans every item as create', function() use ($plan) {
        $notCreate = array_filter($plan->items, fn(PlanItem $i) => !$i->isCreate());

        return $notCreate === []
            ? true
            : 'not create: ' . implode(', ', array_map(fn(PlanItem $i) => $i->handle, $notCreate));
    });

    check('the plan covers section, entry type, both groups and nine fields', function() use ($plan) {
        $counts = [
            PlanItem::KIND_SECTION => count($plan->ofKind(PlanItem::KIND_SECTION)),
            PlanItem::KIND_ENTRY_TYPE => count($plan->ofKind(PlanItem::KIND_ENTRY_TYPE)),
            PlanItem::KIND_CATEGORY_GROUP => count($plan->ofKind(PlanItem::KIND_CATEGORY_GROUP)),
            PlanItem::KIND_TAG_GROUP => count($plan->ofKind(PlanItem::KIND_TAG_GROUP)),
            PlanItem::KIND_FIELD => count($plan->ofKind(PlanItem::KIND_FIELD)),
        ];

        $expected = [
            PlanItem::KIND_SECTION => 1,
            PlanItem::KIND_ENTRY_TYPE => 1,
            PlanItem::KIND_CATEGORY_GROUP => 1,
            PlanItem::KIND_TAG_GROUP => 1,
            PlanItem::KIND_FIELD => 9,
        ];

        return $counts === $expected ? true : json_encode($counts);
    });

    check('planning writes nothing', function() {
        return Craft::$app->getEntries()->getSectionByHandle(HANDLE) === null
            ? true
            : 'the section exists after planning alone';
    });

    check('a blueprint without taxonomy plans no groups and seven fields', function() {
        $trimmed = plugin()->builder->plan(blueprint([
            'roles' => array_values(array_diff(Role::all(), Role::TAXONOMY)),
        ]));

        return $trimmed->ofKind(PlanItem::KIND_CATEGORY_GROUP) === []
            && $trimmed->ofKind(PlanItem::KIND_TAG_GROUP) === []
            && count($trimmed->ofKind(PlanItem::KIND_FIELD)) === 7
            ? true
            : 'unexpected shape: ' . $trimmed->summary();
    });

    // ------------------------------------------------------------------ conflicts

    heading('Conflicts');

    check('a field of the wrong type with a blueprint handle is a conflict, not a rewrite', function() {
        $decoy = new PlainText();
        $decoy->name = 'Decoy';
        $decoy->handle = HANDLE . 'FeaturedImage';

        if (!Craft::$app->getFields()->saveField($decoy)) {
            return 'could not create the decoy field';
        }

        try {
            $conflicted = plugin()->builder->plan(blueprint());
            $item = $conflicted->itemForRole(Role::FEATURED_IMAGE);

            if ($item === null || !$item->isConflict()) {
                return 'the decoy was not reported as a conflict';
            }

            if (!$conflicted->isBlocked()) {
                return 'a conflicting plan did not report itself blocked';
            }

            $result = plugin()->builder->build(blueprint());

            if ($result->success) {
                return 'the build went ahead despite the conflict';
            }

            $stillPlainText = Craft::$app->getFields()->getFieldByHandle(HANDLE . 'FeaturedImage');

            return $stillPlainText instanceof PlainText
                ? true
                : 'the decoy field was replaced rather than left alone';
        } finally {
            $field = Craft::$app->getFields()->getFieldByHandle(HANDLE . 'FeaturedImage');

            if ($field !== null) {
                Craft::$app->getFields()->deleteField($field);
            }
        }
    });

    check('a field of the right type with a blueprint handle is reused untouched', function() {
        $existing = new PlainText();
        $existing->name = 'Pre-existing summary';
        $existing->handle = HANDLE . 'Summary';
        $existing->instructions = 'Written by the site, not the plugin.';

        if (!Craft::$app->getFields()->saveField($existing)) {
            return 'could not create the pre-existing field';
        }

        $replanned = plugin()->builder->plan(blueprint());
        $item = $replanned->itemForRole(Role::SUMMARY);

        return $item !== null && $item->isReuse() && $item->existingUid === $existing->uid
            ? true
            : 'the pre-existing field was not reported as reuse';
    });

    // ------------------------------------------------------------------ building

    heading('Building');

    $result = plugin()->builder->build(blueprint());

    check('the build succeeds', fn() => $result->success ? true : implode('; ', $result->problems));

    check('it reuses the field that already existed rather than creating it', function() use ($result) {
        foreach ($result->reused as $reused) {
            if (str_contains($reused, HANDLE . 'Summary')) {
                return true;
            }
        }

        return 'the pre-existing summary field was not reported as reused: ' . implode('; ', $result->reused);
    });

    check('it keeps the instructions the site had written on that field', function() {
        $field = Craft::$app->getFields()->getFieldByHandle(HANDLE . 'Summary');

        return $field?->instructions === 'Written by the site, not the plugin.'
            ? true
            : 'the field settings were overwritten: ' . var_export($field?->instructions, true);
    });

    $portfolio = plugin()->portfolios->getPortfolioByHandle(HANDLE);

    check('the portfolio is registered', fn() => $portfolio instanceof Portfolio ? true : 'not registered');

    check('the section exists, is a structure and has the right URI', function() use ($portfolio) {
        $section = $portfolio->getSection();

        if ($section === null) {
            return 'no section';
        }

        $siteSettings = array_values($section->getSiteSettings())[0] ?? null;

        return $section->type === Section::TYPE_STRUCTURE
            && $siteSettings?->uriFormat === 'portfolio-test/{slug}'
            && $siteSettings?->template === 'portfolio-test/_entry'
            ? true
            : sprintf('type=%s uri=%s template=%s', $section->type, $siteSettings?->uriFormat, $siteSettings?->template);
    });

    check('the category group has archive URLs', function() use ($portfolio) {
        $group = $portfolio->getCategoryGroup();
        $siteSettings = $group !== null ? (array_values($group->getSiteSettings())[0] ?? null) : null;

        return $siteSettings?->hasUrls === true && $siteSettings?->uriFormat === 'portfolio-test/category/{slug}'
            ? true
            : 'category group site settings: ' . var_export($siteSettings?->uriFormat, true);
    });

    check('every role resolves to a live field', function() use ($portfolio) {
        $missing = [];

        foreach (Role::all() as $role) {
            if ($portfolio->fieldForRole($role) === null) {
                $missing[] = $role;
            }
        }

        return $missing === [] ? true : 'unresolved: ' . implode(', ', $missing);
    });

    check('the entry type field layout holds all nine fields across two tabs', function() use ($portfolio) {
        $layout = $portfolio->getEntryType()?->getFieldLayout();

        if ($layout === null) {
            return 'no field layout';
        }

        $tabs = $layout->getTabs();
        $fields = $layout->getCustomFields();

        return count($tabs) === 2 && count($fields) === 9
            ? true
            : sprintf('%d tabs, %d fields', count($tabs), count($fields));
    });

    check('the assets fields are restricted to images', function() use ($portfolio) {
        $featured = $portfolio->fieldForRole(Role::FEATURED_IMAGE);

        return $featured instanceof Assets
            && $featured->restrictFiles === true
            && $featured->allowedKinds === ['image']
            && $featured->maxRelations === 1
            ? true
            : 'featured image field settings are not as specified';
    });

    check('the categories field points at the group that was just created', function() use ($portfolio) {
        $field = $portfolio->fieldForRole(Role::CATEGORIES);

        return $field?->source === 'group:' . $portfolio->categoryGroupUid
            ? true
            : 'source is ' . var_export($field?->source ?? null, true);
    });

    // ------------------------------------------------------------------ idempotency

    heading('Idempotency');

    check('re-planning a built portfolio reports reuse for everything', function() {
        $replan = plugin()->builder->plan(blueprint());

        return $replan->isSatisfied() && $replan->creations() === []
            ? true
            : 'still wants to create: ' . implode(', ', array_map(fn(PlanItem $i) => $i->handle, $replan->creations()));
    });

    check('building twice creates nothing the second time', function() {
        $second = plugin()->builder->build(blueprint());

        return $second->success && $second->created === []
            ? true
            : 'created again: ' . implode('; ', $second->created);
    });

    check('there is still only one portfolio with this handle', function() {
        $matching = array_filter(
            plugin()->portfolios->getAllPortfolios(),
            fn(Portfolio $p) => $p->handle === HANDLE,
        );

        return count($matching) === 1 ? true : count($matching) . ' portfolios share the handle';
    });

    // ------------------------------------------------------------------ content

    heading('Content');

    $portfolio = plugin()->portfolios->getPortfolioByHandle(HANDLE);
    $section = $portfolio->getSection();
    $entryType = $portfolio->getEntryType();
    $elements = Craft::$app->getElements();

    $categories = [];

    foreach (['Alpha', 'Beta'] as $title) {
        $category = new Category();
        $category->groupId = $portfolio->getCategoryGroup()->id;
        $category->title = $title;
        $elements->saveElement($category);
        $categories[$title] = $category;
    }

    $tag = new Tag();
    $tag->groupId = $portfolio->getTagGroup()->id;
    $tag->title = 'shared';
    $elements->saveElement($tag);

    $entries = [];

    $fixtures = [
        ['One', 'one', 'Acme', ['Alpha'], true],
        ['Two', 'two', 'Acme', ['Alpha', 'Beta'], true],
        ['Three', 'three', 'Globex', ['Beta'], false],
    ];

    foreach ($fixtures as [$title, $slug, $client, $categoryNames, $tagged]) {
        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->typeId = $entryType->id;
        $entry->title = $title;
        $entry->slug = $slug;
        $entry->setFieldValues([
            $portfolio->handleForRole(Role::SUMMARY) => "Summary for $title",
            $portfolio->handleForRole(Role::CLIENT) => $client,
            $portfolio->handleForRole(Role::COMPLETED_DATE) => new DateTime('2026-01-0' . (count($entries) + 1), new DateTimeZone('UTC')),
            $portfolio->handleForRole(Role::CATEGORIES) => array_map(fn($n) => $categories[$n]->id, $categoryNames),
            $portfolio->handleForRole(Role::TAGS) => $tagged ? [$tag->id] : [],
        ]);

        if (!$elements->saveElement($entry)) {
            throw new RuntimeException("Could not save $title: " . json_encode($entry->getErrors()));
        }

        $entries[$slug] = $entry;
    }

    check('every field round-trips through a save', function() use ($portfolio, $entries) {
        $entry = Entry::find()->id($entries['two']->id)->one();

        $summary = $entry->getFieldValue($portfolio->handleForRole(Role::SUMMARY));
        $client = $entry->getFieldValue($portfolio->handleForRole(Role::CLIENT));
        $date = $entry->getFieldValue($portfolio->handleForRole(Role::COMPLETED_DATE));
        $cats = $entry->getFieldValue($portfolio->handleForRole(Role::CATEGORIES))->all();

        return $summary === 'Summary for Two'
            && $client === 'Acme'
            && $date instanceof DateTimeInterface
            && count($cats) === 2
            ? true
            : sprintf('summary=%s client=%s date=%s cats=%d', var_export($summary, true), var_export($client, true), get_debug_type($date), count($cats));
    });

    // ------------------------------------------------------------------ the query API

    heading('Query API');

    $query = plugin()->query;

    check('items() is scoped to the portfolio section', function() use ($query, $portfolio, $section) {
        $ids = $query->items($portfolio)->ids();
        $sectionIds = Entry::find()->sectionId($section->id)->ids();

        sort($ids);
        sort($sectionIds);

        return $ids === $sectionIds && count($ids) === 3 ? true : count($ids) . ' items';
    });

    check('items() filters by category slug', function() use ($query, $portfolio) {
        $titles = array_map(fn(Entry $e) => $e->title, $query->items($portfolio, ['category' => 'alpha'])->all());
        sort($titles);

        return $titles === ['One', 'Two'] ? true : implode(', ', $titles);
    });

    check('two category filters mean both, not either', function() use ($query, $portfolio) {
        $titles = array_map(
            fn(Entry $e) => $e->title,
            $query->items($portfolio, ['category' => 'alpha', 'categories' => 'beta'])->all(),
        );

        return $titles === ['Two'] ? true : implode(', ', $titles);
    });

    check('items() filters by tag', function() use ($query, $portfolio) {
        $titles = array_map(fn(Entry $e) => $e->title, $query->items($portfolio, ['tag' => 'shared'])->all());
        sort($titles);

        return $titles === ['One', 'Two'] ? true : implode(', ', $titles);
    });

    check('items() filters by client through the role map', function() use ($query, $portfolio) {
        return count($query->items($portfolio, ['client' => 'Globex'])->all()) === 1
            ? true
            : 'client filter did not narrow to one';
    });

    check('filtering by a category that does not exist returns nothing, not everything', function() use ($query, $portfolio) {
        return (int)$query->items($portfolio, ['category' => 'no-such-category'])->count() === 0
            ? true
            : 'a missing category returned results';
    });

    check('unrecognised criteria are handed to the entry query', function() use ($query, $portfolio) {
        return count($query->items($portfolio, ['limit' => 2])->all()) === 2
            ? true
            : 'limit was not applied';
    });

    check('filters() counts only categories that have items', function() use ($query, $portfolio) {
        $filters = $query->filters($portfolio);
        $counts = [];

        foreach ($filters as $filter) {
            $counts[$filter['category']->title] = $filter['count'];
        }

        return $counts === ['Alpha' => 2, 'Beta' => 2] ? true : json_encode($counts);
    });

    check('related() ranks by how many terms overlap', function() use ($query, $portfolio, $entries) {
        $related = $query->related($portfolio, Entry::find()->id($entries['two']->id)->one(), 2);
        $titles = array_map(fn(Entry $e) => $e->title, $related);

        // “One” shares a category and a tag with Two; “Three” shares only a category.
        return $titles === ['One', 'Three'] ? true : implode(', ', $titles);
    });

    check('related() never includes the entry itself', function() use ($query, $portfolio, $entries) {
        $related = $query->related($portfolio, Entry::find()->id($entries['two']->id)->one(), 5);

        foreach ($related as $entry) {
            if ($entry->id === $entries['two']->id) {
                return 'the entry came back as its own relative';
            }
        }

        return true;
    });

    check('next() and prev() walk the structure in its own order', function() use ($query, $portfolio) {
        // Order-agnostic on purpose: the section's default placement decides which fixture comes
        // first, and a test that hardcodes it breaks the day that setting changes.
        $ordered = $query->items($portfolio)->all();

        if (count($ordered) !== 3) {
            return 'expected three items, got ' . count($ordered);
        }

        [$first, $middle, $last] = $ordered;

        $next = $query->next($portfolio, $middle);
        $prev = $query->prev($portfolio, $middle);

        return $next?->id === $last->id
            && $prev?->id === $first->id
            && $query->prev($portfolio, $first) === null
            && $query->next($portfolio, $last) === null
            ? true
            : sprintf(
                'middle=%s next=%s prev=%s (order: %s)',
                $middle->title,
                $next?->title ?? 'null',
                $prev?->title ?? 'null',
                implode(', ', array_map(fn(Entry $e) => $e->title, $ordered)),
            );
    });

    check('categories() and tags() are scoped to the portfolio groups', function() use ($query, $portfolio) {
        return count($query->categories($portfolio)->all()) === 2
            && count($query->tags($portfolio)->all()) === 1
            ? true
            : 'taxonomy queries are not scoped';
    });

    // ------------------------------------------------------------------ renames

    heading('Renaming');

    check('renaming a field leaves the role map working', function() use ($portfolio) {
        $field = $portfolio->fieldForRole(Role::CLIENT);
        $original = $field->handle;
        $field->handle = HANDLE . 'ClientRenamed';

        if (!Craft::$app->getFields()->saveField($field)) {
            return 'could not rename the field';
        }

        plugin()->portfolios->clearCaches();
        $reloaded = plugin()->portfolios->getPortfolioByHandle(HANDLE);
        $now = $reloaded->handleForRole(Role::CLIENT);

        // Put it back before judging, so a failure here does not poison later checks.
        $field->handle = $original;
        Craft::$app->getFields()->saveField($field);
        plugin()->portfolios->clearCaches();

        return $now === HANDLE . 'ClientRenamed'
            ? true
            : 'the role map returned ' . var_export($now, true) . ' after the rename';
    });

    // ------------------------------------------------------------------ templates

    heading('Starter templates');

    $templateRoot = plugin()->starter->templatesPath() . '/' . $portfolio->templateRoot;

    check('templates are written', function() use ($portfolio, $templateRoot) {
        $results = plugin()->starter->write($portfolio, false);
        $written = array_filter($results, fn(array $r) => $r['status'] === StarterTemplates::WRITTEN);

        $expected = plugin()->starter->fileCount($portfolio);

        return count($written) === $expected && file_exists($templateRoot . '/index.twig')
            ? true
            : count($written) . " written, expected $expected";
    });

    check('the portfolio handle is substituted into them', function() use ($templateRoot) {
        $contents = file_get_contents($templateRoot . '/index.twig');

        return str_contains($contents, "craft.portfolio.of('" . HANDLE . "')") && !str_contains($contents, '%%')
            ? true
            : 'tokens were not substituted';
    });

    check('a second write leaves existing files alone', function() use ($portfolio) {
        $results = plugin()->starter->write($portfolio, false);
        $skipped = array_filter($results, fn(array $r) => $r['status'] === StarterTemplates::SKIPPED);

        return count($skipped) === plugin()->starter->fileCount($portfolio) ? true : count($skipped) . ' skipped';
    });

    check('force overwrites them', function() use ($portfolio, $templateRoot) {
        file_put_contents($templateRoot . '/index.twig', 'edited by hand');
        plugin()->starter->write($portfolio, true);

        return file_get_contents($templateRoot . '/index.twig') !== 'edited by hand'
            ? true
            : 'force did not overwrite';
    });

    // ------------------------------------------------------------------ Pro surfaces

    heading('Pro surfaces');

    check('the grid renders markup under Pro', function() use ($portfolio) {
        $html = (string)plugin()->renderer->grid($portfolio, ['lightbox' => false]);

        return str_contains($html, 'data-pf-grid="1"')
            && substr_count($html, 'data-pf-item="1"') === 3
            ? true
            : 'grid markup is not as expected';
    });

    check('the grid carries category slugs for client-side filtering', function() use ($portfolio) {
        $renderer = new \justinholtweb\portfolio\services\Renderer();
        $html = (string)$renderer->grid($portfolio, []);

        return str_contains($html, 'data-pf-categories="alpha"')
            && str_contains($html, 'data-pf-categories="alpha beta"')
            ? true
            : 'category slugs are missing from the items';
    });

    check('the grid inlines its CSS and JS once, not once per grid', function() use ($portfolio) {
        $renderer = new \justinholtweb\portfolio\services\Renderer();
        $html = (string)$renderer->grid($portfolio, []) . (string)$renderer->grid($portfolio, []);

        return substr_count($html, '.pf-grid__filters') === 1
            && substr_count($html, 'Portfolio grid runtime') === 1
            ? true
            : 'assets were emitted more than once';
    });

    check('JSON-LD is emitted for an item when the setting is on', function() use ($portfolio, $entries) {
        $settings = plugin()->getSettings();
        $was = $settings->jsonLd;
        $settings->jsonLd = true;

        try {
            $data = plugin()->jsonld->data(Entry::find()->id($entries['two']->id)->one(), $portfolio);

            return is_array($data)
                && $data['@type'] === 'CreativeWork'
                && $data['name'] === 'Two'
                && ($data['sourceOrganization']['name'] ?? null) === 'Acme'
                && $data['genre'] === ['Alpha', 'Beta']
                ? true
                : 'unexpected structured data: ' . json_encode($data);
        } finally {
            $settings->jsonLd = $was;
        }
    });

    check('JSON-LD stays quiet when the setting is off', function() use ($portfolio, $entries) {
        $settings = plugin()->getSettings();
        $was = $settings->jsonLd;
        $settings->jsonLd = false;

        try {
            return plugin()->jsonld->data(Entry::find()->id($entries['two']->id)->one(), $portfolio) === null
                ? true
                : 'structured data was emitted with the setting off';
        } finally {
            $settings->jsonLd = $was;
        }
    });

    // ------------------------------------------------------------------ the Lite boundary

    heading('The Lite boundary');

    setEdition('lite');

    check('the grid degrades to a notice rather than markup', function() use ($portfolio) {
        $html = (string)(new \justinholtweb\portfolio\services\Renderer())->grid($portfolio, []);

        return !str_contains($html, 'data-pf-grid')
            && str_contains($html, 'Portfolio Pro')
            ? true
            : 'the grid rendered under Lite';
    });

    check('JSON-LD is off under Lite whatever the setting says', function() use ($portfolio, $entries) {
        $settings = plugin()->getSettings();
        $was = $settings->jsonLd;
        $settings->jsonLd = true;

        try {
            return plugin()->jsonld->data(Entry::find()->id($entries['two']->id)->one(), $portfolio) === null
                ? true
                : 'structured data was emitted under Lite';
        } finally {
            $settings->jsonLd = $was;
        }
    });

    check('the query API is unaffected by the edition', function() use ($query, $portfolio) {
        return count($query->items($portfolio)->all()) === 3
            && count($query->filters($portfolio)) === 2
            ? true
            : 'the query API changed under Lite';
    });

    check('a second portfolio is refused under Lite', function() {
        $second = plugin()->builder->build(blueprint(['handle' => HANDLE . 'Two', 'name' => 'Second']));

        $refused = !$second->success
            && $second->problems !== []
            && str_contains($second->problems[0], 'one portfolio');

        if (!$refused) {
            // Clean up whatever it managed to make before failing the check.
            $stray = plugin()->portfolios->getPortfolioByHandle(HANDLE . 'Two');

            if ($stray !== null) {
                plugin()->builder->teardown($stray);
            }

            return 'Lite allowed a second portfolio';
        }

        return Craft::$app->getEntries()->getSectionByHandle(HANDLE . 'Two') === null
            ? true
            : 'Lite refused the portfolio but left a section behind';
    });

    check('adoption is refused under Lite and allowed under Pro', function() {
        $lite = \justinholtweb\portfolio\models\Edition::allowsAdoption(false);
        $pro = \justinholtweb\portfolio\models\Edition::allowsAdoption(true);

        return $lite === false && $pro === true ? true : 'the adoption gate is wrong';
    });

    setEdition('pro');

    // ------------------------------------------------------------------ the Twig surface

    heading('Twig surface');

    check('craft.portfolio.of() narrows to one portfolio', function() {
        $variable = (new PortfolioVariable())->of(HANDLE);

        return $variable->current()?->handle === HANDLE ? true : 'of() did not narrow';
    });

    check('the variable degrades to an empty query when there is no portfolio', function() {
        $variable = new PortfolioVariable();
        $variable->portfolio = new Portfolio(['sectionUid' => 'nope', 'entryTypeUid' => 'nope']);

        return (int)$variable->items()->count() === 0 ? true : 'a broken portfolio returned entries';
    });

    check('portfolioField reads a value through the role map', function() use ($entries) {
        $extension = new \justinholtweb\portfolio\twig\Extension();
        $entry = Entry::find()->id($entries['two']->id)->one();

        return $extension->fieldValue($entry, Role::CLIENT) === 'Acme'
            ? true
            : 'the filter did not read the client role';
    });

    check('portfolioField returns null for a role the portfolio does not have', function() use ($entries) {
        $extension = new \justinholtweb\portfolio\twig\Extension();
        $entry = Entry::find()->id($entries['two']->id)->one();

        return $extension->fieldValue($entry, 'notARole') === null ? true : 'an unknown role returned a value';
    });

    check('the portfolio for an entry is found from its section', function() use ($entries) {
        $entry = Entry::find()->id($entries['one']->id)->one();

        return plugin()->portfolios->getPortfolioForEntry($entry)?->handle === HANDLE
            ? true
            : 'the entry did not resolve to its portfolio';
    });

    // ------------------------------------------------------------------ drift and removal

    heading('Drift and removal');

    check('deleting a field is reported as drift', function() use ($portfolio) {
        $field = $portfolio->fieldForRole(Role::GALLERY);
        Craft::$app->getFields()->deleteField($field);

        plugin()->portfolios->clearCaches();
        $reloaded = plugin()->portfolios->getPortfolioByHandle(HANDLE);
        $drift = plugin()->builder->driftFor($reloaded);

        return count($drift) === 1 && str_contains($drift[0], 'Gallery')
            ? true
            : 'drift: ' . json_encode($drift);
    });

    check('a portfolio with a deleted field still answers for every other role', function() {
        $reloaded = plugin()->portfolios->getPortfolioByHandle(HANDLE);

        return count($reloaded->liveRoles()) === 8
            && $reloaded->handleForRole(Role::GALLERY) === null
            && $reloaded->handleForRole(Role::SUMMARY) !== null
            ? true
            : 'live roles: ' . implode(', ', $reloaded->liveRoles());
    });

    check('teardown reports what it is about to destroy', function() {
        $reloaded = plugin()->portfolios->getPortfolioByHandle(HANDLE);
        $impact = plugin()->builder->teardownImpact($reloaded);

        return $impact['entries'] === 3 && $impact['categories'] === 2 && $impact['tags'] === 1
            ? true
            : json_encode($impact);
    });

    check('forgetting a portfolio leaves the section and its entries standing', function() use ($section) {
        $reloaded = plugin()->portfolios->getPortfolioByHandle(HANDLE);
        plugin()->portfolios->deletePortfolio($reloaded);

        $gone = plugin()->portfolios->getPortfolioByHandle(HANDLE) === null;
        $sectionSurvives = Craft::$app->getEntries()->getSectionByHandle(HANDLE) !== null;
        // `(int)`, because an element query's `count()` comes back as a *string* from the driver
        // whenever it actually reaches the database — it only returns a real int on the
        // short-circuit path where the query is known to be empty.
        $entriesSurvive = (int)Entry::find()->sectionId($section->id)->count() === 3;

        return $gone && $sectionSurvives && $entriesSurvive
            ? true
            : sprintf('gone=%s section=%s entries=%s', var_export($gone, true), var_export($sectionSurvives, true), var_export($entriesSurvive, true));
    });

    check('rebuilding after forgetting adopts the existing model rather than duplicating it', function() {
        $rebuilt = plugin()->builder->build(blueprint());

        if (!$rebuilt->success) {
            return implode('; ', $rebuilt->problems);
        }

        // The gallery field was deleted above, so exactly one thing should be created.
        $created = array_filter($rebuilt->created, fn(string $c) => !str_contains($c, 'Gallery'));

        return $created === [] ? true : 'also created: ' . implode('; ', $created);
    });

    check('teardown removes the section, entries and groups', function() {
        $reloaded = plugin()->portfolios->getPortfolioByHandle(HANDLE);
        $teardown = plugin()->builder->teardown($reloaded);

        return $teardown->success
            && Craft::$app->getEntries()->getSectionByHandle(HANDLE) === null
            && Craft::$app->getCategories()->getGroupByHandle(HANDLE . 'Categories') === null
            && Craft::$app->getTags()->getTagGroupByHandle(HANDLE . 'Tags') === null
            && plugin()->portfolios->getPortfolioByHandle(HANDLE) === null
            ? true
            : 'teardown left something behind: ' . implode('; ', $teardown->problems);
    });

    check('teardown removes the fields it created', function() {
        $left = [];

        foreach (Role::all() as $role) {
            if (Craft::$app->getFields()->getFieldByHandle(HANDLE . ucfirst($role)) !== null) {
                $left[] = $role;
            }
        }

        return $left === [] ? true : 'fields left behind: ' . implode(', ', $left);
    });
} finally {
    sweep();
    setEdition($originalEdition);
    Craft::$app->getProjectConfig()->flush();

    // The starter templates land in the site's own templates directory, so they are swept too.
    $templateDir = Craft::$app->getPath()->getSiteTemplatesPath() . '/portfolio-test';

    if (is_dir($templateDir)) {
        craft\helpers\FileHelper::removeDirectory($templateDir);
    }
}

$elapsed = number_format(microtime(true) - $startedAt, 1);

echo "\n" . str_repeat('─', 60) . "\n";
echo "$passed passed, $failed failed  ({$elapsed}s)\n\n";

exit($failed === 0 ? 0 : 1);
