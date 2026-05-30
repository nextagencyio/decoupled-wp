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
 *   2. OPTIONALLY `dc_components` — a CF flexible field for
 *      complex components, only when the type needs galleries / CTAs
 *      / embeds mixed into the page. Most types don't.
 *
 * The frontend receives:
 *   { bodyHtml: string,          // rendered post_content
 *     components: Component[] }   // [] when there are none
 *
 * A Component is { kind: 'richtext'|'gallery'|'cta'|'embed', ... }.
 *
 * These helpers are intentionally namespace-free `dc_*` functions
 * so templates and other includes can call them directly.
 */

if (!defined('ABSPATH')) {
    exit;
}

use Carbon_Fields\Field;

if (!function_exists('dc_make_components_field')) {
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
     * @param string $name  Carbon Fields meta key (e.g. 'dc_components').
     * @return \Carbon_Fields\Field\Complex_Field
     */
    function dc_make_components_field(string $name = 'dc_components')
    {
        return Field::make('complex', $name, __('Page components', 'dc-core'))
            ->set_layout('grid')
            ->set_collapsed(true)
            ->setup_labels([
                'singular_name' => __('Component', 'dc-core'),
                'plural_name'   => __('Components', 'dc-core'),
            ])
            // — Rich text: a whole section of prose. The DEFAULT block.
            //   This is what prose looks like inside the components
            //   field — never a per-paragraph block.
            ->add_fields('richtext', __('Rich text', 'dc-core'), [
                Field::make('rich_text', 'html', __('Text', 'dc-core')),
            ])
            // — Gallery: an image grid. A real complex component.
            ->add_fields('gallery', __('Image gallery', 'dc-core'), [
                Field::make('complex', 'images', __('Images', 'dc-core'))
                    ->set_layout('grid')
                    ->add_fields([
                        Field::make('image', 'src', __('Image', 'dc-core'))
                            ->set_value_type('url'),
                        Field::make('text', 'alt', __('Alt text', 'dc-core')),
                    ]),
            ])
            // — Call to action: heading + text + button.
            ->add_fields('cta', __('Call to action', 'dc-core'), [
                Field::make('text', 'heading', __('Heading', 'dc-core')),
                Field::make('textarea', 'text', __('Text', 'dc-core'))
                    ->set_rows(2),
                Field::make('text', 'button_label', __('Button label', 'dc-core'))
                    ->set_width(50),
                Field::make('text', 'button_url', __('Button URL', 'dc-core'))
                    ->set_width(50),
            ])
            // — Embed: a third-party iframe/script (map, flipbook, etc.).
            ->add_fields('embed', __('Embed', 'dc-core'), [
                Field::make('textarea', 'embed_code', __('Embed code / URL', 'dc-core'))
                    ->set_rows(3),
                Field::make('text', 'caption', __('Caption', 'dc-core')),
            ])
            // — Hero: headline section with optional bg image + CTA pair.
            ->add_fields('hero', __('Hero', 'dc-core'), [
                Field::make('text', 'eyebrow', __('Eyebrow', 'dc-core')),
                Field::make('text', 'title', __('Title', 'dc-core')),
                Field::make('textarea', 'subtitle', __('Subtitle', 'dc-core'))->set_rows(2),
                Field::make('select', 'layout', __('Layout', 'dc-core'))
                    ->set_options(['centered' => 'Centered', 'left-aligned' => 'Left aligned'])
                    ->set_default_value('centered'),
                Field::make('image', 'background_image', __('Background image', 'dc-core'))->set_value_type('url'),
                Field::make('text', 'primary_cta_text', __('Primary CTA text', 'dc-core'))->set_width(50),
                Field::make('text', 'primary_cta_url', __('Primary CTA URL', 'dc-core'))->set_width(50),
                Field::make('text', 'secondary_cta_text', __('Secondary CTA text', 'dc-core'))->set_width(50),
                Field::make('text', 'secondary_cta_url', __('Secondary CTA URL', 'dc-core'))->set_width(50),
            ])
            // — Text block: eyebrow + title + rich content + optional CTA.
            ->add_fields('textblock', __('Text block', 'dc-core'), [
                Field::make('text', 'eyebrow', __('Eyebrow', 'dc-core')),
                Field::make('text', 'title', __('Title', 'dc-core')),
                Field::make('rich_text', 'content', __('Content', 'dc-core')),
                Field::make('select', 'alignment', __('Alignment', 'dc-core'))
                    ->set_options(['left' => 'Left', 'center' => 'Center'])
                    ->set_default_value('left'),
                Field::make('text', 'cta_text', __('CTA text', 'dc-core'))->set_width(50),
                Field::make('text', 'cta_url', __('CTA URL', 'dc-core'))->set_width(50),
            ])
            // — Card group: heading + a grid of cards.
            ->add_fields('cardgroup', __('Card group', 'dc-core'), [
                Field::make('text', 'eyebrow', __('Eyebrow', 'dc-core')),
                Field::make('text', 'title', __('Title', 'dc-core')),
                Field::make('textarea', 'subtitle', __('Subtitle', 'dc-core'))->set_rows(2),
                Field::make('select', 'columns', __('Columns', 'dc-core'))
                    ->set_options(['2' => '2', '3' => '3', '4' => '4'])->set_default_value('3'),
                Field::make('complex', 'cards', __('Cards', 'dc-core'))
                    ->set_layout('grid')
                    ->add_fields([
                        Field::make('text', 'icon', __('Icon', 'dc-core'))->set_width(50),
                        Field::make('text', 'title', __('Title', 'dc-core'))->set_width(50),
                        Field::make('textarea', 'description', __('Description', 'dc-core'))->set_rows(2),
                        Field::make('text', 'link_text', __('Link text', 'dc-core'))->set_width(50),
                        Field::make('text', 'link_url', __('Link URL', 'dc-core'))->set_width(50),
                    ]),
            ])
            // — Side by side: text + image with feature list.
            ->add_fields('sidebyside', __('Side by side', 'dc-core'), [
                Field::make('text', 'eyebrow', __('Eyebrow', 'dc-core')),
                Field::make('text', 'title', __('Title', 'dc-core')),
                Field::make('rich_text', 'content', __('Content', 'dc-core')),
                Field::make('image', 'image', __('Image', 'dc-core'))->set_value_type('url'),
                Field::make('select', 'image_position', __('Image position', 'dc-core'))
                    ->set_options(['left' => 'Left', 'right' => 'Right'])->set_default_value('right'),
                Field::make('complex', 'features', __('Features', 'dc-core'))
                    ->set_layout('grid')
                    ->add_fields([
                        Field::make('text', 'icon', __('Icon', 'dc-core'))->set_width(33),
                        Field::make('text', 'title', __('Title', 'dc-core'))->set_width(33),
                        Field::make('textarea', 'description', __('Description', 'dc-core'))->set_rows(2),
                    ]),
                Field::make('text', 'cta_text', __('CTA text', 'dc-core'))->set_width(50),
                Field::make('text', 'cta_url', __('CTA URL', 'dc-core'))->set_width(50),
            ])
            // — Accordion: heading + Q/A items.
            ->add_fields('accordion', __('Accordion', 'dc-core'), [
                Field::make('text', 'eyebrow', __('Eyebrow', 'dc-core')),
                Field::make('text', 'title', __('Title', 'dc-core')),
                Field::make('textarea', 'subtitle', __('Subtitle', 'dc-core'))->set_rows(2),
                Field::make('complex', 'items', __('Items', 'dc-core'))
                    ->add_fields([
                        Field::make('text', 'question', __('Question', 'dc-core')),
                        Field::make('rich_text', 'answer', __('Answer', 'dc-core')),
                    ]),
            ])
            // — Quote: testimonials, single or grid.
            ->add_fields('quote', __('Quote / testimonials', 'dc-core'), [
                Field::make('text', 'eyebrow', __('Eyebrow', 'dc-core')),
                Field::make('text', 'title', __('Title', 'dc-core')),
                Field::make('select', 'layout', __('Layout', 'dc-core'))
                    ->set_options(['single' => 'Single', 'grid' => 'Grid'])->set_default_value('single'),
                Field::make('complex', 'testimonials', __('Testimonials', 'dc-core'))
                    ->add_fields([
                        Field::make('textarea', 'quote', __('Quote', 'dc-core'))->set_rows(3),
                        Field::make('text', 'author_name', __('Author name', 'dc-core'))->set_width(50),
                        Field::make('text', 'author_title', __('Author title', 'dc-core'))->set_width(50),
                        Field::make('text', 'author_company', __('Author company', 'dc-core'))->set_width(50),
                        Field::make('text', 'rating', __('Rating (1-5)', 'dc-core'))->set_width(50),
                        Field::make('image', 'author_image', __('Author image', 'dc-core'))->set_value_type('url'),
                    ]),
            ])
            // — Pricing: tiers, each with a feature list (2-level nest).
            ->add_fields('pricing', __('Pricing', 'dc-core'), [
                Field::make('text', 'eyebrow', __('Eyebrow', 'dc-core')),
                Field::make('text', 'title', __('Title', 'dc-core')),
                Field::make('textarea', 'subtitle', __('Subtitle', 'dc-core'))->set_rows(2),
                Field::make('complex', 'tiers', __('Tiers', 'dc-core'))
                    ->add_fields([
                        Field::make('text', 'name', __('Name', 'dc-core'))->set_width(50),
                        Field::make('text', 'price', __('Price', 'dc-core'))->set_width(25),
                        Field::make('text', 'billing_period', __('Billing period', 'dc-core'))->set_width(25),
                        Field::make('textarea', 'description', __('Description', 'dc-core'))->set_rows(2),
                        // Features as one-per-line text, NOT a nested complex:
                        // Carbon's programmatic setter doesn't reliably persist
                        // 3-level-deep complex (components → tiers → features).
                        // The normalizer splits this into a string[] for props.
                        Field::make('textarea', 'features', __('Features (one per line)', 'dc-core'))->set_rows(4),
                        Field::make('checkbox', 'is_featured', __('Featured tier', 'dc-core')),
                        Field::make('text', 'cta_text', __('CTA text', 'dc-core'))->set_width(50),
                        Field::make('text', 'cta_url', __('CTA URL', 'dc-core'))->set_width(50),
                    ]),
            ])
            // — Logo collection.
            ->add_fields('logocollection', __('Logo collection', 'dc-core'), [
                Field::make('text', 'eyebrow', __('Eyebrow', 'dc-core')),
                Field::make('text', 'title', __('Title', 'dc-core')),
                Field::make('complex', 'logos', __('Logos', 'dc-core'))
                    ->set_layout('grid')
                    ->add_fields([
                        Field::make('text', 'name', __('Name', 'dc-core')),
                        Field::make('image', 'image', __('Logo', 'dc-core'))->set_value_type('url'),
                        Field::make('text', 'url', __('URL', 'dc-core')),
                    ]),
            ])
            // — Stats: a row of figures. Stored as a one-per-line textarea
            //   ("value | label | description"), NOT a nested complex:
            //   this is the 6th nested-complex group in one components
            //   field and Carbon's programmatic carbon_set_post_meta
            //   refuses to persist its rows (the editor UI handles it, but
            //   content-import / dc-puck write programmatically). A
            //   pipe-delimited textarea round-trips reliably and stays
            //   human-editable; the normalizer parses `figures` into the
            //   `stats` array the frontend contract expects.
            ->add_fields('stats', __('Stats', 'dc-core'), [
                Field::make('text', 'eyebrow', __('Eyebrow', 'dc-core')),
                Field::make('text', 'title', __('Title', 'dc-core')),
                Field::make('text', 'background_color', __('Background color', 'dc-core')),
                Field::make('textarea', 'figures', __('Stats — one per line: value | label | description', 'dc-core'))
                    ->set_rows(4),
            ])
            // — Newsletter signup.
            ->add_fields('newsletter', __('Newsletter', 'dc-core'), [
                Field::make('text', 'eyebrow', __('Eyebrow', 'dc-core')),
                Field::make('text', 'title', __('Title', 'dc-core')),
                Field::make('textarea', 'subtitle', __('Subtitle', 'dc-core'))->set_rows(2),
                Field::make('text', 'placeholder', __('Input placeholder', 'dc-core'))->set_width(50),
                Field::make('text', 'button_text', __('Button text', 'dc-core'))->set_width(50),
                Field::make('select', 'background_color', __('Background', 'dc-core'))
                    ->set_options(['light' => 'Light', 'dark' => 'Dark', 'gradient' => 'Gradient'])
                    ->set_default_value('light'),
            ]);
    }
}

