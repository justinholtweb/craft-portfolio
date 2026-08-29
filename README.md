# Portfolio

**A portfolio is a content model, not a page.** Portfolio builds the whole thing — section, entry
type, fields, categories, tags — reads back the plan before it writes a line, and then hands the
front end a query API that never names a field handle.

```sh
composer require justinholtweb/craft-portfolio
php craft plugin/install portfolio
php craft portfolio/setup/build --templates=1
```

Craft 5.3+, PHP 8.2+. No build step, no runtime dependencies beyond Craft's own.

Reference point: the WordPress *Portfolio Post Type* plugin. That one registers a `portfolio` post
type and two taxonomies and stops — the theme author writes `archive-portfolio.php` by hand.
Craft has no post types to register, so the same value has to be delivered differently: the work
here is building the content model a Craft developer would otherwise assemble by hand in the CP,
and then **knowing what it built** well enough that the front end can be queried without hardcoding
anything.

---

## What you get, in one command

```
+ section        portfolio                  A structure section at portfolio/{slug}
+ entryType      portfolioItem              With a field layout holding the fields below.
+ categoryGroup  portfolioCategories        Archives at portfolio/category/{slug}
+ tagGroup       portfolioTags
+ field          portfolioSummary           Plain Text
+ field          portfolioDescription       CKEditor
+ field          portfolioFeaturedImage     Assets
+ field          portfolioGallery           Assets
+ field          portfolioClient            Plain Text
+ field          portfolioCompletedDate     Date
+ field          portfolioProjectUrl        Link
+ field          portfolioCategories        Categories
+ field          portfolioTags              Tags
```

…plus working `index`, `_entry`, `category` and `tag` templates on disk, so `/portfolio` renders
the moment the command finishes.

---

## Roles: why your templates survive a rename

Each field plays a **role**. Templates ask for the role; the plugin looks up the handle at the
moment it is asked.

```twig
{{ entry|portfolioField('client') }}
{{ craft.portfolio.items({ category: 'branding' }).all() }}
```

Rename `portfolioClient` to `clientName` in the control panel and nothing breaks — the role map is
keyed by field **UID**, not by handle. It is also what lets one set of templates work against a
portfolio the plugin built and (Pro) a section you built by hand years ago and adopted.

The roles: `summary`, `description`, `featuredImage`, `gallery`, `client`, `completedDate`,
`projectUrl`, `categories`, `tags`. Untick any of them at build time and everything skips it —
the starter templates, the grid, the structured data.

---

## Plan before you write

Building a content model is not undoable in any sense an author would recognise, so nothing is
written until you have read what will happen:

| | |
|---|---|
| **create** | doesn't exist; will be made |
| **reuse** | already exists with this handle and the right type — **used exactly as it is, settings untouched** |
| **conflict** | already exists with this handle and the wrong type — the build stops |

That last row is the point. A `portfolioSummary` field the site has been using for two years is
never reshaped because a blueprint happened to want that handle. And because *reuse* is a real
outcome rather than an error, `build` is safe to run twice: the second run creates nothing.

---

## The query API

Everything below is in **Lite**.

```twig
{# a query, so .all() / .one() / pagination / {% cache %} all behave normally #}
{% set items = craft.portfolio.items({ category: 'branding', limit: 12 }).all() %}

craft.portfolio.item('northwind-rebrand')
craft.portfolio.categories()        {# scoped to this portfolio's group #}
craft.portfolio.tags()
craft.portfolio.filters()           {# categories that have items, with counts, in one query #}
craft.portfolio.related(entry, 3)   {# ranked by how many terms overlap #}
craft.portfolio.next(entry)
craft.portfolio.prev(entry)

entry|portfolioField('featuredImage')
entry|portfolioHandle('client')     {# the current handle, to build your own query #}
```

Criteria the plugin doesn't recognise are handed straight to the entry query, so `limit`, `search`,
`with`, `orderBy` and the rest work as they always do. `category` and `tag` accept a slug, an ID,
an element, or a list of any of those — and two filters mean **both**, which is what a filter UI
means when two things are selected.

Multiple portfolios (Pro) are named explicitly:

