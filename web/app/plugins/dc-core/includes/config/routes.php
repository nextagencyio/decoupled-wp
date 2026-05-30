<?php
/**
 * Shared route-template helpers for previews and revalidation.
 */

namespace Dc\Core\Routing;

use Dc\Core\Config;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Whether a post type should have WordPress admin view/preview links
 * rewritten to the headless frontend.
 */
function is_headless_type(string $post_type): bool
{
    $routes = Config\routes();
    $types = is_array($routes['headlessPostTypes'] ?? null) ? $routes['headlessPostTypes'] : [];
    return in_array($post_type, $types, true);
}

/**
 * Build the configured frontend path for a post, or null if no route
 * template is configured for that post type.
 */
function frontend_path_for_post(\WP_Post $post): ?string
{
    $routes = Config\routes();
    $templates = is_array($routes['templates'] ?? null) ? $routes['templates'] : [];
    $template = $templates[$post->post_type] ?? null;

    if (!is_string($template) || $template === '') {
        return null;
    }

    return render_template($template, tokens_for_post($post));
}

/**
 * @return array<int, string>
 */
function list_paths_for_post_type(string $post_type): array
{
    $routes = Config\routes();
    $list_routes = is_array($routes['listRoutes'] ?? null) ? $routes['listRoutes'] : [];
    $paths = $list_routes[$post_type] ?? [];
    return is_array($paths) ? array_values(array_filter($paths, 'is_string')) : [];
}

/**
 * @return array<int, string>
 */
function revalidate_always_paths(): array
{
    $routes = Config\routes();
    $paths = $routes['revalidateAlways'] ?? ['/'];
    return is_array($paths) ? array_values(array_filter($paths, 'is_string')) : ['/'];
}

/**
 * @param array<string, string> $tokens
 */
function render_template(string $template, array $tokens): string
{
    $path = $template;
    foreach ($tokens as $token => $value) {
        $path = str_replace('{' . $token . '}', encode_token($token, $value), $path);
    }

    return $path;
}

function encode_token(string $token, string $value): string
{
    if ($token === 'uri') {
        $segments = array_map('rawurlencode', explode('/', trim($value, '/')));
        return implode('/', $segments);
    }

    return rawurlencode($value);
}

/**
 * @return array<string, string>
 */
function tokens_for_post(\WP_Post $post): array
{
    $timestamp = strtotime($post->post_date ?: 'now') ?: time();

    return [
        'id'        => (string) $post->ID,
        'slug'      => $post->post_name,
        'uri'       => $post->post_type === 'page' ? get_page_uri($post->ID) : $post->post_name,
        'post_type' => $post->post_type,
        'year'      => gmdate('Y', $timestamp),
        'month'     => gmdate('m', $timestamp),
        'day'       => gmdate('d', $timestamp),
    ];
}
