# YSAQMD parity validation — config-driven content model

Goal: rebuild the YSAQMD content model (previously the bespoke
`ysaqmd-content` plugin on branch `yolo-solano-aqmd-cms`) entirely as
config interpreted by `spark-core`'s config system — zero project PHP.
This validates that the config-driven approach can fully replace a
hand-written per-project plugin.

## Target (what the bespoke plugin defined)

**3 CPTs:** `ysaqmd_program`, `ysaqmd_board_member`, `ysaqmd_burn_day`
**2 taxonomies:** `audience` (program+post), `program_category` (program)

**Fields + GraphQL (per CPT):**

| CPT | Carbon field | type | GraphQL field | GraphQL type |
|---|---|---|---|---|
| Program | ysaqmd_lede | text | lede | String |
| Program | ysaqmd_hero_image | image(url) | heroImage | String |
| Program | ysaqmd_hero_alt | text | — | — |
| Program | ysaqmd_last_updated | date Y-m-d | lastUpdated | String |
| Program | ysaqmd_apply_url | text | applyUrl | String |
| Program | ysaqmd_related_forms | complex(label,url) | relatedForms | [String] (JSON) |
| Program | ysaqmd_eligibility | rich_text | eligibility | String |
| BoardMember | ysaqmd_representation | text | representation | String |
| BoardMember | ysaqmd_role | select(member/chair/vice_chair) | role | String |
| BoardMember | ysaqmd_jurisdiction | select(yolo/solano) | jurisdiction | String |
| BoardMember | ysaqmd_term_start | date | termStart | String |
| BoardMember | ysaqmd_term_end | date | termEnd | String |
| BoardMember | ysaqmd_headshot | image(url) | headshot | String |
| BoardMember | ysaqmd_headshot_alt | text | headshotAlt | String |
| BurnDay | ysaqmd_status_date | date* required | statusDate | String |
| BurnDay | ysaqmd_status | select* required | burnStatus | String |
| BurnDay | ysaqmd_hours | text | hours | String |
| BurnDay | ysaqmd_notes | rich_text | notes | String |
| BurnDay | ysaqmd_more_info_url | text | moreInfoUrl | String |

## Gaps in the config system (what we must add)

Already supported (verified in fields-page.php):
- select with options ✓ · value_type ✓ · help_text/width/rows ✓
- generic `meta_resolver($key)` helper EXISTS (graphql-extensions.php:84)
  but is NOT exposed through resolver_from_config / allowed list.

Must add:

1. **`required` flag** — `spark_apply_field_options` → `set_required(true)`.
2. **`storageFormat`** — date fields → `set_storage_format('Y-m-d')`.
3. **`complex` field type** — generic repeater with sub-fields
   (label/url), `set_layout`. New field-type in the match + validator
   whitelist. Stored value resolved as a JSON-encoded `[String]`.
4. **`meta` scalar resolver** — expose `meta_resolver` via
   `resolver_from_config` + `allowed_graphql_resolvers`, taking `key`.
5. **`metaComplexJson` resolver** — for the complex field: return
   `array_map(json_encode, carbon_get_post_meta(...))` as `[String]`.
6. **Per-CPT GraphQL fields** — today `register_shared_fields` puts the
   SAME fields on every sharedPostType. YSAQMD needs DIFFERENT fields
   per type. Add `graphql.postTypeFields: { Program: {...}, BoardMember:
   {...}, BurnDay: {...} }`, registered on each named GraphQL type.

## Field-type / resolver whitelist additions

- `allowed_field_types()` += `complex`
- `allowed_graphql_resolvers()` += `meta`, `metaComplexJson`

## Config-only capability surface (post-improvements)

The config system now covers the common WP content-model needs without
any project PHP:

**Field types:** text · textarea · rich_text · image · checkbox ·
select · date · time · number (min/max/step) · radio · multiselect ·
color · complex (repeater) · association (post/term/user) · plus
presets (introParagraphs / components / galleryImages).