if (!function_exists('dc_make_intro_field')) {
    /**
     * Intro-paragraph repeater — a flat array of short lead
     * paragraphs shown above the body. A genuinely structured field
     * (each intro paragraph is a distinct, separately-placed element)
     * so a simple one-text-field Complex is the right tool here.
     *
     * @param string $name  Carbon Fields meta key.
     * @return \Carbon_Fields\Field\Complex_Field
     */
    function dc_make_intro_field(string $name = 'dc_intro_paragraphs')
    {
        return Field::make('complex', $name, __('Intro paragraphs', 'dc-core'))
            ->set_layout('grid')
            ->setup_labels([
                'singular_name' => __('Paragraph', 'dc-core'),
                'plural_name'   => __('Paragraphs', 'dc-core'),
            ])
            ->add_fields([
                Field::make('textarea', 'text', __('Paragraph', 'dc-core'))
                    ->set_rows(3),
            ]);
    }
}

if (!function_exists('dc_make_gallery_field')) {
    /**
     * Standalone gallery / logo-strip repeater — image + alt pairs.
     * For a content type whose gallery is a fixed, top-level field
     * (not interleaved with prose).
     *
     * @param string $name  Carbon Fields meta key.
     * @return \Carbon_Fields\Field\Complex_Field
     */
    function dc_make_gallery_field(string $name = 'dc_gallery_images')
    {
        return Field::make('complex', $name, __('Gallery images', 'dc-core'))
            ->set_layout('grid')
            ->add_fields([
                Field::make('image', 'image', __('Image', 'dc-core'))
                    ->set_value_type('url'),
                Field::make('text', 'alt', __('Alt text', 'dc-core')),
            ]);
    }
}

