---
title: Configuration
slug: configuration
order: 20
summary: Every setting, the config file, permissions, and where a portfolio is stored.
---

Settings live at **Settings → Plugins → Portfolio** (or **Portfolio → Settings** for admins). None
of them are required: a fresh install works with every one left at its default.

## General

| Setting | Default | What it does |
|---|---|---|
| **Default portfolio** (`defaultPortfolio`) | *The first one* | Which portfolio `craft.portfolio.*` means when a template names none. Under Lite there is only one, so this never matters. Takes a portfolio handle. |
| **Serve tag archives** (`tagArchives`) | On | Registers a `<template folder>/tag/<slug>` route for every portfolio with a tag group. See [Tag archives](templates#tag-archives). |
| **Tag archive template** (`tagTemplate`) | `tag` | The template, relative to the portfolio's template folder, that the tag route renders. |

If the configured default portfolio is later forgotten or renamed, Portfolio falls back to the
first one rather than to nothing.

## Grid defaults (Pro)

These are the defaults for `craft.portfolio.grid()`. Every one can be overridden per call — see
[The grid](grid).

| Setting | Default | What it does |
|---|---|---|
| **Layout** (`gridLayout`) | `grid` | `grid` (even rows), `masonry` (CSS columns) or `list` (image beside text). |
| **Columns** (`gridColumns`) | `3` | 1 to 6. |
| **Card image width** (`gridImageWidth`) | `800` | Pixel width of the card image transform, 100–4000. Height is three quarters of it. |
| **Filtering on by default** (`gridFilterable`) | On | Render the category filter bar. |
| **Lightbox on by default** (`gridLightbox`) | On | Open an item's images in a lightbox instead of following the card link. |
| **Inline the grid CSS and JS** (`gridAssets`) | On | Turn off if you would rather copy the CSS into your own stylesheet. The markup and class names are identical either way. |

## Structured data (Pro)

| Setting | Default | What it does |
|---|---|---|
| **Emit schema.org CreativeWork** (`jsonLd`) | Off | Lets `craft.portfolio.jsonld()` output anything. With it off, the call renders an empty string — so the starter item template can call it unconditionally. |

It is off by default, so a site that already emits its own structured data does not find a second
description of every project appearing on upgrade. Turn it on once you have decided Portfolio
should be the one describing them.

## The config file

Like any Craft plugin, every setting can be pinned per environment in `config/portfolio.php`,
which overrides whatever is saved in the control panel:

```php
<?php

return [
    'defaultPortfolio' => 'caseStudies',
    'tagArchives' => true,
    'tagTemplate' => 'tag',
    'jsonLd' => true,
    'gridLayout' => 'masonry',
    'gridColumns' => 4,
    'gridImageWidth' => 900,
    'gridFilterable' => true,
    'gridLightbox' => true,
    'gridAssets' => true,
];
```

## Permissions

Portfolio adds two, under **Portfolio** in the user group permissions:

- **View portfolios** — see the Portfolio section of the control panel, each portfolio's role map
  and any drift.
- **Build and remove portfolios** — nested under it. Writing the starter templates from the
  control panel needs this **and** an admin account, because it writes into (and with overwrite
  on, over) the site's own template files. It doesn't need `allowAdminChanges` — templates aren't
  project config.

Building, adopting, forgetting or removing a portfolio additionally needs an **admin** account on
an environment with **`allowAdminChanges`** on, because all four change project config. Craft
enforces that for sections and fields anyway; Portfolio checks it up front so the refusal comes on
the first screen rather than four screens later.

## Where a portfolio is stored

In project config, and nowhere else:

```yaml
portfolio:
  portfolios:
    72c3979c-…:
      handle: portfolio
      name: Portfolio
      section: 72c3979c-…      # the section
      entryType: c932c6e4-…    # the entry type
      categoryGroup: 5d1e…     # optional
      tagGroup: 9b0a…          # optional
      templateRoot: portfolio
      sectionType: structure
      adopted: false
      sortOrder: 0
      roles:
        client: 29baa693-…     # the field playing "client"
        summary: 89545e44-…    # the field playing "summary"
        # …
```

Everything is referenced **by UID**, which is why renaming a field, a section or a group in the
control panel cannot break a portfolio. There are no database tables and no install migration: a
portfolio deploys with the sections and fields it describes, through the same `craft up` that
deploys them.

One consequence: project config sorts its keys, so the roles come back alphabetically however
they were written. The control panel and `portfolio/setup/status` always list them in their
canonical order — summary, description, featured image, gallery, client, completed date, project
URL, categories, tags.
