<?php
/**
 * Canonical minimal content envelope, used by `wp dc content example`.
 *
 * Mirrors the default model shipped in config/defaults.php (page +
 * dc_resource, hero image, intro paragraphs, components, gallery).
 * The WP analogue of Drupal's get_import_example.
 */

namespace Dc\Core\Content;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @return array<string, mixed>
 */
function example_envelope(): array
{
    return [
        'version' => 1,
        'content' => [
            'terms' => [
                [
                    'taxonomy'    => 'dc_topic',
                    'slug'        => 'accessibility',
                    'name'        => 'Accessibility',
                    'description' => 'Content about accessibility.',
                ],
            ],
            'media' => [
                [
                    'ref'       => 'hero-home',
                    'sourceUrl' => 'https://images.example.com/hero-home.jpg',
                    'alt'       => 'City hall at dusk',
                    'title'     => 'Hero — Home',
                ],
            ],
            'posts' => [
                [
                    'ref'      => 'page-home',
                    'postType' => 'page',
                    'status'   => 'publish',
                    'title'    => 'Home',
                    'slug'     => 'home',
                    'content'  => '<p>Standard WordPress editor HTML lives here.</p>',
                    'fields'   => [
                        'dc_hero_image'       => ['media' => 'hero-home'],
                        'dc_hero_alt'         => 'City hall at dusk',
                        'dc_intro_paragraphs' => [
                            'First intro paragraph.',
                            'Second intro paragraph.',
                        ],
                    ],
                ],
                [
                    'ref'      => 'resource-guide',
                    'postType' => 'dc_resource',
                    'status'   => 'publish',
                    'title'    => 'Getting Started Guide',
                    'slug'     => 'getting-started',
                    'content'  => '<p>The body of the resource.</p>',
                    'terms'    => [
                        'dc_topic' => ['accessibility'],
                    ],
                    'fields'   => [
                        'dc_hero_image'       => ['media' => 'hero-home'],
                        'dc_hero_alt'         => 'City hall at dusk',
                        'dc_intro_paragraphs' => ['An intro line.'],
                        'dc_components'       => [
                            ['type' => 'richtext', 'html' => '<p>A rich text component.</p>'],
                            [
                                'type'         => 'cta',
                                'heading'      => 'Apply today',
                                'text'         => 'Spaces are limited.',
                                'button_label' => 'Apply now',
                                'button_url'   => '/apply/',
                            ],
                        ],
                        'dc_gallery_images'   => [
                            ['media' => 'hero-home', 'alt' => 'City hall at dusk'],
                        ],
                    ],
                ],
            ],
        ],
    ];
}
