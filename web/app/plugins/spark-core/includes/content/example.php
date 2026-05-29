<?php
/**
 * Canonical minimal content envelope, used by `wp spark content example`.
 *
 * Mirrors the default model shipped in config/defaults.php (page +
 * spark_resource, hero image, intro paragraphs, components, gallery).
 * The WP analogue of Drupal's get_import_example.
 */

namespace Spark\Core\Content;

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
                    'taxonomy'    => 'spark_topic',
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
                        'spark_hero_image'       => ['media' => 'hero-home'],
                        'spark_hero_alt'         => 'City hall at dusk',
                        'spark_intro_paragraphs' => [
                            'First intro paragraph.',
                            'Second intro paragraph.',
                        ],
                    ],
                ],
                [
                    'ref'      => 'resource-guide',
                    'postType' => 'spark_resource',
                    'status'   => 'publish',
                    'title'    => 'Getting Started Guide',
                    'slug'     => 'getting-started',
                    'content'  => '<p>The body of the resource.</p>',
                    'terms'    => [
                        'spark_topic' => ['accessibility'],
                    ],
                    'fields'   => [
                        'spark_hero_image'       => ['media' => 'hero-home'],
                        'spark_hero_alt'         => 'City hall at dusk',
                        'spark_intro_paragraphs' => ['An intro line.'],
                        'spark_components'       => [
                            ['type' => 'richtext', 'html' => '<p>A rich text component.</p>'],
                            [
                                'type'         => 'cta',
                                'heading'      => 'Apply today',
                                'text'         => 'Spaces are limited.',
                                'button_label' => 'Apply now',
                                'button_url'   => '/apply/',
                            ],
                        ],
                        'spark_gallery_images'   => [
                            ['media' => 'hero-home', 'alt' => 'City hall at dusk'],
                        ],
                    ],
                ],
            ],
        ],
    ];
}
