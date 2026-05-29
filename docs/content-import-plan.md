# Content Import Plan — `decoupled-wp`

## Context

`decoupled-wp` is the WordPress sibling of the Drupal `decoupled-project`
product. It will be a new repo cloned from `decoupled-wp-spark`, paired
later with its own Astro starter (the WP analogue of
`spark-astro-starter`).

The two CMS products should reach **functional parity**. Today they are
close on *model* but not on *content*:

| Capability | Drupal (`dc_core`) | WP (`spark-core`) |
| --- | --- | --- |
| Model/schema as JSON | profile config | ✅ `wp spark model import` |
| **Content as JSON** | ✅ `dc_import` (`DrupalContentImporter`) | ❌ **missing — this plan** |
| GraphQL exposure of model | ✅ GraphQL Compose | ✅ WPGraphQL + `graphql-extensions.php` |
| Audit compliance dashboard | `dc_dashboard` | `spark-core` compliance-dashboard |

The gap: WordPress can be *told its shape* (CPTs, taxonomies, Carbon
Fields, GraphQL, routes — all already config-driven in `spark-core`),
but there is no way to **load actual content** — pages, posts, terms,
media, field values, components — from a JSON file the way Drupal's
`dc_import` ingests a `{model, content}` envelope.

This plan defines a WP-native content-import system for `spark-core`
that covers the same cases as `dc_import`, reads naturally for
WordPress, and is idempotent + safe for a hosted (no-PHP-upload)
product.

## Goal

Add `wp spark content import <file.json>` (and the engine behind it) so
an operator or the dashboard/Make pipeline can populate a fresh WP
instance with real content from a single JSON document — the same step
that, on Drupal, is:

```bash
ddev drush php:eval "\Drupal::service('dc_import.importer')->importFile('/path/to/import.json');"
```

becoming, on WP:

```bash
ddev wp spark content import /path/to/content.json
```

## Non-Goals (v1)

- No arbitrary PHP / callbacks in the content file (same security
  boundary as the model system — data only).
- No migration of historic WP sites; this is for fresh provisioning +
  re-import.
- No media *upload* protocol beyond fetching a URL or referencing an
  already-present attachment (no multipart in v1).
- No revisioning of imported content (the model system has revisions;
  content import is fire-and-forget + idempotent).
- No bidirectional sync (WP → JSON export of content is a later nice-to-have).

## Relationship to the existing model system

Content import **depends on** the active model but does not replace it.
Order of operations on a fresh instance:

1. `wp spark model import model.json` (or a bundled model) — registers
   CPTs, taxonomies, Carbon Fields groups, GraphQL, routes.
2. `wp spark content import content.json` — creates the actual posts,
   terms, media, and writes field values **against** that model.

The importer reads the active model (`Spark\Core\Config\model()`) to
know each field's Carbon Fields key, type, and whether it is a complex
/ association / preset field — so it writes meta in the exact shape the
GraphQL resolvers already expect. **If a content file references a
field or post type not in the active model, that is a validation
error** (fail loud, like the model validator).

## Envelope — WP-native shape

Drupal's `dc_import` uses `{model, content}` with a terse field DSL
(`paragraph(bundle)[]`, `ref(node:bundle)`, `term(vocab)`). The WP
envelope keeps the same *information* but uses WP-idiomatic names so it
reads naturally for a WordPress developer and maps 1:1 onto WP concepts.

```json
{
  "version": 1,
  "content": {
    "terms": [
      {
        "taxonomy": "spark_topic",
        "slug": "accessibility",
        "name": "Accessibility",
        "parent": null,
        "description": ""
      }
    ],
    "media": [
      {
        "ref": "hero-home",
        "sourceUrl": "https://example.com/uploads/hero.jpg",
        "alt": "City hall at dusk",
        "title": "Hero — Home"
      }
    ],
    "posts": [
      {
        "ref": "page-home",
        "postType": "page",
        "status": "publish",
        "title": "Home",
        "slug": "home",
        "content": "<p>Standard WordPress editor HTML lives here.</p>",
        "excerpt": "",
        "parent": null,
        "menuOrder": 0,
        "terms": {
          "spark_topic": ["accessibility"]
        },
        "fields": {
          "spark_hero_image": { "media": "hero-home" },
          "spark_hero_alt": "City hall at dusk",
          "spark_intro_paragraphs": [
            "First intro paragraph.",
            "Second intro paragraph."
          ],
          "spark_components": [
            {
              "type": "richtext",
              "spark_richtext_body": "<p>A rich text component.</p>"
            },
            {
              "type": "cta",
              "spark_cta_label": "Apply now",
              "spark_cta_url": "/apply/"
            }
          ]
        }
      }
    ]
  }
}
```

