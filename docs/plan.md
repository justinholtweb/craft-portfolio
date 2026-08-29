# Portfolio — a Craft CMS 5 plugin

## Context

`/Users/jholt/Sites/craft-portfolio` is an empty directory. The ask is a new Craft 5 plugin named
**Portfolio** that creates portfolio sections and schema in a site and integrates easily with the
front end, in the spirit of the WordPress *Portfolio Post Type* plugin.

That WP plugin is deliberately tiny: it registers a `portfolio` post type, a portfolio category
taxonomy and a portfolio tag taxonomy, turns on featured images/excerpts/custom fields, and stops.
It ships **no** shortcodes, no template tags and no settings — the theme author writes
`archive-portfolio.php` and `single-portfolio.php` by hand.

Craft has no post types to register, so the equivalent value has to be delivered differently: the
plugin's job is to **build the content model** (section, entry type, fields, category group, tag
group) that a Craft developer would otherwise assemble by hand in the CP, and then to **know what
it built** so the front end can be queried and rendered without hardcoding field handles. That
knowledge is the thing the WP plugin cannot have and is where this plugin earns its place.

Decisions taken with Justin before planning:

- **Lite (free) + Pro (paid).** Proposed pricing **$49 / $39 renewal** — adjustable.
- **Category group + tag group** for the taxonomy, mirroring the WP plugin. Categories get real
  archive URIs; tag archives are served by a plugin-registered site route.
- **Full front-end integration**: Twig query API + starter templates written into the site +
  a drop-in rendered grid with client-side filtering and a lightbox.

---

## The load-bearing idea

**A portfolio is a described blueprint plus a role map, not a pile of hand-made project-config
edits.**

- A `Blueprint` describes the *desired* content model.
- `services\Builder` diffs it against project config and creates only what is missing — it never
  overwrites or renames anything that already exists, and it reports what it reused.
- What it built is recorded as a **role map**: which field handle plays *featured image*, which
  plays *summary*, *gallery*, *client*, *completed date*, *project URL*, *categories*, *tags*.

Everything front-end reads the role map rather than a hardcoded handle. That is what lets
`craft.portfolio.items()` work identically on a portfolio the plugin built and (Pro) on a section
the site already had, and what lets someone rename `portfolioClient` to `clientName` without
breaking a template.

The role map lives in **project config** (`portfolio.portfolios.<uid>`), referencing sections,
fields and groups **by UID** so renames survive. No derived DB tables at all — which sidesteps the
project-config coalescing trap in `[[craft-plugin-gotchas]]` entirely, since there is nothing to
tear down on removal.

---

## Package

- Package `justinholtweb/craft-portfolio`, namespace `justinholtweb\portfolio`, handle `portfolio`
- PHP 8.2+, Craft 5.3+, **no build step, no runtime dependencies** (family convention — see
  `/Users/jholt/Sites/craft-blaster/composer.json`)
- Editions `lite` / `pro`, gated through one pure static class `src/models/Edition.php` taking
  `bool $isPro`, exactly as `/Users/jholt/Sites/craft-legs/src/models/Edition.php` does, with
  `Plugin::isPro()` the only place that asks Craft

### Edition line — Lite is schema and queries; Pro is presentation and scale

| | Lite | Pro |
|---|---|---|
| Build a portfolio blueprint (section, entry type, fields, category + tag group) | ✓ | ✓ |
| Number of portfolios | 1 | unlimited |
| Starter templates written into the site | ✓ | ✓ |
| Twig query API (`items` `item` `categories` `tags` `related` `next` `prev` `filters`) | ✓ | ✓ |
| Console `portfolio/setup/*` | ✓ | ✓ |
| Adopt an existing section as a portfolio (role mapping over a hand-built model) | — | ✓ |
| `craft.portfolio.grid()` renderer — 3 layouts, client-side filtering, lightbox | — | ✓ |
| Schema.org `CreativeWork` JSON-LD | — | ✓ |

---

## What gets built

**Section** `portfolio` — Structure (default) or Channel, URI `portfolio/{slug}`, template
`portfolio/_entry`, per-site settings for every site the admin ticks.

**Entry type** `portfolioItem`, field layout in two tabs:

- *Content* — Title, `portfolioSummary`, `portfolioDescription`, `portfolioFeaturedImage`,
  `portfolioGallery`
- *Details* — `portfolioClient`, `portfolioCompletedDate`, `portfolioProjectUrl`,
  `portfolioCategories`, `portfolioTags`

**Fields** (every handle editable at build time; each maps to one role)

