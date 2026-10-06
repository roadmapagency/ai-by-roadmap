<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks;

/**
 * Validates a filled ACF block's field names and values against the block's real
 * ACF field tree BEFORE it is serialized.
 *
 * ACFTransformer serializes whatever it is given: an unknown field name is written
 * with a fabricated `field_<slug>_<name>` reference, so the block looks valid, ACF
 * never binds it, and the template renders an empty slot. That failure mode cost a
 * whole conversion run (215 block instances storing names no schema defined), and it
 * is invisible until someone looks at the rendered page.
 *
 * The schema source is ACF itself (acf_get_field_groups + acf_get_fields), not the
 * JSON schema the theme contributes to BlockRegistry — the latter marks every field
 * `required`, which is fine for constraining an LLM but wrong for validating input.
 *
 * Input shape is the nested JSON form (the same thing ACFTransformer::convert()
 * takes): repeaters are lists of row objects, groups are objects.
 *
 *   BlockValidator::validate('acf/hero', ['heading' => 'Hi', 'items' => [['title' => 'A']]])
 *
 * Returns [] when the block is clean, else a structured report:
 *
 *   [
 *     'unknown'          => ['title', 'items.text'],
 *     'did_you_mean'     => ['title' => 'heading', 'items.text' => 'items.body'],
 *     'known'            => ['layout', 'heading', 'items', 'items.title', 'items.body'],
 *     'invalid_enum'     => [['path' => 'layout', 'value' => 'wide', 'allowed' => ['split', 'centered']]],
 *     'missing_required' => ['heading'],
 *   ]
 */
final class BlockValidator
{
    /**
     * Keys that are never ACF fields but are legitimately present on a block:
     * the hidden source marker, the block id FillBlock injects, the render-blocks
     * layout hints, and the per-block escape hatch.
     */
    private const RESERVED_KEYS = [
        'ai_content',
        'id',
        '_align',
        '_anchor',
        '_allow_unknown',
    ];

    /**
     * Names a porter commonly invents for a field that exists under another name.
     * Pure edit distance cannot get `title` -> `heading`, and these are exactly the
     * substitutions observed in real conversions, so try them first.
     *
     * @var array<string, string[]>
     */
    private const ALIASES = [
        'title'        => ['heading', 'label', 'name'],
        'subtitle'     => ['intro', 'subheading', 'text'],
        'subtext'      => ['intro', 'text', 'body'],
        'body'         => ['intro', 'text', 'description'],
        'text'         => ['body', 'intro', 'description'],
        'description'  => ['body', 'text', 'intro'],
        'copy'         => ['body', 'text', 'intro'],
        'cards'        => ['items', 'cards'],
        'bullets'      => ['items', 'list'],
        'questions'    => ['items'],
        'categories'   => ['items'],
        'rows'         => ['items'],
        'variant'      => ['layout', 'style', 'card_style'],
        'style'        => ['layout', 'card_style', 'variant'],
        'alignment'    => ['align', 'layout', 'header_align'],
        'image_ratio'  => ['image_aspect'],
        'aspect'       => ['image_aspect'],
        'hero_image'   => ['image'],
        'photo'        => ['image'],
        'button_text'  => ['primary_button_text', 'link_label', 'button_label'],
        'button_url'   => ['primary_button_url', 'link_url'],
        'button_style' => ['primary_button_style'],
        'cta_text'     => ['primary_button_text'],
        'cta_url'      => ['primary_button_url'],
        'bg'           => ['background'],
        'bg_color'     => ['background'],
        'theme'        => ['background', 'variant', 'layout'],
        'icon_name'    => ['icon'],
        'step_number'  => ['number', 'label'],
        'quote'        => ['text', 'body'],
    ];

