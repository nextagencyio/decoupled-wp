<?php
/**
 * Content envelope validator.
 *
 * Validates a {version, content:{terms,media,posts}} envelope against
 * the ACTIVE model before the importer writes anything. Fail-loud: a
 * single structural error aborts the whole import (a half-seeded fresh
 * tenant is more confusing than a clean failure). Media-level problems
 * (a bad source URL) are deliberately NOT validated here — they're
 * handled as warn-and-continue at import time, since a broken image
 * shouldn't sink an otherwise-good content load.
 *
 * Returns a flat list of human-readable error strings, each prefixed
 * with a JSON-ish path so the operator can find it.
 */

namespace Spark\Core\Content;

use Spark\Core\Config;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @param array<string, mixed> $envelope
 * @return array<int, string> Error strings; empty means valid.
 */
function validate_envelope(array $envelope): array
{
    $errors = [];

    if ((int) ($envelope['version'] ?? 0) !== 1) {
        $errors[] = 'version: only content envelope version 1 is supported.';
    }

    $content = $envelope['content'] ?? null;
    if (!is_array($content)) {
        $errors[] = 'content: missing or not an object.';
        return $errors;
    }

    $terms = is_array($content['terms'] ?? null) ? $content['terms'] : [];
    $media = is_array($content['media'] ?? null) ? $content['media'] : [];
    $posts = is_array($content['posts'] ?? null) ? $content['posts'] : [];

    // Collect declared refs so post-level references can be checked.
    $media_refs = [];
    $post_refs = [];

    // ---- terms ----
    $taxonomies = Config\taxonomies();
    foreach ($terms as $i => $term) {
        $path = "content.terms[{$i}]";
        if (!is_array($term)) {
            $errors[] = "{$path}: must be an object.";
            continue;
        }
        $taxonomy = (string) ($term['taxonomy'] ?? '');
        if ($taxonomy === '') {
            $errors[] = "{$path}.taxonomy: required.";
        } elseif (!array_key_exists($taxonomy, $taxonomies) && !taxonomy_exists($taxonomy)) {
            $errors[] = "{$path}.taxonomy: '{$taxonomy}' is not in the active model.";
        }
        if ((string) ($term['slug'] ?? '') === '' && (string) ($term['name'] ?? '') === '') {
            $errors[] = "{$path}: needs at least a slug or a name.";
        }
    }

    // ---- media ----
    foreach ($media as $i => $item) {
        $path = "content.media[{$i}]";
        if (!is_array($item)) {
            $errors[] = "{$path}: must be an object.";
            continue;
        }
        $ref = (string) ($item['ref'] ?? '');
        if ($ref === '') {
            $errors[] = "{$path}.ref: required (file-local identity token).";
        } else {
            if (isset($media_refs[$ref])) {
                $errors[] = "{$path}.ref: duplicate media ref '{$ref}'.";
            }
            $media_refs[$ref] = true;
        }
        if ((string) ($item['sourceUrl'] ?? '') === '') {
            $errors[] = "{$path}.sourceUrl: required.";
        }
    }

    // First pass over posts: collect refs so associations resolve.
    foreach ($posts as $i => $post) {
        if (!is_array($post)) {
            continue;
        }
        $ref = (string) ($post['ref'] ?? '');
        if ($ref !== '') {
            if (isset($post_refs[$ref])) {
                $errors[] = "content.posts[{$i}].ref: duplicate post ref '{$ref}'.";
            }
            $post_refs[$ref] = true;
        }
    }

    // ---- posts (deep) ----
    $index = field_index();
    $valid_statuses = ['publish', 'draft', 'pending', 'private', 'future'];

    foreach ($posts as $i => $post) {
        $path = "content.posts[{$i}]";
        if (!is_array($post)) {
            $errors[] = "{$path}: must be an object.";
            continue;
        }

        $post_type = (string) ($post['postType'] ?? '');
        if ($post_type === '') {
            $errors[] = "{$path}.postType: required.";
            continue;
        }
        if (!is_importable_post_type($post_type)) {
            $errors[] = "{$path}.postType: '{$post_type}' is not a core type or in the active model.";
            continue;
        }

        if ((string) ($post['title'] ?? '') === '') {
            $errors[] = "{$path}.title: required.";
        }

        $status = (string) ($post['status'] ?? 'publish');
        if (!in_array($status, $valid_statuses, true)) {
            $errors[] = "{$path}.status: '{$status}' is not a valid WP status.";
        }

        // parent ref must resolve to a declared post ref.
        $parent = $post['parent'] ?? null;
        if (is_string($parent) && $parent !== '' && !isset($post_refs[$parent])) {
            $errors[] = "{$path}.parent: references undeclared post ref '{$parent}'.";
        }

        // terms attachment: taxonomy must be modeled + attached to type.
        $post_terms = is_array($post['terms'] ?? null) ? $post['terms'] : [];
        foreach ($post_terms as $taxonomy => $slugs) {
            $taxonomy = (string) $taxonomy;
            if (!array_key_exists($taxonomy, $taxonomies) && !taxonomy_exists($taxonomy)) {
                $errors[] = "{$path}.terms: taxonomy '{$taxonomy}' is not in the active model.";
            }
            if (!is_array($slugs)) {
                $errors[] = "{$path}.terms.{$taxonomy}: must be an array of term slugs.";
            }
        }

        // fields: each key must exist on this post type, and certain
        // shapes (image/media ref, select option) are checked.
        $fields = is_array($post['fields'] ?? null) ? $post['fields'] : [];
        $type_fields = $index[$post_type] ?? [];
        foreach ($fields as $key => $value) {
            $key = (string) $key;
            $spec = $type_fields[$key] ?? null;
            if ($spec === null) {
                $errors[] = "{$path}.fields.{$key}: not a field on post type '{$post_type}' in the active model.";
                continue;
            }
            $errors = array_merge(
                $errors,
                validate_field_value("{$path}.fields.{$key}", $spec, $value, $media_refs, $post_refs)
            );
        }
    }

    return $errors;
}

