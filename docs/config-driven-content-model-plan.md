# Config-Driven Content Model Plan

## Goal

Make `decoupled-wp-spark` usable as a hosted WordPress CMS on
decoupled.io without requiring per-project PHP file uploads.

Today each project edits or adds PHP includes inside `spark-core` for:

- custom post types
- taxonomies
- Carbon Fields containers
- component field definitions
- WPGraphQL object types and fields
- preview URLs
- revalidation paths

That works for one-off client-owned repositories, but it does not fit a
hosted product where customers can configure content models but cannot
upload executable code.

The target architecture is:

1. A trusted, preinstalled plugin owns all PHP execution.
2. Project-specific content models are stored as validated data.
3. The plugin interprets that data into WordPress, Carbon Fields, and
   WPGraphQL registrations at runtime.
4. decoupled.io provides the UI, versioning, validation, and publishing
   workflow for that content-model data.

## Non-Goals

- No arbitrary PHP uploads.
- No arbitrary resolver callbacks in config.
- No raw SQL, shell commands, or dynamic `eval`.
- No full page-builder model where every paragraph becomes a block.
- No attempt to expose every Carbon Fields option in v1.
- No migration of all historic projects before the first usable version.

## Current State

The existing plugin boundary is good. `web/app/plugins/spark-core/` is
already the place where the content model lives.

Current hardcoded files:

| Concern | Current file |
| --- | --- |
| Carbon Fields boot | `includes/carbon-fields-bootstrap.php` |
| CPT registration | `includes/post-types.php` |
| Taxonomy registration | `includes/taxonomies.php` |
| Field helpers | `includes/fields-body-block.php` |
| Field containers | `includes/fields-page.php` |
| GraphQL extensions | `includes/graphql-extensions.php` |
| Preview routes | `includes/preview-links.php` |
| Revalidation routes | `includes/revalidate.php` |

The plan is not to throw this away. The plan is to extract the patterns
inside those files into generic registrars.

## Proposed Plugin Shape

Rename or evolve `spark-core` into a platform plugin such as
`spark-content-model`.

Suggested internal structure:

```text
web/app/plugins/spark-core/
  spark-core.php
  includes/
    carbon-fields-bootstrap.php
    config/
      defaults.php
      loader.php
      schema.php
      validator.php
      normalizer.php
      versioning.php
    registrars/
      post-types.php
      taxonomies.php
      carbon-fields.php
      graphql-types.php
      graphql-fields.php
      preview-routes.php
      revalidation-routes.php
    resolvers/
      body-html.php
      image.php
      intro.php
      gallery.php
      components.php
      meta.php
    admin/
      content-model-screen.php
      import-export.php
      validation-notices.php
    migrations/
      registry.php
      rename-meta-key.php
      rename-post-type.php
```

For a first implementation, this can be done inside the existing
`spark-core` plugin without renaming it. Renaming can wait until the
architecture stabilizes.

## Content Model Config

The content model should be declarative JSON. It can be stored in
WordPress as an option or custom table, and later synced from
decoupled.io.

Example v1 shape:

```json
{
  "version": 1,
  "project": {
    "name": "Example Project",
    "textDomain": "spark-core"
  },
  "postTypes": {
    "resource": {
      "wpKey": "spark_resource",
      "labels": {
        "singular": "Resource",
        "plural": "Resources"
      },
      "slug": "resources",
      "graphql": {
        "singleName": "Resource",
        "pluralName": "Resources"
      },
      "supports": ["title", "editor", "excerpt", "thumbnail", "revisions"],
      "menuIcon": "dashicons-portfolio",
      "public": true,
      "hasArchive": true,
      "showInRest": false
    }
  },
  "taxonomies": {
    "topic": {
      "wpKey": "spark_topic",
      "labels": {
        "singular": "Topic",
        "plural": "Topics"
      },
      "slug": "topic",
      "postTypes": ["spark_resource", "post"],
      "hierarchical": true,
      "graphql": {
        "singleName": "Topic",
        "pluralName": "Topics"
      }
    }
  },
  "fieldGroups": [
    {
      "id": "page_details",
      "label": "Page details",
      "location": {
        "postType": "page"
      },
      "tabs": [
        {
          "label": "Overview",
          "fields": [
            {
              "type": "image",
              "key": "spark_hero_image",
              "label": "Hero image",
              "valueType": "url",
              "graphql": {
                "fieldName": "heroImage",
                "resolver": "heroImage"
              }
            },
            {
              "type": "text",
              "key": "spark_hero_alt",
              "label": "Hero alt text"
            },
            {
              "type": "preset",
              "preset": "introParagraphs",
              "key": "spark_intro_paragraphs",
              "graphql": {
                "fieldName": "introParagraphs",
                "type": ["String"],
                "resolver": "introParagraphs"
              }
            }
          ]
        }
      ]
    }
  ],
  "graphql": {
    "sharedFields": {
      "bodyHtml": {
        "type": "String",
        "resolver": "bodyHtml",
        "postTypes": ["page", "spark_resource"]
      },
      "metaDescription": {
        "type": "String",
        "resolver": "metaDescription",
        "postTypes": ["page", "spark_resource", "post"]
      },
      "catalogSlug": {
        "type": "String",
        "resolver": "catalogSlug",
        "postTypes": ["page", "spark_resource"]
      }
    }
  },
  "routes": {
    "page": "/{uri}/",
    "post": "/blog/{slug}/",
    "spark_resource": "/resources/{slug}/"
  },
  "revalidation": {
    "always": ["/"],
    "listRoutes": {
      "post": ["/blog/"],
      "spark_resource": ["/resources/"]
    }
  }
}
```