    /**
     * The block's ACF field tree: name => ['type', 'required', 'choices', 'sub'].
     *
     * @return array<string, array{type:string, required:bool, choices:string[], sub:array<string,mixed>}>
     */
    public static function schema(string $block_name): array
    {
        static $cache = [];

        if (isset($cache[$block_name])) {
            return $cache[$block_name];
        }

        $tree = [];
        if (function_exists('acf_get_field_groups') && function_exists('acf_get_fields')) {
            foreach (acf_get_field_groups(['block' => $block_name]) as $group) {
                $tree += self::tree((array) acf_get_fields($group));
            }
        }

        $cache[$block_name] = $tree;

        return $tree;
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @return array<string, array{type:string, required:bool, choices:string[], sub:array<string,mixed>}>
     */
    private static function tree(array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            $name = (string) ($field['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $out[$name] = [
                'type'     => (string) ($field['type'] ?? 'text'),
                'required' => ! empty($field['required']),
                'choices'  => isset($field['choices']) && is_array($field['choices'])
                    ? array_map('strval', array_keys($field['choices']))
                    : [],
                'sub'      => ! empty($field['sub_fields']) && is_array($field['sub_fields'])
                    ? self::tree($field['sub_fields'])
                    : [],
            ];
        }

        return $out;
    }

    /**
     * True when the block name is a registered ACF block on this site.
     */
    public static function is_registered_block(string $block_name): bool
    {
        if (! function_exists('acf_get_block_types')) {
            return true; // Cannot tell; do not block the write on a missing ACF API.
        }

        return isset(acf_get_block_types()[$block_name]);
    }

    /**
     * Validate a filled block. Empty array = clean.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public static function validate(string $block_name, array $fields): array
    {
        if (! self::is_registered_block($block_name)) {
            $registered = function_exists('acf_get_block_types') ? array_keys(acf_get_block_types()) : [];
            sort($registered);

            return [
                'unknown_block' => $block_name,
                'known_blocks'  => $registered,
                'did_you_mean'  => self::suggest_from(substr($block_name, 4), array_map(
                    static fn (string $n): string => substr($n, 4),
                    $registered
                )),
            ];
        }

        $schema = self::schema($block_name);

        // A block with no ACF fields at all (rare, but legal) has nothing to check.
        if ($schema === []) {
            return [];
        }

        $report = [
            'unknown'          => [],
            'did_you_mean'     => [],
            'invalid_enum'     => [],
            'missing_required' => [],
        ];

        $allow_unknown = ! empty($fields['_allow_unknown']);

        self::walk($fields, $schema, '', $report);

        if ($allow_unknown) {
            $report['unknown']      = [];
            $report['did_you_mean'] = [];
        }

        $report = array_filter($report, static fn ($v): bool => $v !== []);

        if ($report === []) {
            return [];
        }

        $report['known'] = self::known_paths($schema);

        return $report;
    }

    /**
     * Recursively compare one level of supplied data against one level of the schema.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $level
     * @param array<string, mixed> $report
     */
    private static function walk(array $data, array $level, string $prefix, array &$report): void
    {
        foreach ($data as $name => $value) {
            $name = (string) $name;

            if (in_array($name, self::RESERVED_KEYS, true) || ($name !== '' && $name[0] === '_')) {
                continue;
            }

            $path = $prefix === '' ? $name : $prefix . '.' . $name;

            if (! isset($level[$name])) {
                $report['unknown'][] = $path;
                $guess = self::suggest_from($name, array_keys($level));
                if ($guess !== null) {
                    $report['did_you_mean'][$path] = $prefix === '' ? $guess : $prefix . '.' . $guess;
                }
                continue;
            }

            $spec = $level[$name];

            // Repeater / group: recurse into the row objects.
            if ($spec['sub'] !== [] && is_array($value)) {
                if (self::is_list_of_rows($value)) {
                    foreach ($value as $row) {
                        if (is_array($row)) {
                            self::walk($row, $spec['sub'], $path, $report);
                        }
                    }
                } else {
                    self::walk($value, $spec['sub'], $path, $report);
                }
                continue;
            }

            self::check_value($path, $value, $spec, $report);
        }

        // Required fields that were not supplied (or supplied empty) at this level.
        foreach ($level as $name => $spec) {
            if (! $spec['required']) {
                continue;
            }
            $path = $prefix === '' ? $name : $prefix . '.' . $name;
            if (self::is_empty($data[$name] ?? null)) {
                $report['missing_required'][] = $path;
            }
        }
    }

    /**
     * @param array<string, mixed> $spec
     * @param array<string, mixed> $report
     */
    private static function check_value(string $path, mixed $value, array $spec, array &$report): void
    {
        if ($spec['choices'] === [] || self::is_empty($value)) {
            return;
        }

        $values = in_array($spec['type'], ['checkbox', 'select'], true) && is_array($value)
            ? $value
            : [$value];

        foreach ($values as $single) {
            if (is_array($single) || is_bool($single)) {
                continue;
            }
            if (! in_array((string) $single, $spec['choices'], true)) {
                $report['invalid_enum'][] = [
                    'path'    => $path,
                    'value'   => (string) $single,
                    'allowed' => $spec['choices'],
                ];
            }
        }
    }

    /**
     * A repeater value is a list of row objects; a group value is a single object.
     *
     * @param array<mixed> $value
     */
    private static function is_list_of_rows(array $value): bool
    {
        return isset($value[0]) && is_array($value[0]);
    }

    private static function is_empty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /**
     * Closest known name for a stray one: a documented alias first, then edit distance.
     *
     * @param string[] $known
     */
    private static function suggest_from(string $name, array $known): ?string
    {
        if ($known === []) {
            return null;
        }

        foreach (self::ALIASES[$name] ?? [] as $candidate) {
            if (in_array($candidate, $known, true)) {
                return $candidate;
            }
        }

        $best     = null;
        $distance = PHP_INT_MAX;
        foreach ($known as $candidate) {
            $d = levenshtein($name, (string) $candidate);
            if ($d < $distance) {
                $distance = $d;
                $best     = (string) $candidate;
            }
        }

        // Only suggest a genuinely close name; an unrelated word helps nobody.
        return ($best !== null && $distance <= max(2, (int) floor(strlen($name) / 3))) ? $best : null;
    }

    /**
     * Every valid dotted path in the schema, e.g. ['heading', 'items', 'items.title'].
     *
     * @param array<string, mixed> $level
     * @return string[]
     */
    private static function known_paths(array $level, string $prefix = ''): array
    {
        $paths = [];
        foreach ($level as $name => $spec) {
            $path    = $prefix === '' ? (string) $name : $prefix . '.' . $name;
            $paths[] = $path;
            if (! empty($spec['sub'])) {
                $paths = array_merge($paths, self::known_paths($spec['sub'], $path));
            }
        }

        return $paths;
    }

    /**
     * Turn a report into the WP_Error the abilities layer returns.
     *
     * @param array<string, mixed> $report
     */
    public static function to_wp_error(string $block_name, int $index, array $report): \WP_Error
    {
        if (isset($report['unknown_block'])) {
            return new \WP_Error('unknown_block', sprintf(
                /* translators: 1: block name, 2: position in the block list */
                __('Block "%1$s" (position %2$d) is not a registered ACF block on this site.', 'ai-by-roadmap'),
                $block_name,
                $index
            ), $report);
        }

        if (! empty($report['unknown'])) {
            $hints = [];
            foreach ((array) ($report['did_you_mean'] ?? []) as $from => $to) {
                $hints[] = $from . ' -> ' . $to;
            }

            return new \WP_Error('unknown_fields', sprintf(
                /* translators: 1: block name, 2: position, 3: field names, 4: suggestions */
                __('Block "%1$s" (position %2$d) has field names its ACF schema does not define: %3$s.%4$s Read the block schema and use its exact field names.', 'ai-by-roadmap'),
                $block_name,
                $index,
                implode(', ', (array) $report['unknown']),
                $hints ? ' ' . __('Did you mean:', 'ai-by-roadmap') . ' ' . implode('; ', $hints) . '.' : ''
            ), $report);
        }

        if (! empty($report['invalid_enum'])) {
            $first = $report['invalid_enum'][0];

            return new \WP_Error('invalid_enum', sprintf(
                /* translators: 1: block name, 2: position, 3: field path, 4: value, 5: allowed values */
                __('Block "%1$s" (position %2$d): "%3$s" does not accept "%4$s". Allowed: %5$s.', 'ai-by-roadmap'),
                $block_name,
                $index,
                $first['path'],
                $first['value'],
                implode(', ', $first['allowed'])
            ), $report);
        }

        return new \WP_Error('missing_required', sprintf(
            /* translators: 1: block name, 2: position, 3: field names */
            __('Block "%1$s" (position %2$d) is missing required field(s): %3$s.', 'ai-by-roadmap'),
            $block_name,
            $index,
            implode(', ', (array) ($report['missing_required'] ?? []))
        ), $report);
    }
}
