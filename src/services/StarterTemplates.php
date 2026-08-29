<?php

namespace justinholtweb\portfolio\services;

use Craft;
use craft\base\Component;
use craft\helpers\FileHelper;
use justinholtweb\portfolio\models\Portfolio;
use justinholtweb\portfolio\Plugin;
use Throwable;

/**
 * Writes working front-end templates into the site.
 *
 * Two things this deliberately does not do:
 *
 * - **It never runs on install.** A plugin that writes into `templates/` the moment it is required
 *   is a plugin nobody trusts. Writing is always an explicit act, from the CP or the console.
 * - **It never overwrites without `force`.** The templates become the site's the second they land;
 *   after that they are somebody's work, not our output.
 *
 * The sources are plain text with `%%TOKEN%%` placeholders rather than Twig, because rendering
 * Twig that produces Twig means escaping every tag in the output and reading it afterwards is
 * miserable. Substitution is enough: the templates ask for *roles*, not handles, so there is
 * nothing per-field to interpolate.
 */
class StarterTemplates extends Component
{
    public const WRITTEN = 'written';
    public const SKIPPED = 'skipped';
    public const FAILED = 'failed';

    /** Source file => destination, relative to the portfolio's template root. */
    private const FILES = [
        'index.twig.txt' => 'index.twig',
        '_entry.twig.txt' => '_entry.twig',
        'category.twig.txt' => 'category.twig',
        'tag.twig.txt' => 'tag.twig',
        '_partials/card.twig.txt' => '_partials/card.twig',
        '_partials/filters.twig.txt' => '_partials/filters.twig',
        '_partials/styles.twig.txt' => '_partials/styles.twig',
    ];

    /**
     * @return array<int, array{path: string, status: string, note: string}>
     */
    public function write(Portfolio $portfolio, bool $force = false): array
    {
        $targetRoot = $this->targetRoot($portfolio);
        $results = [];

        if (!$this->rootIsWritable()) {
            return [[
                'path' => $this->templatesPath(),
                'status' => self::FAILED,
                'note' => Craft::t('portfolio', 'The templates directory is not writable, so nothing was written.'),
            ]];
        }

        $tokens = $this->tokens($portfolio);
        $files = self::FILES;

        // A tag archive with no tag group would be a template nothing can ever route to — the
        // route is only registered for portfolios that have one.
        if ($portfolio->tagGroupUid === null) {
            unset($files['tag.twig.txt']);
        }

        if ($portfolio->categoryGroupUid === null) {
            unset($files['category.twig.txt'], $files['_partials/filters.twig.txt']);
        }

        foreach ($files as $source => $destination) {
            $path = $targetRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $destination);
            $display = $portfolio->templateRoot . '/' . $destination;

            $existed = file_exists($path);

            if ($existed && !$force) {
                $results[] = [
                    'path' => $display,
                    'status' => self::SKIPPED,
                    'note' => Craft::t('portfolio', 'Already exists — left alone.'),
                ];
                continue;
            }

            try {
                $contents = strtr($this->source($source), $tokens);
                FileHelper::writeToFile($path, $contents);

                $results[] = [
                    'path' => $display,
                    'status' => self::WRITTEN,
                    'note' => $existed ? Craft::t('portfolio', 'Overwritten.') : '',
                ];
            } catch (Throwable $e) {
                Craft::error("Could not write $display: " . $e->getMessage(), Plugin::LOG_CATEGORY);

                $results[] = [
                    'path' => $display,
                    'status' => self::FAILED,
                    'note' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /** Whether any starter template is already on disk — so the CP can offer the right verb. */
    /** How many files a write would consider, for this portfolio's shape. */
    public function fileCount(Portfolio $portfolio): int
    {
        $count = count(self::FILES);

        if ($portfolio->tagGroupUid === null) {
            $count--;
        }

        if ($portfolio->categoryGroupUid === null) {
            $count -= 2;
        }

        return $count;
    }

    public function anyExist(Portfolio $portfolio): bool
    {
        $root = $this->targetRoot($portfolio);

        foreach (self::FILES as $destination) {
            if (file_exists($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $destination))) {
                return true;
            }
        }

        return false;
    }

    public function templatesPath(): string
    {
        return Craft::$app->getPath()->getSiteTemplatesPath();
    }

    public function rootIsWritable(): bool
    {
        $path = $this->templatesPath();

        return is_dir($path) && is_writable($path);
    }

    private function targetRoot(Portfolio $portfolio): string
    {
        return $this->templatesPath() . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $portfolio->templateRoot);
    }

    private function source(string $file): string
    {
        $path = __DIR__ . '/../templates/_starter/' . $file;
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new BuildException("Missing starter template source: $file");
        }

        return $contents;
    }

    /** @return array<string, string> */
    private function tokens(Portfolio $portfolio): array
    {
        $section = $portfolio->getSection();
        $uriRoot = $portfolio->templateRoot;

        // The URI the index lives at is whatever the section's URI format has before `{slug}` —
        // which is not always the template root, because either can be edited afterwards.
        if ($section !== null) {
            foreach ($section->getSiteSettings() as $siteSettings) {
                $uriFormat = (string)$siteSettings->uriFormat;

                if ($uriFormat !== '') {
                    $trimmed = trim(preg_replace('/\{[^}]*\}.*$/', '', $uriFormat), '/');

                    if ($trimmed !== '') {
                        $uriRoot = $trimmed;
                    }

                    break;
                }
            }
        }

        return [
            '%%NAME%%' => $portfolio->name,
            '%%HANDLE%%' => $portfolio->handle,
            '%%ROOT%%' => $portfolio->templateRoot,
            '%%URI_ROOT%%' => $uriRoot,
        ];
    }
}
