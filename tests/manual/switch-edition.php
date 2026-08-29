<?php
/**
 * Switches the Portfolio edition locally, for testing the Lite/Pro boundary without a licence.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-portfolio/tests/manual/switch-edition.php pro
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

$edition = $argv[1] ?? 'lite';

if (!in_array($edition, ['lite', 'pro'], true)) {
    fwrite(STDERR, "Usage: switch-edition.php lite|pro\n");
    exit(1);
}

Craft::$app->getPlugins()->switchEdition('portfolio', $edition);
Craft::$app->getProjectConfig()->flush();

echo "Portfolio is now $edition.\n";