if (!function_exists('dc_intro_to_strings')) {
    /**
     * Convert a stored intro-paragraphs Complex value into a flat
     * array of strings — the shape the frontend wants.
     *
     * @param mixed $raw  carbon_get_post_meta(...) value.
     * @return array<int, string>
     */
    function dc_intro_to_strings($raw): array
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

if (!function_exists('dc_gallery_to_images')) {
    /**
     * Convert a stored gallery Complex value into a flat array of
     * {src, alt} pairs.
     *
     * @param mixed $raw  carbon_get_post_meta(...) value.
     * @return array<int, array{src: string, alt: string}>
     */
    function dc_gallery_to_images($raw): array
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

if (!function_exists('dc_components_to_array')) {
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
    function dc_components_to_array($raw): array
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

if (!function_exists('dc_lines_to_stats')) {
    /**
     * Parse the stats `figures` textarea — one stat per line as
     * "value | label | description" — into the [{value,label,description}]
     * array the frontend contract expects. The description is optional;
     * blank lines are skipped. A line with no pipe is treated as just a
     * value. Tolerates a legacy JSON string (older content).
     *
     * @param mixed $raw
     * @return array<int, array{value:string,label:string,description:string}>
     */
    function dc_lines_to_stats($raw): array
    {
        if (is_array($raw)) {
            return $raw; // already structured (e.g. inbound from Puck)
        }
        $text = (string) $raw;
        $trimmed = trim($text);
        // Back-compat: decode a legacy JSON-encoded figures value.
        if ($trimmed !== '' && ($trimmed[0] === '[' || $trimmed[0] === '{')) {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            $out[] = [
                'value'       => $parts[0] ?? '',
                'label'       => $parts[1] ?? '',
                'description' => $parts[2] ?? '',
            ];
        }
        return $out;
    }
}

if (!function_exists('dc_stats_to_lines')) {
    /**
     * Encode a [{value,label,description}] array into the stats `figures`
     * textarea format — one "value | label | description" line per stat.
     * The inverse of dc_lines_to_stats(); used by content-import and
     * dc-puck save when writing programmatically.
     *
     * @param mixed $stats
     */
    function dc_stats_to_lines($stats): string
    {
        if (!is_array($stats)) {
            return '';
        }
        $lines = [];
        foreach ($stats as $s) {
            if (!is_array($s)) {
                continue;
            }
            $value = trim((string) ($s['value'] ?? ''));
            $label = trim((string) ($s['label'] ?? ''));
            $desc  = trim((string) ($s['description'] ?? ''));
            $parts = [$value, $label];
            if ($desc !== '') {
                $parts[] = $desc;
            }
            $lines[] = implode(' | ', $parts);
        }
        return implode("\n", $lines);
    }
}

if (!function_exists('dc_camel_key')) {
    /**
     * snake_case (Carbon subkey) → camelCase (Puck / Astro prop).
     */
    function dc_camel_key(string $key): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));
    }
}

