<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks;

use WP_Error;
use WP_Post;

/**
 * Read-modify-write mechanics shared by every partial-edit ability
 * (set-block-image, update-block-fields, insert-block, remove-block,
 * move-block). Keeping them here means all of those tools agree on:
 *
 *   - The index space. Blocks are addressed by their position among the
 *     post's ACF blocks only (`acf/*`). Non-ACF rows — synced patterns
 *     (`core/block`), core blocks, freeform whitespace — are skipped and do
 *     not consume an index. This is the same index get-post-blocks reports.
 *   - The optimistic-concurrency guards (expected_block_type, expected_modified).
 *   - How the document is written back: parse_blocks() → mutate one entry →
 *     serialize_blocks() → wp_update_post() with wp_slash(). Core's serializer
 *     reproduces ACF's own escaping, so untouched blocks come out byte-identical
 *     (verified: serialize_blocks(parse_blocks($c)) === $c on real content).
 */
final class BlockPatcher
{
    /**
     * Map of ACF index → raw parse_blocks() index.
     *
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, int>
     */
    public static function acf_positions(array $blocks): array
    {
        $positions = [];
        foreach ($blocks as $i => $block) {
            if (self::is_acf($block)) {
                $positions[] = $i;
            }
        }
        return $positions;
    }

    /**
     * Raw index of the Nth ACF block, or null when out of range.
     *
     * @param array<int, array<string, mixed>> $blocks
     */
    public static function locate(array $blocks, int $acf_index): ?int
    {
        if ($acf_index < 0) {
            return null;
        }
        return self::acf_positions($blocks)[$acf_index] ?? null;
    }

    /**
     * @param array<string, mixed> $block
     */
    public static function is_acf(array $block): bool
    {
        return str_starts_with((string) ($block['blockName'] ?? ''), 'acf/');
    }

    /**
     * True for the whitespace-only rows parse_blocks() emits between blocks.
     *
     * @param array<string, mixed> $block
     */
    public static function is_separator(array $block): bool
    {
        return empty($block['blockName']) && trim((string) ($block['innerHTML'] ?? '')) === '';
    }