/**
 * Validate one field value against its model spec. Structural only —
 * media existence at the source URL is checked at import time.
 *
 * @param array<string, mixed> $spec
 * @param mixed $value
 * @param array<string, bool> $media_refs
 * @param array<string, bool> $post_refs
 * @return array<int, string>
 */
function validate_field_value(string $path, array $spec, $value, array $media_refs, array $post_refs): array
{
    $errors = [];
    $writer = (string) ($spec['writer'] ?? 'unsupported');

    switch ($writer) {
        case 'unsupported':
            $errors[] = "{$path}: field type is not importable.";
            break;

        case 'image':
        case 'file':
            // Accept { media: <ref> } or { url: "..." } (or a bare url string).
            if (is_array($value)) {
                if (isset($value['media'])) {
                    $ref = (string) $value['media'];
                    if (!isset($media_refs[$ref])) {
                        $errors[] = "{$path}.media: references undeclared media ref '{$ref}'.";
                    }
                } elseif (!isset($value['url'])) {
                    $errors[] = "{$path}: image/file object needs 'media' (ref) or 'url'.";
                }
            } elseif (!is_string($value)) {
                $errors[] = "{$path}: image/file must be a url string or { media | url } object.";
            }
            break;

        case 'select':
            $options = is_array($spec['options'] ?? null) ? $spec['options'] : [];
            $vals = is_array($value) ? $value : [$value];
            foreach ($vals as $v) {
                if (!array_key_exists((string) $v, $options) && !in_array((string) $v, $options, true)) {
                    $errors[] = "{$path}: '{$v}' is not an allowed option.";
                }
            }
            break;

        case 'intro':
            if (!is_array($value)) {
                $errors[] = "{$path}: introParagraphs must be an array of strings.";
            }
            break;

        case 'gallery':
            if (!is_array($value)) {
                $errors[] = "{$path}: galleryImages must be an array of image refs/objects.";
            }
            break;

        case 'components':
            if (!is_array($value)) {
                $errors[] = "{$path}: components must be an array of component objects.";
                break;
            }
            foreach ($value as $j => $row) {
                if (!is_array($row) || (string) ($row['type'] ?? '') === '') {
                    $errors[] = "{$path}[{$j}]: each component needs a 'type'.";
                }
            }
            break;

        case 'complex':
            if (!is_array($value)) {
                $errors[] = "{$path}: complex field must be an array of rows.";
            }
            break;

        case 'association':
            if (!is_array($value)) {
                $errors[] = "{$path}: association must be an array.";
                break;
            }
            foreach ($value as $j => $rel) {
                if (!is_array($rel)) {
                    $errors[] = "{$path}[{$j}]: association entry must be an object.";
                    continue;
                }
                if (isset($rel['post']) && !isset($post_refs[(string) $rel['post']])) {
                    $errors[] = "{$path}[{$j}].post: references undeclared post ref '{$rel['post']}'.";
                }
            }
            break;

        // scalar, bool: accept as-is; WP/Carbon coerce.
    }

    return $errors;
}
