<?php
/**
 * WP-CLI commands for the Spark content model.
 *
 * The operator workflow for a no-custom-code host (decoupled.io): push a
 * content model onto an instance as DATA, never as a PHP file. The model
 * is validated by the trusted plugin and stored in the
 * `spark_content_model_active` option; spark-core interprets it at
 * runtime. No executable code ever comes from the model.
 *
 *   wp spark model export [--pretty] [> model.json]
 *   wp spark model validate <file.json|->
 *   wp spark model import <file.json|->        (validate + store + flush)
 *   wp spark model use <name>                  (a bundled models/<name>.json)
 *   wp spark model list                        (bundled model names)
 *   wp spark model reset                       (back to the built-in default)
 */

namespace Spark\Core\CLI;

use Spark\Core\Config;

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

\WP_CLI::add_command('spark model', __NAMESPACE__ . '\\Model_Command');
\WP_CLI::add_command('spark content', __NAMESPACE__ . '\\Content_Command');

/**
 * Manage the Spark content model as data (no PHP uploads).
 */
class Model_Command
{
    /**
     * Print the active content model as JSON.
     *
     * ## OPTIONS
     *
     * [--pretty]
     * : Pretty-print the JSON.
     *
     * @when after_wp_load
     */
    public function export($args, $assoc): void
    {
        $flags = JSON_UNESCAPED_SLASHES
            | (isset($assoc['pretty']) ? JSON_PRETTY_PRINT : 0);
        \WP_CLI::line((string) wp_json_encode(Config\model(), $flags));
    }

    /**
     * Validate a model JSON file without storing it.
     *
     * ## OPTIONS
     *
     * <file>
     * : Path to a JSON file, or `-` to read STDIN.
     *
     * @when after_wp_load
     */
    public function validate($args): void
    {
        $model = $this->read_json($args[0]);
        $errors = Config\validate_model(Config\normalize_model($model));
        if ($errors !== []) {
            foreach ($errors as $e) {
                \WP_CLI::warning($e);
            }
            \WP_CLI::error(count($errors) . ' validation error(s).');
        }
        \WP_CLI::success('Model is valid.');
    }

    /**
     * Validate and store a model JSON file as the active model.
     *
     * ## OPTIONS
     *
     * <file>
     * : Path to a JSON file, or `-` to read STDIN.
     *
     * @when after_wp_load
     */
    public function import($args): void
    {
        $model = $this->read_json($args[0]);
        $errors = Config\save_model($model);
        if ($errors !== []) {
            foreach ($errors as $e) {
                \WP_CLI::warning($e);
            }
            \WP_CLI::error('Model rejected — not stored.');
        }
        \WP_CLI::success('Model imported, stored, and rewrite rules flushed.');
    }

    /**
     * Activate a bundled model shipped with the plugin (models/<name>.json).
     *
     * ## OPTIONS
     *
     * <name>
     * : The bundled model name (without .json).
     *
     * @when after_wp_load
     */
    public function use($args): void
    {
        $name = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $args[0]));
        $path = SPARK_CORE_DIR . 'models/' . $name . '.json';
        if (!is_file($path)) {
            \WP_CLI::error("No bundled model '{$name}'. Run `wp spark model list`.");
        }
        $model = $this->read_json($path);
        $errors = Config\save_model($model);
        if ($errors !== []) {
            foreach ($errors as $e) {
                \WP_CLI::warning($e);
            }
            \WP_CLI::error("Bundled model '{$name}' failed validation.");
        }
        \WP_CLI::success("Activated bundled model '{$name}'.");
    }

    /**
     * List the bundled models shipped with the plugin.
     *
     * @when after_wp_load
     */
    public function list(): void
    {
        $dir = SPARK_CORE_DIR . 'models';
        $files = is_dir($dir) ? glob($dir . '/*.json') : [];
        if (!$files) {
            \WP_CLI::line('(no bundled models)');
            return;
        }
        foreach ($files as $f) {
            \WP_CLI::line(basename($f, '.json'));
        }
    }

    /**
     * Reset to the plugin's built-in default model.
     *
     * @when after_wp_load
     */
    public function reset(): void
    {
        Config\reset_model();
        \WP_CLI::success('Reset to the built-in default model.');
    }

    /**
     * Read + JSON-decode a file path or STDIN (`-`). Errors out the
     * command on read / parse failure.
     *
     * @return array<string, mixed>
     */
    private function read_json(string $source): array
    {
        $raw = $source === '-'
            ? file_get_contents('php://stdin')
            : (is_file($source) ? file_get_contents($source) : false);

        if ($raw === false) {
            \WP_CLI::error("Cannot read model source: {$source}");
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            \WP_CLI::error('Invalid JSON: ' . json_last_error_msg());
        }
        return $decoded;
    }
}

