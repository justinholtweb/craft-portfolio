---
title: Starter templates
slug: templates
order: 40
summary: The index, item, category and tag templates Portfolio writes into your site, and the tag archive route.
---

Portfolio can write a working set of front-end templates into your site, so `/portfolio` renders
the moment the build finishes. They are a starting point, not a theme: once written they are your
files, and the plugin never touches them again unless you ask.

## Writing them

Any one of:

- tick **Write the starter templates** when building in the control panel
- `php craft portfolio/setup/build --templates=1`
- `php craft portfolio/setup/templates [handle]` for a portfolio that already exists
- **Write the templates** under *Front-end templates* on a portfolio's page

They are never written on install, and never written by anything you didn't ask for.

**Existing files are left alone.** Each file is reported as written, left alone or failed. To
overwrite, pass `--force=1` on the console, or tick **Overwrite files that already exist** on the portfolio's page —
and expect to lose any edits you made.

If the templates folder isn't writable, nothing is written and you are told so. On a read-only
production filesystem, write them in development and commit them.

## What you get

Written into the portfolio's template folder — `templates/portfolio/` for a portfolio handled
`portfolio`, `templates/case-studies/` for `caseStudies`:

| File | Rendered for |
|---|---|
| `index.twig` | `/portfolio` — every item, with the filter bar |
| `_entry.twig` | Each item — the section's entry template |
| `category.twig` | `/portfolio/category/<slug>` — the category group's template |
| `tag.twig` | `/portfolio/tag/<slug>` — see [Tag archives](#tag-archives) |
| `_partials/card.twig` | One card, used by every list |
| `_partials/filters.twig` | The category filter bar |
| `_partials/styles.twig` | Just enough CSS to look like something on day one |

Files for parts the portfolio doesn't have are skipped: no tag group means no `tag.twig`, no
category group means no `category.twig` and no filter bar.

The item page shows the featured image, summary, client, completed date, categories, tags, project
URL, description and gallery, then a *More like this* strip from `craft.portfolio.related()` and
previous/next links. It also calls `craft.portfolio.jsonld(entry)` in the `<head>`, which renders
nothing until you are on [Pro with structured data on](grid#structured-data).

## None of them names a field

Every value is read by role:

```twig
{% set portfolio = craft.portfolio.of('portfolio') %}
{% set items = portfolio.items().all() %}

{% set client = entry|portfolioField('client') %}
{% set featured = (item|portfolioField('featuredImage')).one() ?? null %}
```

So renaming a field in the control panel cannot blank a page, and the same templates work
unchanged against a portfolio you [adopted](usage#adopting-a-section-you-already-built-pro). The
only things substituted when the files are written are the portfolio's name, handle and folder —
there is nothing per-field to fill in.

The card partial guards its one variable (`item ?? null`), so it renders cleanly with devMode's
strict variables on.

## Making them yours

The templates are standalone pages with their own `<!DOCTYPE html>`, because Portfolio can't know
what your layout is called. To fit them into your site:

1. Replace the `<!DOCTYPE html> … </html>` wrapper with `{% extends "_layout" %}` (or whatever
   yours is) and put the `<main>` in a block.
2. Delete the `{% include '…/_partials/styles' %}` line once your own stylesheet covers the
   `pf-` classes. Nothing else depends on it.

The styles partial respects `prefers-color-scheme`, and every colour is a custom property on
`:root` (`--pf-ink`, `--pf-muted`, `--pf-line`, `--pf-bg`) if you only want to retint it.

## Tag archives

Craft categories get URIs from their group's settings. Tags don't — there is nowhere to give a tag
a URI. So for every portfolio with a tag group, Portfolio registers a site route:

```
<template folder>/tag/<slug>   →   <template folder>/tag.twig
```

The route goes straight to the template, not through a controller, so the page is an ordinary
render that `{% cache %}` and static caching treat like any other. The template receives `slug`
from the URL and `portfolioHandle`, and looks the tag up itself:

```twig
{% set portfolio = craft.portfolio.of('portfolio') %}
{% set tag = portfolio.tags({ slug: slug }).one() %}

{% if not tag %}
    {% exit 404 %}
{% endif %}

{% set items = portfolio.items({ tag: tag }).all() %}
```

Link to a tag archive with `url('portfolio/tag/' ~ tag.slug)`.

To route tags yourself, turn off **Serve tag archives** in [Settings](configuration#general). To
render a different template, change **Tag archive template** — it is relative to the portfolio's
template folder.
