<?php
/**
 * Bundled-model loader.
 *
 * On a no-custom-code host (decoupled.io) a project model is DATA, not a
 * PHP file. Vetted models ship as JSON under the plugin's models/
 * directory. Selecting one with DC_CONTENT_MODEL (env or constant)
 * loads models/<name>.json through the same validation path as an
 * imported or admin-saved model — no executable code from the model.
 *
 * Precedence (see loader.php `model()`):
 *   1. stored option (admin screen / `wp dc model import`)
 *   2. this filter (DC_CONTENT_MODEL bundled JSON)
 *   3. built-in default_model()
 *
 * So an explicitly imported model always wins; the env var is the
 * provisioning default for a fresh instance.
 */

namespace Dc\Core\Config\Bundled;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @return array<string, mixed>|null
 */
function load(string $name): ?array
{
    $name = preg_replace('/[^a-z0-9_-]/', '', strtolower($name));
    if ($name === '') {
        return null;
    }
    $path = DC_CORE_DIR . 'models/' . $name . '.json';
    if (!is_file($path)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? $decoded : null;
}

add_filter('dc_core_content_model', static function ($model) {
    // DC_CONTENT_MODEL is the PROVISIONING DEFAULT for a fresh
    // instance — it must NOT override a model an operator explicitly
    // stored (admin screen / `wp dc model import|use`). If a stored
    // option exists, that wins; the env default only fills the empty case.
    if (is_array(\Dc\Core\Config\stored_model())) {
        return $model;
    }
    $selected = defined('DC_CONTENT_MODEL')
        ? DC_CONTENT_MODEL
        : (getenv('DC_CONTENT_MODEL') ?: '');
    if ($selected === '') {
        return $model;
    }
    $bundled = load((string) $selected);
    return is_array($bundled) ? $bundled : $model;
});
