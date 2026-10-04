---
title: The grid and structured data
slug: grid
order: 50
summary: "Pro: craft.portfolio.grid() with three layouts, a client-side filter bar and a lightbox, and CreativeWork JSON-LD."
---

Both of these are **Pro**. Everything they do can be built in Lite with
[`craft.portfolio.items()`](usage#the-query-api) and your own markup — the starter templates do
exactly that. Pro is for when you would rather not.

## The grid

```twig
{{ craft.portfolio.grid() }}

{{ craft.portfolio.grid({ layout: 'masonry', columns: 4, category: 'branding' }) }}
```

One tag renders a whole portfolio: every item as a card with its featured image, title, client and
summary, a category filter bar above it, and a lightbox over each item's images.

### Options

Anything you leave out falls back to the [grid defaults](configuration#grid-defaults-pro) in
Settings.

| Option | Default | |
|---|---|---|
| `layout` | `grid` | `grid` (even rows), `masonry` (CSS columns) or `list` (image beside text) |
| `columns` | `3` | 1 to 6 |
| `filterable` | `true` | Render the category filter bar |
| `lightbox` | `true` | Open the item's images in a lightbox when its card is clicked |
| `imageWidth` | `800` | Card image transform width in pixels; height is three quarters of it |
| `class` | — | Extra class on the grid's wrapper |
| `portfolio` | the default | Which portfolio, by handle |

And to narrow which items appear — the same meanings as in `items()`:

| Option | |
|---|---|
| `category` / `categories` | Slug, ID, category, or a list |
| `tag` / `tags` | The same, for tags |
| `client` | Value of the `client` role |
| `limit`, `orderBy`, `search` | Passed to the entry query |
| `criteria` | Any other entry-query criteria, as a hash |

```twig
{{ craft.portfolio.grid({
    portfolio: 'caseStudies',
    layout: 'list',
    tag: 'award-winning',
    limit: 6,
    filterable: false,
    criteria: { with: ['caseStudiesFeaturedImage'] },
}) }}
```

### Filtering happens in the browser

Every item is rendered on the server with its category slugs on the element. Pressing a filter
hides the cards that don't match; pressing it again, or **All**, shows everything.

That is a deliberate trade. Filtering on the server means a query string per filter and a cache
entry per combination. Filtering in the browser means an archive page stays one response, which
`{% cache %}`, static caching and a CDN can all hold. Portfolios are tens or hundreds of projects,
not tens of thousands, so sending them all is cheap.

The filter bar only shows categories that have items in this grid, each with its count. Buttons
carry `aria-pressed`, and a visually hidden live region announces the new count after each change,
so a filter that empties the grid is never silent.

If you need a real URL per category — for sharing or for search engines — those already exist:
they are the category archives at `/portfolio/category/<slug>`.

### The lightbox

With the lightbox on, clicking a card whose item has images opens them — the featured image first,
then the gallery — instead of following the link. Escape closes it, the arrow keys move between
images, and focus is kept inside it while it is open. A card with no images still links to its
item page.

If you want every card to go to its item page, pass `lightbox: false` or turn off **Lightbox on
by default**.

### CSS and JS

The grid's CSS and its small runtime are **inlined into the grid's own markup**, once per request
however many grids the page has. They are not registered through Craft's view, because
`registerCss()` and `registerJs()` only reach the page if the layout calls `{{ head() }}` and
`{{ endBody() }}` — and plenty of real layouts don't. A grid whose styles silently fail to arrive
on some sites is worse than one that carries them.

The CSS is scoped under `.pf-grid` and takes its colours from `currentColor`, so it inherits your
text colour in light and dark themes alike. Retint it with custom properties on `.pf-grid`:

```css
.pf-grid {
    --pf-grid-gap: 2rem;
    --pf-grid-radius: 0;
    --pf-grid-muted: #6b7280;
    --pf-grid-line: #e5e7eb;
}
```

To ship the CSS in your own stylesheet instead, turn off **Inline the grid CSS and JS**. The markup
and class names stay the same. The source is `src/resources/portfolio.css` and `portfolio.js` in
the plugin.

### Grids added after the page loads

The runtime wires up every grid on `DOMContentLoaded`. For a grid inserted later — by htmx,
Turbo, Sprig or a live preview — call it again on the new content:

```js
window.PortfolioGrid.init(container);
```

Grids already wired are skipped, so calling it twice is harmless.

### On Lite

`craft.portfolio.grid()` renders an HTML comment explaining that the grid needs Pro — invisible on
a live page. With `devMode` on it renders a visible dashed note instead, so you aren't left
wondering why the tag output nothing.

## Structured data

```twig
<head>
    …
    {{ craft.portfolio.jsonld(entry) }}
</head>
```

Emits a schema.org `CreativeWork` for one portfolio item. The starter `_entry.twig` already calls
it. Two things must both be true for it to output anything: **Pro**, and **Emit schema.org
CreativeWork** turned on in [Settings](configuration#structured-data-pro). Otherwise it renders an
empty string, so it is safe to leave in a template.

Called with no argument on an item page, it uses the entry Craft matched for the URL.

### What maps to what

| schema.org | From |
|---|---|
| `name` | The entry title |
| `url` | The entry URL |
| `description` | The `summary` role, as plain text |
| `datePublished` / `dateModified` | The entry's post date and last update |
| `dateCreated` | The `completedDate` role |
| `image` | Every image in `featuredImage` and `gallery` |
| `genre` | Category titles |
| `keywords` | Tag titles, comma-separated |
| `sameAs` | The `projectUrl` role |
| `sourceOrganization` | The `client` role, as an `Organization` |

`sourceOrganization` is schema.org's *"the organization on whose behalf the creator was
working"* — which is exactly what a client is.

Empty values are left out rather than emitted blank, and a role the portfolio doesn't have is
simply skipped. `<`, `>` and `&` in your content are written as `\u003C`-style escapes, so a
title containing `</script>` cannot close the tag early — JSON parsers read them back as the
original characters.

### Emitted from the template, on purpose

Portfolio could inject this into every item page's response. It doesn't, because that means
rewriting a body that is already rendered, restamping `content-length`, and taking care never to
touch an RSS template that happens to live in the same section. An item page is a template you
already own; one line in its `<head>` is simpler and lands inside whatever caching the page has.

### Merging into your own graph

If your site already emits a `@graph` and you want the portfolio item inside it rather than in a
second script tag, ask for the data as an array:

```twig
{% set work = craft.app.plugins.getPlugin('portfolio').jsonld.data(entry) %}
```

It returns `null` under the same conditions that make `jsonld()` render nothing.
