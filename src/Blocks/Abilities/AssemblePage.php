<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks\Abilities;

use Roadmap\AiByRoadmap\Blocks\ACFTransformer;
use Roadmap\AiByRoadmap\Blocks\BlockPatcher;
use Roadmap\AiByRoadmap\Blocks\BlockRegistry;
use Roadmap\AiByRoadmap\Blocks\CptTemplate;
use Roadmap\AiByRoadmap\Plugin;

/**
 * Non-metered counterpart to compose-page. The caller (typically an external
 * LLM driving the MCP server) has already analysed the source, chosen its
 * blocks, and filled every field itself — using the schemas returned by
 * list-blocks. This ability only runs the deterministic tail of the pipeline:
 * serialize the filled blocks into ACF block markup and persist the page.
 *
 * No LLM is called here. Use compose-page instead when you have raw content and
 * no model of your own to do the analyse/choose/fill work.
 */
final class AssemblePage
{
    public const ID = 'ai-by-roadmap/assemble-page';

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'                => ['show_in_rest' => true],
            'category'            => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'               => __('Assemble a page from filled blocks', 'ai-by-roadmap'),
            'description'         => __('Assemble and persist a WordPress page from blocks you have already filled yourself — no AI is called. Use this when an LLM is driving the MCP: call list-blocks to learn each block\'s field schema, fill the fields yourself, then pass the ordered list of {type, fields} here. The blocks are serialized to ACF markup and saved: provide post_id to update an existing page (e.g. an empty placeholder found via find-posts), or omit it to create a new draft. Call find-posts first to decide whether a matching page already exists rather than duplicating it. Choose the destination with list-post-types: match the source page route to a type\'s rewrite_slug (e.g. a /programs/… route → post_type "program", not the generic "page"); when that type has a locked template, supply your blocks in that exact order and of those exact types — this is enforced server-side, and a mismatch returns an error telling you exactly what to fix. list-post-types marks some template rows fixed:true (e.g. synced patterns, core/block): never include those in blocks — supply only the fillable_blocks, in order, and the server inserts the fixed rows at their template positions. For rich-text fields (schema format "html"), write HTML inline tags (<strong>, <em>) — never Markdown. This tool builds whole pages: to change copy or structure on a page that already has blocks, use get-post-blocks (include_fields: true) with update-block-fields, insert-block, remove-block or move-block instead — a non-empty post is refused here unless replace_content is true. Prefer compose-page only when you have raw content and no model to do the analyse/choose/fill work.', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['blocks'],
                'properties'           => [
                    'blocks'          => [
                        'type'        => 'array',
                        'description' => 'Ordered list of blocks to place on the page, top to bottom.',
                        'items'       => [
                            'type'                 => 'object',
                            'additionalProperties' => false,
                            'required'             => ['type', 'fields'],
                            'properties'           => [
                                'type'   => [
                                    'type'        => 'string',
                                    'description' => 'Block type ID (e.g. acf/hero). Must be one returned by list-blocks.',
                                    'enum'        => array_keys(BlockRegistry::get_blocks()),
                                ],
                                'fields' => [
                                    'type'                 => 'object',
                                    'additionalProperties' => true,
                                    'description'          => 'Field values for this block, matching its schema from list-blocks. May include an ai_content string holding the verbatim source slice used for this block.',
                                ],
                            ],
                        ],
                    ],
                    'post_id'         => [
                        'type'        => 'integer',
                        'description' => 'Provide to fill an EXISTING post — e.g. an empty placeholder found via find-posts (error if it does not exist). Omit to create a new draft. A post that already has blocks is refused unless replace_content is true; to change part of an existing page use update-block-fields / insert-block / remove-block / move-block instead.',
                    ],
                    'replace_content' => [
                        'type'        => 'boolean',
                        'default'     => false,
                        'description' => 'Set true to DISCARD the existing content of post_id and replace it with these blocks. Only needed when the post already has blocks; this is destructive, so confirm with the user first.',
                    ],
                    'title'           => [
                        'type'        => 'string',
                        'description' => 'Title for the new page when post_id is omitted. Defaults to "AI generated page" plus a timestamp.',
                    ],
                    'post_type'       => [
                        'type'        => 'string',
                        'description' => 'Post type for the new page when post_id is omitted. Defaults to "page". Use list-post-types and match the source route to a type\'s rewrite_slug (e.g. "program" for a /programs/… route).',
                    ],
                ],
            ],
            'output_schema'       => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['blocks'],
                'properties'           => [
                    'blocks'    => [
                        'type'  => 'array',
                        'items' => ['type' => 'string'],
                    ],
                    'post_id'   => ['type' => 'integer'],
                    'edit_link' => ['type' => 'string'],
                ],
            ],
            'permission_callback' => static fn(): bool => current_user_can('edit_posts'),
            'execute_callback'    => [self::class, 'execute'],
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public static function execute(array $input)
    {
        $registered  = BlockRegistry::get_blocks();
        $transformer = new ACFTransformer();
        $post_id     = isset($input['post_id']) ? (int) $input['post_id'] : null;

        // A supplied post_id always means "update this post". Guard it up front so
        // we never silently create a duplicate when the id is wrong.
        $existing = $post_id ? get_post($post_id) : null;
        if ($post_id && ! $existing) {
            return new \WP_Error('post_not_found', sprintf(
                /* translators: %d: post ID */
                __('No post with ID %d exists. Omit post_id to create a new page, or use find-posts to locate the right one.', 'ai-by-roadmap'),
                $post_id
            ), ['status' => 404]);
        }

        // Overwriting is the destructive path, so it is opt-in: a post that
        // already has blocks is only replaced when replace_content is true.
        if ($existing) {
            $existing_count = BlockPatcher::named_count(parse_blocks((string) $existing->post_content));
            if ($existing_count > 0 && empty($input['replace_content'])) {
                return new \WP_Error('post_not_empty', sprintf(
                    /* translators: 1: post ID, 2: block count */
                    __('Post %1$d already has %2$d blocks. To edit it, use get-post-blocks (include_fields: true) with update-block-fields, insert-block, remove-block or move-block. To discard its content and rebuild it from scratch, call assemble-page again with replace_content: true.', 'ai-by-roadmap'),
                    $post_id,
                    $existing_count
                ), ['status' => 409]);
            }
        }

        // Destination post type — an existing post's type when updating, else the
        // requested type (default page). Used to enforce a locked CPT template.
        $target_type = $post_id
            ? (string) get_post_type($post_id)
            : (string) ($input['post_type'] ?? 'page');

        // The destination's locked template (if any). Fixed rows (e.g. synced
        // patterns) are never supplied by the caller — they are merged in below.
        $tpl         = $target_type !== '' ? CptTemplate::for_post_type($target_type) : ['rows' => [], 'blocks' => [], 'lock' => '', 'has_fixed' => false];
        $fixed_types = CptTemplate::fixed_types($tpl['rows']);

        $types      = [];
        $serialized = [];
        foreach ((array) $input['blocks'] as $block) {
            $block = (array) $block;
            $type  = (string) ($block['type'] ?? '');

            if (in_array($type, $fixed_types, true)) {
                return new \WP_Error('fixed_template_row', sprintf(
                    /* translators: 1: block type, 2: post type, 3: list of fillable block types */
                    __('"%1$s" is a fixed row of the "%2$s" template and is inserted automatically. Omit it and supply only these blocks, in order: %3$s.', 'ai-by-roadmap'),
                    $type,
                    $target_type,
                    implode(', ', $tpl['blocks'])
                ));
            }

            if (! isset($registered[$type])) {
                return new \WP_Error(
                    'invalid_block_type',
                    sprintf(
                        /* translators: 1: block type, 2: list of registered block IDs */
                        __('Block "%1$s" is not registered. Registered: %2$s.', 'ai-by-roadmap'),
                        $type,
                        implode(', ', array_keys($registered))
                    )
                );
            }

            $types[] = $type;

            // ai_content holds the verbatim source slice for this block; default
            // to empty so a block that legitimately has none still serializes.
            $fields = (array) ($block['fields'] ?? []);
            $fields[Plugin::AI_CONTENT_FIELD] = (string) ($fields[Plugin::AI_CONTENT_FIELD] ?? '');

            $serialized[] = $transformer->convert([$type => $fields]);
        }

        // Reject (with actionable feedback) before persisting anything if the
        // destination post type has a locked block template the blocks violate.
        $template_check = CptTemplate::validate($tpl, $target_type, $types);
        if (is_wp_error($template_check)) {
            return $template_check;
        }

        // Re-insert the template's fixed rows (synced patterns etc.) at their
        // positions so the saved post matches the locked template exactly.
        if ($tpl['has_fixed'] && in_array($tpl['lock'], ['all', 'insert'], true)) {
            $serialized = CptTemplate::merge_fixed_rows($tpl['rows'], $serialized);
        }

        $result   = ['blocks' => $serialized];
        $content  = implode("\n\n", $serialized);

        if ($post_id) {
            $updated = wp_update_post([
                'ID'           => $post_id,
                // wp_update_post() runs wp_unslash() on input; slash so ACF's
                // <-escaped block attributes survive intact.
                'post_content' => wp_slash($content),
            ], true);
            if (is_wp_error($updated)) {
                return $updated;
            }
            $result['post_id']   = $post_id;
            $result['edit_link'] = (string) get_edit_post_link($post_id, 'raw');
        } else {
            $title = (string) ($input['title'] ?? '');
            if ($title === '') {
                $title = sprintf(
                    /* translators: %s: current date/time */
                    __('AI generated page — %s', 'ai-by-roadmap'),
                    date_i18n('M j, Y \a\t g:ia')
                );
            }

            $post_type = (string) ($input['post_type'] ?? 'page');
            if (! post_type_exists($post_type)) {
                return new \WP_Error('invalid_post_type', sprintf(
                    /* translators: %s: post type */
                    __('Post type "%s" is not registered.', 'ai-by-roadmap'),
                    $post_type
                ));
            }

            $new_id = wp_insert_post([
                'post_type'    => $post_type,
                'post_status'  => 'draft',
                'post_title'   => $title,
                // wp_insert_post() runs wp_unslash() on input; slash so ACF's
                // <-escaped block attributes survive intact.
                'post_content' => wp_slash($content),
            ], true);

            if (is_wp_error($new_id)) {
                return $new_id;
            }

            $result['post_id']   = (int) $new_id;
            $result['edit_link'] = (string) get_edit_post_link((int) $new_id, 'raw');
        }

        return $result;
    }

}