```twig
{% set work = craft.portfolio.of('caseStudies') %}
{{ work.items().count() }}
```

### Sensible defaults, stated

- A **structure** section keeps whatever order the author dragged it into. Portfolio does not set
  an `orderBy` for those, because setting one would silently replace it.
- A **channel** orders by the `completedDate` role, falling back to post date.
- Filtering by a category that doesn't exist returns **nothing**, not everything. An archive for a
  deleted category showing every project is worse than showing none.

---

## Tag archives

Craft categories get URIs from project config. Tags don't — there is nowhere to put one. So
Portfolio registers a site route, `<template folder>/tag/<slug>`, for every portfolio that has a
tag group, and hands the slug to an ordinary template. The page is a normal cacheable render with
nothing of the plugin in the request path. Turn it off in Settings if you'd rather route it
yourself.

---

## Pro

| | Lite | Pro |
|---|:--:|:--:|
| Build the content model, plan-first | ✓ | ✓ |
| Portfolios | 1 | unlimited |
| Starter templates | ✓ | ✓ |
| The whole query API | ✓ | ✓ |
| Console commands | ✓ | ✓ |
| Adopt a section you already built | — | ✓ |
| `craft.portfolio.grid()` | — | ✓ |
| schema.org `CreativeWork` JSON-LD | — | ✓ |

**Lite is the content model and the queries. Pro is presentation and scale.**

### The grid

```twig
{{ craft.portfolio.grid({ layout: 'masonry', columns: 4, category: 'branding' }) }}
```

Three layouts (`grid`, `masonry`, `list`), a category filter bar, and a keyboard-accessible
lightbox over each item's images. The CSS and JS are inlined once per request — not registered
through the view, because `registerCss()` only reaches the page if the template calls `{{ head() }}`
and `{{ endBody() }}`, and plenty of real templates don't.

Filtering happens in the browser over already-rendered cards. That is deliberate: deciding it
server-side means a query string per filter and a cache entry per combination, where this way an
archive page stays one cacheable response.

### Structured data

`{{ craft.portfolio.jsonld(entry) }}` emits `CreativeWork` with the summary as `description`, the
categories as `genre`, the tags as `keywords`, the project URL as `sameAs`, and the client as
`sourceOrganization` — *"the organization on whose behalf the creator was working"*, which is
exactly what a client is.

Emitted from the template, never injected into a prepared response. The item template already
calls it.

---

## Console

```sh
php craft portfolio/setup/build [handle] [--name=] [--type=structure|channel]
                                [--noTaxonomy] [--dryRun] [--templates] [--force]
php craft portfolio/setup/status
php craft portfolio/setup/templates [handle] [--force]
php craft portfolio/setup/remove [handle] --confirm=<handle>
```

`status` is also a drift report: it exits non-zero when a field the role map points at has been
deleted, which is the single most likely reason a portfolio template goes quietly blank.

`remove` deletes the section — and Craft cascades that to its entries. It prints the counts first
and needs the handle typed back, either at the prompt or as `--confirm`.

---

## Two things it deliberately does not do

**It never writes into `templates/` on install.** Writing the starter templates is always an
explicit act, and existing files are never overwritten without `--force`. They become your
templates the second they land.

**Uninstalling does not delete your content model.** The section, entry type, fields, groups and
every entry stay exactly where they are; only the plugin's own project config goes. "Forget this
portfolio" in the CP does the same thing on purpose. Taking the schema away is a separate,
confirmed act with the damage counted out in front of you.

---

## Where things live

A portfolio is stored in **project config**, at `portfolio.portfolios.<uid>`, referencing sections,
fields and groups by UID:

```yaml
handle: portfolio
name: Portfolio
section: 72c3979c-… # Portfolio
entryType: c932c6e4-… # Portfolio Item
roles:
  client: 29baa693-… # Client
  summary: 89545e44-… # Summary
```

No database tables, no install migration, nothing derived to garbage-collect — and it deploys with
the sections and fields it describes, which is the only place it makes sense to keep it.

---

## Licence

Commercial. Lite is free; Pro needs a licence per installation. See `LICENSE.md`.
