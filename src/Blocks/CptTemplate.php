<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks;

/**
 * Reads a post type's locked Gutenberg block template. Used both to advertise
 * the template (list-post-types) and to enforce it when a page is assembled,
 * so the two never drift.
 *
 * A template row whose block type is NOT a registered ACF block (for example a
 * synced pattern, `core/block` with a `ref`) is a FIXED row: nobody fills it,
 * the editor renders it as-is, and the AI pipeline must reproduce it verbatim
 * at its template position. `blocks` lists only the fillable rows so existing
 * callers keep working; `rows` carries the full picture.
 */
final class CptTemplate
{
    /**
     * @return array{
     *   rows: array<int, array{type:string, attrs:array<string, mixed>, fixed:bool}>,
     *   blocks: array<int, string>,
     *   lock: string,
     *   has_fixed: bool
     * }
     */
    public static function for_post_type(string $post_type): array
    {
        $obj        = get_post_type_object($post_type);
        $registered = BlockRegistry::get_blocks();

        $rows      = [];
        $blocks    = [];
        $has_fixed = false;

        if ($obj && is_array($obj->template)) {
            // Each template entry is [block_name, attrs, innerBlocks?].
            foreach ($obj->template as $entry) {
                $type = is_array($entry) ? (string) ($entry[0] ?? '') : (string) $entry;
                if ($type === '') {
                    continue;
                }
                $attrs = is_array($entry) && isset($entry[1]) && is_array($entry[1]) ? $entry[1] : [];
                $fixed = ! isset($registered[$type]);

                $rows[] = ['type' => $type, 'attrs' => $attrs, 'fixed' => $fixed];
                if ($fixed) {
                    $has_fixed = true;
                } else {
                    $blocks[] = $type;
                }
            }
        }

        $lock = ($obj && $obj->template_lock) ? (string) $obj->template_lock : '';

        return ['rows' => $rows, 'blocks' => $blocks, 'lock' => $lock, 'has_fixed' => $has_fixed];
    }

    /**
     * Block types of the fixed rows, for error messages.
     *
     * @param array<int, array{type:string, attrs:array<string, mixed>, fixed:bool}> $rows
     * @return array<int, string>
     */
    public static function fixed_types(array $rows): array
    {
        $types = [];
        foreach ($rows as $row) {
            if (! empty($row['fixed'])) {
                $types[] = (string) $row['type'];
            }
        }
        return array_values(array_unique($types));
    }

    /**
     * Serialize a fixed row from its template attrs, e.g. `<!-- wp:block {"ref":852} /-->`.
     * Delegates to core so escaping and namespace handling match the editor.
     *
     * @param array{type:string, attrs:array<string, mixed>} $row
     */
    public static function serialize_fixed_row(array $row): string
    {
        return serialize_block([
            'blockName'    => (string) $row['type'],
            'attrs'        => (array) ($row['attrs'] ?? []),
            'innerBlocks'  => [],
            'innerHTML'    => '',
            'innerContent' => [],
        ]);
    }

    /**
     * Walk the template rows: fixed rows serialize from their template attrs,
     * fillable rows consume the next supplied serialized block. Surplus supplied
     * blocks are appended (enforce_template should already have rejected a count
     * mismatch; this keeps the merge lossless regardless). For a template with
     * no fixed rows this is the identity on $fillable_serialized.
     *
     * @param array<int, array{type:string, attrs:array<string, mixed>, fixed:bool}> $rows
     * @param array<int, string> $fillable_serialized
     * @return array<int, string>
     */
    public static function merge_fixed_rows(array $rows, array $fillable_serialized): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! empty($row['fixed'])) {
                $out[] = self::serialize_fixed_row($row);
                continue;
            }
            if ($fillable_serialized) {
                $out[] = array_shift($fillable_serialized);
            }
        }
        return array_merge($out, array_values($fillable_serialized));
    }
}
