# Component Palette Expansion Plan — `decoupled-wp`

## Goal

Expand the WP component set from the current coarse four
(richtext / cta / gallery / embed) to the full 10-type palette the
paired Astro starter (`decoupled-components-astro-wp`, forked from the
Drupal starter) ships renderers for:

| Puck type | Repeatable / nested data |
| --- | --- |
| Hero | — (cta pair, bg image) |
| TextBlock | — (eyebrow, content, cta) |
| CardGroup | `cards[]` (icon, title, desc, link) |
| SideBySide | `features[]` (icon, title, desc) + image |
| Accordion | `items[]` (question, answer) |
| Quote | `testimonials[]` (quote, author, rating, image) |
| Pricing | `tiers[]` → each with `features[]` (string list) |
| LogoCollection | `logos[]` (name, image, url) |
| Stats | `stats[]` (value, label, desc) |
| Newsletter | — (placeholder, button, bg) |

The exact prop shapes are the source of truth in
`decoupled-components-astro-wp/lib/types.ts` — the plan mirrors them so
the three layers don't drift.

## Architecture decision (locked)

**GraphQL exposes components as a single `SparkComponent`-style type
with a JSON `props` field**, NOT one GraphQL type per component.

- Carbon Fields storage stays **structured** (real complex fields with
  per-type subfields) so the wp-admin editor UI is rich. Only the
  GraphQL *serialization* is JSON.
- Nesting (cards, tiers→features, testimonials) serializes for free;
  no per-nested-shape GraphQL types.
- Puck round-trips JSON natively; spark-puck barely changes.
- The pattern already exists: `graphql-extensions.php` has a
  `SparkBlock { kind, data }` type where `data` is `wp_json_encode`d —
  reuse that mechanism for the components field.

Tradeoff accepted: GraphQL consumers see an opaque JSON string per
component, not field-level typed queries. Fine — the consumer is one
Astro frontend that already dispatches on `kind` and parses props.

## Layer-by-layer

### 1. Carbon Fields storage (spark-core)

`spark_make_components_field()` in `includes/fields-body-block.php`
currently builds a complex field with 4 `add_fields()` groups. Add the
6 new groups. Carbon supports nested complex for the repeatables:

- Hero: text(eyebrow), text(title), text(subtitle), select(layout),
  image(background_image), text(primary_cta_text/url),
  text(secondary_cta_text/url)
- TextBlock: text(eyebrow), text(title), rich_text(content),
  select(alignment), text(cta_text/url)
- CardGroup: text(eyebrow/title/subtitle), select(columns),
  complex(cards){ text(icon/title), textarea(description),
  text(link_text/link_url) }
- SideBySide: text(eyebrow/title), rich_text(content), image,
  select(image_position), complex(features){ icon/title/description },
  text(cta_text/url)
- Accordion: text(eyebrow/title/subtitle),
  complex(items){ text(question), textarea(answer) }
- Quote: text(eyebrow/title), select(layout),
  complex(testimonials){ textarea(quote), text(author_name/title/
  company), image(author_image), text(rating) }
- Pricing: text(eyebrow/title/subtitle),
  complex(tiers){ text(name/price/billing_period),
  textarea(description), complex(features){ text(value) },
  checkbox(is_featured), text(cta_text/url) }
- LogoCollection: text(eyebrow/title),
  complex(logos){ text(name), image, text(url) }
- Stats: text(eyebrow/title), text(background_color),
  complex(stats){ text(value/label), textarea(description) }
- Newsletter: text(eyebrow/title/subtitle/placeholder/button_text),
  select(background_color)

Note: Carbon nested complex (Pricing → tiers → features) is two levels;
keep it to two — deeper nesting gets unwieldy and none of these need it.

### 2. GraphQL exposure (spark-core)

Change the components resolver to emit `[{ kind, props }]` where
`props` is `wp_json_encode` of the row's normalized data (mirroring the
`SparkBlock { kind, data }` resolver already in
`graphql-extensions.php`). The `SparkComponent` type becomes
`{ kind: String, props: String }` (props = JSON). The normalization
(Carbon row → plain assoc array) lives in one helper reused by GraphQL
*and* spark-puck load (single source of truth, prevents drift).

### 3. spark-puck mapping (spark-puck)

`build_default_mapping()` in `includes/mapping.php` gains the 6 new
entries: `puckType → { component_type, fields }`. The transform's
`row_to_puck` / save already handle scalar + gallery; extend to handle
the new nested repeatables generically (a `complex` field type in the
mapping → recurse into sub-rows). Puck prop names = the
`lib/types.ts` names (eyebrow, title, cards, tiers, …) exactly.

### 4. Astro renderer (decoupled-components-astro-wp)

`SparkComponents.astro` currently switches on 4 kinds. Either:
(a) extend its switch to all 10, reusing the existing
`src/components/paragraphs/Paragraph*.astro` components (they already
render these shapes — just feed them the parsed `props`), or
(b) keep `SparkComponents` thin and delegate: parse `props` JSON, map
`kind` → the existing `Paragraph*` component.

Option (b) is less code and reuses the polished Drupal renderers
verbatim — recommended. `wp-client.ts` parses each component's `props`
JSON into an object before handing to the renderer.

## The contract that must not drift

For each component, three names must match exactly:
`Puck type` (mapping + Astro switch) · `component_type` (Carbon `_type`
+ GraphQL `kind`) · prop names (Carbon subkeys ↔ Puck props ↔ Astro
component props). The plan's table is the canonical list; build from it.

## Phases

1. **spark-core Carbon fields** — add the 6 groups; verify the editor
   renders inputs for each in wp-admin; `wp spark content import` can
   write one of each (extend the example envelope).
2. **spark-core GraphQL** — components resolver → `{kind, props-JSON}`;
   shared normalizer; verify via wp-cli `graphql()` that each kind
   round-trips with correct nested data.
3. **spark-puck** — mapping + transform for the 6 new types; DDEV
   round-trip (load → edit a nested repeatable → save → reload stable).
4. **Astro** — renderer dispatch for all 10 (delegate to existing
   Paragraph* components); `npm run build` clean; queries validated
   against live schema via wp-cli.
5. **Smoke** — import one of each → GraphQL returns all 10 → editor
   loads/saves them → frontend builds.

## Test plan

- PHP lint every changed file.
- wp-cli `graphql()` per kind: props JSON parses, nested arrays intact.
- spark-puck round-trip including a nested repeatable (Pricing tiers
  with feature lists) — stable row ids, no orphan/dupe.
- Astro `npm run build` clean; client queries validated via wp-cli.

## Risks

| Risk | Mitigation |
| --- | --- |
| Name drift across 3 layers | the contract table is canonical; build from it |
| Carbon deep nesting (tiers→features) unwieldy | cap at 2 levels; feature list is a simple text complex |
| JSON props lose type safety | acceptable — single known consumer parses by kind |
| spark-puck nested-repeatable round-trip | generic `complex` handling in transform + row-id at each level |
