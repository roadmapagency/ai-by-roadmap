<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks;

use Roadmap\AiByRoadmap\Plugin;

/**
 * Enumerate the human-readable text values of a block (headings, copy,
 * button labels, repeater row text…) with dotted paths, for search-content
 * and replace-text. Skips non-text field types (images, numbers, booleans,
 * choices, URLs — URLs are audit-links' territory).
 */
final class TextFields
{
    private const SKIP_TYPES = ['image', 'file', 'gallery', 'number', 'range', 'true_false', 'checkbox', 'select', 'radio', 'button_group', 'url', 'link', 'lucide_icon', 'color_picker', 'date_picker', 'post_object', 'relationship', 'taxonomy', 'user'];

    /**
     * @param array<string, mixed>             $fields Unflattened block fields.
     * @param array<int, array<string, mixed>> $defs
     * @return array<int, array{path:string, value:string}>
     */
    public static function collect(array $fields, array $defs, string $prefix = '', bool $include_ai_content = false): array
    {
        $out = [];
        foreach ($defs as $def) {
            $name = (string) $def['name'];
            if ($name === '' || ! array_key_exists($name, $fields)) {
                continue;
            }
            if (! $include_ai_content && $prefix === '' && $name === Plugin::AI_CONTENT_FIELD) {
                continue;
            }
            $value = $fields[$name];
            $path  = $prefix . $name;

            if ($def['type'] === 'repeater') {
                foreach ((array) $value as $i => $row) {
                    $out = array_merge($out, self::collect((array) $row, (array) ($def['sub_fields'] ?? []), "{$path}[{$i}].", $include_ai_content));
                }
                continue;
            }
            if ($def['type'] === 'group') {
                $out = array_merge($out, self::collect((array) $value, (array) ($def['sub_fields'] ?? []), $path . '.', $include_ai_content));
                continue;
            }
            if (in_array($def['type'], self::SKIP_TYPES, true) || ! is_string($value) || $value === '') {
                continue;
            }
            $out[] = ['path' => $path, 'value' => $value];
        }
        return $out;
    }

    /**
     * Top-level field name of a dotted path ("items[2].title" → "items").
     */
    public static function top_level(string $path): string
    {
        return explode('.', explode('[', $path)[0])[0];
    }

    /**
     * Short excerpt around the first match, tags kept (they matter for edits).
     */
    public static function snippet(string $value, int $offset, int $length, int $context = 60): string
    {
        $start = max(0, $offset - $context);
        $end   = min(strlen($value), $offset + $length + $context);
        $s     = substr($value, $start, $end - $start);
        return ($start > 0 ? '…' : '') . $s . ($end < strlen($value) ? '…' : '');
    }
}