| role | handle | type |
|---|---|---|
| summary | `portfolioSummary` | `craft\fields\PlainText` multiline |
| description | `portfolioDescription` | CKEditor field **if `craftcms/ckeditor` is installed**, else PlainText multiline |
| featuredImage | `portfolioFeaturedImage` | `craft\fields\Assets`, max 1, images only |
| gallery | `portfolioGallery` | `craft\fields\Assets`, images only |
| client | `portfolioClient` | `craft\fields\PlainText` |
| completedDate | `portfolioCompletedDate` | `craft\fields\Date` |
| projectUrl | `portfolioProjectUrl` | `craft\fields\Link` (5.3+; `Url` is superseded) |
| categories | `portfolioCategories` | `craft\fields\Categories` |
| tags | `portfolioTags` | `craft\fields\Tags` |

**Category group** `portfolioCategories`, URI `portfolio/category/{slug}`, template
`portfolio/category`. **Tag group** `portfolioTags` — Craft tags have no URIs, so the plugin
registers a site URL rule `portfolio/tag/<slug>` → `portfolio/tag` when tag archives are enabled.

Optional fields can be unticked at build time; the role map simply has no entry for them and the
Twig API and starter templates skip them.

---

## Files

```
composer.json  README.md  CHANGELOG.md  LICENSE.md  CLAUDE.md  docs/plan.md
src/
  Plugin.php                        editions, components, CP nav, routes, permissions, twig, url rules
  icon.svg  icon-mask.svg
  models/
    Settings.php                    template root, tag archives, JSON-LD toggle, grid defaults
    Edition.php                     pure static gates, takes bool $isPro
    Blueprint.php                   desired model: names, handles, type, URIs, sites, which fields
    FieldSpec.php                   one field: role, handle, name, type, per-type config
    Portfolio.php                   a built portfolio: section uid + role map + options
    BuildPlan.php                   diff result — create / reuse / conflict, per item
    BuildResult.php                 what actually happened
  services/
    Portfolios.php                  read/write portfolio defs in project config, resolve by uid/handle
    Builder.php                     plan() and build() — the schema engine
    Roles.php                       role map → field handle → value, both directions
    Query.php                       backs the Twig API; every query starts from the role map
    Renderer.php                    Pro: grid markup + scoped CSS + runtime payload
    Templates.php                   render starter templates from the blueprint, write to @templates
    Jsonld.php                      Pro: CreativeWork structured data
  twig/PortfolioVariable.php        craft.portfolio.*
  twig/Extension.php                portfolioField() / portfolioUrl() filters
  controllers/PortfoliosController.php   build wizard, adopt, status, remove, write templates
  console/controllers/SetupController.php  build | status | templates | remove
  templates/                       CP: _index, _build, _adopt, _status, settings
  templates/_starter/              starter templates as .twig.txt sources, rendered on write
  resources/portfolio.js  portfolio.css   Pro grid runtime — plain IIFE, inlined, no build step
  translations/en/portfolio.php
tests/integration/checks.php
```

---

## Build order

**1 — Skeleton.** composer.json, `Plugin.php` with editions/components/nav/settings, `Settings`,
`Edition`, icons, translations, LICENSE. Wire into the harness (bind mount, path repo,
`ddev composer update`, `plugin/install`).

**2 — The schema engine.** `Blueprint`, `FieldSpec`, `Builder::plan()` and `Builder::build()`,
`Portfolios` with project-config read/write and `EVENT_ADD/UPDATE/REMOVE_ITEM` handlers.
`plan()` returns a `BuildPlan` naming every item as *create*, *reuse* (already exists with this
handle) or *conflict* (exists but is the wrong type). `build()` applies only *create* items,
inside a transaction where Craft allows it, and is safe to run twice.

**3 — CP.** Portfolio index, build wizard (name/handle, section type, URI formats, sites, optional
fields, asset volume, taxonomy on/off), a plan preview before anything is written, and a status
screen that re-runs `plan()` against the live model to surface drift (deleted field, renamed
section). Console `setup/build|status|templates|remove` — `remove` takes a typed confirmation and
never touches entries unless `--with-entries`.

**4 — Twig query API.** `Roles` + `Query` + `PortfolioVariable`:

```twig
craft.portfolio.items({category: 'branding', limit: 12})   → EntryQuery, criteria applied
craft.portfolio.item(slug)  .categories()  .tags()  .filters()
craft.portfolio.related(entry, 3)   .next(entry)   .prev(entry)
entry|portfolioField('featuredImage')                      → role-aware accessor
```