## Field Type Strategy

Use a whitelist of supported field types. Each type maps to a known
Carbon Fields builder and a known GraphQL resolver shape.

### Primitive v1 Fields

- `text`
- `textarea`
- `rich_text`
- `image`
- `file`
- `checkbox`
- `select`
- `date`
- `time`
- `association`

### Preset v1 Fields

Presets are important because most projects should not design low-level
Carbon Fields structures from scratch.

- `hero`
- `introParagraphs`
- `galleryImages`
- `components`
- `seo`
- `externalLink`
- `location`
- `person`
- `eventDate`

The current starter's `spark_make_intro_field`,
`spark_make_gallery_field`, and `spark_make_components_field` become
preset builders.

### Component v1 Types

Start with the current component set:

- `richtext`
- `gallery`
- `cta`
- `embed`

Later component types:

- `stats`
- `accordion`
- `cards`
- `quote`
- `logoStrip`
- `timeline`
- `callout`

Components should stay coarse. The content-model rule remains:
ordinary prose belongs in `post_content`, not in one Carbon Fields row
per paragraph.

## GraphQL Strategy

The plugin should register GraphQL fields from config, but resolver
logic must come from a trusted resolver registry.

Allowed resolver names in v1:

- `bodyHtml`
- `heroImage`
- `introParagraphs`
- `galleryImages`
- `components`
- `metaDescription`
- `catalogSlug`
- `rawMetaString`
- `rawMetaBoolean`
- `rawMetaNumber`
- `rawMetaDate`
- `imageFromMeta`
- `linkFromMeta`

The config can choose resolver names and meta keys. It cannot provide
PHP callables.

Example registry:

```php
$resolvers = [
    'bodyHtml' => body_html_resolver(),
    'heroImage' => hero_resolver($src_key, $alt_key),
    'introParagraphs' => intro_resolver($key),
    'components' => components_resolver($key),
];
```

Shared object types such as `SparkImage` and `SparkComponent` can stay
static at first. If a project needs custom GraphQL object types later,
add a declarative object-type registry with scalar/list fields only.

## Routing Strategy

The current preview and revalidation files hardcode paths:

- `spark_resource` maps to `/resources/{slug}/`
- `page` maps to `/{uri}/`
- `post` maps to `/blog/{slug}/`

Move those to route templates:

```json
{
  "routes": {
    "page": "/{uri}/",
    "post": "/blog/{slug}/",
    "event": "/events/{slug}/"
  }
}
```

Supported tokens:

- `{id}`
- `{slug}`
- `{uri}`
- `{post_type}`
- `{year}`
- `{month}`
- `{day}`
- selected taxonomy slugs later, if needed

Preview links and revalidation paths should use the same route
resolver so they cannot drift.

## Storage Strategy

### Local Development

Support a checked-in JSON file for development:

```text
web/app/content-model/default.json
```

This makes local iteration easy and keeps the current starter useful
outside the hosted product.

### Hosted WordPress

Store the active model in WordPress:

- Option name: `spark_content_model_active`
- Revision option/table: `spark_content_model_revisions`
- Applied hash: `spark_content_model_hash`

For small configs, options are enough. For product-grade revisioning,
use a custom table:

```sql
spark_content_model_revisions
  id bigint
  model_hash varchar(64)
  model_json longtext
  status varchar(20)
  created_by bigint
  created_at datetime
  applied_at datetime null
```

### decoupled.io Sync

Later, decoupled.io becomes the source of truth:

1. User edits model in decoupled.io.
2. decoupled.io validates the model.
3. decoupled.io signs the JSON payload.
4. WordPress receives it through a locked REST endpoint.
5. WordPress validates it again.
6. WordPress stores it as a pending revision.
7. User or automation applies it.
8. WordPress flushes rewrites and exposes the new schema.

## Validation Rules

Validation has to be strict because config is replacing code review.

Required validation:

- JSON must match the schema version.
- All post type keys must match allowed patterns.
- All meta keys must match allowed patterns and prefixes.
- All GraphQL names must be valid GraphQL identifiers.
- All route templates must start with `/`.
- Route templates may only use supported tokens.
- Field types must exist in the whitelist.
- Resolver names must exist in the resolver registry.
- Field group locations must target registered post types or core
  types.
- Taxonomies may only attach to registered or allowed core post types.
- No HTML/script in labels/help text beyond normal escaped strings.
- No duplicate meta keys inside incompatible field shapes.
- No duplicate GraphQL fields on the same GraphQL type.
- No protected WordPress keys unless explicitly allowed.

Recommended naming policy:

- plugin-owned generated post types use `spark_` prefix by default
- project-friendly aliases can display without the prefix
- meta keys use `spark_` or a per-project prefix
- config IDs use lowercase snake case

## Security Model

The trusted plugin is the security boundary.

Config may control:

- labels
- slugs
- allowed field types
- field order
- tabs
- help text
- route templates
- known resolver selection
- known GraphQL field names

Config may not control:

- executable code
- PHP class names
- PHP function names outside the resolver whitelist
- SQL
- shell commands
- include paths
- file paths
- arbitrary REST callbacks
- arbitrary WP hooks
- arbitrary GraphQL resolve callbacks

All labels and help text should be escaped on output. The model editor
should require an administrator capability, probably a custom
`manage_spark_content_model` capability.

## Admin UI Strategy

Do not start with a complex visual schema builder. Start with a
developer-grade admin screen:

1. Show the active model hash and version.
2. Provide JSON import/export.
3. Validate before save.
4. Save invalid config only as a draft, never active.
5. Show validation errors with JSON paths.
6. Provide a rollback button to the previous active model.

Then add a product UI in decoupled.io:

- content type builder
- field group builder
- component preset picker
- route editor
- GraphQL preview
- diff before apply
- rollback

WordPress does not need to be the primary modeling UI in the hosted
product.

## Migration Strategy

### Phase 1: Preserve Current Behavior

Create a PHP or JSON default model that exactly represents the current
starter:

- `spark_resource`
- `spark_topic`
- page details
- resource details
- hero image
- intro paragraphs
- components
- gallery images
- shared GraphQL fields
- current preview and revalidation routes

The first milestone is a no-op behavior change.

### Phase 2: Dual Path

Keep existing includes available while introducing config-driven
registrars. Add a feature flag:

```php
define('SPARK_CONTENT_MODEL_SOURCE', 'config');
```

Supported values:

- `legacy`
- `file`
- `option`

### Phase 3: Config Default

Make config the default. Keep legacy includes for one release as a
fallback.

### Phase 4: Hosted Mode

Disable file-based config edits in hosted environments. Only signed
config revisions from decoupled.io or admin-approved option updates can
be applied.

## Implementation Phases

### Phase 0: Design Lock

Deliverables:

- This plan reviewed and adjusted.
- v1 JSON schema documented.
- Supported field and resolver list finalized.
- Decision made on whether to keep the plugin name `spark-core`.

Exit criteria:

- One example config can represent the current starter without custom
  PHP includes.

### Phase 1: Config Loader and Default Model

Build:

- `includes/config/defaults.php`
- `includes/config/loader.php`
- `includes/config/normalizer.php`
- `includes/config/validator.php`

Behavior:

- Load default model from PHP array or JSON.
- Validate the model.
- Normalize defaults such as `public`, `showInGraphql`, and supports.
- Expose `spark_content_model()` helper.

Tests:

- Valid default model passes.
- Invalid field type fails.
- Invalid GraphQL name fails.
- Invalid route token fails.

Exit criteria:

- The plugin can load a current-starter-equivalent config without
  registering anything from it yet.

### Phase 2: Config-Driven CPTs and Taxonomies

Build:

- generic post type registrar
- generic taxonomy registrar
- activation hook calls generic registrars before flushing rewrites

Replace:

- hardcoded `register_resource()`
- hardcoded `register_topic()`

Keep:

- same public behavior for `spark_resource` and `spark_topic`

Tests:

