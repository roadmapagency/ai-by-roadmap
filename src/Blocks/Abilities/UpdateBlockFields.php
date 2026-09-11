<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks\Abilities;

use Roadmap\AiByRoadmap\Blocks\AcfBlockFields;
use Roadmap\AiByRoadmap\Blocks\ACFTransformer;
use Roadmap\AiByRoadmap\Blocks\BlockPatcher;
use WP_Error;

/**
 * Merge-patch the fields of one ACF block on an existing post. This is the
 * tool that makes the connector usable for maintenance rather than only for
 * the initial build: change a paragraph, swap a CTA link, fix a typo — without
 * resupplying the other fifteen blocks.
 *
 * Semantics:
 *   - Merge, never replace: keys absent from `fields` keep their values.
 *     Repeaters and groups are the exception — they are replaced whole.
 *   - Only the target block is re-serialized. Every other block round-trips
 *     through parse_blocks()/serialize_blocks() byte-identical.
 *   - Human field names in; ACF keys (`_field` pointer rows) handled here.
 *   - Idempotent: a patch that changes nothing does not touch the post.
 */
final class UpdateBlockFields
{
    public const ID = 'ai-by-roadmap/update-block-fields';

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'                => ['show_in_rest' => true],
            'category'            => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'               => __('Update fields on one block', 'ai-by-roadmap'),
            'description'         => __('Change one or more fields on a single ACF block of an existing post, leaving every other field and block untouched (merge-patch). Workflow: find-posts → get-post-blocks with include_fields: true to see the block_index, the field names and current values → call this with only the fields to change. Use the schema field names from list-blocks/get-post-blocks (e.g. heading, intro, primary_button_url) — an unknown name returns the valid list. Rich-text fields take inline HTML (<p>, <strong>, <em>), never Markdown; images take attachment IDs; repeaters take the full array of rows and are replaced whole. Pass expected_block_type and expected_modified (from get-post-blocks) so the write is refused if the blocks moved or the post changed in between. ai_content is only touched if you include it.', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['post_id', 'block_index', 'fields'],
                'properties'           => [
                    'post_id'             => ['type' => 'integer', 'description' => 'The post to update.'],
                    'block_index'         => ['type' => 'integer', 'description' => 'Zero-based index among the post\'s ACF blocks, as returned by get-post-blocks.'],
                    'fields'              => [
                        'type'                 => 'object',
                        'additionalProperties' => true,
                        'minProperties'        => 1,
                        'description'          => 'Field name → new value, for the fields to change only. Same shape as get-post-blocks include_fields returns.',
                    ],
                    'expected_block_type' => ['type' => 'string', 'description' => 'Optional guard, e.g. "acf/hero": refuse if the block at block_index is a different type (blocks were reordered).'],
                    'expected_modified'   => ['type' => 'string', 'description' => 'Optional guard: the post\'s modified value from get-post-blocks/find-posts. Refuse if the post changed since.'],
                ],
            ],
            'output_schema'       => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['success', 'post_id', 'block_index', 'block_type', 'changed_fields', 'unchanged_fields', 'modified'],
                'properties'           => [
                    'success'             => ['type' => 'boolean'],
                    'post_id'             => ['type' => 'integer'],
                    'block_index'         => ['type' => 'integer'],
                    'block_type'          => ['type' => 'string'],
                    'changed_fields'      => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Fields whose stored value actually changed.'],
                    'unchanged_fields'    => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Fields you supplied that already had that value (nothing written for them).'],
                    'modified'            => ['type' => 'string', 'description' => 'The post\'s new modified value; use as expected_modified for a follow-up write.'],
                    'round_trip_lossless' => ['type' => 'boolean', 'description' => 'True when re-serializing left all other blocks byte-identical (normal). False means the post content was not in canonical form and the untouched blocks were re-encoded — content is preserved, whitespace/escaping may differ.'],
                    'edit_link'           => ['type' => 'string'],
                    'permalink'           => ['type' => 'string'],
                ],
            ],
            'permission_callback' => static fn(): bool => current_user_can('edit_posts'),
            'execute_callback'    => [self::class, 'execute'],
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public static function execute(array $input)
    {
        $post_id     = (int) $input['post_id'];
        $block_index = (int) $input['block_index'];
        $fields      = (array) ($input['fields'] ?? []);

        $post = get_post($post_id);
        if (! $post) {
            return new WP_Error('post_not_found', __('Post not found.', 'ai-by-roadmap'), ['status' => 404]);
        }
        if (! current_user_can('edit_post', $post_id)) {
            return new WP_Error('forbidden', __('You are not allowed to edit this post.', 'ai-by-roadmap'), ['status' => 403]);
        }
        if ($fields === []) {
            return new WP_Error('empty_patch', __('fields is empty — supply at least one field to change.', 'ai-by-roadmap'), ['status' => 400]);
        }

        $guard = BlockPatcher::check_modified($post, isset($input['expected_modified']) ? (string) $input['expected_modified'] : null);
        if (is_wp_error($guard)) {
            return $guard;
        }

        $content = (string) $post->post_content;
        $blocks  = parse_blocks($content);
        $target  = BlockPatcher::locate($blocks, $block_index);

        if ($target === null) {
            return new WP_Error('block_not_found', sprintf(
                /* translators: 1: requested block index, 2: number of ACF blocks */
                __('No ACF block at index %1$d on this post (it has %2$d). Call get-post-blocks to list them.', 'ai-by-roadmap'),
                $block_index,
                count(BlockPatcher::acf_positions($blocks))
            ), ['status' => 404]);
        }

        $block_type = (string) $blocks[$target]['blockName'];

        $guard = BlockPatcher::check_type($block_type, isset($input['expected_block_type']) ? (string) $input['expected_block_type'] : null, $block_index);
        if (is_wp_error($guard)) {
            return $guard;
        }

        $defs = AcfBlockFields::definitions($block_type);
        if ($defs === []) {
            return new WP_Error('unknown_block', sprintf(
                /* translators: %s: block type */
                __('No ACF field definitions found for %s — the block may not be registered on this site.', 'ai-by-roadmap'),
                $block_type
            ), ['status' => 400]);
        }

        $valid = AcfBlockFields::validate($fields, $defs, $block_type);
        if (is_wp_error($valid)) {
            return $valid;
        }

        $old_data = (array) ($blocks[$target]['attrs']['data'] ?? []);
        $new_data = $old_data;

        // Repeaters/groups are replaced whole: clear their flattened rows first
        // so a shorter new list cannot leave stale rows behind.
        foreach (array_keys($fields) as $name) {
            $def = AcfBlockFields::find($defs, (string) $name);
            if ($def && in_array($def['type'], ['repeater', 'group'], true)) {
                $new_data = AcfBlockFields::strip_field($new_data, $def);
            }
        }

        $patch    = (new ACFTransformer())->flatten($block_type, $fields);
        $new_data = array_merge($new_data, $patch);

        $changed   = [];
        $unchanged = [];
        foreach (array_keys($fields) as $name) {
            $def    = AcfBlockFields::find($defs, (string) $name);
            $before = wp_json_encode(AcfBlockFields::unflatten($old_data, [$def]));
            $after  = wp_json_encode(AcfBlockFields::unflatten($new_data, [$def]));
            if ($before === $after) {
                $unchanged[] = (string) $name;
            } else {
                $changed[] = (string) $name;
            }
        }

        $result = [
            'success'             => true,
            'post_id'             => $post_id,
            'block_index'         => $block_index,
            'block_type'          => $block_type,
            'changed_fields'      => $changed,
            'unchanged_fields'    => $unchanged,
            'modified'            => BlockPatcher::modified($post),
            'round_trip_lossless' => true,
            'edit_link'           => (string) get_edit_post_link($post_id, 'raw'),
            'permalink'           => (string) get_permalink($post_id),
        ];

        if ($changed === []) {
            return $result; // idempotent: nothing to write, no new revision
        }

        $result['round_trip_lossless'] = BlockPatcher::round_trip_lossless($content);

        $blocks[$target]['attrs']['data'] = $new_data;

        $modified = BlockPatcher::save($post_id, $blocks);
        if (is_wp_error($modified)) {
            return $modified;
        }
        $result['modified'] = $modified;

        return $result;
    }
}