Multi-portfolio (Pro) selects with `craft.portfolio.of('caseStudies').items()`; Lite resolves to
the single portfolio implicitly.

**5 — Starter templates.** `index`, `_entry`, `category`, `tag`, `_partials/card`,
`_partials/filters` — generated *from the blueprint* so every handle in them is real. Written on
an explicit action only (never on install), refusing to overwrite without `--force`, and reporting
clearly if `@templates` isn't writable.

**6 — Pro.** `Renderer` (`craft.portfolio.grid()` — grid / masonry / list, columns, limit,
filterable, lightbox), the plain-IIFE runtime in `src/resources/` inlined into the page, `Jsonld`,
multi-portfolio, and adopt-an-existing-section (a mapping screen that lists the section's fields
and asks which plays which role).

**7 — Finish.** README (family voice — see `/Users/jholt/Sites/craft-friends/README.md`),
CHANGELOG, `CLAUDE.md` including traps found, `docs/plan.md`, an edition audit that every Pro path
is gated in exactly one place, and `php -l` across `src/`.

---

## Traps to design against up front

From `[[craft-plugin-gotchas]]` and the family CLAUDE.md files, the ones this plugin will actually
hit:

- **Never mark settings `required`** — blocks saving every other setting on a fresh install.
- **`fieldlayoutfields` is gone in Craft 5** — layouts are JSON in `fieldlayouts.config`; build them
  through `FieldLayout`/`FieldLayoutTab`/`CustomField` objects, never SQL.
- **Project config writes are buffered** — the console commands must call
  `getProjectConfig()->saveModifiedConfigData()` themselves.
- **No nested `<form>` in a CP template** — it corrupts the page form badly enough that Save runs
  Delete. The wizard's secondary actions post via `Craft.sendActionRequest`.
- **`status('live')` is entry-only** — category and tag queries need `enabled`.
- **devMode turns on `strict_variables`** — every read of the grid options hash needs `?? null`.
- **Twig resolves `foo.bar()` before `getBar()`** — do not give the Twig variable a method whose
  name differs from a getter only by the `get` prefix.
- **`Component::load()` / `getBehavior()` collisions** — no service method named `load()`, no
  element accessor named `getBehavior()`.
- **Uninstall must not delete the content model.** The section, fields and groups become the site's
  own; only `portfolio.*` project config goes. README says so in bold.

---

## Verification

Everything runs in the shared harness at `/Users/jholt/Sites/plugin-testing` (Craft 5.9, DDEV) —
there is no local PHP on this Mac.

```sh
cd ~/Sites/plugin-testing
ddev snapshot --name pre-portfolio                     # building schema mutates project config
ddev exec php /var/www/craft-portfolio/tests/integration/checks.php
ddev exec bash -c 'find /var/www/craft-portfolio/src -name "*.php" -print0 | xargs -0 -n1 php -l'
```

`tests/integration/checks.php` follows `/Users/jholt/Sites/craft-friends/tests/integration/checks.php`
— idempotent, self-cleaning, sweeping strays from a run that died half way. Every fixture is
handled `portfolioTest*` so a stray is obvious in a harness that already has forty plugins in it.

Coverage the checks must include:

- `plan()` on a clean slate reports create for every item; run `build()`, then `plan()` again
  reports reuse for every item and creates nothing (idempotency).
- A pre-existing field with a blueprint handle is reported *reuse*, and a pre-existing field of the
  wrong type is reported *conflict* and blocks the build rather than clobbering it.
- Built section/entry type/fields/groups actually exist in project config with the expected
  handles, and the role map resolves each role to the right field UID.
- Saving an entry through the built entry type round-trips every field, including the CKEditor
  branch when that plugin is present and the PlainText branch when it isn't.
- Twig API: `items()` scopes to the section, criteria filter correctly, `related()` ranks by shared
  terms, `next()`/`prev()` respect structure order.
- Renaming a field handle after a build leaves the role map working (UID-keyed).
- Edition: every Pro entry point returns the Lite behaviour under `lite`, and a second portfolio is
  refused under `lite` with a clear message.
- Removal takes the schema out but leaves entries standing.

CP-side behaviour (wizard, plan preview, drift status, adopt) is checked with an authenticated
session — `admin` / `claudepassword`, or `php craft users/impersonate admin` for a login URL —
rather than reported as untestable.

Front end is verified by building a portfolio in the harness, writing the starter templates,
seeding two or three entries, and loading `/portfolio`, an entry, a category archive and a tag
archive; then the Pro grid with filtering and the lightbox in the browser.