- `wp post-type list` includes `spark_resource`.
- `wp taxonomy list` includes `spark_topic`.
- WPGraphQL still exposes configured CPTs and taxonomies.
- Rewrite flush works on activation.

Exit criteria:

- Current CPT and taxonomy behavior is fully data-driven.

### Phase 3: Config-Driven Carbon Fields

Build:

- field builder registry
- preset builder registry
- field group registrar
- tab support
- location support for post type equals

Replace:

- hardcoded containers in `fields-page.php`

Keep:

- helper behavior from `fields-body-block.php`
- WYSIWYG-first content-model rule

Tests:

- Carbon Fields boots.
- Page details render on `page`.
- Resource details render on `spark_resource`.
- Saving values persists the same meta keys.
- Existing content remains readable.

Exit criteria:

- No per-project field container PHP is needed for current field shapes.

### Phase 4: Config-Driven GraphQL Fields

Build:

- resolver registry
- GraphQL field registrar
- static shared object type registration
- GraphQL type mapping from post type config

Replace:

- `register_resource_fields()`
- `register_page_fields()`
- hardcoded calls to `register_shared_fields()`

Keep:

- `SparkImage`
- `SparkComponent`
- current resolver behavior

Tests:

- Existing frontend queries still work.
- `heroImage`, `introParagraphs`, `bodyHtml`, `components`,
  `galleryImages`, `metaDescription`, and `catalogSlug` resolve.
- Missing Carbon Fields plugin functions fail gracefully.

Exit criteria:

- A configured post type can receive GraphQL fields without a PHP file.

### Phase 5: Config-Driven Routes, Preview, and Revalidation

Build:

- route template resolver
- shared `frontend_path_for_post()`
- preview links based on configured routes
- revalidation paths based on configured routes and list routes

Replace:

- hardcoded `switch` in `preview-links.php`
- hardcoded `switch` in `revalidate.php`

Tests:

- Page preview URL uses `/{uri}/`.
- Resource preview URL uses `/resources/{slug}/`.
- Revalidation hits homepage, list route, and detail route.
- Unknown post types no-op cleanly.

Exit criteria:

- Adding a new configured CPT route requires config only.

### Phase 6: Import, Export, and Revisions

Build:

- admin screen under Tools or Spark menu
- JSON textarea/import
- export active model
- validation result screen
- revision storage
- rollback to previous model

Tests:

- Invalid config cannot become active.
- Valid config can become active.
- Rollback restores previous model.
- Rewrite rules flush after route-affecting changes.

Exit criteria:

- A project model can be changed without editing files.

### Phase 7: decoupled.io Integration

Build on the hosted app side:

- project schema editor
- JSON schema validation
- signed payload publishing
- model revision history
- environment targeting: dev, staging, production
- diff view before apply

Build on WordPress side:

- authenticated REST endpoint for model sync
- signature verification
- pending revision creation
- apply endpoint
- audit log

Tests:

- Unsigned payload rejected.
- Payload for wrong site rejected.
- Valid payload stored as pending.
- Apply updates active model.
- Rollback works.

Exit criteria:

- decoupled.io can manage a WordPress content model without code
  upload access.

### Phase 8: Product Builder UI

Build:

- content type editor
- taxonomy editor
- field group editor
- preset picker
- route editor
- GraphQL query preview
- generated Astro query snippet preview
- validation warnings for risky changes

Exit criteria:

- A non-PHP user can create a typical brochure or RFP pilot content
  model from decoupled.io.

## Frontend Implications

The Astro frontend currently expects a fairly stable schema:

- `bodyHtml`
- `heroImage`
- `introParagraphs`
- `components`
- `galleryImages`
- `metaDescription`
- `catalogSlug`

For v1, preserve these shared fields wherever possible. That keeps
`astro-spark` simple.

For custom content types, the frontend needs one of these strategies:

1. A conventional shared query that works across all configured content
   types.
2. A generated GraphQL fragment per content type.
3. A decoupled.io API that exposes the model to Astro and helps it
   select queries.

Recommended v1: keep the shared fields stable and generate only route
and collection metadata.

## Test Plan

### Automated PHP Checks

- Syntax check all plugin PHP files.
- Unit-test config validation and normalization where possible.
- Add WP-CLI smoke commands for local DDEV.

### WordPress Smoke Tests

- Activate plugin.
- Confirm CPTs exist.
- Confirm taxonomies exist.
- Confirm Carbon Fields containers render.
- Save a page with hero, intro, components, gallery.
- Query WPGraphQL for saved values.
- Confirm preview link points to frontend.
- Confirm revalidation sends expected path payloads.

### Backward Compatibility Tests

