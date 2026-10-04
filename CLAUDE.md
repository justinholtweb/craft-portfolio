# Portfolio — Craft CMS 5 Plugin

## Project Overview

Portfolio builds a portfolio's whole content model in Craft — section, entry type, fields, category
group, tag group — and then knows what it built well enough that the front end can be queried
without naming a field. Distributed as `justinholtweb/craft-portfolio`. **Lite (free) + Pro
(paid)**, proposed at $49 / $39 renewal.

Reference point: the WordPress *Portfolio Post Type* plugin, which registers a `portfolio` post type
and two taxonomies and provides nothing else. Craft has no post types to register, so the plugin's
job is the scaffolding a developer would otherwise do by hand — plus the part WordPress cannot have,
which is a record of what was built.

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, Yii2, Twig
- **No build step, no runtime dependencies.** The Pro grid runtime is a plain IIFE in
  `src/resources/`, inlined into the page; `craftcms/ckeditor` is a *suggest*, never a require.

## Architecture

### Namespace & package

- Namespace: `justinholtweb\portfolio`
- Package: `justinholtweb/craft-portfolio`
- Handle: `portfolio`

### The load-bearing idea: a blueprint, then a role map

- `models\Blueprint` — the desired content model. Entirely declarative, holds no Craft state.
- `services\Builder` — `plan()` diffs the blueprint against project config and reports every item
  as *create*, *reuse* or *conflict* without writing anything; `build()` applies only the creates.
- `models\Portfolio` — what exists: section UID, entry type UID, and **role → field UID**.

Two rules everything else follows from:

1. **Plan before you write.** Building a content model is not undoable in any sense an author would
   recognise, so nobody should discover what happened by reading a project-config diff.
2. **Never edit what you did not create.** A handle taken by the right type is reused *as it is*.
   A handle taken by the wrong type is a conflict and stops the build. As a consequence, `build()`
   is safe to run twice — the second run creates nothing. The rule covers deleting too: `build()`
   records the UIDs its plan marked *create* in `Portfolio::$created`, and `teardown()` deletes only
   those. Forgetting a portfolio drops that record, so a forget-then-rebuild claims nothing.

### Why the role map exists

Templates ask for a role (`client`), never a handle (`portfolioClient`). The map is keyed by field
**UID**, so a rename in the CP cannot break a template, and (Pro) the same templates work against a
section the site built by hand and adopted.

### Storage: project config only

A portfolio lives at `portfolio.portfolios.<uid>` in project config. No tables, no install
migration, nothing derived.

That is not a shortcut — it sidesteps a whole class of bug. The project-config coalescing trap (a
path added *and* removed in one request fires no removal handler) cannot bite when nothing is
derived from the path, and uninstalling cannot orphan anything. The config handlers only drop a
memo.

### Services

- `portfolios` — the register: read/write project config, resolve by handle/uid/section/entry
- `builder` — `plan()`, `build()`, `driftFor()`, `teardownImpact()`, `teardown()`
- `query` — every front-end read; each one starts from the role map
- `renderer` — Pro: `craft.portfolio.grid()` markup, scoped CSS, inlined runtime
- `starter` — writes the front-end templates into the site
- `jsonld` — Pro: `CreativeWork` structured data

### Deliberate design calls

- **Structure sections get no `orderBy`.** Craft already orders a single-structure entry query by
  `structureelements.lft`; setting an explicit order would *replace* it and silently discard the
  order the author dragged the section into.
- **Grid filtering is client-side.** Server-side filtering means a query string per filter and a
  cache entry per combination; this way an archive page stays one cacheable response.
- **JSON-LD is emitted from the template, not injected into the response.** Injection means
  rewriting a prepared body, restamping `content-length`, and carefully not touching
  `feed.rss.twig`. A portfolio item page is a template the developer already owns.
- **Tag archives are a URL rule routed straight to a template**, not through a controller — Craft
  tags have no URI of their own, and the page should stay an ordinary cacheable render.
