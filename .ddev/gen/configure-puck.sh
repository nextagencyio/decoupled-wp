#!/usr/bin/env bash
#
# Enable the dc-puck visual "Design Studio" editor for an engagement.
#
# The WP analogue of the Drupal stack's configure-puck.php. dc-puck
# (in web/app/plugins/) auto-enables any post type whose dc-core content
# model declares a `components` preset field — so "turn on Puck" is two
# steps:
#
#   1. Model: the engagement's model JSON (.ddev/gen/<slug>-model.json)
#      must give a `page` (or a dedicated `landing`) post type a field
#      group with a components field, e.g.:
#
#        { "id": "page_builder", "label": "Page Builder", "postType": "page",
#          "tabs": [ { "label": "Content", "fields": [
#            { "type": "preset", "preset": "components",
#              "key": "dc_components", "label": "Page sections" } ] } ] }
#
#      Add that group to the model JSON and re-import (below). After import,
#      `enabled_post_types()` includes `page` and the "Open Design Studio"
#      button appears on those edit screens.
#
#      For the DEPLOYED frontend to READ the stack (not just edit it),
#      also expose the components field on the post type's GraphQL type
#      so the spark-astro-wp `fetchLandings()` can query it:
#
#        "graphql": {
#          "sharedPostTypes": ["page"],
#          "postTypeFields": { "Page": {
#            "components": { "type": {"list_of":"DcComponent"},
#              "resolver": "components", "key": "dc_components" } } } }
#
#      Without this the editor still round-trips (dc/v1 load/save), but the
#      deployed site's wp-mode homepage gets an empty landing list and
#      falls back to its no-landing render.
#
#   2. Options: point dc-puck at the frontend + name the sections field.
#      - dc_puck_editor_url  = the frontend ORIGIN, NO /editor suffix
#        (dc-puck appends "/editor/{id}"; a trailing /editor → 404).
#      - dc_puck_sections_field = dc_components (the Carbon components store).
#
# Usage (from the repo root, DDEV running):
#   MODEL=.ddev/gen/<slug>-model.json EDITOR_URL=http://localhost:4321 \
#     bash .ddev/gen/configure-puck.sh
#
# Idempotent. EDITOR_URL defaults to the spark-astro-wp dev port.

set -euo pipefail

MODEL="${MODEL:-}"
EDITOR_URL="${EDITOR_URL:-http://localhost:4321}"
SECTIONS_FIELD="${SECTIONS_FIELD:-dc_components}"

if [[ -n "$MODEL" ]]; then
  echo "→ importing model: $MODEL"
  ddev wp dc model import "$MODEL"
else
  echo "→ no MODEL given; assuming the active model already has a components field."
  echo "  (set MODEL=.ddev/gen/<slug>-model.json to import one that adds it.)"
fi

echo "→ setting dc-puck options"
ddev wp option update dc_puck_editor_url "$EDITOR_URL"
ddev wp option update dc_puck_sections_field "$SECTIONS_FIELD"

echo "→ Puck-enabled post types:"
ddev wp eval 'wp_set_current_user(1); foreach (\Dc\Puck\Admin\enabled_post_types() as $t) { echo "   - $t\n"; }'

cat <<EOF

dc-puck configured.
  editor_url     = $EDITOR_URL   (frontend origin; the plugin adds /editor/{id})
  sections_field = $SECTIONS_FIELD

Demo loop: open a Puck-enabled page in wp-admin → "Open Design Studio"
(or http://localhost:4321/editor/{id}/?token=…, mint via
 ddev wp eval 'wp_set_current_user(1); echo \\Dc\\Puck\\Token\\generate(<id>);').
The frontend must run in the SAME origin as editor_url, and WP_BASE_URL in
the frontend .env must use HTTP (Node rejects DDEV's self-signed HTTPS cert).
EOF