- Existing default project content still resolves.
- Existing meta keys are unchanged.
- Existing frontend GraphQL query still works.
- Existing roles/admin branding/dashboard behavior unchanged.

### Hosted Security Tests

- Invalid JSON rejected.
- Unknown field type rejected.
- Unknown resolver rejected.
- Malicious labels escaped.
- Bad signature rejected.
- Replay/stale revision behavior defined and tested.

## Operational Concerns

### Rewrite Rules

Any change to post type slugs, taxonomy slugs, or route templates should
trigger `flush_rewrite_rules()`. Avoid flushing on every request.

### GraphQL Schema Cache

WPGraphQL schema changes may need cache invalidation. Add an explicit
post-apply hook for GraphQL/cache plugins if needed.

### Existing Content

Changing meta keys can strand data. The admin UI should warn when a
field key is renamed or removed.

Later, add explicit migrations:

- rename meta key
- copy meta key
- rename post type
- attach taxonomy to post type
- detach taxonomy from post type

### Rollback

Rollback should restore the previous model quickly, but it should not
delete content or meta. Removing a field from the model hides it from
the UI/API; it does not remove stored meta.

## Recommended v1 Scope

Support enough to cover typical RFP pilot sites:

- pages
- posts
- 1 to 5 custom post types
- simple hierarchical taxonomies
- hero image
- intro paragraphs
- WYSIWYG body HTML
- gallery images
- richtext/gallery/cta/embed components
- SEO description
- route templates
- preview/revalidation
- stable shared GraphQL fields

Defer:

- arbitrary nested field builders
- arbitrary custom resolvers
- repeatable relationship graphs
- multilingual modeling
- field-level permissions
- advanced workflow
- generated Astro code commits
- visual schema builder inside WordPress

## Risks

| Risk | Mitigation |
| --- | --- |
| Config becomes as complex as code | Prefer presets and a narrow whitelist |
| GraphQL schema drift breaks Astro | Preserve stable shared fields in v1 |
| Field key changes strand content | Add diff warnings and later migrations |
| Route changes break previews/revalidation | Use one shared route resolver |
| Carbon Fields cannot express some future need | Treat those as platform feature work |
| Hosted user needs custom business logic | Add approved platform integrations, not code uploads |
| Applying bad schema breaks wp-admin | Validate before activation and keep rollback |

## Milestone Estimate

Assuming one developer familiar with this starter:

| Milestone | Size |
| --- | --- |
| Phase 0 design lock | 0.5 day |
| Phase 1 config loader/validator | 1 to 2 days |
| Phase 2 CPT/taxonomy registrars | 1 day |
| Phase 3 Carbon Fields registrar | 2 to 3 days |
| Phase 4 GraphQL registrar | 2 days |
| Phase 5 routes/preview/revalidation | 1 day |
| Phase 6 WP import/export/revisions | 2 to 3 days |
| Phase 7 decoupled.io sync | 3 to 5 days |
| Phase 8 product builder UI | separate product project |

An MVP that removes per-project PHP edits for local/starter use is
roughly 1 to 2 weeks. A hosted product workflow with decoupled.io sync,
signatures, revisions, and rollback is more like 2 to 3 weeks before
polish.

## First Implementation Slice

The smallest useful slice:

1. Add a default content-model config that mirrors the current starter.
2. Add loader, validator, and normalizer.
3. Convert CPT and taxonomy registration to use config.
4. Convert preview/revalidation route paths to use config.

That gets the core shape proven quickly while leaving Carbon Fields and
GraphQL unchanged. Once that is stable, convert fields and GraphQL.

## Decision Points

- Keep the plugin name `spark-core`, or introduce
  `spark-content-model`?
- Store local config as PHP arrays, JSON files, or both?
- Should hosted WordPress accept admin-pasted JSON, or only signed
  decoupled.io payloads?
- Should GraphQL shared fields be mandatory for all content types?
- Should post type keys expose the `spark_` prefix to decoupled.io
  users, or hide it behind friendly IDs?
- Should field key renames be blocked in v1 unless a migration is
  supplied?

## Recommendation

Start with the current `spark-core` plugin and make the current starter
model config-driven without changing behavior. Do not build the hosted
schema editor first.

The order should be:

1. Prove the interpreter in WordPress.
2. Preserve current GraphQL/front-end compatibility.
3. Add WordPress import/export and rollback.
4. Connect decoupled.io as the source of truth.
5. Build the polished schema UI once the config language has survived
   real projects.

This path keeps the hosted product safe: customers upload content-model
data, not code, while Simple Spark still controls every executable path
in the WordPress runtime.
