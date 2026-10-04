---
title: FAQ
slug: faq
order: 70
summary: Pricing, what it builds, what it will never touch, and how it compares to doing it by hand.
---

## Is Portfolio free?

Lite is, and it is not a trial. It builds the whole content model, writes the starter templates,
and gives you the entire `craft.portfolio.*` query API and every console command — for one
portfolio. Pro is $49 with a $39/year renewal, and adds unlimited portfolios, adopting a section
you already built, `craft.portfolio.grid()`, and `CreativeWork` structured data.

## Why not just build the section and fields myself?

You can, and on a site with one portfolio and a developer who never renames anything, you lose
little. What you get from Portfolio is the part a hand-built section can't have: a **record of
what was built**. Because it knows which field plays *client* and which plays *featured image*,
your templates ask for roles instead of handles — so a rename in the control panel can't break
them, `related()` knows which fields to compare, and the grid and JSON-LD need no configuration.

The build itself is thirteen pieces of content model in the right order, with the category and
tag fields pointed at groups that were created a moment earlier. That's a tedious afternoon by
hand, and a one-line command here.

## Will it change a field I already have?

No. A field, section, entry type or group whose handle the blueprint wants is either **reused
exactly as it is** — if it is the right type — or reported as a **conflict** that stops the build.
Portfolio never edits the settings of anything it finds. The one change it will make to something
existing is adding its entry type to an existing section of the same handle, and the plan tells
you before it does.

## Is it safe to run the build twice?

Yes. The second run finds everything already there, reports it all as *reuse*, and creates
nothing.

## Does it work with a portfolio I built by hand years ago?

On Pro, yes — [adopt the section](usage#adopting-a-section-you-already-built-pro) and map your
existing fields to roles. Nothing is created or edited; the same templates, grid and JSON-LD then
work against it. And because the section was yours first, Portfolio will never delete an adopted
section's content model — you can only forget the portfolio.

## Can I rename the fields it creates?

Yes. The role map holds each field's UID, not its handle, so renames are followed automatically.
Rename the section, entry type or groups too, if you like.

## Can I add my own fields to the entry type?

Yes. It is an ordinary entry type and its field layout is yours. Portfolio doesn't know about
extra fields and doesn't need to — read them by handle as usual.

## Does it create database tables?

No. A portfolio lives in project config, next to the sections and fields it describes, and deploys
with them. There is no install migration and nothing to clean up.

## What happens if I uninstall it?

Your section, fields, groups and entries all stay — they are ordinary Craft content. Templates
using `craft.portfolio.*` or `portfolioField` stop working, so replace those with field handles
first.

## Does it write anything into my templates folder on its own?

Never. Starter templates are written only when you ask, and an existing file is never overwritten
without `--force` or the overwrite checkbox.

## Structure or channel?

A **structure** if the order of your work is a decision — your best project first. Authors drag
items into order, and Portfolio keeps that order everywhere. A **channel** if the order is
chronological; it sorts by completed date.

## Why does the grid filter in the browser instead of on the server?

So an archive page stays a single cacheable response. Server-side filtering means a query string
per filter and a cache entry per combination; a portfolio is tens or hundreds of items, which is
cheap to send whole. Category archives already give every category a real URL when you need one.

## Does it work with multiple sites?

Yes. When building, tick the sites the section should be enabled on — every site by default. The
query API returns the current site's entries, as any entry query does.

## Does it need CKEditor?

No. If CKEditor is installed and enabled, the description is a rich-text field; otherwise it is
multi-line plain text. You can also turn CKEditor off for the description when building.

## Which versions are supported?

Craft CMS 5.3+ and PHP 8.2+.