**Field options:** required · default · help_text · width · rows ·
value_type · storage_format · options (select/radio/multiselect) ·
relate/postTypes/taxonomies/max (association).

**GraphQL resolvers (whitelisted, no callbacks in config):**
bodyHtml · heroImage · introParagraphs · components · galleryImages ·
metaDescription · catalogSlug · meta (scalar) · metaInt · metaBool ·
metaList (set→[String]) · metaComplexJson (repeater→[String]) ·
terms (taxonomy→[SparkTerm]) · relatedPosts (association→[SparkRef]).

**GraphQL fields:** shared (every type) via `graphql.fields`, OR
per-CPT via `graphql.postTypeFields` keyed by GraphQL type name.

**Shared object types:** SparkImage · SparkComponent · SparkTerm · SparkRef · SparkBlock.

### Whitelist derived from prior engagements (HT / NMSF / esplanade / gfparks)

A survey of the four hand-written content plugins set the whitelist
ceiling. Everything they used is now config-expressible:

- **Field types** they used: text, textarea, rich_text, image (url),
  file (url), select, checkbox, date, date_time, complex (incl. nested),
  association — all supported.
- **Resolver patterns** they used → now covered:
  scalar passthrough (`meta`) · typed int/bool incl. the `'yes'` truthy
  token (`metaInt`, `metaBool` w/ `truthy`) · image object (`heroImage`) ·
  complex→string-list / int-list (`metaStringList`, `metaIntList` via
  `subKey`) · raw underscore meta (`rawMeta`) · `bodyHtml`
  (`the_content`) · `metaDescription` (excerpt fallback) · taxonomy terms
  (`terms`) · association refs (`relatedPosts`).
- **The one resolver every engagement hand-wrote** — the
  kind-discriminated complex normalizer (body blocks / components / page
  sections) — is now `variantMap`: config declares the `_type` variants
  and a per-kind slot map (incl. field rename, imageList/stringList
  transforms); output is `[SparkBlock]`. This was the last thing forcing
  custom PHP.

When a project needs something outside this set, the fix is to add ONE
named resolver or field type to spark-core (PHP, once) — every project
then gets it via config. The config itself never contains executable code.

## Operator workflow on a no-custom-code host (decoupled.io)

A content model is DATA, never a PHP file. Three load paths, all going
through the same validation in `Config\save_model()` / `model()`:

1. **Admin screen** — Tools → "Spark Content Model": paste JSON, Validate
   and Save. `manage_options` + nonce gated, 1 MB cap, validates before
   storing. Stores the `spark_content_model_active` option.
2. **WP-CLI** (`includes/cli.php`):
   - `wp spark model export [--pretty]`        dump the active model
   - `wp spark model validate <file|->`         dry-run validation
   - `wp spark model import <file|->`           validate + store + flush
   - `wp spark model use <name>`                activate models/<name>.json
   - `wp spark model list`                      bundled model names
   - `wp spark model reset`                     back to built-in default
3. **Bundled JSON + env** — vetted models ship as `models/<name>.json`;
   `SPARK_CONTENT_MODEL=<name>` (env/constant) is the provisioning default
   for a fresh instance (`includes/config/bundled.php`).

Precedence: stored option (admin/import) → SPARK_CONTENT_MODEL bundled
JSON → built-in default. An explicitly imported model always wins.

No path accepts executable code: field types, resolvers, route tokens,
and presets are closed whitelists; a bad model is rejected and the
instance falls back to the default rather than fataling.

## Acceptance

- `wp eval` dumps register_post_type for all 3 CPTs == bespoke args
  (graphql names, supports, rewrite, archive).
- Both taxonomies present with correct object_type + graphql names.
- GraphQL introspection: Program/BoardMember/BurnDay expose exactly the
  fields above, same names + types.
- Re-seed YSAQMD content; a GraphQL query returns the same shape the
  Astro pilot's src/data/*.json fixtures use.
