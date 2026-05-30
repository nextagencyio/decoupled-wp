<?php
/**
 * Default Decoupled content model.
 *
 * This data mirrors the starter's original hardcoded model. It is the
 * first step toward hosted, config-driven projects: PHP remains trusted
 * plugin code, while per-project structure can eventually come from a
 * validated JSON payload.
 */

namespace Dc\Core\Config;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The built-in content model used when no project-specific model has
 * been supplied.
 *
 * @return array<string, mixed>
 */
function default_model(): array
{
    return [
        'version' => 1,
        'postTypes' => [
            'dc_landing' => [
                'labels' => [
                    'name'          => 'Landing Pages',
                    'singular_name' => 'Landing Page',
                    'add_new_item'  => 'Add new landing page',
                    'edit_item'     => 'Edit landing page',
                    'new_item'      => 'New landing page',
                    'view_item'     => 'View landing page',
                    'all_items'     => 'All landing pages',
                ],
                'public'              => true,
                'has_archive'         => false,
                'rewrite'             => ['slug' => 'landing', 'with_front' => false],
                'show_in_rest'        => false,
                'show_in_graphql'     => true,
                'graphql_single_name' => 'landing',
                'graphql_plural_name' => 'landings',
                'menu_icon'           => 'dashicons-layout',
                'menu_position'       => 19,
                // No 'editor': a landing page is built entirely from the
                // component palette (Design Studio / the Components tab),
                // so the WYSIWYG body would only confuse authors.
                'supports'            => ['title', 'thumbnail', 'revisions', 'page-attributes'],
            ],
            'dc_resource' => [
                'labels' => [
                    'name'          => 'Resources',
                    'singular_name' => 'Resource',
                    'add_new_item'  => 'Add new resource',
                    'edit_item'     => 'Edit resource',
                    'new_item'      => 'New resource',
                    'view_item'     => 'View resource',
                    'all_items'     => 'All resources',
                ],
                'public'              => true,
                'has_archive'         => 'resources',
                'rewrite'             => ['slug' => 'resources', 'with_front' => false],
                'show_in_rest'        => false,
                'show_in_graphql'     => true,
                'graphql_single_name' => 'resource',
                'graphql_plural_name' => 'resources',
                'menu_icon'           => 'dashicons-portfolio',
                'menu_position'       => 20,
                'supports'            => ['title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'page-attributes'],
                'capability_type'     => ['dc_resource', 'dc_resources'],
                'map_meta_cap'        => true,
            ],
        ],
        'taxonomies' => [
            'dc_topic' => [
                'object_type' => ['dc_resource', 'post'],
                'args' => [
                    'labels' => [
                        'name'          => 'Topics',
                        'singular_name' => 'Topic',
                        'add_new_item'  => 'Add new topic',
                        'edit_item'     => 'Edit topic',
                        'all_items'     => 'All topics',
                    ],
                    'public'              => true,
                    'hierarchical'        => true,
                    'show_in_rest'        => false,
                    'show_in_graphql'     => true,
                    'graphql_single_name' => 'topic',
                    'graphql_plural_name' => 'topics',
                    'rewrite'             => ['slug' => 'topic', 'with_front' => false],
                ],
            ],
        ],
        'fieldGroups' => [
            [
                'id'       => 'page_details',
                'label'    => 'Page details',
                'postType' => 'page',
                'tabs'     => [
                    [
                        'label'  => 'Overview',
                        'fields' => [
                            [
                                'type'       => 'image',
                                'key'        => 'dc_hero_image',
                                'label'      => 'Hero image',
                                'value_type' => 'url',
                                'help_text'  => 'Large image shown at the top of the page.',
                            ],
                            [
                                'type'      => 'text',
                                'key'       => 'dc_hero_alt',
                                'label'     => 'Hero alt text',
                                'help_text' => 'Describe the hero image for screen readers.',
                            ],
                            [
                                'type'      => 'preset',
                                'preset'    => 'introParagraphs',
                                'key'       => 'dc_intro_paragraphs',
                                'help_text' => 'Short lead paragraphs shown above the body. The main body is the editor above.',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'id'       => 'landing_components',
                'label'    => 'Landing page',
                'postType' => 'dc_landing',
                'tabs'     => [
                    [
                        'label'  => 'Components',
                        'fields' => [
                            [
                                'type'      => 'preset',
                                'preset'    => 'components',
                                'key'       => 'dc_components',
                                'help_text' => 'Build the page from the component palette: hero, cards, pricing, testimonials, stats, FAQ, newsletter, and more. Drag to reorder.',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'id'       => 'resource_details',
                'label'    => 'Resource details',
                'postType' => 'dc_resource',
                'tabs'     => [
                    [
                        'label'  => 'Overview',
                        'fields' => [
                            [
                                'type'       => 'image',
                                'key'        => 'dc_hero_image',
                                'label'      => 'Hero image',
                                'value_type' => 'url',
                            ],
                            [
                                'type'  => 'text',
                                'key'   => 'dc_hero_alt',
                                'label' => 'Hero alt text',
                            ],
                            [
                                'type'      => 'preset',
                                'preset'    => 'introParagraphs',
                                'key'       => 'dc_intro_paragraphs',
                                'help_text' => 'Short lead paragraphs shown above the body.',
                            ],
                        ],
                    ],
                    [
                        'label'  => 'Components',
                        'fields' => [
                            [
                                'type'      => 'preset',
                                'preset'    => 'components',
                                'key'       => 'dc_components',
                                'help_text' => 'Optional. Add galleries, calls to action, or embeds. For a run of ordinary text, add one Rich-text block — or just use the main editor above.',
                            ],
                        ],
                    ],
                    [
                        'label'  => 'Gallery',
                        'fields' => [
                            [
                                'type'      => 'preset',
                                'preset'    => 'galleryImages',
                                'key'       => 'dc_gallery_images',
                                'help_text' => 'Photos shown on the resource. Drag to reorder.',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'graphql' => [
            'sharedPostTypes' => ['page', 'dc_resource', 'dc_landing'],
            'fields' => [
                'heroImage' => [
                    'type'        => 'DcImage',
                    'description' => 'Hero image shown at the top of the content.',
                    'resolver'    => 'heroImage',
                    'srcKey'      => 'dc_hero_image',
                    'altKey'      => 'dc_hero_alt',
                ],
                'introParagraphs' => [
                    'type'        => ['list_of' => 'String'],
                    'description' => 'Lead paragraphs shown above the body.',
                    'resolver'    => 'introParagraphs',
                    'key'         => 'dc_intro_paragraphs',
                ],
                'bodyHtml' => [
                    'type'        => 'String',
                    'description' => 'The page body — rendered HTML from the standard WordPress editor.',
                    'resolver'    => 'bodyHtml',
                ],
                'components' => [
                    'type'        => ['list_of' => 'DcComponent'],
                    'description' => 'Optional complex components (gallery / CTA / embed / rich text) shown after the body.',
                    'resolver'    => 'components',
                    'key'         => 'dc_components',
                ],
                'galleryImages' => [
                    'type'        => ['list_of' => 'DcImage'],
                    'description' => 'Gallery images for the content.',
                    'resolver'    => 'galleryImages',
                    'key'         => 'dc_gallery_images',
                ],
                'metaDescription' => [
                    'type'        => 'String',
                    'description' => 'SEO meta description for the headless frontend.',
                    'resolver'    => 'metaDescription',
                ],
                'catalogSlug' => [
                    'type'        => 'String',
                    'description' => 'Optional routing-slug override for the headless frontend.',
                    'resolver'    => 'catalogSlug',
                ],
            ],
        ],
        'routes' => [
            'headlessPostTypes' => ['dc_resource', 'dc_landing', 'page'],
            'templates' => [
                'dc_landing'  => '/{slug}/',
                'dc_resource' => '/resources/{slug}/',
                'page'           => '/{uri}/',
                'post'           => '/blog/{slug}/',
            ],
            'listRoutes' => [
                'dc_resource' => ['/resources/'],
                'post'           => ['/blog/'],
            ],
            'revalidateAlways' => ['/'],
        ],
    ];
}