/**
 * Import CONTENT (posts / terms / media / field values) as data.
 *
 * The model defines the shape (`wp spark model`); this loads actual
 * content against that shape from a JSON envelope. The WP analogue of
 * Drupal's dc_import.
 *
 *   wp spark content example [--pretty]      print a valid envelope
 *   wp spark content validate <file.json|->  validate, don't write
 *   wp spark content import <file.json|->     validate + import
 *   wp spark content seed [<name>]            import a bundled seed
 *   wp spark content seeds                    list bundled seeds
 */
class Content_Command
{
    /**
     * Print a canonical minimal content envelope.
     *
     * ## OPTIONS
     *
     * [--pretty]
     * : Pretty-print the JSON.
     *
     * @when after_wp_load
     */
    public function example($args, $assoc): void
    {
        $flags = JSON_UNESCAPED_SLASHES
            | (isset($assoc['pretty']) ? JSON_PRETTY_PRINT : 0);
        \WP_CLI::line((string) wp_json_encode(\Spark\Core\Content\example_envelope(), $flags));
    }

    /**
     * Validate a content envelope against the active model. No writes.
     *
     * ## OPTIONS
     *
     * <file>
     * : Path to a JSON file, or `-` to read STDIN.
     *
     * @when after_wp_load
     */
    public function validate($args): void
    {
        $envelope = $this->read_json($args[0]);
        $errors = \Spark\Core\Content\validate_envelope($envelope);
        if ($errors !== []) {
            foreach ($errors as $e) {
                \WP_CLI::warning($e);
            }
            \WP_CLI::error(count($errors) . ' validation error(s).');
        }
        \WP_CLI::success('Content envelope is valid against the active model.');
    }

    /**
     * Validate and import a content envelope.
     *
     * ## OPTIONS
     *
     * <file>
     * : Path to a JSON file, or `-` to read STDIN.
     *
     * @when after_wp_load
     */
    public function import($args): void
    {
        $envelope = $this->read_json($args[0]);
        $result = \Spark\Core\Content\import_envelope($envelope);

        if (!$result['ok']) {
            foreach ($result['errors'] as $e) {
                \WP_CLI::warning($e);
            }
            \WP_CLI::error('Content rejected — nothing imported.');
        }

        foreach ($result['warnings'] as $w) {
            \WP_CLI::warning($w);
        }

        $s = $result['summary'];
        \WP_CLI::log(sprintf(
            'terms: +%d ~%d | media: %d | posts: +%d ~%d',
            $s['terms']['created'] ?? 0,
            $s['terms']['updated'] ?? 0,
            $s['media']['imported'] ?? 0,
            $s['posts']['created'] ?? 0,
            $s['posts']['updated'] ?? 0
        ));
        \WP_CLI::success('Content import complete.');
    }

    /**
     * Import a content seed bundled with the plugin (seed/<name>.json).
     *
     * The starter-content analogue of `wp spark model use`: a fresh
     * tenant runs this to populate the demo landing page so the paired
     * Astro frontend looks identical to the Drupal `decoupled-components`
     * starter out of the box.
     *
     * ## OPTIONS
     *
     * [<name>]
     * : The bundled seed name (without .json). Defaults to `launchpad`.
     *
     * @when after_wp_load
     */
    public function seed($args): void
    {
        $name = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($args[0] ?? 'launchpad')));
        $path = SPARK_CORE_DIR . 'seed/' . $name . '.json';
        if (!is_file($path)) {
            \WP_CLI::error("No bundled seed '{$name}'. Run `wp spark content seeds`.");
        }

        $envelope = $this->read_json($path);
        $result = \Spark\Core\Content\import_envelope($envelope);

        if (!$result['ok']) {
            foreach ($result['errors'] as $e) {
                \WP_CLI::warning($e);
            }
            \WP_CLI::error("Seed '{$name}' rejected — nothing imported.");
        }

        foreach ($result['warnings'] as $w) {
            \WP_CLI::warning($w);
        }

        $s = $result['summary'];
        \WP_CLI::log(sprintf(
            'terms: +%d ~%d | media: %d | posts: +%d ~%d',
            $s['terms']['created'] ?? 0,
            $s['terms']['updated'] ?? 0,
            $s['media']['imported'] ?? 0,
            $s['posts']['created'] ?? 0,
            $s['posts']['updated'] ?? 0
        ));
        \WP_CLI::success("Seed '{$name}' imported.");
    }

    /**
     * List the content seeds bundled with the plugin (seed/*.json).
     *
     * @when after_wp_load
     */
    public function seeds(): void
    {
        $dir = SPARK_CORE_DIR . 'seed';
        $files = is_dir($dir) ? glob($dir . '/*.json') : [];
        if (!$files) {
            \WP_CLI::line('(no bundled seeds)');
            return;
        }
        foreach ($files as $f) {
            \WP_CLI::line(basename($f, '.json'));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function read_json(string $source): array
    {
        $raw = $source === '-'
            ? file_get_contents('php://stdin')
            : (is_file($source) ? file_get_contents($source) : false);

        if ($raw === false) {
            \WP_CLI::error("Cannot read content source: {$source}");
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            \WP_CLI::error('Invalid JSON: ' . json_last_error_msg());
        }
        return $decoded;
    }
}