    /**
     * Number of real (named) blocks — ACF blocks and fixed rows alike. This is
     * what find-posts reports as block_count and what decides is_empty.
     *
     * @param array<int, array<string, mixed>> $blocks
     */
    public static function named_count(array $blocks): int
    {
        $count = 0;
        foreach ($blocks as $block) {
            if (! empty($block['blockName'])) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Non-ACF named rows (synced patterns etc.) with their document position,
     * so a caller can see why find-posts.block_count exceeds the ACF count.
     *
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, array{position:int, block_type:string, ref?:int}>
     */
    public static function fixed_rows(array $blocks): array
    {
        $rows     = [];
        $position = 0;
        foreach ($blocks as $block) {
            if (empty($block['blockName'])) {
                continue;
            }
            if (! self::is_acf($block)) {
                $row = ['position' => $position, 'block_type' => (string) $block['blockName']];
                if (isset($block['attrs']['ref'])) {
                    $row['ref'] = (int) $block['attrs']['ref'];
                }
                $rows[] = $row;
            }
            $position++;
        }
        return $rows;
    }

    /**
     * The freeform "\n\n" row assemble-page leaves between blocks; inserted
     * alongside new blocks so the document keeps the same spacing.
     *
     * @return array<string, mixed>
     */
    public static function separator_block(): array
    {
        return [
            'blockName'    => null,
            'attrs'        => [],
            'innerBlocks'  => [],
            'innerHTML'    => "\n\n",
            'innerContent' => ["\n\n"],
        ];
    }

    /**
     * Insert a block before the ACF block at $acf_index (or append when
     * $acf_index equals the ACF count), keeping one "\n\n" separator between
     * neighbours as assemble-page does.
     *
     * @param array<int, array<string, mixed>> $blocks
     * @param array<string, mixed>             $block
     * @return array<int, array<string, mixed>>
     */
    public static function insert_at(array $blocks, int $acf_index, array $block): array
    {
        $positions = self::acf_positions($blocks);
        $count     = count($positions);

        if ($count === 0) {
            // Nothing to space against: drop trailing whitespace rows and append.
            $blocks = array_values(array_filter($blocks, static fn($b) => ! self::is_separator($b)));
            $blocks[] = $block;
            return $blocks;
        }

        if ($acf_index >= $count) {
            $raw = $positions[$count - 1] + 1;
            array_splice($blocks, $raw, 0, [self::separator_block(), $block]);
            return array_values($blocks);
        }

        $raw = $positions[$acf_index];
        array_splice($blocks, $raw, 0, [$block, self::separator_block()]);
        return array_values($blocks);
    }

    /**
     * Remove the block at raw index $raw together with one adjacent separator
     * row so the document does not accumulate blank lines.
     *
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, array<string, mixed>>
     */
    public static function remove_at(array $blocks, int $raw): array
    {
        $remove = [$raw];
        if (isset($blocks[$raw + 1]) && self::is_separator($blocks[$raw + 1])) {
            $remove[] = $raw + 1;
        } elseif (isset($blocks[$raw - 1]) && self::is_separator($blocks[$raw - 1])) {
            $remove[] = $raw - 1;
        }
        foreach ($remove as $i) {
            unset($blocks[$i]);
        }
        return array_values($blocks);
    }

    /**
     * Merge-patch a block's flat ACF data with human-shaped field values.
     * Repeaters and groups named in $fields are replaced whole (their old
     * flattened rows are stripped first); everything else is merged key by
     * key. Returns the new data plus which supplied fields actually changed,
     * compared in unflattened form so "1" vs 1 is not a change.
     *
     * This is THE merge path — update-block-fields, update-blocks,
     * replace-text and audit-links all go through it.
     *
     * @param array<string, mixed>             $data   Current attrs.data.
     * @param array<int, array<string, mixed>> $defs   AcfBlockFields::definitions().
     * @param array<string, mixed>             $fields Patch, by schema field name.
     * @return array{data: array<string, mixed>, changed: array<int, string>, unchanged: array<int, string>}
     */
    public static function patch_data(string $block_type, array $data, array $defs, array $fields): array
    {
        $patch = (new ACFTransformer())->flatten($block_type, $fields);
        $new   = $data;

        // Repeaters/groups: swap the field's old flattened keys for the new
        // ones IN PLACE, so key order (and therefore the serialized JSON) stays
        // as close to the original as possible.
        foreach (array_keys($fields) as $name) {
            $def = AcfBlockFields::find($defs, (string) $name);
            if (! $def || ! in_array($def['type'], ['repeater', 'group'], true)) {
                continue;
            }
            $owned_new = array_diff_key($patch, AcfBlockFields::strip_field($patch, $def));
            $patch     = array_diff_key($patch, $owned_new);
            $stripped  = AcfBlockFields::strip_field($new, $def);
            $owned_old = array_diff_key($new, $stripped);

            // Unflattened rows carry every sub-field, including ones the block
            // never stored. Don't invent empty keys the original did not have —
            // it keeps the serialized block minimal and diffs quiet. A key that
            // WAS stored is kept even when emptied (that is a real change).
            foreach ($owned_new as $key => $value) {
                $key = (string) $key;
                if ($key[0] === '_' || array_key_exists($key, $owned_old)) {
                    continue;
                }
                if ($value === '' || $value === null || $value === []) {
                    unset($owned_new[$key], $owned_new['_' . $key]);
                }
            }

            if ($owned_old === []) {
                $new = array_merge($stripped, $owned_new);
                continue;
            }
            $rebuilt  = [];
            $inserted = false;
            foreach ($new as $key => $value) {
                if (array_key_exists($key, $owned_old)) {
                    if (! $inserted) {
                        $rebuilt  = array_merge($rebuilt, $owned_new);
                        $inserted = true;
                    }
                    continue;
                }
                $rebuilt[$key] = $value;
            }
            $new = $rebuilt;
        }

        // Scalars: array_merge keeps an existing key's position and appends new
        // ones — except empty values for keys the block never stored (same rule
        // as above: never invent empty keys).
        foreach ($patch as $key => $value) {
            $key = (string) $key;
            if ($key[0] !== '_' && ! array_key_exists($key, $new) && ($value === '' || $value === null || $value === [])) {
                unset($patch[$key], $patch['_' . $key]);
            }
        }
        $new = array_merge($new, $patch);

        $changed   = [];
        $unchanged = [];
        foreach (array_keys($fields) as $name) {
            $def = AcfBlockFields::find($defs, (string) $name);
            if ($def === null) {
                $changed[] = (string) $name;
                continue;
            }
            $before = wp_json_encode(AcfBlockFields::unflatten($data, [$def]));
            $after  = wp_json_encode(AcfBlockFields::unflatten($new, [$def]));
            if ($before === $after) {
                $unchanged[] = (string) $name;
            } else {
                $changed[] = (string) $name;
            }
        }

        return ['data' => $new, 'changed' => $changed, 'unchanged' => $unchanged];
    }

    /**
     * Post modified stamp in the format find-posts / get-post-blocks return.
     */
    public static function modified(WP_Post $post): string
    {
        return (string) $post->post_modified;
    }

    /**
     * Optimistic-concurrency guard: refuse when the post changed since the
     * caller read it.
     *
     * @return true|WP_Error
     */
    public static function check_modified(WP_Post $post, ?string $expected)
    {
        $expected = $expected !== null ? trim($expected) : '';
        if ($expected === '' || $expected === self::modified($post)) {
            return true;
        }

        return new WP_Error('stale_post', sprintf(
            /* translators: 1: expected modified timestamp, 2: actual modified timestamp */
            __('The post was modified since you read it (you expected modified "%1$s", it is now "%2$s"). Re-read it with get-post-blocks and retry with the new value.', 'ai-by-roadmap'),
            $expected,
            self::modified($post)
        ), ['status' => 409]);
    }

    /**
     * Guard against blocks having been reordered between read and write.
     *
     * @return true|WP_Error
     */
    public static function check_type(string $actual, ?string $expected, int $block_index)
    {
        $expected = $expected !== null ? trim($expected) : '';
        if ($expected === '' || $expected === $actual) {
            return true;
        }

        return new WP_Error('block_type_mismatch', sprintf(
            /* translators: 1: block index, 2: actual block type, 3: expected block type */
            __('The block at index %1$d is "%2$s", not the expected "%3$s". The blocks may have been reordered — re-read them with get-post-blocks.', 'ai-by-roadmap'),
            $block_index,
            $actual,
            $expected
        ), ['status' => 409]);
    }

    /**
     * Whether core's serializer reproduces this content exactly. When true, a
     * one-block mutation followed by save() leaves every other block untouched
     * byte for byte. Surfaced to callers as round_trip_lossless.
     */
    public static function round_trip_lossless(string $content): bool
    {
        return serialize_blocks(parse_blocks($content)) === $content;
    }

    /**
     * Persist a mutated block list. Returns the new modified stamp.
     *
     * @param array<int, array<string, mixed>> $blocks
     * @return string|WP_Error
     */
    public static function save(int $post_id, array $blocks)
    {
        $content = serialize_blocks($blocks);

        self::ensure_undo_point($post_id);

        // wp_update_post() runs wp_unslash() on input; slash so ACF's
        // <-escaped block attributes survive intact.
        $result = wp_update_post([
            'ID'           => $post_id,
            'post_content' => wp_slash($content),
        ], true);

        if (is_wp_error($result)) {
            return $result;
        }

        clean_post_cache($post_id);
        $post = get_post($post_id);

        return $post ? self::modified($post) : '';
    }

    /**
     * WordPress snapshots a post AFTER each save, so a post that has never had
     * a revision loses its pre-edit state on the first agent write. Take that
     * snapshot first when none exists, so restore-revision can always undo the
     * first edit too.
     */
    public static function ensure_undo_point(int $post_id): void
    {
        $post = get_post($post_id);
        if (! $post || ! post_type_supports((string) $post->post_type, 'revisions')) {
            return;
        }
        if (wp_get_post_revisions($post_id, ['posts_per_page' => 1, 'fields' => 'ids']) === []) {
            wp_save_post_revision($post_id);
        }
    }

    /**
     * Compact summary of the ACF blocks after a structural edit, so callers do
     * not need a second read to learn the new indices.
     *
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, array{index:int, block_type:string, label:string}>
     */
    public static function summary(array $blocks): array
    {
        $out   = [];
        $index = 0;
        foreach ($blocks as $block) {
            if (! self::is_acf($block)) {
                continue;
            }
            $out[] = [
                'index'      => $index,
                'block_type' => (string) $block['blockName'],
                'label'      => AcfBlockFields::label((array) ($block['attrs']['data'] ?? [])),
            ];
            $index++;
        }
        return $out;
    }
}
