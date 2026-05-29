# decoupled-wp-spark

The **WordPress half** of a decoupled WordPress + Astro stack — a
reusable starter for client redesign pilots. It pairs with
[`astro-spark`](../../nodejs/astro-spark/) (the public-facing Astro
frontend).

WordPress here is an **editor-only CMS**: it never serves public
traffic. Editors log in, manage content in simple Carbon Fields forms,
and the Astro frontend reads that content over WPGraphQL and renders a
fast, static, CDN-fronted public site.

It began as the distilled, generic version of the Human Technologies
pilot (`~/nodejs/humantechnologies-2026/` + the `ht-cms` DDEV project),
and has since become **config-driven**: a project's whole content model
(CPTs, taxonomies, fields, GraphQL, routes) is declarative JSON the
trusted plugin interprets at runtime — no per-project PHP. That makes it
deployable to a **no-custom-code host** like decoupled.io. See
[*Config-driven content model*](#config-driven-content-model) below.

## What's in the box

Bedrock-style WordPress, Composer-managed, DDEV for local dev, plus one
client-owned plugin — **`spark-core`** — that carries everything a
decoupled setup needs:

| Piece | File | What it does |
|---|---|---|
| Carbon Fields boot | `carbon-fields-bootstrap.php` | Boots Carbon Fields and fixes the asset-URL 404 that happens when CF is Composer-installed outside the web root |
| **Content-model engine** | `config/loader.php` | Loads, normalizes, and **validates** a content-model config, then caches it. The single source of truth every registrar reads. Invalid models are rejected and the site falls back to the default rather than fataling. |
| **Default + bundled models** | `config/defaults.php`, `config/bundled.php`, `models/*.json` | The built-in starter model, plus a bundled-JSON loader: `SPARK_CONTENT_MODEL=<name>` activates `models/<name>.json` (data, never PHP) |
| Registrars | `post-types.php`, `taxonomies.php`, `fields-page.php`, `graphql-extensions.php`, `config/routes.php` | Turn the config into WordPress CPTs, taxonomies, Carbon Fields containers, GraphQL types/fields, route templates, and revalidation paths |
| Body + components | `fields-body-block.php` | Body prose is the **standard WordPress editor** (`post_content`). Carbon Fields adds only structured extras — **never** a CF block per heading/paragraph. |
| Editor role | `roles.php` | A generic `content_editor` role |
| GraphQL | `graphql-extensions.php` | WPGraphQL object types (`SparkImage`, `SparkComponent`, `SparkTerm`, `SparkRef`, `SparkBlock`) + a whitelisted resolver library (see *Config-driven content model* below) |
| **Operator CLI** | `cli.php` | `wp spark model import / export / validate / use / list / reset` — load a model as data over SSH, no file edits |
| **Admin model screen** | `admin/content-model.php` | **Tools → Spark Content Model**: paste/edit the model JSON, validate-and-save. `manage_options` + nonce gated, 1 MB cap |
| Headless preview | `preview-links.php` | Rewrites WP "View" / "Preview" to the Astro frontend |
| Headless redirect | `headless-redirect.php` | Redirects public WP front-end hits to the Astro site |
| Revalidation | `revalidate.php` | On post save, pings the frontend to rebuild the changed page (paths from the config's `routes`) |
| Admin branding | `brand-admin.php` + `assets/css/` | Neutral admin/login branding scaffold (recolor via CSS custom properties) |
| Status widget | `dashboard.php` | A "Decoupled Status" wp-admin widget |
| Compliance Dashboard | `compliance-dashboard.php` + `templates/` | A wp-admin page that reads the frontend's a11y / SEO / performance audit JSON and shows score cards + per-page tables |

## Architecture

```
  Editors                          Public visitors
     │                                   │
     ▼                                   ▼
  WordPress (this repo)            Astro static site
  decoupled-wp-spark.ddev.site     <slug>.sparkdemo.cloud  (Vercel)
     │                                   ▲
     │  WPGraphQL  ───────────────────────┘
     │  (content + headless-preview login)
     │
     └─ on save → revalidate webhook → frontend rebuilds the page
```

WordPress is never the public site. `headless-redirect.php` makes that
literal — any public front-end request to WordPress bounces to the
Astro domain.

## Config-driven content model

A project's content model — CPTs, taxonomies, Carbon Fields containers,
GraphQL fields, routes, revalidation — is **declarative data**, not PHP.
The trusted `spark-core` plugin interprets it at runtime. This is what
lets the WordPress side run on a **no-custom-code host** (decoupled.io):
you ship a model, not a plugin fork.

**Nothing in a model is executable.** Field types, GraphQL resolvers,
route tokens, and field presets are closed whitelists. A model that
references anything outside them fails validation and is rejected.

### Capability surface

**Field types:** `text` · `textarea` · `rich_text` · `image` · `file` ·
`checkbox` · `select` · `radio` · `multiselect` · `number` · `color` ·
`date` · `time` · `date_time` · `complex` (repeater, incl. nested) ·
`association` (relate to posts / terms / users) · plus presets
(`introParagraphs` · `components` · `galleryImages`).

**Field options:** `required` · `default` · `help_text` · `width` ·
`rows` · `value_type` · `storage_format` · `options` (select/radio/
multiselect) · `min`/`max`/`step` (number) · `relate`/`postTypes`/
`taxonomies`/`max` (association).

**GraphQL resolvers** (whitelisted — config supplies meta keys, never
callbacks):

| Resolver | Returns | Use |
|---|---|---|
| `bodyHtml` | String | rendered `post_content` (the_content) |
| `meta` / `rawMeta` | String | a Carbon / raw meta value |
| `metaInt` / `metaBool` | Int / Boolean | typed scalar (`metaBool` takes a `truthy` token, e.g. `'yes'`) |
| `metaList` | [String] | a `set`/multiselect value |
| `metaStringList` / `metaIntList` | [String] / [Int] | pluck one `subKey` from each complex row |
| `metaComplexJson` | [String] | each complex row as JSON |
| `heroImage` | SparkImage | `{src, alt}` from two meta keys |
| `introParagraphs` / `galleryImages` / `components` | lists | the presets |
| `metaDescription` | String | excerpt fallback (SEO) |
| `catalogSlug` | String | routing-slug override |
| `terms` | [SparkTerm] | a taxonomy's terms (`{slug, name}`) |
| `relatedPosts` | [SparkRef] | an `association` value (`{id, slug, title}`) |
| `variantMap` | [SparkBlock] | the **kind-discriminated complex normalizer** — body blocks / components / page sections. Config declares per-`_type` variants + a slot map (with field rename and `imageList`/`stringList`/`html` transforms). |

**GraphQL fields** can be `shared` (registered on every type via
`graphql.fields`) or **per-CPT** via `graphql.postTypeFields` keyed by
GraphQL type name.

> The whitelist was set from the field types + resolver patterns used
> across five real engagements (HT, NMSF, esplanade, gfparks, YSAQMD).
> If a *new* project needs something outside it, add the one named
> resolver or field type to `spark-core` (PHP, once, in a release) —
> every project then gets it via config. You cannot patch a model with
> code on the host, so add the primitive before it ships.

### Loading a model (three host-safe paths)

All three funnel through the same validation; an explicitly imported
model wins over the env default, which wins over the built-in default.

1. **Admin** — Tools → *Spark Content Model*: paste/edit JSON, save.
2. **WP-CLI** (scriptable for provisioning):
   ```bash
   wp spark model export --pretty > model.json   # dump active model
   wp spark model validate model.json            # dry-run
   wp spark model import model.json              # validate + store + flush
   wp spark model use ysaqmd                     # activate a bundled model
   wp spark model list                          # bundled model names
   wp spark model reset                         # back to the default
   ```
3. **Bundled JSON** — drop `models/<name>.json` in the plugin and set
   `SPARK_CONTENT_MODEL=<name>` (env or constant) as the provisioning
   default for a fresh instance.

See `docs/ysaqmd-parity-validation.md` for the full schema, a worked
model, and the parity validation against the bespoke YSAQMD plugin.

## Quick start

Requires [DDEV](https://ddev.com) and Composer.

```bash
cd ~/ddev/decoupled-wp-spark
ddev start
ddev composer install          # pulls WP core + plugins, copies CF assets
ddev wp core install \
  --url=https://decoupled-wp-spark.ddev.site \
  --title="Decoupled WP Spark" \
  --admin_user=admin --admin_password=admin \
  --admin_email=admin@example.com

# Activate the plugins. Carbon Fields is NOT a plugin — it's a library
# loaded via the Composer autoloader and booted by spark-core.
ddev wp plugin activate spark-core wp-graphql wp-graphql-jwt-authentication

# A fresh install defaults to plain permalinks; pretty permalinks are
# required for the GraphQL route and CPT URLs.
ddev wp rewrite structure '/%postname%/'
ddev wp rewrite flush

ddev launch wp-admin
```

The GraphQL endpoint is then at `/graphql` (e.g.
`https://decoupled-wp-spark.ddev.site/graphql`) — point the frontend's
GraphQL URL env var at it (`PUBLIC_WP_GRAPHQL_URL` in the Astro
`yolo-solano-aqmd-website` pilot; `SPARK_GRAPHQL_URL` in `astro-spark`).
The `/index.php?graphql` query-param form also works immediately if
pretty permalinks aren't flushed yet.

> **Note — `web/index.php`.** WordPress core lives in `web/wp/` but
> the web docroot is `web/`. The committed `web/index.php` front
> controller bridges that — it's required, don't delete it.

## Starting a new project from this starter

1. **Copy the directory** to `~/ddev/<issuer>-cms/` and rename in
   `.ddev/config.yaml` (`name:`), `wp-cli.yml`, and `composer.json`.
2. **Rename the plugin** `spark-core` → `<issuer>-core` if you want
   (optional — `spark-core` works fine as-is). If you rename, update
   the `SPARK_CORE_*` constants, the namespace `Spark\Core`, and the
   `copy-carbon-fields-assets` paths in `composer.json`.
3. **Define the content model** — author it as JSON (see *Config-driven
   content model* above) and load it via any of the three paths: the
   **Tools → Spark Content Model** screen, `wp spark model import`, or a
   bundled `models/<name>.json` + `SPARK_CONTENT_MODEL`. The trusted
   plugin interprets the config — a normal content model needs **no new
   PHP**. Only reach for code if the project needs a field type or
   resolver outside the whitelist, in which case add it to `spark-core`.
4. **Recolor** `assets/css/admin-brand.css` + `login-brand.css` — they
   use CSS custom properties; change the `--spark-*` values, drop a
   logo/favicon into `assets/brand/`.
5. **Point at the frontend** — set `SPARK_FRONTEND_URL` /
   `SPARK_AUDIT_URL` in `.ddev/config.yaml` and `.env`.

## Gotchas baked in (learned the hard way on HT)

- **Carbon Fields asset URL.** CF is Composer-installed at repo-root
  `vendor/` — above the web root — so its auto-detected asset URL is
  empty and meta boxes render blank. `carbon-fields-bootstrap.php`
  fixes this with `define('Carbon_Fields\URL', ...)` *before* `boot()`,
  pointing at a web-accessible copy. The `composer.json`
  `post-install-cmd` copies that copy into the plugin. Don't remove
  either half.
- **`set_attribute('readOnly', …)`** — camelCase. Carbon Fields v3.6
  rejects lowercase `readonly` with a "site is slightly misconfigured"
  notice.
- **Collapsed meta boxes look empty.** If a Carbon Fields container
  renders blank, check `closedpostboxes_*` user meta — a saved
  collapsed state, not a bug.

## Deploy notes

The starter is Pantheon-/Fly-/any-host agnostic. For the Simple Spark
pattern, the WordPress side runs on managed hosting (decoupled.io) and
the Astro side on Vercel. WordPress is IP-allowlisted or access-gated —
only editors and the build pipeline reach it.

## See also

- **`astro-spark`** (`~/nodejs/astro-spark/`) — the frontend half. Its
  README covers the content-source switch, the audit pipeline, and
  Vercel deploy.
- The `proposal-wp-astro` Claude Code skill in `~/nodejs/rfpbids/`
  documents when to use this stack and the full pilot methodology.
