<?php
/**
 * Body content fields — the WYSIWYG-first pattern.
 *
 * IMPORTANT — the content-model rule this file enforces:
 *
 *   Ordinary prose body content (headings, paragraphs, lists,
 *   inline images, links) belongs in the STANDARD WordPress
 *   `post_content` WYSIWYG — NOT in a Carbon Fields complex field
 *   with one group per element. Making an editor add a CF block per
 *   heading and per paragraph is slower and worse than the page
 *   builder a decoupled redesign is meant to replace.
 *
 *   Carbon Fields is for COMPLEX COMPONENTS only — galleries, CTA
 *   bands, embeds, stat rows — things a WYSIWYG genuinely cannot
 *   express. When such components must interleave with prose, the
 *   components field's DEFAULT block is a single "Rich text" block
 *   (one `rich_text` field holding a whole section of prose), NOT
 *   one-block-per-paragraph.
 *
 * So a content type gets:
 *   1. `post_content` — the WYSIWYG body (always; nothing to set up).
 *   2. OPTIONALLY `spark_components` — a CF flexible field for
 *      complex components, only when the type needs galleries / CTAs
 *      / embeds mixed into the page. Most types don't.
 *
 * The frontend receives:
 *   { bodyHtml: string,          // rendered post_content
 *     components: Component[] }   // [] when there are none
 *
 * A Component is { kind: 'richtext'|'gallery'|'cta'|'embed', ... }.
 *
 * These helpers are intentionally namespace-free `spark_*` functions
 * so templates and other includes can call them directly.
 */

if (!defined('ABSPATH')) {
    exit;
}

use Carbon_Fields\Field;

if (!function_exists('spark_make_components_field')) {
    /**
     * Build the optional "complex components" flexible field.
     *
     * Attach this ONLY to content types that need galleries / CTAs /
     * embeds interleaved with prose. A plain text-and-images content
     * type needs nothing here — its `post_content` WYSIWYG is enough.
     *
     * The first block type is "Rich text": a single rich-text field
     * holding a whole run of prose. That is how prose interleaves
     * with components — few large rich-text blocks, not one block
     * per paragraph.
     *
     * @param string $name  Carbon Fields meta key (e.g. 'spark_components').
     * @return \Carbon_Fields\Field\Complex_Field
     */
    function spark_make_components_field(string $name = 'spark_components')
    {
        return Field::make('complex', $name, __('Page components', 'spark-core'))
            ->set_layout('grid')
            ->set_collapsed(true)
            ->setup_labels([
                'singular_name' => __('Component', 'spark-core'),
                'plural_name'   => __('Components', 'spark-core'),
            ])
            // — Rich text: a whole section of prose. The DEFAULT block.
            //   This is what prose looks like inside the components
            //   field — never a per-paragraph block.
            ->add_fields('richtext', __('Rich text', 'spark-core'), [
                Field::make('rich_text', 'html', __('Text', 'spark-core')),
            ])
            // — Gallery: an image grid. A real complex component.
            ->add_fields('gallery', __('Image gallery', 'spark-core'), [
                Field::make('complex', 'images', __('Images', 'spark-core'))
                    ->set_layout('grid')
                    ->add_fields([
                        Field::make('image', 'src', __('Image', 'spark-core'))
                            ->set_value_type('url'),
                        Field::make('text', 'alt', __('Alt text', 'spark-core')),
                    ]),
            ])
            // — Call to action: heading + text + button.
            ->add_fields('cta', __('Call to action', 'spark-core'), [
                Field::make('text', 'heading', __('Heading', 'spark-core')),
                Field::make('textarea', 'text', __('Text', 'spark-core'))
                    ->set_rows(2),
                Field::make('text', 'button_label', __('Button label', 'spark-core'))
                    ->set_width(50),
                Field::make('text', 'button_url', __('Button URL', 'spark-core'))
                    ->set_width(50),
            ])
            // — Embed: a third-party iframe/script (map, flipbook, etc.).
            ->add_fields('embed', __('Embed', 'spark-core'), [
                Field::make('textarea', 'embed_code', __('Embed code / URL', 'spark-core'))
                    ->set_rows(3),
                Field::make('text', 'caption', __('Caption', 'spark-core')),
            ]);
    }
}