if (!function_exists('dc_normalize_row')) {
    /**
     * Recursively normalize a Carbon complex ROW into a clean assoc
     * array for JSON exposure: drops Carbon internals (`_type`), camel-
     * cases keys, and recurses into nested complex values (arrays of
     * rows). This is the single source of truth shared by the GraphQL
     * `props` exposure and dc-puck's load transform — so the wire
     * shape never drifts between read and edit.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    function dc_normalize_row(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            if ($key === '_type') {
                continue;
            }
            $prop = dc_camel_key((string) $key);
            if (is_array($value)) {
                // A list of rows (nested complex) vs. an empty array.
                $is_rows = $value !== [] && array_is_list($value)
                    && is_array($value[0] ?? null);
                if ($is_rows) {
                    $out[$prop] = array_map(
                        static fn($r) => is_array($r) ? dc_normalize_row($r) : $r,
                        $value
                    );
                } else {
                    $out[$prop] = $value;
                }
            } else {
                $out[$prop] = $value;
            }
        }
        return $out;
    }
}

if (!function_exists('dc_components_to_blocks')) {
    /**
     * Convert a stored components Complex value into a list of
     * `{ kind, props }` blocks where `props` is a JSON-encoded object
     * of the row's normalized data. This is the generic exposure that
     * scales to the full component palette (hero, cards, pricing with
     * nested tiers/features, …) without a GraphQL type per component.
     *
     * @param mixed $raw  carbon_get_post_meta(...) value.
     * @return array<int, array{kind:string, props:string}>
     */
    function dc_components_to_blocks($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $kind = (string) ($row['_type'] ?? '');
            if ($kind === '') {
                continue;
            }
            $props = dc_normalize_row($row);
            // Stats' figures are stored as one-per-line "value | label |
            // description" text (see the field definition); parse into the
            // `stats` array the contract wants.
            if ($kind === 'stats') {
                $props['stats'] = dc_lines_to_stats($props['figures'] ?? '');
                unset($props['figures']);
            }
            // Pricing tiers store their feature list as one-per-line text
            // (Carbon can't round-trip 3-level complex via the API). Split
            // it back into the string[] the frontend contract expects.
            if ($kind === 'pricing' && isset($props['tiers']) && is_array($props['tiers'])) {
                foreach ($props['tiers'] as &$tier) {
                    if (isset($tier['features']) && is_string($tier['features'])) {
                        $tier['features'] = array_values(array_filter(
                            array_map('trim', preg_split('/\r\n|\r|\n/', $tier['features']) ?: []),
                            static fn($s) => $s !== ''
                        ));
                    }
                }
                unset($tier);
            }
            $out[] = [
                'kind'  => $kind,
                'props' => (string) wp_json_encode($props),
            ];
        }
        return $out;
    }
}
