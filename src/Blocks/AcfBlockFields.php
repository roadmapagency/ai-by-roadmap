<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks;

use WP_Error;

/**
 * ACF field introspection for a block type, plus the two translations every
 * partial-edit ability needs:
 *
 *   - unflatten(): ACF's flat block data ({"items_0_title": "…", "_items_0_title":
 *     "field_…"}) → the human-shaped values list-blocks advertises (repeaters as
 *     arrays of row objects, images as attachment IDs, booleans as booleans).
 *   - validate(): a caller-supplied patch → actionable errors that name the
 *     valid field names / choices, so an LLM can self-correct in one round trip.
 *
 * Definitions come from ACF itself (acf_get_field_groups(['block' => …]) +
 * acf_get_fields, recursing into sub_fields) rather than the registered JSON
 * schema, because the schema erases types we need (image → "string").
 */
final class AcfBlockFields
{
    /** @var array<string, array<int, array<string, mixed>>> */
    private static array $cache = [];

    /**
     * Ordered field definitions for a block type. Each entry:
     *   {name, key, type, label, choices?: string[], sub_fields?: self[]}
     *
     * @return array<int, array<string, mixed>>
     */
    public static function definitions(string $block_name): array
    {
        if (isset(self::$cache[$block_name])) {
            return self::$cache[$block_name];
        }

        $defs = [];
        if (function_exists('acf_get_field_groups') && function_exists('acf_get_fields')) {
            foreach (acf_get_field_groups(['block' => $block_name]) as $group) {
                foreach ((array) acf_get_fields($group) as $field) {
                    $defs[] = self::normalize((array) $field);
                }
            }
        }

        return self::$cache[$block_name] = $defs;
    }

    /**
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private static function normalize(array $field): array
    {
        $def = [
            'name'  => (string) ($field['name'] ?? ''),
            'key'   => (string) ($field['key'] ?? ''),
            'type'  => (string) ($field['type'] ?? 'text'),
            'label' => (string) ($field['label'] ?? $field['name'] ?? ''),
        ];

        if (! empty($field['choices']) && is_array($field['choices'])) {
            $def['choices'] = array_map('strval', array_keys($field['choices']));
        }

        if (! empty($field['sub_fields']) && is_array($field['sub_fields'])) {
            $def['sub_fields'] = array_map(
                static fn($sub) => self::normalize((array) $sub),
                array_values($field['sub_fields'])
            );
        }

        return $def;
    }

    /**
     * Top-level field names (the keys a patch may use).
     *
     * @param array<int, array<string, mixed>> $defs
     * @return array<int, string>
     */
    public static function names(array $defs): array
    {
        return array_values(array_filter(array_map(static fn($d) => (string) $d['name'], $defs)));
    }

    /**
     * @param array<int, array<string, mixed>> $defs
     * @return array<string, mixed>|null
     */
    public static function find(array $defs, string $name): ?array
    {
        foreach ($defs as $def) {
            if (($def['name'] ?? '') === $name) {
                return $def;
            }
        }
        return null;
    }

    /**
     * Top-level image fields of a block type, name → label.
     *
     * @return array<string, string>
     */
    public static function image_fields(string $block_name): array
    {
        $out = [];
        foreach (self::definitions($block_name) as $def) {
            if ($def['type'] === 'image') {
                $out[$def['name']] = $def['label'];
            }
        }
        return $out;
    }

    /**
     * Flat ACF block data → human-shaped values, following the definitions.
     * Pointer rows (`_field`) are skipped. Missing fields come back as their
     * empty value so the caller always sees the full shape.
     *
     * @param array<string, mixed>              $data
     * @param array<int, array<string, mixed>>  $defs
     * @return array<string, mixed>
     */
    public static function unflatten(array $data, array $defs, string $prefix = ''): array
    {
        $out = [];
        foreach ($defs as $def) {
            $name = (string) $def['name'];
            if ($name === '') {
                continue;
            }
            $key   = $prefix . $name;
            $value = $data[$key] ?? null;

            switch ($def['type']) {
                case 'repeater':
                    $out[$name] = self::unflatten_rows($data, (array) ($def['sub_fields'] ?? []), $key, $value);
                    break;

                case 'group':
                    if (is_string($value) && $value !== '' && $value[0] === '{') {
                        $decoded    = json_decode($value, true);
                        $out[$name] = is_array($decoded) ? $decoded : [];
                        break;
                    }
                    $out[$name] = self::unflatten($data, (array) ($def['sub_fields'] ?? []), $key . '_');
                    break;

                case 'image':
                case 'file':
                    $out[$name] = is_numeric($value) ? (int) $value : 0;
                    break;

                case 'true_false':
                    $out[$name] = in_array($value, [true, 1, '1', 'true'], true);
                    break;

                case 'number':
                case 'range':
                    $out[$name] = is_numeric($value) ? $value + 0 : '';
                    break;

                case 'checkbox':
                    if (is_array($value)) {
                        $out[$name] = array_values(array_map('strval', $value));
                    } elseif (is_string($value) && $value !== '' && $value[0] === '[') {
                        $decoded    = json_decode($value, true);
                        $out[$name] = is_array($decoded) ? array_map('strval', $decoded) : [];
                    } else {
                        $out[$name] = $value === null || $value === '' ? [] : [(string) $value];
                    }
                    break;

                default:
                    if (is_array($value)) {
                        $out[$name] = $value;
                    } else {
                        $out[$name] = $value === null ? '' : (is_scalar($value) ? $value : '');
                    }
            }
        }
        return $out;
    }