if (!function_exists('spark_make_intro_field')) {
    /**
     * Intro-paragraph repeater — a flat array of short lead
     * paragraphs shown above the body. A genuinely structured field
     * (each intro paragraph is a distinct, separately-placed element)
     * so a simple one-text-field Complex is the right tool here.
     *
     * @param string $name  Carbon Fields meta key.
     * @return \Carbon_Fields\Field\Complex_Field
     */
    function spark_make_intro_field(string $name = 'spark_intro_paragraphs')
    {
        return Field::make('complex', $name, __('Intro paragraphs', 'spark-core'))
            ->set_layout('grid')
            ->setup_labels([
                'singular_name' => __('Paragraph', 'spark-core'),
                'plural_name'   => __('Paragraphs', 'spark-core'),
            ])
            ->add_fields([
                Field::make('textarea', 'text', __('Paragraph', 'spark-core'))
                    ->set_rows(3),
            ]);
    }
}

if (!function_exists('spark_make_gallery_field')) {
    /**
     * Standalone gallery / logo-strip repeater — image + alt pairs.
     * For a content type whose gallery is a fixed, top-level field
     * (not interleaved with prose).
     *
     * @param string $name  Carbon Fields meta key.
     * @return \Carbon_Fields\Field\Complex_Field
     */
    function spark_make_gallery_field(string $name = 'spark_gallery_images')
    {
        return Field::make('complex', $name, __('Gallery images', 'spark-core'))
            ->set_layout('grid')
            ->add_fields([
                Field::make('image', 'image', __('Image', 'spark-core'))
                    ->set_value_type('url'),
                Field::make('text', 'alt', __('Alt text', 'spark-core')),
            ]);
    }
}

if (!function_exists('spark_intro_to_strings')) {
    /**
     * Convert a stored intro-paragraphs Complex value into a flat
     * array of strings — the shape the frontend wants.
     *
     * @param mixed $raw  carbon_get_post_meta(...) value.
     * @return array<int, string>
     */
    function spark_intro_to_strings($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            $text = trim((string) ($row['text'] ?? ''));
            if ($text !== '') {
                $out[] = $text;
            }
        }
        return $out;
    }
}

if (!function_exists('spark_gallery_to_images')) {
    /**
     * Convert a stored gallery Complex value into a flat array of
     * {src, alt} pairs.
     *
     * @param mixed $raw  carbon_get_post_meta(...) value.
     * @return array<int, array{src: string, alt: string}>
     */
    function spark_gallery_to_images($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            $src = (string) ($row['image'] ?? '');
            if ($src === '') {
                continue;
            }
            $out[] = ['src' => $src, 'alt' => (string) ($row['alt'] ?? '')];
        }
        return $out;
    }
}

if (!function_exists('spark_components_to_array')) {
    /**
     * Convert a stored components Complex value into a flat array of
     * typed components for the GraphQL layer / frontend.
     *
     * Each component: { kind, ... }. `richtext` carries rendered
     * HTML; `gallery` carries {src,alt}[]; `cta` carries heading +
     * text + button; `embed` carries code + caption.
     *
     * @param mixed $raw  carbon_get_post_meta(...) value.
     * @return array<int, array<string, mixed>>
     */
    function spark_components_to_array($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            // Carbon Fields stores the active group key in `_type`.
            $kind = $row['_type'] ?? '';
            switch ($kind) {
                case 'richtext':
                    $out[] = [
                        'kind' => 'richtext',
                        'html' => (string) ($row['html'] ?? ''),
                    ];
                    break;
                case 'gallery':
                    $images = [];
                    foreach (($row['images'] ?? []) as $img) {
                        $src = (string) ($img['src'] ?? '');
                        if ($src === '') {
                            continue;
                        }
                        $images[] = [
                            'src' => $src,
                            'alt' => (string) ($img['alt'] ?? ''),
                        ];
                    }
                    $out[] = ['kind' => 'gallery', 'images' => $images];
                    break;
                case 'cta':
                    $out[] = [
                        'kind'        => 'cta',
                        'heading'     => (string) ($row['heading'] ?? ''),
                        'text'        => (string) ($row['text'] ?? ''),
                        'buttonLabel' => (string) ($row['button_label'] ?? ''),
                        'buttonUrl'   => (string) ($row['button_url'] ?? ''),
                    ];
                    break;
                case 'embed':
                    $out[] = [
                        'kind'    => 'embed',
                        'code'    => (string) ($row['embed_code'] ?? ''),
                        'caption' => (string) ($row['caption'] ?? ''),
                    ];
                    break;
            }
        }
        return $out;
    }
}
