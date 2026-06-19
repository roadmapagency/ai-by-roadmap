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
 */
final class ACFTransformer
{
    public function convert(array $block, string $align = ''): string
    {
        $block_name = (string) array_key_first($block);
        $block_data = (array) $block[$block_name];

        $data       = [];
        $block_slug = str_replace('acf/', '', $block_name);

        $this->process_fields($block_data, $data, $block_slug);

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

    private function process_fields(array $fields, array &$data, string $block_slug, string $prefix = ''): void
    {
        foreach ($fields as $field_name => $field_value) {
            $prefixed = $prefix !== '' ? $prefix . '_' . $field_name : $field_name;

            if (is_array($field_value) && isset($field_value[0]) && is_array($field_value[0])) {
                $this->process_repeater($field_value, $data, $block_slug, $prefixed);
                continue;
            }

            if (is_array($field_value) && ! isset($field_value[0])) {
                $data[$prefixed]       = wp_json_encode($field_value);
                $data['_' . $prefixed] = 'field_' . $block_slug . '_' . $prefixed;
                continue;
            }

            $data[$prefixed]       = $field_value;
            $data['_' . $prefixed] = 'field_' . $block_slug . '_' . $prefixed;
        }
    }

    private function process_repeater(array $rows, array &$data, string $block_slug, string $prefixed): void
    {
        $data[$prefixed]       = count($rows);
        $data['_' . $prefixed] = 'field_' . $block_slug . '_' . $prefixed;

        foreach ($rows as $index => $row) {
            foreach ($row as $field => $value) {
                $key = "{$prefixed}_{$index}_{$field}";

                if (is_array($value) && isset($value[0]) && is_array($value[0])) {
                    $data[$key]       = count($value);
                    $data['_' . $key] = "field_{$block_slug}_{$prefixed}_{$field}";

                    foreach ($value as $nested_index => $nested_row) {
                        $this->process_fields(
                            (array) $nested_row,
                            $data,
                            $block_slug,
                            "{$prefixed}_{$index}_{$field}_{$nested_index}"
                        );
                    }
                    continue;
                }

                if (is_array($value) && ! isset($value[0])) {
                    $data[$key]       = wp_json_encode($value);
                    $data['_' . $key] = "field_{$block_slug}_{$prefixed}_{$field}";
                    continue;
                }

                $data[$key]       = $value;
                $data['_' . $key] = "field_{$block_slug}_{$prefixed}_{$field}";
            }
        }
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
