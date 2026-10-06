<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks;

/**
 * Converts the JSON output of a filler agent into the serialized ACF block
 * comment that Gutenberg/ACF understand.
 *
 * Handles:
 *   - Nested repeater fields (flattened to ACF's {name}_{index}_{field} format).
 *   - Associative-array fields (e.g. Font Awesome icon objects) → JSON-encoded.
 *   - Image-filename strings → resolved to attachment IDs via WP_Query.
 *   - Inline Markdown safety net: a model may emit Markdown (`**bold**`,
 *     `*italic*`) even though fields expect HTML. Using the block's own schema
 *     (BlockRegistry), rich-text fields (format: "html") get Markdown converted
 *     to <strong>/<em>; plain-text fields get the markers stripped so no stray
 *     asterisks render. Asterisk emphasis only — underscores are left alone so
 *     URLs/slugs (e.g. /foo_bar) are never corrupted.
 */
final class ACFTransformer
{
    public function convert(array $block, string $align = ''): string
    {
        $block_name = (string) array_key_first($block);
        $block_data = (array) $block[$block_name];

        $data = $this->flatten($block_name, $block_data);

        $attrs = [
            'id'   => uniqid('block_'),
            'name' => $block_name,
            'data' => $data,
            'mode' => 'preview',
        ];
        if ($align !== '') {
            $attrs['align'] = $align;
        }

        if (function_exists('\\acf_parse_save_blocks_callback')) {
            return \acf_parse_save_blocks_callback([
                'name'  => $block_name,
                'attrs' => wp_json_encode($attrs),
                'void'  => '/',
            ]);
        }

        if (function_exists('\\acf_serialize_block_attributes')) {
            return '<!-- wp:' . $block_name . ' ' . \acf_serialize_block_attributes($attrs) . ' /-->';
        }

        return '<!-- wp:' . $block_name . ' ' . $this->fallback_serialize($attrs) . ' /-->';
    }

    /**
     * Human-shaped field values → ACF's flat block `data` (values plus the
     * `_field` → `field_{slug}_{name}` pointer rows), with the Markdown safety
     * net applied. This is the single flattening path: convert() uses it for
     * whole blocks and update-block-fields uses it for merge patches, so the
     * two can never drift.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function flatten(string $block_name, array $fields): array
    {
        $data       = [];
        $block_slug = str_replace('acf/', '', $block_name);
        $props      = BlockRegistry::get_blocks()[$block_name]['properties'] ?? [];

        $this->process_fields($fields, $data, $block_slug, '', (array) $props);

        return $data;
    }

    /**
     * Check a block's field names and values against its real ACF field tree.
     *
     * convert() deliberately serializes whatever it is given, so callers that accept
     * externally authored block data MUST run this first: an unknown field name is
     * otherwise written with a fabricated key reference, binds to nothing, and renders
     * an empty slot that looks fine in the markup.
     *
     * @param array<string, mixed> $block Single-key block array, as passed to convert().
     * @return array<string, mixed> Empty when the block is valid.
     */
    public function validate(array $block): array
    {
        $block_name = (string) array_key_first($block);

        return BlockValidator::validate($block_name, (array) $block[$block_name]);
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $data
     * @param array<string, mixed> $props  Schema properties for THIS field level.
     */
    private function process_fields(array $fields, array &$data, string $block_slug, string $prefix = '', array $props = []): void
    {
        foreach ($fields as $field_name => $field_value) {
            $prefixed = $prefix !== '' ? $prefix . '_' . $field_name : $field_name;
            $spec     = isset($props[$field_name]) && is_array($props[$field_name]) ? $props[$field_name] : null;

            if (is_array($field_value) && isset($field_value[0]) && is_array($field_value[0])) {
                $sub_props = $spec['items']['properties'] ?? [];
                $this->process_repeater($field_value, $data, $block_slug, $prefixed, (array) $sub_props);
                continue;
            }

            if (is_array($field_value) && ! isset($field_value[0])) {
                $data[$prefixed]       = wp_json_encode($field_value);
                $data['_' . $prefixed] = 'field_' . $block_slug . '_' . $prefixed;
                continue;
            }

            $data[$prefixed]       = is_string($field_value) ? $this->normalize_inline_md($field_value, $spec) : $field_value;
            $data['_' . $prefixed] = 'field_' . $block_slug . '_' . $prefixed;
        }
    }

    /**
     * @param array<int, mixed>    $rows
     * @param array<string, mixed> $data
     * @param array<string, mixed> $sub_props  Schema properties for a row's sub-fields.
     */
    private function process_repeater(array $rows, array &$data, string $block_slug, string $prefixed, array $sub_props = []): void
    {
        $data[$prefixed]       = count($rows);
        $data['_' . $prefixed] = 'field_' . $block_slug . '_' . $prefixed;

        foreach ($rows as $index => $row) {
            foreach ($row as $field => $value) {
                $key  = "{$prefixed}_{$index}_{$field}";
                $spec = isset($sub_props[$field]) && is_array($sub_props[$field]) ? $sub_props[$field] : null;

                if (is_array($value) && isset($value[0]) && is_array($value[0])) {
                    $data[$key]       = count($value);
                    $data['_' . $key] = "field_{$block_slug}_{$prefixed}_{$field}";

                    $nested_props = $spec['items']['properties'] ?? [];
                    foreach ($value as $nested_index => $nested_row) {
                        $this->process_fields(
                            (array) $nested_row,
                            $data,
                            $block_slug,
                            "{$prefixed}_{$index}_{$field}_{$nested_index}",
                            (array) $nested_props
                        );
                    }
                    continue;
                }

                if (is_array($value) && ! isset($value[0])) {
                    $data[$key]       = wp_json_encode($value);
                    $data['_' . $key] = "field_{$block_slug}_{$prefixed}_{$field}";
                    continue;
                }

                $data[$key]       = is_string($value) ? $this->normalize_inline_md($value, $spec) : $value;
                $data['_' . $key] = "field_{$block_slug}_{$prefixed}_{$field}";
            }
        }
    }

    /**
     * Normalize inline Markdown emphasis. Rich-text fields (schema format
     * "html") get HTML tags; everything else has the markers stripped so no
     * literal asterisks ever render. Asterisk syntax only — underscores are
     * preserved to avoid mangling URLs/slugs.
     *
     * @param array<string, mixed>|null $spec  This field's schema entry.
     */
    private function normalize_inline_md(string $value, ?array $spec): string
    {
        $is_html = is_array($spec) && ($spec['format'] ?? null) === 'html';

        if ($is_html) {
            $value = (string) preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $value);
            $value = (string) preg_replace('/(?<!\*)\*(?!\s)(.+?)(?<!\s)\*(?!\*)/s', '<em>$1</em>', $value);
            return $value;
        }

        $value = (string) preg_replace('/\*\*(.+?)\*\*/s', '$1', $value);
        $value = (string) preg_replace('/(?<!\*)\*(?!\s)(.+?)(?<!\s)\*(?!\*)/s', '$1', $value);
        return $value;
    }

    private function fallback_serialize(array $attrs): string
    {
        $json = wp_json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $json = preg_replace('/--/', '\\u002d\\u002d', $json);
        $json = preg_replace('/</', '\\u003c', $json);
        $json = preg_replace('/>/', '\\u003e', $json);
        $json = preg_replace('/&/', '\\u0026', $json);
        $json = preg_replace('/\\"/', '\\u0022', $json);
        return (string) $json;
    }
}
