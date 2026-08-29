<?php
/**
 * Seeds a handful of portfolio items so the front end has something to render.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-portfolio/tests/manual/seed-demo.php
 *
 * Re-running updates the same entries rather than making more. Pass `clean` to remove them.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\Tag;
use justinholtweb\portfolio\models\Role;
use justinholtweb\portfolio\Plugin;

$clean = ($argv[1] ?? '') === 'clean';

$portfolio = Plugin::getInstance()->portfolios->getDefaultPortfolio();

if ($portfolio === null) {
    fwrite(STDERR, "No portfolio built. Run: php craft portfolio/setup/build\n");
    exit(1);
}

$section = $portfolio->getSection();
$entryType = $portfolio->getEntryType();
$categoryGroup = $portfolio->getCategoryGroup();
$tagGroup = $portfolio->getTagGroup();

$elements = Craft::$app->getElements();

if ($clean) {
    foreach (Entry::find()->sectionId($section->id)->status(null)->all() as $entry) {
        $elements->deleteElement($entry, true);
    }

    foreach (Category::find()->groupId($categoryGroup->id)->status(null)->all() as $category) {
        $elements->deleteElement($category, true);
    }

    foreach (Tag::find()->groupId($tagGroup->id)->status(null)->all() as $tag) {
        $elements->deleteElement($tag, true);
    }

    echo "Cleaned.\n";
    exit(0);
}

function term(string $class, int $groupId, string $title): craft\base\ElementInterface
{
    /** @var class-string<craft\base\ElementInterface> $class */
    $existing = $class::find()->groupId($groupId)->title($title)->status(null)->one();

    if ($existing !== null) {
        return $existing;
    }

    $element = new $class();
    $element->groupId = $groupId;
    $element->title = $title;

    if (!Craft::$app->getElements()->saveElement($element)) {
        throw new RuntimeException("Could not save $title: " . json_encode($element->getErrors()));
    }

    return $element;
}

$categories = [
    'Branding' => term(Category::class, $categoryGroup->id, 'Branding'),
    'Web' => term(Category::class, $categoryGroup->id, 'Web'),
    'Print' => term(Category::class, $categoryGroup->id, 'Print'),
];

$tags = [
    'rebrand' => term(Tag::class, $tagGroup->id, 'rebrand'),
    'ecommerce' => term(Tag::class, $tagGroup->id, 'ecommerce'),
    'editorial' => term(Tag::class, $tagGroup->id, 'editorial'),
];

// Any image already in the site — the point is to exercise the asset roles, not to add fixtures.
$image = Asset::find()->kind('image')->one();

$items = [
    ['Northwind Rebrand', 'northwind-rebrand', 'A full identity refresh for a hundred-year-old shipping company.', 'Northwind Shipping', '2026-02-01', ['Branding', 'Print'], ['rebrand', 'editorial']],
    ['Harbour Store', 'harbour-store', 'A storefront that loads in under a second on a phone at the docks.', 'Harbour Supply Co', '2026-05-14', ['Web'], ['ecommerce']],
    ['Field Notes Quarterly', 'field-notes-quarterly', 'Four issues of a print journal, set and produced in-house.', 'Field Notes', '2025-11-20', ['Print'], ['editorial']],
    ['Tidewater Identity', 'tidewater-identity', 'Naming, marks and a small design system for a coastal restaurant group.', 'Tidewater Group', '2026-07-02', ['Branding', 'Web'], ['rebrand']],
];

$summaryHandle = $portfolio->handleForRole(Role::SUMMARY);
$clientHandle = $portfolio->handleForRole(Role::CLIENT);
$dateHandle = $portfolio->handleForRole(Role::COMPLETED_DATE);
$categoriesHandle = $portfolio->handleForRole(Role::CATEGORIES);
$tagsHandle = $portfolio->handleForRole(Role::TAGS);
$featuredHandle = $portfolio->handleForRole(Role::FEATURED_IMAGE);
$urlHandle = $portfolio->handleForRole(Role::PROJECT_URL);

foreach ($items as [$title, $slug, $summary, $client, $date, $categoryNames, $tagNames]) {
    $entry = Entry::find()->sectionId($section->id)->slug($slug)->status(null)->one() ?? new Entry();
    $entry->sectionId = $section->id;
    $entry->typeId = $entryType->id;
    $entry->title = $title;
    $entry->slug = $slug;
    $entry->enabled = true;

    $values = [
        $summaryHandle => $summary,
        $clientHandle => $client,
        $dateHandle => new DateTime($date, new DateTimeZone('UTC')),
        $categoriesHandle => array_map(fn(string $n) => $categories[$n]->id, $categoryNames),
        $tagsHandle => array_map(fn(string $n) => $tags[$n]->id, $tagNames),
        $urlHandle => 'https://example.com/' . $slug,
    ];

    if ($image !== null && $featuredHandle !== null) {
        $values[$featuredHandle] = [$image->id];
    }

    $entry->setFieldValues(array_filter($values, fn($v, $k) => $k !== null, ARRAY_FILTER_USE_BOTH));

    if (!$elements->saveElement($entry)) {
        fwrite(STDERR, "Could not save $title: " . json_encode($entry->getErrors()) . "\n");
        exit(1);
    }

    echo "  ✓ $title\n";
}

echo "\nSeeded " . count($items) . " items, " . count($categories) . " categories, " . count($tags) . " tags.\n";
