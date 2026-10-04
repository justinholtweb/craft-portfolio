---
title: Installation
slug: installation
order: 10
summary: Requirements, editions, install, and building your first portfolio in one command.
---

## Requirements

- Craft CMS 5.3 or later
- PHP 8.2 or later

That is the whole list. Portfolio has no build step and no runtime dependencies beyond Craft's
own. If [CKEditor](https://plugins.craftcms.com/ckeditor) is installed, the description field is
built as a rich-text field; if it isn't, it is built as multi-line plain text. CKEditor is a
suggestion, never a requirement.

## Install

```sh
composer require justinholtweb/craft-portfolio
php craft plugin/install portfolio
```

Or find **Portfolio** in the Craft Plugin Store and install it from there.

Installing creates nothing. No section, no fields, no tables, no templates — a portfolio is built
when you ask for one, after you have read what building it will do.

## Your first portfolio, from the command line

```sh
php craft portfolio/setup/build --templates=1
```

That prints the plan, builds it, and writes the starter templates into `templates/portfolio/`:

```
Portfolio “Portfolio” (portfolio)
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

  13 to create
```

Add some entries to the new **Portfolio** section and `/portfolio` renders them.

To see the plan without building anything, add `--dryRun=1`. Every option is covered in
[Usage](usage#console-commands).

## Your first portfolio, from the control panel

1. Go to **Portfolio → Portfolios** and choose **Build a portfolio**.
2. Give it a **Name** and a **Handle**. Everything is named from the handle: `work` gives you a
   `work` section, a `workItem` entry type and a `workClient` field.
3. Pick a **Section type**. A structure lets an author drag projects into order; a channel orders
   them by date.
4. Untick any **Fields** you don't want. Unticking *Categories* or *Tags* skips that group too.
5. Choose the **Image volume** for the featured image and gallery fields.
6. Check the **URLs and templates** — they are worked out from the handle, and you can change them.
7. Press **Show me the plan**. Nothing is written yet.
8. Read the plan. If it is right, press **Build it**.

## Before you build: `allowAdminChanges`

Building a portfolio writes sections and fields, which means writing project config. Craft only
allows that where `allowAdminChanges` is on, and only for admins. So build in development, commit
`config/project/`, and deploy — exactly as you would a section you made by hand. On an environment
with admin changes off, the plan says so and the build button never appears.

## Editions

Lite is free and is not a trial. Pro is $49, with a $39/year renewal.

**Lite is the content model and the queries. Pro is presentation and scale.**

| | Lite | Pro |
|---|:--:|:--:|
| **Price** | **Free** | **$49**, $39/year renewal |
| Build the content model, plan first | ✓ | ✓ |
| Number of portfolios | 1 | unlimited |
| Starter templates written into the site | ✓ | ✓ |
| The whole `craft.portfolio.*` query API | ✓ | ✓ |
| `portfolioField` / `portfolioHandle` filters | ✓ | ✓ |
| Tag archive routes | ✓ | ✓ |
| Console: `build`, `status`, `templates`, `remove` | ✓ | ✓ |
| **Adopt a section you already built** | — | ✓ |
| **`craft.portfolio.grid()`** — three layouts, filter bar, lightbox | — | ✓ |
| **schema.org `CreativeWork` JSON-LD** | — | ✓ |

Everything the grid does can be done in Lite with `craft.portfolio.items()` and your own markup —
the starter templates do exactly that. Pro is for when you would rather not write it.

Switching editions changes nothing in your content model. A Pro site that drops back to Lite keeps
every portfolio it built; it just cannot build another one, and the grid and JSON-LD stop rendering.

## Uninstalling

Uninstalling deletes nothing Portfolio built. The section, entry type, fields, category and tag
groups and every entry stay exactly where they are, as ordinary Craft content, and the starter
templates on disk are yours and are left alone too. What stops working is the plugin's own Twig —
`craft.portfolio.*` and the `portfolioField` filter — so swap those for plain field handles before
you uninstall from a live site.

If you actually want the content model gone, that is a separate, deliberate act — see
[Removing a portfolio](usage#removing-a-portfolio).
