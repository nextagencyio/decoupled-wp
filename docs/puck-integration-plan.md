# Puck Integration Plan — `decoupled-wp`

## Context

The Drupal product (`decoupled-project`) ships `dc_puck` (~1,139 LOC): a
module that powers the **Puck visual editor** on the paired Astro
frontend. Puck is a React page-builder; `dc_puck` does the bidirectional
translation between Puck's JSON component tree and Drupal's
paragraph/entity system.

`decoupled-wp` has no Puck integration yet. This plan defines
`spark-puck` — the WordPress analogue — and the Astro-side wiring so the
forked frontend (`decoupled-components-astro` → a WP fork) gets the same
visual-editing experience against WordPress + Carbon Fields.

We already built the harder direction once: the content-import engine's
`field-writers.php` converts a JSON component tree → Carbon Fields meta.
`savePuckData` is essentially that writer, reused. `loadPuckData` is its
inverse (Carbon meta → JSON tree), which the GraphQL `components` /
`variantMap` resolvers already do read-side. So spark-puck is mostly
**assembling existing transforms behind a REST contract**, not inventing
new ones.

## What dc_puck does (the reference contract)

### REST surface (`dc_puck.routing.yml`)

| Route | Method | Purpose |
| --- | --- | --- |
| `/api/puck/load/{node}` | GET | entity → Puck JSON `{content, root, zones}` |
| `/api/puck/save/{node}` | POST | Puck JSON → entity (create/update/delete sections) |
| `/api/puck/mapping` | GET | the component↔field mapping config |
| `/api/puck/configure` | POST | set mapping / sections field |
| `/api/puck/token/{node}` | GET | mint a short-lived edit token (perm-gated) |
| `/api/puck/validate-token` | POST | verify a token (open, used by the frontend) |

### Transform (`PuckMappingService`)

- **`getSectionsField()`** — the entity field that holds the ordered
  sections (Drupal default `field_sections`, a paragraph reference).
- **`getMapping()` / `buildDefaultMapping()`** — auto-detects paragraph
  types + their fields and builds `puckType → {paragraph_type, fields}`.
  Field types recognized: `text` (text_long), `boolean`, `image`,
  `paragraphs` (nested entity refs → Puck array fields), else `string`.
  Puck type = PascalCase of bundle; prop = camelCase of `field_*`.
- **`loadPuckData(node)`** — walks the sections field, turns each
  paragraph into `{type, props}`, stamping `_drupalUuid` +
  `_drupalRevisionId` on each component (and `_drupalUuid` on nested
  children) so the next save can diff.
- **`savePuckData(node, puckData)`** — for each Puck component: find the
  existing paragraph by `_drupalUuid` (update) or create new; map props
  → fields; recurse for nested arrays; **delete paragraphs whose UUID is
  no longer present**; save the node as a new revision.
- **`createFileFromUrl()`** — image props arrive as URLs; downloaded
  into a Drupal file entity (skips empty / example.com / placeholder).

### Auth

A token minted per node (`/token/{node}`, requires `access content`),
validated on load/save. The headless frontend never holds WP/Drupal
credentials — it holds a short-lived token scoped to one node.

## The WP equivalent: `spark-puck`

A plugin in `decoupled-wp` (bundled in spark-core or a sibling plugin —
**decision below**) that registers the **same REST contract** under a WP
namespace and transforms against **Carbon Fields components** instead of
paragraphs.

### REST surface (WP REST API)

| Route | Method | Purpose |
| --- | --- | --- |
| `/wp-json/spark-puck/v1/load/{id}` | GET | post → Puck JSON |
| `/wp-json/spark-puck/v1/save/{id}` | POST | Puck JSON → post |
| `/wp-json/spark-puck/v1/mapping` | GET | mapping config |
| `/wp-json/spark-puck/v1/configure` | POST | set mapping |
| `/wp-json/spark-puck/v1/token/{id}` | GET | mint edit token (cap-gated) |
| `/wp-json/spark-puck/v1/validate-token` | POST | verify token |

