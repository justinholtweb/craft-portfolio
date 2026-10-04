---
title: Troubleshooting
slug: troubleshooting
order: 60
summary: Conflicts, blank templates, missing build buttons, grids that won't filter, and what to check first.
---

## Start with `status`

```sh
php craft portfolio/setup/status
```

It prints every portfolio, the handle currently behind each role, and anything that has drifted.
It exits non-zero when something is wrong. Most of the answers below begin with what it says.

## The plan shows a conflict

A conflict means a handle the blueprint wants is already taken by something of the **wrong type**
— a `portfolioClient` that is a Dropdown, say, where the blueprint wants Plain Text. Portfolio will
not change that field's type or settings, because it did not create it and the site may depend on
it. The plan's note says which type it found and which it wanted.

You have three ways out:

- **Pick a different handle** for the portfolio. Every field is named from it, so `work` asks for
  `workClient` instead and the collision disappears.
- **Rename the existing field** in the control panel, then re-plan.
- **Untick that role** if you don't need it.

A Single section with the portfolio's handle is a conflict too: a portfolio needs a channel or a
structure.

## There is no "Build it" button

The plan renders, but only **Re-plan** is offered. One of these is true:

1. **There is a conflict** — the plan lists it in red.
2. **`allowAdminChanges` is off** in this environment. The plan says so at the top. Build in
   development and deploy the project config.
3. **You are on Lite and already have a portfolio.** Lite manages one. The index page says so, and
   a build with a new handle is refused.

## "Build a portfolio" or "Adopt a section" is missing, or forbidden

- **Build a portfolio** disappears on Lite once a portfolio exists. Lite manages one.
- **Adopt a section** needs Pro, and only appears when there is a channel or structure not
  already claimed by a portfolio.
- If the button is there but the page is forbidden, you are missing one of the three things that
  changing a content model needs: an **admin** account, the **Build and remove portfolios**
  permission, and `allowAdminChanges`.

## A template has gone blank

Almost always a deleted field. `status` shows `— field deleted —` against the role, and the
Portfolios index lists the portfolio under **Needs attention**.

`entry|portfolioField('client')` returns `null` when the portfolio has no field for a role, which
is what lets templates survive a trimmed-down portfolio — but it also means a deleted field fails
quietly. Either recreate a field of the right type and point the role at it, or
[adopt](usage#adopting-a-section-you-already-built-pro) the section again with the new field
mapped (Pro).

A *renamed* field is never the cause. The role map is keyed by UID and follows the rename.

## `portfolioField` returns null on an entry that has the field

The filter works out which portfolio an entry belongs to from the entry's **section**. An entry in
a section that isn't a portfolio — a page that relates to projects, say — has no role map, so it
returns `null`. Read the value off the portfolio item itself, or use the field handle directly on
entries outside a portfolio.

## The archive is in the wrong order

- In a **structure**, items come out in the order they are arranged in the section — drag them in
  the control panel. Portfolio deliberately sets no `orderBy` for structures.
- In a **channel**, items come out newest *completed date* first, then by post date. An item
  with no completed date can land somewhere surprising; give every item one, or pass your own
  `orderBy`.
- If you pass `orderBy` yourself, yours wins — including over a structure's order.

## A category archive shows nothing

Filtering by a category that doesn't exist returns nothing, never everything. Check the slug, and
check that the category is **enabled**: disabled categories aren't part of the portfolio's
taxonomy.

## `/portfolio/tag/<slug>` is a 404

Check, in order:

1. **Serve tag archives** is on in Settings.
2. The portfolio has a **tag group**. The route is only registered for portfolios that have one.
3. `templates/<folder>/tag.twig` exists — or whatever **Tag archive template** names. Write the
   starter templates if it doesn't.
4. The tag exists and is enabled. The starter template returns a 404 for an unknown slug on
   purpose.

## "The template root must be a path inside the templates folder"

The template folder — on the build screen or when adopting — is a path relative to `templates/`:
letters, numbers, `-` and `_`, with `/` between segments. `work` and `work/projects` are fine;
`/work`, `../work` and `work/` are not.

## Adopting fails on the handle

An adopted portfolio's handle must be a valid handle (a lowercase letter, then letters and
numbers) and not already used by another portfolio. Leave it blank to use the section's handle.

## "Not the plugin's to remove"

You tried to remove the content model of an **adopted** portfolio. That is refused on purpose —
the section was there before Portfolio, and so were its entries. Use **Forget this portfolio**: the
role map goes, the section stays.

## `templates` says "Already exists — left alone"

That is the protection working. Starter templates never overwrite your files unless you pass
`--force=1`, or tick **Overwrite files that already exist** on the portfolio's page. Forcing
replaces your edits.

## The write-templates button is forbidden

Writing templates from the control panel needs an **admin** account as well as **Build and
remove portfolios**. Or run `php craft portfolio/setup/templates` instead.

## "The templates directory is not writable"

Nothing was written. Fix the permissions on `templates/`, or write the templates in development
and commit them — which is where they belong anyway.

## The grid renders nothing (Pro)

- **On Lite**, the grid renders an HTML comment. View the source; it says so. With `devMode` on it
  is a visible note.
- **No portfolio** — the comment says *no portfolio to render*. On a site with several, check the
  `portfolio` option or the **Default portfolio** setting.
- **Criteria that match nothing** — a category slug that doesn't exist yields an empty grid and a
  *Nothing here yet* message.

## The grid is unstyled, or filters and lightbox do nothing (Pro)

- **Inline the grid CSS and JS** is off, and your own stylesheet doesn't cover the `pf-grid`
  classes.
- The grid was **inserted after the page loaded** (htmx, Turbo, Sprig). Call
  `window.PortfolioGrid.init(container)` on the new content.
- A **Content-Security-Policy** without `'unsafe-inline'` blocks inlined `<style>` and `<script>`.
  Turn off inlining and serve `portfolio.css` and `portfolio.js` from your own build.

## Clicking a card opens a lightbox instead of the project (Pro)

That is the lightbox: on a card whose item has images, it takes over the click. Pass
`lightbox: false`, or turn off **Lightbox on by default**.

## No JSON-LD in the page source (Pro)

`craft.portfolio.jsonld()` outputs nothing unless both of these are true:

1. The plugin is on **Pro**.
2. **Emit schema.org CreativeWork** is on in Settings. It is **off** by default.

Then check the call is actually in the item template's `<head>` — the starter `_entry.twig` has
it — and that the entry is in a portfolio section.

## Pro features stopped after a deploy

The plugin's edition lives in project config. If a deploy re-applied `config/project/` with the
edition set to Lite, the grid and JSON-LD stop and a second portfolio can't be built. Existing
portfolios keep working — nothing in the content model depends on the edition.

## A change made by a script didn't stick

If you drive the builder from your own PHP rather than the console command or the CP, project
config writes are buffered until the request ends. In Craft 5,
`saveModifiedConfigData()` only updates the database; the YAML is written separately. Call
`Craft::$app->getProjectConfig()->flush()`, which does both — otherwise the next `craft up`
reverts the change from the untouched files. The console commands already do this.
