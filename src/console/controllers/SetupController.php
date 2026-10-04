<?php

namespace justinholtweb\portfolio\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use craft\models\Section;
use justinholtweb\portfolio\models\Blueprint;
use justinholtweb\portfolio\models\PlanItem;
use justinholtweb\portfolio\models\Role;
use justinholtweb\portfolio\Plugin;
use justinholtweb\portfolio\services\StarterTemplates;
use yii\console\ExitCode;

/**
 * Builds, inspects and removes portfolios from the command line.
 *
 * `php craft portfolio/setup/build` on a fresh site gets you a working portfolio at `/portfolio`
 * with templates on disk, which is the whole point of the plugin in one command.
 */
class SetupController extends Controller
{
    /** Display name for the portfolio. Defaults to a title-cased handle. */
    public ?string $name = null;

    /** `structure` or `channel`. */
    public string $type = Section::TYPE_STRUCTURE;

    /** Skip the category and tag groups. */
    public bool $noTaxonomy = false;

    /** Show what would be created and stop. */
    public bool $dryRun = false;

    /** Write the starter templates as part of the build. */
    public bool $templates = false;

    /** Overwrite templates that already exist. */
    public bool $force = false;

    /**
     * Typed confirmation for `remove`: pass the portfolio's handle.
     *
     * Craft turns `$interactive` off whenever stdin is not a terminal, so a prompt alone would make
     * the command unusable from a script or a deploy — and "unusable" is how people end up doing
     * it by hand in the database instead. This is the same confirmation, in a form automation can
     * give: it still cannot happen by accident, because the handle has to be typed out.
     */
    public ?string $confirm = null;

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        return match ($actionID) {
            'build' => array_merge($options, ['name', 'type', 'noTaxonomy', 'dryRun', 'templates', 'force']),
            'templates' => array_merge($options, ['force']),
            'remove' => array_merge($options, ['confirm']),
            default => $options,
        };
    }

    /**
     * Builds a portfolio: section, entry type, fields, category group and tag group.
     *
     * Safe to run twice — anything that already exists is reused, never rewritten.
     */
    public function actionBuild(string $handle = 'portfolio'): int
    {
        $plugin = Plugin::getInstance();

        $blueprint = new Blueprint([
            'handle' => $handle,
            'name' => $this->name ?? ucfirst(preg_replace('/(?<!^)[A-Z]/', ' $0', $handle)),
            'sectionType' => $this->type === Section::TYPE_CHANNEL ? Section::TYPE_CHANNEL : Section::TYPE_STRUCTURE,
            'roles' => $this->noTaxonomy
                ? array_values(array_diff(Role::all(), Role::TAXONOMY))
                : Role::all(),
        ]);

        $plan = $plugin->builder->plan($blueprint);

        $this->stdout("\nPortfolio “{$blueprint->name}” ({$blueprint->handle})\n", Console::BOLD);
        $this->printPlan($plan);

        if ($plan->blockers !== []) {
            foreach ($plan->blockers as $blocker) {
                $this->stderr("  ✗ $blocker\n", Console::FG_RED);
            }

            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($plan->hasConflicts()) {
            $this->stderr("\nThe build cannot go ahead while those conflicts stand.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($this->dryRun) {
            $this->stdout("\nDry run — nothing was written.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        if ($plan->isSatisfied() && $plugin->portfolios->getPortfolioByHandle($handle) !== null) {
            $this->stdout("\nAlready built. Nothing to do.\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        $result = $plugin->builder->build($blueprint);

        foreach ($result->created as $created) {
            $this->stdout("  + $created\n", Console::FG_GREEN);
        }

        foreach ($result->reused as $reused) {
            $this->stdout("  = $reused\n", Console::FG_GREY);
        }

        foreach ($result->problems as $problem) {
            $this->stderr("  ✗ $problem\n", Console::FG_RED);
        }

        if (!$result->success) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->flushProjectConfig();

        if ($this->templates && $result->portfolio !== null) {
            $this->stdout("\nTemplates\n", Console::BOLD);
            $this->printTemplateResults($plugin->starter->write($result->portfolio, $this->force));
        }

        $this->stdout("\nDone. " . $result->summary() . "\n", Console::FG_GREEN);
        $this->stdout("Next: php craft portfolio/setup/templates $handle\n\n");

        return ExitCode::OK;
    }

    /** What exists, and anything that has drifted since it was built. */
    public function actionStatus(): int
    {
        $plugin = Plugin::getInstance();
        $portfolios = $plugin->portfolios->getAllPortfolios();

        $this->stdout("\nPortfolio " . ($plugin->isPro() ? 'Pro' : 'Lite') . "\n", Console::BOLD);

        if ($portfolios === []) {
            $this->stdout("No portfolios yet. Run: php craft portfolio/setup/build\n\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $exit = ExitCode::OK;

        foreach ($portfolios as $portfolio) {
            $section = $portfolio->getSection();

            $this->stdout("\n  {$portfolio->name} ({$portfolio->handle})\n", Console::BOLD);
            $this->stdout('    section       ' . ($section->handle ?? '— missing —') . "\n");
            $this->stdout('    entry type    ' . ($portfolio->getEntryType()->handle ?? '— missing —') . "\n");
            $this->stdout('    categories    ' . ($portfolio->getCategoryGroup()->handle ?? '—') . "\n");
            $this->stdout('    tags          ' . ($portfolio->getTagGroup()->handle ?? '—') . "\n");
            $this->stdout('    templates     ' . $portfolio->templateRoot . "/\n");

            $this->stdout("    roles\n");

            // Canonical role order, not the order project config happens to hand them back —
            // project config sorts its keys, so `$portfolio->roles` arrives alphabetically.
            foreach (Role::all() as $role) {
                if (!array_key_exists($role, $portfolio->roles)) {
                    continue;
                }

                $handle = $portfolio->handleForRole($role);
                $label = str_pad(Role::label($role), 16);

                if ($handle === null) {
                    $this->stdout("      $label — field deleted —\n", Console::FG_RED);
                } else {
                    $this->stdout("      $label $handle\n");
                }
            }

            $drift = $plugin->builder->driftFor($portfolio);

            foreach ($drift as $problem) {
                $this->stderr("    ✗ $problem\n", Console::FG_RED);
                $exit = ExitCode::UNSPECIFIED_ERROR;
            }
        }

        $this->stdout("\n");

        return $exit;
    }

    /** Writes the starter front-end templates into the site. */
    public function actionTemplates(string $handle = ''): int
    {
        $plugin = Plugin::getInstance();
        $portfolio = $handle === ''
            ? $plugin->portfolios->getDefaultPortfolio()
            : $plugin->portfolios->getPortfolioByHandle($handle);

        if ($portfolio === null) {
            $this->stderr("No such portfolio.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("\nWriting into " . $plugin->starter->templatesPath() . "/{$portfolio->templateRoot}/\n\n");

        $results = $plugin->starter->write($portfolio, $this->force);
        $this->printTemplateResults($results);

        foreach ($results as $result) {
            if ($result['status'] === StarterTemplates::FAILED) {
                return ExitCode::UNSPECIFIED_ERROR;
            }
        }

        $this->stdout("\n");

        return ExitCode::OK;
    }

    /**
     * Removes a portfolio's content model — section, entry type, fields, groups — and every entry
     * in it.
     *
     * Deleting a section in Craft deletes its entries. There is no version of this that keeps
     * them, so the count is printed and typed confirmation is required.
     */
    public function actionRemove(string $handle = ''): int
    {
        $plugin = Plugin::getInstance();
        $portfolio = $handle === ''
            ? $plugin->portfolios->getDefaultPortfolio()
            : $plugin->portfolios->getPortfolioByHandle($handle);

        if ($portfolio === null) {
            $this->stderr("No such portfolio.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($portfolio->adopted) {
            $this->stderr("“{$portfolio->handle}” adopted a section the site already had, so its content model is not the plugin’s to remove. Use the CP to forget it instead.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $impact = $plugin->builder->teardownImpact($portfolio);

        $this->stdout("\nThis will permanently delete:\n\n", Console::FG_RED, Console::BOLD);
        $this->stdout("  the “{$portfolio->handle}” section and its {$impact['entries']} entries\n");
        $this->stdout("  the “" . ($portfolio->getEntryType()->handle ?? '?') . "” entry type\n");

        if ($impact['categories'] > 0 || $portfolio->categoryGroupUid !== null) {
            $this->stdout("  the category group and its {$impact['categories']} categories\n");
        }

        if ($impact['tags'] > 0 || $portfolio->tagGroupUid !== null) {
            $this->stdout("  the tag group and its {$impact['tags']} tags\n");
        }

        foreach ($impact['fields'] as $field) {
            $this->stdout("  the “{$field}” field, unless another field layout uses it\n");
        }

        $this->stdout("\nStarter templates on disk are left alone.\n\n");

        if ($this->confirm !== null) {
            if ($this->confirm !== $portfolio->handle) {
                $this->stderr("--confirm must be the portfolio handle, “{$portfolio->handle}”. Nothing was removed.\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
        } elseif ($this->interactive) {
            $typed = $this->prompt("Type the portfolio handle “{$portfolio->handle}” to confirm:");

            if ($typed !== $portfolio->handle) {
                $this->stdout("Not confirmed. Nothing was removed.\n", Console::FG_YELLOW);
                return ExitCode::OK;
            }
        } else {
            $this->stderr("Nothing was removed. There is no terminal to confirm at, so pass --confirm={$portfolio->handle}.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $result = $plugin->builder->teardown($portfolio);

        foreach ($result->created as $done) {
            $this->stdout("  − $done\n", Console::FG_YELLOW);
        }

        foreach ($result->reused as $kept) {
            $this->stdout("  = $kept\n", Console::FG_GREY);
        }

        foreach ($result->problems as $problem) {
            $this->stderr("  ✗ $problem\n", Console::FG_RED);
        }

        $this->flushProjectConfig();

        return $result->success ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    // ------------------------------------------------------------------ output

    private function printPlan(\justinholtweb\portfolio\models\BuildPlan $plan): void
    {
        foreach ($plan->items as $item) {
            [$mark, $colour] = match ($item->action) {
                PlanItem::ACTION_CREATE => ['+', Console::FG_GREEN],
                PlanItem::ACTION_REUSE => ['=', Console::FG_GREY],
                default => ['✗', Console::FG_RED],
            };

            $kind = str_pad($item->kind, 14);
            $handle = str_pad($item->handle, 26);

            $this->stdout("  $mark $kind $handle", $colour);
            $this->stdout($item->note === '' ? "\n" : " {$item->note}\n", Console::FG_GREY);
        }

        $this->stdout("\n  " . $plan->summary() . "\n");
    }

    private function printTemplateResults(array $results): void
    {
        foreach ($results as $result) {
            [$mark, $colour] = match ($result['status']) {
                StarterTemplates::WRITTEN => ['+', Console::FG_GREEN],
                StarterTemplates::SKIPPED => ['=', Console::FG_GREY],
                default => ['✗', Console::FG_RED],
            };

            $this->stdout("  $mark " . str_pad($result['path'], 40), $colour);
            $this->stdout($result['note'] === '' ? "\n" : " {$result['note']}\n", Console::FG_GREY);
        }
    }

    /**
     * Project config writes are buffered until the request ends.
     *
     * `flush()`, not `saveModifiedConfigData()`. In Craft 5 the latter only persists the change to
     * the `projectconfig` table; writing `config/project/*.yaml` is a second step
     * (`writeYamlFiles()`), and `flush()` is the pair. Calling only the first leaves the change in
     * the database and nowhere else — every call returns true, and the next `craft up` reverts it
     * from the untouched files.
     */
    private function flushProjectConfig(): void
    {
        Craft::$app->getProjectConfig()->flush();
    }
}
