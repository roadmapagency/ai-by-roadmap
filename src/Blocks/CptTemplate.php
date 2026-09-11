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
     * Whether a template lock permits a structural operation, following the
     * editor's own semantics: "all" forbids inserting, removing and moving;
     * "insert" forbids inserting and removing but allows reordering; anything
     * else (no lock, "contentOnly" is not used for post types) allows all.
     *
     * @param string $op One of insert|remove|move.
     */
    public static function lock_allows(string $lock, string $op): bool
    {
        if ($lock === 'all') {
            return false;
        }
        if ($lock === 'insert') {
            return $op === 'move';
        }
        return true;
    }

    /**
     * WP_Error explaining that a structural edit is not allowed on this type.
     */
    public static function locked_error(string $post_type, string $lock, string $op): \WP_Error
    {
        return new \WP_Error('template_locked', sprintf(
            /* translators: 1: operation, 2: post type, 3: template lock value */
            __('Cannot %1$s a block on post type "%2$s": its block template is locked (template_lock: %3$s). Edit the block\'s fields in place with update-block-fields, or rebuild the post with assemble-page using the exact template.', 'ai-by-roadmap'),
            $op,
            $post_type,
            $lock
        ), ['status' => 409]);
    }

    /**
     * Enforce a post type's locked block template against an ordered list of
     * fillable block types. Returns true when acceptable, or a WP_Error whose
     * message tells the caller exactly how to fix the block list.
     *
     *   - template_lock "all"    → blocks must match the template exactly, in order.
     *   - template_lock "insert" → same set of blocks (with counts), any order.
     *   - no lock / no template  → anything goes (flexible CPT or plain page).
     *
     * Fixed rows are excluded from the comparison: callers supply only the
     * fillable blocks and the server merges the fixed rows in.
     *
     * @param array{rows:array<int, array{type:string, attrs:array<string, mixed>, fixed:bool}>, blocks:array<int, string>, lock:string, has_fixed:bool} $tpl
     * @param array<int, string> $types Block type IDs, in order.
     * @return true|\WP_Error
     */
    public static function validate(array $tpl, string $post_type, array $types, string $retry_hint = '')
    {
        if ($post_type === '') {
            return true;
        }

        $expected = $tpl['blocks'];

        if (empty($expected) || ! in_array($tpl['lock'], ['all', 'insert'], true)) {
            return true;
        }

        $fixed_note = '';
        if ($tpl['has_fixed']) {
            $fixed_note = ' ' . sprintf(
                /* translators: %s: list of fixed block types */
                __('Rows of type %s are fixed template rows inserted by the server — do not include them.', 'ai-by-roadmap'),
                implode(', ', self::fixed_types($tpl['rows']))
            );
        }
        if ($retry_hint === '') {
            $retry_hint = __('Adjust your blocks to match the template exactly, then call assemble-page again.', 'ai-by-roadmap');
        }

        if ($tpl['lock'] === 'all') {
            if ($types === $expected) {
                return true;
            }

            $pos = 0;
            $max = max(count($expected), count($types));
            for ($i = 0; $i < $max; $i++) {
                if (($expected[$i] ?? null) !== ($types[$i] ?? null)) {
                    $pos = $i + 1;
                    break;
                }
            }

            return new \WP_Error('template_mismatch', sprintf(
                /* translators: 1: post type, 2: expected count, 3: expected blocks, 4: supplied count, 5: supplied blocks, 6: position, 7: expected block, 8: supplied block */
                __('Post type "%1$s" has a locked block template (template_lock: all). Supply exactly these %2$d blocks, in this order: %3$s. You supplied %4$d: %5$s. First mismatch at position %6$d: expected "%7$s", got "%8$s".', 'ai-by-roadmap') . ' ' . $retry_hint . $fixed_note,
                $post_type,
                count($expected),
                implode(', ', $expected),
                count($types),
                $types ? implode(', ', $types) : '(none)',
                $pos,
                $expected[$pos - 1] ?? '(none)',
                $types[$pos - 1] ?? '(none)'
            ));
        }

        // template_lock "insert": same multiset of block types, order-independent.
        $want = $expected;
        $got  = $types;
        sort($want);
        sort($got);
        if ($want === $got) {
            return true;
        }

        // Per-type count deltas: positive = missing that many, negative = extra.
        $expected_counts = self::counts($expected);
        $got_counts      = self::counts($types);
        $missing         = [];
        $extra           = [];
        foreach (array_keys($expected_counts + $got_counts) as $type) {
            $delta = ($expected_counts[$type] ?? 0) - ($got_counts[$type] ?? 0);
            if ($delta > 0) {
                $missing[$type] = $delta;
            } elseif ($delta < 0) {
                $extra[$type] = -$delta;
            }
        }

        return new \WP_Error('template_mismatch', sprintf(
            /* translators: 1: post type, 2: expected blocks, 3: supplied blocks, 4: missing summary, 5: extra summary */
            __('Post type "%1$s" has a locked block template (template_lock: insert). Supply exactly this set of blocks (order may vary): %2$s. You supplied: %3$s. Missing: %4$s. Unexpected: %5$s.', 'ai-by-roadmap') . ' ' . $retry_hint . $fixed_note,
            $post_type,
            implode(', ', $expected),
            $types ? implode(', ', $types) : '(none)',
            self::summarize($missing),
            self::summarize($extra)
        ));
    }

    /**
     * @param array<int, string> $items
     * @return array<string, int> block type => count
     */
    private static function counts(array $items): array
    {
        $counts = [];
        foreach ($items as $item) {
            $counts[$item] = ($counts[$item] ?? 0) + 1;
        }
        return $counts;
    }

    /**
     * @param array<string, int> $counts
     */
    private static function summarize(array $counts): string
    {
        if (empty($counts)) {
            return '(none)';
        }
        $parts = [];
        foreach ($counts as $type => $n) {
            $parts[] = $n > 1 ? "{$type} x{$n}" : $type;
        }
        return implode(', ', $parts);
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
