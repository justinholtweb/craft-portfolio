# Changelog

## 5.0.0 — unreleased

Initial release.

- Build a portfolio's whole content model in one go: section, entry type, fields, category group
  and tag group.
- Plan-before-build: every item is reported as create, reuse or conflict before anything is
  written, and a handle already taken by the right type is reused untouched.
- A role map, held by UID in project config, so templates survive field renames.
- `craft.portfolio.*` query API: `items`, `item`, `categories`, `tags`, `filters`, `related`,
  `next`, `prev`, plus the `portfolioField` filter.
- Starter front-end templates written into the site on request.
- Console: `portfolio/setup/build`, `status`, `templates`, `remove`.
- Removing a portfolio's content model deletes only what the plugin created. Anything the build
  reused is kept, and an adopted portfolio can only be forgotten, never removed.
- **Pro** — unlimited portfolios, adopting an existing section, `craft.portfolio.grid()` with
  client-side filtering and a lightbox, and schema.org `CreativeWork` JSON-LD.