### Why this shape

- **`ref`** — a file-local identity token (not a WP ID). Lets posts
  reference media and other posts by a stable name the author controls,
  resolved to real IDs during import. Mirrors how `dc_import` lets
  content reference paragraphs/nodes within one envelope.
- **`terms` at top level** — created/looked-up first so posts can
  attach them by slug. Idempotent by `(taxonomy, slug)`.
- **`media` at top level** — sideloaded first (URL → attachment) so
  posts reference them by `ref`. Idempotent by source URL hash stored
  in attachment meta.
- **`posts[].fields`** — keyed by the Carbon Fields meta key from the
  active model. The importer looks up each key's type in the model and
  routes to the right writer (scalar, image, complex/components,
  association, preset).
- **`postType`/`status`/`terms`/`parent`/`menuOrder`** — plain WP
  concepts, no translation needed.

### Field-value mapping (model type → WP write)

| Model field type | Content `fields` value | How it's written |
| --- | --- | --- |
| `text` / `textarea` / `rich_text` | string | `carbon_set_post_meta($id, $key, $str)` |
| `image` | `{ "media": "<ref>" }` or `{ "url": "..." }` | resolve to attachment ID; store per field `value_type` (id/url) |
| `checkbox` | bool | stored as Carbon bool |
| `select` | string (must be an allowed option) | validated against model options |
| `date` / `time` | ISO string | normalized to Carbon's expected format |
| `association` | `[{ "post": "<ref>" }]` or `[{ "term": "..." }]` | resolved to association value array |
| preset `introParagraphs` | `["p1", "p2"]` | complex rows, one per paragraph (matches `spark_make_intro_field`) |
| preset `galleryImages` | `["<ref>", ...]` | complex rows of image refs |
| preset `components` / `complex` | `[{ "type": "...", ...subfields }]` | complex rows keyed by `type`, subfields by their meta keys |

The mapping is **derived from the active model**, not hardcoded — the
importer asks the model for each field's shape (the same metadata
`fields-page.php` uses to *build* the Carbon Fields container) and
writes values to match. This is the WP analogue of Drupal's
`FieldTypeMapper`.

## Idempotency

Re-running the same import must not create duplicates. Identity keys:

- **terms**: `(taxonomy, slug)` — look up, else create.
- **media**: a `_spark_import_source` meta holding a hash of `sourceUrl`
  — look up an existing attachment with that hash before sideloading.
- **posts**: `(postType, slug)` by default; a `_spark_import_ref` meta
  records the file `ref` for traceability. Title dedup is
  case-insensitive (matches a gotcha learned on the Drupal side —
  `dc_import` lowercases the title key when checking for an existing
  node; build import data the same way).

On a second run, an existing post is **updated in place** (content,
fields, terms re-applied), not duplicated.

## Resolution order (single pass with a ref table)

1. Parse + validate envelope against the active model.
2. Upsert all `terms` → build `{taxonomy/slug → term_id}`.
3. Sideload all `media` → build `{ref → attachment_id}`.
4. Upsert all `posts` (first pass: create/find by slug → build
   `{ref → post_id}`), so cross-post associations can resolve.
5. Second pass over `posts`: write `content`, `terms`, and `fields`
   (now that all refs resolve), flush per-post.
6. Report: created/updated/skipped counts per type, plus any warnings.

## Validation (fail loud, like the model validator)

- Envelope matches `version`.
- Every `postType` exists in the active model (or is a core type:
  `page`, `post`).
- Every `fields` key exists on that post type in the active model.
- Every `terms` taxonomy exists in the active model and is attached to
  the post type.
- Every `media`/`post` `ref` referenced is defined in the envelope.
- `select` values are within the model's allowed options.
- `status` is a valid WP status.
- No HTML in fields the model marks as plain text (escape/strip).
- Component `type` exists in the model's component set.

Validation runs **before any writes**; a single error aborts the whole
import (transactional intent — partial imports are confusing on a
fresh provision). Later we can add `--continue-on-error` if needed.

## Surfaces

