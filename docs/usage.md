---
title: Usage
slug: usage
order: 30
summary: The plan, roles, the craft.portfolio query API, adopting a section, the console, and removing a portfolio.
---

## The plan: create, reuse, conflict

Nothing is built until you have read what building will do. Every section, entry type, field and
group in the blueprint is checked against the site and reported as one of three things:

| | |
|---|---|
| **create** | Doesn't exist. Will be made. |
| **reuse** | Already exists with this handle and the right type. **Used exactly as it is** — its settings are not touched. |
| **conflict** | Already exists with this handle and the wrong type. The build stops. |

The rule behind it is *never edit what you did not create*. A `portfolioSummary` field the site has
been using for two years is never reshaped because a blueprint happened to want that handle. If it
is a Plain Text field, it is reused as it is; if it is anything else, that is a conflict, and the
plan tells you which type it found and which it wanted.

A few things are worth knowing about how each piece is judged:

- **Section** — an existing channel or structure with the handle is reused. An existing *Single*
  is a conflict, because a portfolio needs more than one entry.
- **Entry type** — an existing one is reused and its field layout is left alone. If it isn't
  already in the section, it is added to it.
- **Fields** — matched by handle and by exact field type.
- **Category and tag groups** — an existing group with the handle is reused.

Because *reuse* is a real outcome and not an error, building is safe to run twice. The second run
creates nothing and says so.