    /**
     * Collect a repeater's rows from `{key}_{i}_{sub}` entries. The stored count
     * (when numeric) is honoured, and the scan also continues while row keys
     * exist so a stale/missing count never drops data.
     *
     * @param array<string, mixed>             $data
     * @param array<int, array<string, mixed>> $sub_defs
     * @return array<int, array<string, mixed>>
     */
    private static function unflatten_rows(array $data, array $sub_defs, string $key, $count_value): array
    {
        $count = is_numeric($count_value) ? (int) $count_value : 0;
        $rows  = [];
        $i     = 0;
        while ($i < $count || self::has_row($data, $key, $i)) {
            if ($i > 500) {
                break; // defensive: malformed data must not spin forever
            }
            $rows[] = self::unflatten($data, $sub_defs, "{$key}_{$i}_");
            $i++;
        }
        return $rows;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function has_row(array $data, string $key, int $i): bool
    {
        $needle = "{$key}_{$i}_";
        foreach ($data as $k => $_) {
            if (is_string($k) && str_starts_with($k, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Stored value keys the live definitions do not explain — a sign of field
     * name drift from an older block schema.
     *
     * @param array<string, mixed>             $data
     * @param array<int, array<string, mixed>> $defs
     * @return array<int, string>
     */
    public static function stray_fields(array $data, array $defs): array
    {
        $stray = [];
        foreach ($data as $key => $_) {
            $key = (string) $key;
            if ($key === '' || $key[0] === '_') {
                continue;
            }
            if (! self::explains($key, $defs)) {
                $stray[] = $key;
            }
        }
        return $stray;
    }

    /**
     * @param array<int, array<string, mixed>> $defs
     */
    private static function explains(string $key, array $defs): bool
    {
        foreach ($defs as $def) {
            $name = (string) $def['name'];
            if ($name === '') {
                continue;
            }
            if ($key === $name) {
                return true;
            }
            if (! str_starts_with($key, $name . '_')) {
                continue;
            }
            $rest = substr($key, strlen($name) + 1);
            if ($def['type'] === 'repeater' && preg_match('/^\d+_(.+)$/', $rest, $m)) {
                if (self::explains($m[1], (array) ($def['sub_fields'] ?? []))) {
                    return true;
                }
            }
            if ($def['type'] === 'group' && self::explains($rest, (array) ($def['sub_fields'] ?? []))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Validate a caller-supplied patch against the definitions. Every error
     * names the offending field and what would have been accepted.
     *
     * @param array<string, mixed>             $fields
     * @param array<int, array<string, mixed>> $defs
     * @return true|WP_Error
     */
    public static function validate(array $fields, array $defs, string $block_name, string $path = '')
    {
        $valid = self::names($defs);

        foreach ($fields as $name => $value) {
            $name = (string) $name;
            $full = $path === '' ? $name : $path . '.' . $name;
            $def  = self::find($defs, $name);

            if ($def === null) {
                return new WP_Error('invalid_field', sprintf(
                    /* translators: 1: field path, 2: block type, 3: comma-separated valid field names */
                    __('"%1$s" is not a field on %2$s. Valid fields: %3$s.', 'ai-by-roadmap'),
                    $full,
                    $block_name,
                    $valid ? implode(', ', $valid) : __('(none)', 'ai-by-roadmap')
                ), ['status' => 400]);
            }

            $err = self::validate_value($value, $def, $block_name, $full);
            if (is_wp_error($err)) {
                return $err;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $def
     * @return true|WP_Error
     */
    private static function validate_value($value, array $def, string $block_name, string $full)
    {
        $type = (string) $def['type'];

        switch ($type) {
            case 'repeater':
                if (! is_array($value) || ($value !== [] && ! array_is_list($value))) {
                    return self::type_error($full, __('a list of row objects (an empty list clears the repeater)', 'ai-by-roadmap'));
                }
                foreach ($value as $i => $row) {
                    if (! is_array($row)) {
                        return self::type_error($full . '[' . $i . ']', __('an object of sub-field values', 'ai-by-roadmap'));
                    }
                    $err = self::validate($row, (array) ($def['sub_fields'] ?? []), $block_name, $full . '[' . $i . ']');
                    if (is_wp_error($err)) {
                        return $err;
                    }
                }
                return true;

            case 'group':
                if (! is_array($value) || array_is_list($value) && $value !== []) {
                    return self::type_error($full, __('an object of sub-field values', 'ai-by-roadmap'));
                }
                return self::validate($value, (array) ($def['sub_fields'] ?? []), $block_name, $full);

            case 'select':
            case 'radio':
            case 'button_group':
                $choices = (array) ($def['choices'] ?? []);
                if ($value === '' || $value === null || $choices === []) {
                    return true;
                }
                if (! is_scalar($value) || ! in_array((string) $value, $choices, true)) {
                    return new WP_Error('invalid_choice', sprintf(
                        /* translators: 1: value, 2: field path, 3: comma-separated choices */
                        __('"%1$s" is not a valid choice for %2$s. Choose one of: %3$s.', 'ai-by-roadmap'),
                        is_scalar($value) ? (string) $value : gettype($value),
                        $full,
                        implode(', ', $choices)
                    ), ['status' => 400]);
                }
                return true;

            case 'checkbox':
                if (! is_array($value)) {
                    return self::type_error($full, __('a list of choice values', 'ai-by-roadmap'));
                }
                $choices = (array) ($def['choices'] ?? []);
                foreach ($value as $v) {
                    if ($choices !== [] && ! in_array((string) $v, $choices, true)) {
                        return new WP_Error('invalid_choice', sprintf(
                            /* translators: 1: value, 2: field path, 3: comma-separated choices */
                            __('"%1$s" is not a valid choice for %2$s. Choose from: %3$s.', 'ai-by-roadmap'),
                            (string) $v,
                            $full,
                            implode(', ', $choices)
                        ), ['status' => 400]);
                    }
                }
                return true;

            case 'image':
            case 'file':
                if ($value === '' || $value === null || $value === 0 || $value === '0') {
                    return true;
                }
                if (! is_numeric($value) || (int) $value <= 0) {
                    return self::type_error($full, __('a media-library attachment ID (integer), or 0 to clear', 'ai-by-roadmap'));
                }
                $ok = $type === 'image' ? wp_attachment_is_image((int) $value) : get_post_type((int) $value) === 'attachment';
                if (! $ok) {
                    return new WP_Error('invalid_attachment', sprintf(
                        /* translators: 1: attachment id, 2: field path */
                        __('Attachment %1$d for %2$s does not exist or is not the right media type. Use search-media or upload-media to get a valid attachment ID.', 'ai-by-roadmap'),
                        (int) $value,
                        $full
                    ), ['status' => 400]);
                }
                return true;

            case 'true_false':
                if (! is_bool($value) && ! in_array($value, [0, 1, '0', '1', ''], true)) {
                    return self::type_error($full, __('true or false', 'ai-by-roadmap'));
                }
                return true;

            case 'number':
            case 'range':
                if ($value !== '' && $value !== null && ! is_numeric($value)) {
                    return self::type_error($full, __('a number', 'ai-by-roadmap'));
                }
                return true;

            default:
                if (is_array($value) || is_object($value)) {
                    return self::type_error($full, __('a string', 'ai-by-roadmap'));
                }
                return true;
        }
    }

    private static function type_error(string $full, string $expected): WP_Error
    {
        return new WP_Error('invalid_field_value', sprintf(
            /* translators: 1: field path, 2: expected shape */
            __('%1$s must be %2$s.', 'ai-by-roadmap'),
            $full,
            $expected
        ), ['status' => 400]);
    }

    /**
     * Short human label for a block, from its most identifying text field.
     * Always a string (empty when the block has no such text).
     *
     * @param array<string, mixed> $data
     */
    public static function label(array $data): string
    {
        foreach (['heading', 'title', 'headline', 'eyebrow', 'quote', 'intro'] as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value) && trim(wp_strip_all_tags($value)) !== '') {
                $text = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($value)) ?? '');
                return mb_strlen($text) > 80 ? rtrim(mb_substr($text, 0, 79)) . '…' : $text;
            }
        }
        return '';
    }

    /**
     * Remove every stored entry (value and pointer rows) belonging to one
     * field, including a repeater's flattened rows or a group's sub-fields —
     * used before a repeater/group is replaced whole so stale rows cannot
     * linger. Driven by the definition so siblings that merely share a prefix
     * (e.g. `image_aspect` next to `image`) are never touched.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $def
     * @return array<string, mixed>
     */
    public static function strip_field(array $data, array $def, string $prefix = ''): array
    {
        $key = $prefix . (string) $def['name'];
        unset($data[$key], $data['_' . $key]);

        $subs = (array) ($def['sub_fields'] ?? []);
        if ($def['type'] === 'repeater') {
            $row_pattern = '/^_?' . preg_quote($key, '/') . '_(\d+)_/';
            $indices     = [];
            foreach (array_keys($data) as $k) {
                if (preg_match($row_pattern, (string) $k, $m)) {
                    $indices[(int) $m[1]] = true;
                }
            }
            foreach (array_keys($indices) as $i) {
                foreach ($subs as $sub) {
                    $data = self::strip_field($data, $sub, "{$key}_{$i}_");
                }
            }
        } elseif ($def['type'] === 'group') {
            foreach ($subs as $sub) {
                $data = self::strip_field($data, $sub, $key . '_');
            }
        }

        return $data;
    }
}
