<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks\Abilities;

use Roadmap\AiByRoadmap\Blocks\AcfBlockFields;
use Roadmap\AiByRoadmap\Blocks\BlockPatcher;
use WP_Error;

/**
 * update-block-fields for several blocks at once: every patch is validated
 * before anything is written, then the post is saved a single time (one
 * revision, one modified stamp).
 */
final class UpdateBlocks
{
    public const ID = 'ai-by-roadmap/update-blocks';

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'                => \Roadmap\AiByRoadmap\Plugin::ability_meta(false, false, true),
            'category'            => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'               => __('Update fields on several blocks', 'ai-by-roadmap'),
            'description'         => __('Apply several update-block-fields patches to one post in a single save: pass a list of {block_index, expected_block_type?, fields}. All patches are validated first — any error aborts the whole call with nothing written — then the post is saved once (one revision). Same field rules as update-block-fields (merge-patch, repeaters replaced whole, inline HTML for rich text). Use it when an edit spans multiple blocks, e.g. renaming a programme across a page.', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['post_id', 'patches'],
                'properties'           => [
                    'post_id'           => ['type' => 'integer'],
                    'patches'           => [
                        'type'     => 'array',
                        'minItems' => 1,
                        'items'    => [
                            'type'                 => 'object',
                            'additionalProperties' => false,
                            'required'             => ['block_index', 'fields'],
                            'properties'           => [
                                'block_index'         => ['type' => 'integer'],
                                'expected_block_type' => ['type' => 'string'],
                                'fields'              => ['type' => 'object', 'additionalProperties' => true, 'minProperties' => 1],
                            ],
                        ],
                    ],
                    'expected_modified' => ['type' => 'string', 'description' => 'Optional guard: the post\'s modified value from get-post-blocks/find-posts.'],
                ],
            ],
            'output_schema'       => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['success', 'post_id', 'results', 'modified'],
                'properties'           => [
                    'success'             => ['type' => 'boolean'],
                    'post_id'             => ['type' => 'integer'],
                    'results'             => [
                        'type'  => 'array',
                        'items' => [
                            'type'                 => 'object',
                            'additionalProperties' => false,
                            'required'             => ['block_index', 'block_type', 'changed_fields', 'unchanged_fields'],
                            'properties'           => [
                                'block_index'      => ['type' => 'integer'],
                                'block_type'       => ['type' => 'string'],
                                'changed_fields'   => ['type' => 'array', 'items' => ['type' => 'string']],
                                'unchanged_fields' => ['type' => 'array', 'items' => ['type' => 'string']],
                            ],
                        ],
                    ],
                    'modified'            => ['type' => 'string'],
                    'round_trip_lossless' => ['type' => 'boolean'],
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
        $post_id = (int) $input['post_id'];
        $patches = array_values((array) ($input['patches'] ?? []));

        $post = get_post($post_id);
        if (! $post) {
            return new WP_Error('post_not_found', __('Post not found.', 'ai-by-roadmap'), ['status' => 404]);
        }
        if (! current_user_can('edit_post', $post_id)) {
            return new WP_Error('forbidden', __('You are not allowed to edit this post.', 'ai-by-roadmap'), ['status' => 403]);
        }
        if ($patches === []) {
            return new WP_Error('empty_patch', __('patches is empty.', 'ai-by-roadmap'), ['status' => 400]);
        }

        $guard = BlockPatcher::check_modified($post, isset($input['expected_modified']) ? (string) $input['expected_modified'] : null);
        if (is_wp_error($guard)) {
            return $guard;
        }

        $content   = (string) $post->post_content;
        $blocks    = parse_blocks($content);
        $positions = BlockPatcher::acf_positions($blocks);
        $seen      = [];
        $results   = [];
        $any       = false;

        foreach ($patches as $i => $patch) {
            $patch  = (array) $patch;
            $index  = (int) ($patch['block_index'] ?? -1);
            $fields = (array) ($patch['fields'] ?? []);

            if (isset($seen[$index])) {
                return new WP_Error('duplicate_block', sprintf(
                    /* translators: %d: block index */
                    __('patches address block %d more than once — merge them into one patch.', 'ai-by-roadmap'),
                    $index
                ), ['status' => 400]);
            }
            $seen[$index] = true;

            if (! isset($positions[$index])) {
                return new WP_Error('block_not_found', sprintf(
                    /* translators: 1: patch position, 2: block index, 3: number of ACF blocks */
                    __('patches[%1$d]: no ACF block at index %2$d (the post has %3$d).', 'ai-by-roadmap'),
                    $i,
                    $index,
                    count($positions)
                ), ['status' => 404]);
            }
            if ($fields === []) {
                return new WP_Error('empty_patch', sprintf(
                    /* translators: %d: patch position */
                    __('patches[%d].fields is empty.', 'ai-by-roadmap'),
                    $i
                ), ['status' => 400]);
            }

            $raw  = $positions[$index];
            $type = (string) $blocks[$raw]['blockName'];

            $guard = BlockPatcher::check_type($type, isset($patch['expected_block_type']) ? (string) $patch['expected_block_type'] : null, $index);
            if (is_wp_error($guard)) {
                return $guard;
            }

            $defs = AcfBlockFields::definitions($type);
            if ($defs === []) {
                return new WP_Error('unknown_block', sprintf(
                    /* translators: %s: block type */
                    __('No ACF field definitions found for %s.', 'ai-by-roadmap'),
                    $type
                ), ['status' => 400]);
            }
            $valid = AcfBlockFields::validate($fields, $defs, $type);
            if (is_wp_error($valid)) {
                return new WP_Error($valid->get_error_code(), sprintf(
                    /* translators: 1: patch position, 2: block index, 3: message */
                    __('patches[%1$d] (block %2$d): %3$s', 'ai-by-roadmap'),
                    $i,
                    $index,
                    $valid->get_error_message()
                ), $valid->get_error_data());
            }

            $patched = BlockPatcher::patch_data($type, (array) ($blocks[$raw]['attrs']['data'] ?? []), $defs, $fields);
            if ($patched['changed'] !== []) {
                $blocks[$raw]['attrs']['data'] = $patched['data'];
                $any = true;
            }
            $results[] = [
                'block_index'      => $index,
                'block_type'       => $type,
                'changed_fields'   => $patched['changed'],
                'unchanged_fields' => $patched['unchanged'],
            ];
        }

        $result = [
            'success'             => true,
            'post_id'             => $post_id,
            'results'             => $results,
            'modified'            => BlockPatcher::modified($post),
            'round_trip_lossless' => true,
            'edit_link'           => (string) get_edit_post_link($post_id, 'raw'),
            'permalink'           => (string) get_permalink($post_id),
        ];

        if (! $any) {
            return $result;
        }

        $result['round_trip_lossless'] = BlockPatcher::round_trip_lossless($content);

        $modified = BlockPatcher::save($post_id, $blocks);
        if (is_wp_error($modified)) {
            return $modified;
        }
        $result['modified'] = $modified;

        return $result;
    }
}