Some things block a build outright, before any item is looked at: an invalid handle, and an
environment where `allowAdminChanges` is off. Under Lite, a second portfolio is refused too — see
[Editions](installation#editions).

## Roles

Every field a portfolio builds plays a **role**. Templates ask for the role; Portfolio looks up
the field playing it at the moment it is asked.

| Role | Built as | Used for |
|---|---|---|
| `summary` | Plain Text, multi-line, 255 characters | Cards and archives |
| `description` | CKEditor, or Plain Text without it | The project write-up |
| `featuredImage` | Assets, images only, one | The image that represents the project |
| `gallery` | Assets, images only | More images on the project page |
| `client` | Plain Text | Who the work was for |
| `completedDate` | Date | When it was finished — channels sort on this |
| `projectUrl` | Link | The live work |
| `categories` | Categories | Archive URLs and filtering |
| `tags` | Tags | Looser grouping |

The role map is stored by field **UID**. Rename `portfolioClient` to `clientName` in the control
panel and every template keeps working, because none of them ever said `portfolioClient`.

Untick a role at build time and it simply isn't part of that portfolio. Everything that reads
roles skips a missing one — the starter templates, the grid and the structured data all render
without it rather than erroring.

## The query API

Everything in this section is in **Lite**.

### Items

```twig
{% set items = craft.portfolio.items({ category: 'branding', limit: 12 }).all() %}
```

`items()` returns an ordinary entry query, not results. So `.all()`, `.one()`, `.count()`,
`{% paginate %}`, eager-loading and `{% cache %}` all behave exactly as they do on any other entry
query.

Portfolio understands four criteria of its own:

| Criterion | Accepts |
|---|---|
| `category` / `categories` | A slug, an ID, a category, or a list of any of those |
| `tag` / `tags` | The same, for tags |
| `client` | A value for the field playing the `client` role |
| `portfolio` | Which portfolio to query (Pro, with more than one) |

Everything else is handed straight to the entry query under its own name, so `limit`, `offset`,
`search`, `with`, `orderBy`, `status` and the rest work as they always do:

```twig
{% set items = craft.portfolio.items({
    category: ['branding', 'web'],
    tag: 'award-winning',
    with: ['portfolioFeaturedImage'],
    limit: 9,
}).all() %}
```

A category filter and a tag filter together mean **both**, not either — which is what a filter UI
means when two things are selected.

Filtering by a category or tag that doesn't exist returns **nothing**, not everything. An archive
for a deleted category showing every project would be worse than showing none.

### Default order

- A **structure** section keeps whatever order the author dragged it into. Portfolio deliberately
  sets no `orderBy`, because setting one would replace the structure's order and silently discard
  the author's arrangement.
- A **channel** orders by the `completedDate` role, newest first, then by post date. Without a
  completed date it orders by post date.

Pass your own `orderBy` and it wins.

### One item

```twig
{% set project = craft.portfolio.item('northwind-rebrand') %}
```

### Categories, tags and filters

```twig
{% set categories = craft.portfolio.categories().all() %}   {# this portfolio's group only #}
{% set tags = craft.portfolio.tags({ limit: 20 }).all() %}
```

Both return ordinary element queries scoped to the portfolio's group, enabled elements only.

`filters()` returns only the categories that actually have items, with a count for each — a whole
filter bar in one call, and one relations query rather than one count per category:

```twig
{% for filter in craft.portfolio.filters() %}
    <a href="{{ filter.category.url }}">
        {{ filter.category.title }} ({{ filter.count }})
    </a>
{% endfor %}
```

It takes the same criteria as `items()`, so the counts can match a narrowed list.

### Related, next and previous

```twig
{% for item in craft.portfolio.related(entry, 3) %}…{% endfor %}

{% set prev = craft.portfolio.prev(entry) %}
{% set next = craft.portfolio.next(entry) %}
```

`related()` ranks other items by **how many** categories and tags they share with this one, most
shared first — not merely whether they share any. `next()` and `prev()` follow the portfolio's
default order, so in a structure they follow the order the author dragged.

All three work out which portfolio the entry belongs to from its section, so on a site with
several portfolios a related strip never answers from the wrong one.

### Reading a role on an entry

```twig
{{ entry|portfolioField('client') }}
{% set image = (entry|portfolioField('featuredImage')).one() %}

{# the same thing, as a function or a variable call #}
{{ portfolioField(entry, 'client') }}
{{ craft.portfolio.field(entry, 'client') }}
```

Each returns whatever the field returns — a string, a date, an asset query — or `null` when the
portfolio has no field for that role. That `null` is what lets a template written against the full
field set render against a trimmed-down portfolio.

When you need the handle itself, to build your own query:

```twig
{% set handle = entry|portfolioHandle('client') %}
{% set handle = craft.portfolio.handleFor('client') %}
{% set acme = craft.entries({ section: 'portfolio', (handle): 'Acme' }).all() %}
```

### Asking about the portfolio

```twig
{% if craft.portfolio.exists() %}…{% endif %}          {# is there a portfolio at all? #}
{% if craft.portfolio.has('gallery') %}…{% endif %}    {# does it have a field for this role? #}
{% for role in craft.portfolio.roles() %}…{% endfor %} {# every role name, in canonical order #}
{% set portfolio = craft.portfolio.current() %}        {# the portfolio in play #}
{% for portfolio in craft.portfolio.all() %}…{% endfor %}
```

## Several portfolios (Pro)

Lite manages one portfolio, so nothing ever has to name it. Pro manages as many as you like —
*Work*, *Case Studies*, *Illustration* — each with its own section, fields and groups, named from
its own handle so they never collide.

Name the one you mean with `of()`:

```twig
{% set work = craft.portfolio.of('caseStudies') %}
{% for item in work.items({ limit: 6 }).all() %}…{% endfor %}
{{ work.filters()|length }}
```

`of()` narrows everything that follows it. Without it, `craft.portfolio.*` means the
[default portfolio](configuration#general). Most methods also take a `portfolio` argument or
criterion if you would rather pass it inline.

## Adopting a section you already built (Pro)

A site that built its portfolio by hand years ago can have the query API, the grid and the
structured data without rebuilding anything.

1. Go to **Portfolio → Portfolios → Adopt a section**.
2. Pick the **Section** and the **Entry type** that holds portfolio items.
3. Give it a **Handle** (what templates pass to `of()`) and a **Template folder**, or leave them
   blank to use the section's own. The handle must be a valid handle and not already used by
   another portfolio; the template folder must be a path inside `templates/`, like `work` or
   `work/projects`.
4. Under **Roles**, point each role at one of your existing fields. Leave a role at — if you don't
   have a field for it.

No content model is created and nothing is edited: an adopted portfolio *is* the role map. If you
point the `categories` or `tags` role at a relation field, Portfolio works out which group it
draws from, so filters and archives work too.

Only channels and structures can be adopted, and a section can only belong to one portfolio.

## Drift

A portfolio points at real sections and fields, and those can be deleted underneath it. Portfolio
checks on the Portfolios index, on each portfolio's page and in `portfolio/setup/status`, and
reports:

- the section, entry type, category group or tag group no longer existing
- the field for a role having been deleted

A deleted field is the single most likely reason a portfolio template goes quietly blank, so the
Portfolios index flags it under **Needs attention**, and `status` exits non-zero — usable as a
deploy check.

A *renamed* field is not drift. The role map holds the UID, so it follows the rename.

## Console commands

```sh
php craft portfolio/setup/build [handle] [options]
php craft portfolio/setup/status
php craft portfolio/setup/templates [handle] [--force=1]
php craft portfolio/setup/remove [handle] --confirm=<handle>
```

### `build`

Plans, prints the plan, and builds. The handle defaults to `portfolio`.

| Option | Default | |
|---|---|---|
| `--name` | Title-cased handle | Display name — `caseStudies` becomes *Case Studies* |
| `--type` | `structure` | `structure` or `channel` |
| `--noTaxonomy` | off | Skip the categories and tags roles, and their groups |
| `--dryRun` | off | Print the plan and stop |
| `--templates` | off | Write the starter templates after building |
| `--force` | off | With `--templates`, overwrite templates that already exist |

It exits non-zero on any conflict or blocker, so a deploy script can rely on it. Run against a
portfolio that is already built, it prints *Already built. Nothing to do.*

### `status`

Lists every portfolio with its section, entry type, groups, template folder and the current
handle behind each role, and reports [drift](#drift). Exits non-zero if anything has drifted.

### `templates`

Writes the [starter templates](templates) for a portfolio — the default one if you name none.
Existing files are left alone unless you pass `--force=1`.

### `remove`

See below.

## Removing a portfolio

There are two very different things you might mean, and Portfolio keeps them apart.

**Forget it.** On the portfolio's page in the control panel, **Forget this portfolio** drops the
role map and nothing else. The section, fields, groups and every entry stay exactly where they
are, as ordinary Craft content. This is almost always what you want.

**Remove the content model.** This deletes what Portfolio created for the portfolio: the section —
and because Craft cascades, **every entry in it** — plus the entry type, the category and tag
groups and their contents, and each field unless another field layout is using it. Before anything happens you are shown the counts, and you
have to type the portfolio's handle back:

```sh
php craft portfolio/setup/remove portfolio
# …prints what will be deleted, then asks you to type "portfolio"

php craft portfolio/setup/remove portfolio --confirm=portfolio   # for scripts and deploys
```

`--confirm` exists because Craft turns interactive prompts off whenever there is no terminal, and
a command that can only be confirmed at a prompt is unusable from automation. It is the same typed
confirmation in a form a script can give — it still cannot happen by accident.

Removal only deletes what Portfolio **created**. The build records the UID of everything its plan
marked *create*, and anything it *reused* — a section, group or field the site already had — is
kept and listed as kept, with its settings and content untouched. The counts you are shown before
confirming are for the created items only.

One consequence: forgetting a portfolio forgets that record too. If you forget a portfolio and
build it again, the rebuild finds everything already there and reuses it, so Portfolio no longer
considers any of it its own and Remove will keep all of it. Delete those by hand in **Settings**
if you really want them gone.

**Adopted portfolios can't be removed this way at all.** An adopted section is one the site built
by hand, often years of entries ago, so its content model is not the plugin's to delete: both the
control panel and `portfolio/setup/remove` refuse, and point you at *Forget* instead.

Starter templates on disk are never touched by either.
