<?php
/** Flips plugin settings from the command line, for testing. */
$root = getcwd();
require $root . '/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

$plugin = \justinholtweb\portfolio\Plugin::getInstance();
$settings = $plugin->getSettings()->toArray();

foreach (array_slice($argv, 1) as $pair) {
    [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
    $settings[$key] = match (strtolower($value)) {
        'true', '1' => true,
        'false', '0' => false,
        default => is_numeric($value) ? $value + 0 : $value,
    };
}

Craft::$app->getPlugins()->savePluginSettings($plugin, $settings);
Craft::$app->getProjectConfig()->flush();

echo json_encode($plugin->getSettings()->toArray(), JSON_PRETTY_PRINT) . "\n";
