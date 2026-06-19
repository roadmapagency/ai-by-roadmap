<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks\Abilities;

use Roadmap\AiByRoadmap\Blocks\ACFTransformer;
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
            'description'         => __('Assemble and persist a WordPress page from blocks you have already filled yourself — no AI is called. Use this when an LLM is driving the MCP: call list-blocks to learn each block\'s field schema, fill the fields yourself, then pass the ordered list of {type, fields} here. The blocks are serialized to ACF markup and saved to a new draft page (or an existing post when post_id + replace_content are given). Choose the destination with list-post-types: match the source page route to a type\'s rewrite_slug (e.g. a /programs/… route → post_type "program", not the generic "page"); when that type has a locked template, supply your blocks in that exact order and of those exact types — this is enforced server-side, and a mismatch returns an error telling you exactly what to fix. For rich-text fields (schema format "html"), write HTML inline tags (<strong>, <em>) — never Markdown. Prefer compose-page only when you have raw content and no model to do the analyse/choose/fill work.', 'ai-by-roadmap'),
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
                        'description' => 'Optional post ID. If provided together with replace_content, that post is overwritten. If omitted, a new draft page is created.',
                    ],
                    'replace_content' => [
                        'type'        => 'boolean',
                        'description' => 'When post_id is provided, set true to overwrite its content. Ignored when post_id is omitted.',
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

        // Destination post type — an existing post's type when updating, else the
        // requested type (default page). Used to enforce a locked CPT template.
        $target_type = $post_id
            ? (string) get_post_type($post_id)
            : (string) ($input['post_type'] ?? 'page');

        $types      = [];
        $serialized = [];
        foreach ((array) $input['blocks'] as $block) {
            $block = (array) $block;
            $type  = (string) ($block['type'] ?? '');

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
        $template_check = self::enforce_template($target_type, $types);
        if (is_wp_error($template_check)) {
            return $template_check;
        }

        $result   = ['blocks' => $serialized];
        $content  = implode("\n\n", $serialized);

        if ($post_id && ! empty($input['replace_content'])) {
            wp_update_post([
                'ID'           => $post_id,
                // wp_update_post() runs wp_unslash() on input; slash so ACF's
                // <-escaped block attributes survive intact.
                'post_content' => wp_slash($content),
            ]);
            $result['post_id']   = $post_id;
            $result['edit_link'] = (string) get_edit_post_link($post_id, 'raw');
        } elseif (! $post_id) {
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

    /**
     * Enforce a destination post type's locked block template. Returns true when
     * the blocks are acceptable, or a WP_Error whose message tells the caller
     * exactly how to fix the block list.
     *
     *   - template_lock "all"    → blocks must match the template exactly, in order.
     *   - template_lock "insert" → same set of blocks (with counts), any order.
     *   - no lock / no template  → anything goes (flexible CPT or plain page).
     *
     * @param array<int, string> $types Block type IDs supplied, in order.
     * @return true|\WP_Error
     */
    private static function enforce_template(string $post_type, array $types)
    {
        if ($post_type === '') {
            return true;
        }

        $tpl      = CptTemplate::for_post_type($post_type);
        $expected = $tpl['blocks'];

        if (empty($expected) || ! in_array($tpl['lock'], ['all', 'insert'], true)) {
            return true;
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
                __('Post type "%1$s" has a locked block template (template_lock: all). Supply exactly these %2$d blocks, in this order: %3$s. You supplied %4$d: %5$s. First mismatch at position %6$d: expected "%7$s", got "%8$s". Adjust your blocks to match the template exactly, then call assemble-page again.', 'ai-by-roadmap'),
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
            __('Post type "%1$s" has a locked block template (template_lock: insert). Supply exactly this set of blocks (order may vary): %2$s. You supplied: %3$s. Missing: %4$s. Unexpected: %5$s. Adjust your blocks to match, then call assemble-page again.', 'ai-by-roadmap'),
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
}