WP REST gives us `register_rest_route`, nonce/permission callbacks, and
`WP_REST_Request` for free — no router YAML.

### The sections field

Drupal's `field_sections` (a paragraph reference) maps to a Carbon
Fields **complex field** on the post — the existing `spark_components`
preset is exactly this shape (rows discriminated by `_type`, per-type
subfields). So `getSectionsField()` → the configured complex field key
(default `spark_components`). No new storage concept needed.

### Round-trip identity (the one genuinely new piece)

Drupal stamps `_drupalUuid` on each component so save can match existing
paragraphs. Carbon complex rows have **no stable id** across saves. The
plan: stamp a synthetic **`_sparkRowId`** (a generated uuid) into each
row when loading, and persist it as a hidden subfield (`_spark_row_id`)
on every component type. On save, match rows by `_sparkRowId`:

- present + known → update that row in place
- present + unknown / absent → new row
- known but missing from incoming → drop it

This gives the same create/update/delete-orphan semantics dc_puck gets
from UUIDs. (Alternative: positional matching — simpler but loses edit
stability when rows reorder. Recommend the row-id approach.)

### Mapping

`buildDefaultMapping()` for WP introspects the **active content model's**
component definitions (we already model components: richtext → `{html}`,
gallery → `{images:[{src,alt}]}`, cta → `{heading,text,button_label,
button_url}`, embed → `{embed_code,caption}`). Output:
`puckType → {component_type, fields}` where Puck type is PascalCase of
the component type and props are the subfield keys (already camel-ish).
Stored in an option (`spark_puck_mapping`), editable via `/configure`,
defaulted from the model when empty — mirrors dc_puck's state-backed
mapping.

### Transform, reusing what exists

- **`save` (Puck → Carbon)**: this is `field-writers.php`'s
  `component_rows()` with diffing. Extend it to accept incoming
  `_sparkRowId`, match against current `carbon_get_post_meta`, and write
  the merged set back via `carbon_set_post_meta`. Image props (URLs)
  reuse `media.php`'s idempotent sideload (hash-dedup) instead of
  dc_puck's `createFileFromUrl`.
- **`load` (Carbon → Puck)**: read `carbon_get_post_meta($id, $key)`,
  map each row's `_type` → Puck `type`, subfields → props, stamp
  `_sparkRowId`. This is the inverse of `component_rows()` and overlaps
  the read normalization the GraphQL `components` resolver already does
  — factor that into a shared `components_to_array()` helper both call.

### Auth

WP-native: `/token/{id}` requires `edit_post` cap on that post and mints
a transient-backed token (`set_transient("spark_puck_token_{$id}", ...,
HOUR_IN_SECONDS)`); load/save accept either a logged-in editor (nonce)
or a valid token. Same "frontend holds a scoped short-lived token, never
credentials" model as dc_puck.

## Astro fork wiring

Fork `decoupled-components-astro` → a WP repo (name TBD, e.g.
`decoupled-components-astro-wp`). The data layer is small
(`drupal-client.ts` is 40 lines); the work is:

1. **Read client**: replace the Drupal typed client
   (`schema/client.ts`, codegen'd from Drupal GraphQL) with a WPGraphQL
   client — regenerate `schema/` against `decoupled-wp`'s
   `/wp/graphql`, or hand-write the handful of queries the pages use.
   Env: `DRUPAL_BASE_URL` → `WP_GRAPHQL_URL`, etc.
2. **Puck proxy**: `src/pages/api/drupal-puck/[...path].ts` → repoint at
   `/wp-json/spark-puck/v1/*`. The Puck component config
   (`lib/puck-config.tsx`) and the React component registry stay — Puck
   itself is CMS-agnostic; only the load/save endpoints + prop names
   change to match the WP mapping.
3. **Preview / token**: the editor island gets its edit token from
   `/wp-json/spark-puck/v1/token/{id}` instead of the Drupal route.
4. **Keep**: the AI plugins, Cloudinary upload, demo-mode mock client,
   audit pipeline — all CMS-agnostic or already env-flagged.

The Puck component schema (what props each component exposes in the
editor) must match the WP mapping's prop names exactly — that's the
single contract both sides share.

## Decisions

- **Plugin home**: bundle `spark-puck` inside `spark-core`, or a
  separate `spark-puck` plugin? dc_puck is its own Drupal module.
  Recommend a **separate `spark-puck` plugin** for symmetry and so a
  site can run headless-read-only without the editor. It depends on
  spark-core (reuses the model + field writers).
- **Row identity**: synthetic `_sparkRowId` subfield (recommended) vs.
  positional matching. Row-id preserves edit stability across reorders.
- **Sections field**: reuse `spark_components` (recommended) vs. a
  dedicated `spark_sections` complex field. Reusing keeps one components
  store; a dedicated field separates "page builder sections" from
  "inline components" if that distinction matters later.
- **Astro repo name**: `decoupled-components-astro-wp`? Confirm.

## Implementation phases

### Phase 1 — plugin skeleton + mapping
- `spark-puck` plugin: REST routes registered, permission callbacks,
  token mint/validate (transient-backed).
- `buildDefaultMapping()` from the active model's component set;
  `/mapping` + `/configure`.
- Exit: `GET /mapping` returns a correct default for the bundled model.

### Phase 2 — load (Carbon → Puck)
- Shared `components_to_array()` extracted; `load` stamps `_sparkRowId`.
- Exit: `GET /load/{id}` on an imported post returns a Puck tree whose
  components match what was imported.

### Phase 3 — save (Puck → Carbon)
- Row-id diffing (update/create/delete-orphan); image props via
  `media.php` sideload; persist `_spark_row_id` hidden subfield.
- Exit: load → edit → save → reload round-trips without dupes or loss;
  reordering rows is stable.

### Phase 4 — Astro fork
- Fork repo; repoint read client to WPGraphQL; repoint Puck proxy +
  token to `/wp-json/spark-puck/v1/*`; align Puck component props to the
  WP mapping.
- Exit: editor loads a WP post, edits visually, saves back, frontend
  re-renders from WPGraphQL.

### Phase 5 — polish
- DDEV smoke: model → content import → open editor → edit → save →
  GraphQL reflects the change.
- Token expiry + cap checks verified; orphan rows cleaned.

## Test plan

- **PHP lint** every new file.
- **REST unit/smoke** (DDEV, wp-cli + curl): `/mapping` shape; `/load`
  on an imported post; `/save` round-trip; `/token` cap gate; expired
  token rejected.
- **Round-trip**: import seed → `/load` → mutate a prop + reorder →
  `/save` → `/load` again equal-modulo-edit; no orphan rows; GraphQL
  `components` reflects the change.
- **Astro**: editor island loads, saves, frontend re-renders.

## Parity checklist (vs. dc_puck)

| dc_puck | spark-puck |
| --- | --- |
| `/api/puck/load/{node}` | `/wp-json/spark-puck/v1/load/{id}` |
| `/api/puck/save/{node}` | `/wp-json/spark-puck/v1/save/{id}` |
| `/api/puck/mapping` + `/configure` | same under spark-puck/v1 |
| token generate/validate | transient-backed, cap-gated |
| `field_sections` paragraph ref | `spark_components` Carbon complex |
| `_drupalUuid` row identity | `_sparkRowId` synthetic row id |
| paragraph create/update/delete | Carbon row merge by row-id |
| nested paragraph children | nested complex sub-rows |
| `createFileFromUrl` | `media.php` idempotent sideload |
| auto-detect mapping from bundles | auto-detect from model components |

## Risks

| Risk | Mitigation |
| --- | --- |
| Carbon rows lack stable identity | synthetic `_sparkRowId` subfield |
| Puck prop names drift from WP mapping | one shared mapping is the contract; generate Puck config from it later |
| Carbon complex nesting depth limits | keep components coarse (same rule as the content model) |
| Save clobbers concurrent edits | token is per-post + short-lived; last-write-wins like dc_puck (no locking in v1) |
| Image re-download on every save | media.php hash-dedup already prevents this |