- **Grid CSS/JS is inlined in the returned markup**, once per request, rather than registered
  through the view: `registerCss()` only lands if the template calls `{{ head() }}`/`{{ endBody() }}`.
- **Starter templates are `.twig.txt` with `%%TOKEN%%` placeholders**, not Twig. Rendering Twig that
  produces Twig means escaping every tag and the source becomes unreadable. Substitution suffices
  because the templates ask for *roles*, so there is nothing per-field to interpolate.

## Editions

`models\Edition` is pure and static, taking `bool $isPro`. `Plugin::isPro()` is the only thing that
asks Craft. **Lite is the content model and the queries; Pro is presentation and scale** — Pro adds
unlimited portfolios, adopting an existing section, the grid, and JSON-LD.

## Traps found while building this

- **`saveModifiedConfigData()` no longer writes the YAML in Craft 5.** It persists to the
  `projectconfig` table only; `writeYamlFiles()` is the second half, and `ProjectConfig::flush()` is
  the pair (registered on `Application::EVENT_AFTER_REQUEST`). A bare script that calls only the
  first leaves the change in the database and nowhere else — every call returns `true`, and the next
  `craft up` reverts it from the untouched files. Cost an hour of "why is the edition still lite".
- **The curly-quote interpolation trap, twice.** `"the “$field” field"` interpolates a variable
  named `$field”` — PHP allows bytes ≥ 0x80 in identifiers and `”` is three of them. Undefined
  variable, empty string, no error anybody notices. There is now a check in `checks.php` that greps
  `src/` for it, because this family keeps shipping it.
- **`ElementQuery::count()` returns a *string*** whenever it reaches the database, and a real `int`
  only on the short-circuit path where the query is known empty. So `->count() === 3` is false and
  `->count() === 0` is true, which is the worst possible combination for a test suite. Always cast.
- **`yii\base\Model::addError($attribute, $error)` already exists**, so a result object with
  `addError(string $error)` is a fatal at autoload time, not at the call site. `BuildResult` uses
  `addProblem()`/`$problems` for exactly this reason. Same family as the `Component::load()`
  collision in `[[craft-plugin-gotchas]]`.
- **Craft turns `$interactive` off whenever stdin is not a terminal**, so a console command guarded
  only by `prompt()` is unusable from a script or a deploy — and unusable is how people end up doing
  it by hand in the database. `remove` takes `--confirm=<handle>`: the same typed confirmation, in a
  form automation can give.
- **Project config sorts its keys**, so `$portfolio->roles` comes back alphabetically however it was
  written. Anything that displays roles has to iterate `Role::all()` for canonical order.
- **`craftcms/ckeditor` 5.x fields carry their own config inline** (`toolbar`, `headingLevels`,
  `json`) and the constructor *unsets* `ckeConfig` — so a CKEditor field can be created
  programmatically without touching that plugin's project config at all.
- **A section's `defaultPlacement`** decides which end new entries land at, so any test that asserts
  next/prev against fixture creation order is asserting the wrong thing. Read the ordered list and
  walk the middle.

See also `[[craft-plugin-gotchas]]` in the shared memory for family-wide traps.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container:

```sh
cd ~/Sites/plugin-testing
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-portfolio/tests/integration/checks.php   # 68 checks
ddev exec bash -c 'find /var/www/craft-portfolio/src -name "*.php" -print0 | xargs -0 -n1 php -l'
```

The checks are idempotent and self-cleaning: every fixture is handled `portfolioTest*`, the sweep
runs in a `finally`, and the original edition is restored. `tests/manual/` holds a demo seeder, an
edition switcher and a settings setter for poking at the harness by hand.

`ddev snapshot --name <name>` before anything destructive — these checks create and delete real
sections in a shared harness.

## Coding conventions

- `Craft::t('portfolio', '…')` for user-facing strings
- Business logic in services; controllers stay thin
- Never nest a `<form>` in a CP template — post secondary actions with `Craft.sendActionRequest`
  or a script-built form
- Never mark plugin settings `required`
- Never give the Twig variable a `foo()` and a `getFoo()`; Twig resolves the first and the pair
  recurses until the C stack blows