- **WP-CLI** (v1, this plan):
  - `wp spark content import <file.json|->` — validate + import
  - `wp spark content validate <file.json|->` — dry-run validate only
  - `wp spark content example` — print a minimal valid envelope (the
    WP analogue of Drupal's `get_import_example` MCP tool)
- **REST endpoint** (deferred to a follow-up, parity with Drupal's
  `POST /api/dc-import`): a locked `/wp-json/spark/v1/content-import`
  for the dashboard/Make to push content remotely, auth'd the same way
  the model-sync endpoint will be.

## Provisioning integration

The Fly tenant provisioner (`scripts/fly/` in the dashboard repo) runs
`drush site:install dc_core` for Drupal. The WP analogue will run the
WP install + `wp spark model use <bundled>` + optionally
`wp spark content import <seed>`. This plan only covers the
`spark-core` engine + CLI; wiring it into the WP provisioner is a
dashboard-side task once `decoupled-wp` has its own Fly image (separate
from this plan, mirrors how the Drupal provisioner is dashboard-side).

## File layout (in `spark-core`)

```text
web/app/plugins/spark-core/includes/
  content/
    importer.php       # Spark\Core\Content\Importer — the engine
    validator.php      # envelope validation against active model
    field-writers.php  # model-type → Carbon meta write strategies
    media.php          # idempotent URL sideload + ref table
    example.php        # canonical minimal envelope for `content example`
  cli.php              # add the `content` subcommands alongside `model`
```

`cli.php` already houses `wp spark model …`; add a `Content_Command`
class there (or a sibling file required from it) with `import`,
`validate`, `example`.

## Implementation phases

### Phase 1 — envelope + validator
- Define the v1 schema (this doc's shape).
- `validator.php`: validate against the active model. Pure, testable.
- `wp spark content validate` + `wp spark content example`.
- Exit: a hand-written envelope validates green against the bundled
  default model; bad type/taxonomy/ref fails with a clear path.

### Phase 2 — terms + media
- Idempotent term upsert.
- Idempotent media sideload (URL → attachment, hash-dedup).
- Exit: re-running creates no duplicate terms/attachments.

### Phase 3 — posts (core fields)
- Upsert posts by `(postType, slug)`; ref table; content/excerpt/
  parent/menuOrder/status; term attachment.
- Exit: pages + posts + a CPT import and re-import cleanly; terms
  attached.

### Phase 4 — field writers
- Scalar, image, checkbox, select, date/time.
- Complex/components + presets (`introParagraphs`, `galleryImages`,
  `components`) writing the exact meta shape `fields-page.php` builds.
- Association (post/term refs).
- Exit: a full page with hero, intro, components, gallery imports, and
  the existing WPGraphQL queries return the values (verify against the
  same fields the Astro starter will consume).

### Phase 5 — polish
- `wp spark content import` end-to-end (validate → all phases → report).
- DDEV smoke test: fresh install → model use → content import →
  GraphQL query → spot-check wp-admin.
- Backward-compat: importing nothing / empty arrays is a no-op.

## Test plan

- **PHP lint** every new file.
- **Validator unit cases**: valid envelope, unknown postType, unknown
  field key, unknown taxonomy, dangling ref, bad select option.
- **DDEV smoke** (mirrors the Drupal end-to-end we run on tenants):
  1. `wp spark model use <default>`
  2. `wp spark content import docs/examples/seed.json`
  3. `wp post list`, `wp term list spark_topic` show imported rows
  4. WPGraphQL query returns `bodyHtml`, `heroImage`, `introParagraphs`,
     `components`, `galleryImages`, `metaDescription`
  5. re-import → counts show "updated", not "created"

## Parity checklist (vs. `dc_import`)

| `dc_import` capability | `decoupled-wp` equivalent |
| --- | --- |
| `{model, content}` envelope | `{version, content}` (model applied separately via `wp spark model`) |
| nested paragraphs | `components` / `complex` field rows |
| `ref(node:bundle)` | `association` with `{ "post": "<ref>" }` |
| `term(vocab)` | `terms` upsert + `posts[].terms` |
| `image` field | `media` sideload + `{ "media": "<ref>" }` |
| case-insensitive title dedup | same identity rule |
| idempotent re-import | same |
| `get_import_example` | `wp spark content example` |
| `POST /api/dc-import` | `/wp-json/spark/v1/content-import` (deferred) |

## Fly / Docker image — parity with the Drupal tenant image

`decoupled-wp` ships its own Fly tenant image, the WordPress analogue of
`decoupled-drupal-frankenphp`. The stack is deliberately the **same
shape** as the Drupal one, so most of `decoupled-project`'s Dockerfile +
`docker/` helpers port over with app-layer swaps only:

| Layer | Drupal image | `decoupled-wp` image |
| --- | --- | --- |
| Base | `dunglas/frankenphp:1-php8.5` | **same** |
| DB sidecar | `mariadb-server` in-container, `gosu` to drop priv | **same** |
| PHP ext | gd, intl, opcache, pdo_mysql, zip, apcu | **same** (WP wants the same set) |
| Composer build | `composer install` from committed `composer.lock` | **same** (Bedrock is Composer-managed too) |
| App code COPY | `web/` (Drupal docroot) + `config/` | `web/` (Bedrock docroot: `web/wp`, `web/app`) |
| Settings injection | `docker/drupal-settings.php` → `settings.php` | `web/wp-config.php` + a `docker/wp-config-fly.php` (env-driven, like the Drupal settings) |
| Web server | FrankenPHP + `Caddyfile` + `frankenphp-worker.php` | **same** (WP runs fine under FrankenPHP worker mode) |
| Volume | unified `tenant_data` (`/data` → mysql + files) | **same**; WP uploads (`web/app/uploads`) symlinked into `/data` like Drupal's `files/` |
| Entrypoint | `docker/entrypoint.sh`: init mysql on first boot, start mariadbd, wait, start frankenphp | **same skeleton**; the per-tenant secret it requires changes name (see below) |

### What actually differs

1. **Install/provision step.** Drupal's provisioner runs
   `drush site:install dc_core`. The WP provisioner runs
   `wp core install` + `wp spark model use <bundled>` +
   (optionally) `wp spark content import <seed>`. This is the natural
   home for the content-import command this plan builds — a fresh WP
   tenant comes up modeled *and* seeded in one provision.

2. **Per-tenant secrets.** Drupal needs `DRUPAL_DB_PASSWORD`,
   `HASH_SALT`, `DATABASE_URL`. WP needs the DB creds plus WordPress's
   salt keys (`AUTH_KEY`, `SECURE_AUTH_KEY`, … — 8 of them) and
   `WP_HOME`/`WP_SITEURL`. The entrypoint's "secret must be set by the
   Fly provisioner" guard applies the same way; just a different key
   list. Bedrock already reads these from env via `roots/wp-config`, so
   `docker/wp-config-fly.php` is thin.

3. **Healthcheck / readiness.** Drupal polls `/user/login`; WP polls
   `/wp-login.php` (or `/wp-json/` for a headless-friendlier check).

4. **No `config/` dir.** WP has no Drupal-style config-sync directory;
   the equivalent is the `spark_content_model_active` option (set by
   `wp spark model`), which lives in the DB, not the image.

### Provisioner (dashboard-side, mirrors `scripts/fly/`)

The Drupal provisioner (`scripts/fly/provision-tenant.sh` in the
dashboard repo) resolves the source image from
`decoupled-drupal-frankenphp` and runs the Drupal install. The WP
provisioner will be a sibling that resolves from a
`decoupled-wp-frankenphp` source app and runs the WP install + model +
seed. The whole pipeline (app create → volume → secrets → deploy image
→ wait HTTP → install) is structurally identical; `lib/providers/`
in the dashboard picks the WP path when the space is a WP space.

**Scope note:** building this image + provisioner is its own milestone
*after* the content-import engine lands — the engine is what makes the
provision step meaningful (a tenant you can model + seed). The image
work is mostly mechanical porting of the Drupal Dockerfile, so it's
low-risk once the engine exists. Sequence: engine → image → provisioner
→ Astro starter.

## Open decisions

- **`ref` vs WP ID**: plan uses file-local `ref` tokens. Alternative:
  let authors pass real WP IDs. `ref` is safer for fresh provisioning
  and matches dc_import's intra-envelope referencing — recommend `ref`.
- **Media sideload failures**: abort whole import, or import the post
  without the image and warn? Recommend warn + continue for media only
  (a broken image URL shouldn't sink an otherwise-good content load),
  while keeping structural errors fatal.
- **Transactional scope**: WP has no real DB transaction across the
  REST/CLI boundary like Drupal does. v1 validates-then-writes to get
  most of the safety; full rollback is out of scope.
