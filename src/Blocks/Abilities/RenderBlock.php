<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks\Abilities;

use Roadmap\AiByRoadmap\Blocks\AcfBlockFields;
use Roadmap\AiByRoadmap\Blocks\BlockPatcher;
use WP_Error;

/**
 * Render one ACF block (or all of them) of a post to front-end HTML, without
 * a browser. Lets an agent proof what it just wrote — the actual markup the
 * theme template produces from the stored fields, plus a tags-stripped text
 * version for cheap copy checks.
 */
final class RenderBlock
{
    public const ID = 'ai-by-roadmap/render-block';

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'                => \Roadmap\AiByRoadmap\Plugin::ability_meta(true),
            'category'            => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'               => __('Render a block to HTML', 'ai-by-roadmap'),
            'description'         => __('Render one ACF block of a post (by block_index from get-post-blocks) — or every ACF block with all: true — through the theme\'s template and return the resulting front-end HTML plus a plain-text version. Use it after update-block-fields / insert-block to confirm the change reads correctly, or to proof copy without a browser. Read-only; does not include the site header/footer or CSS. For a visual check use get-preview-link.', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['post_id'],
                'properties'           => [
                    'post_id'     => ['type' => 'integer', 'description' => 'The post whose block to render.'],
                    'block_index' => ['type' => 'integer', 'description' => 'Zero-based ACF index from get-post-blocks. Omit with all: true to render every ACF block.'],
                    'all'         => ['type' => 'boolean', 'default' => false, 'description' => 'Render every ACF block on the post (larger response).'],
                    'include_html' => ['type' => 'boolean', 'default' => true, 'description' => 'Include the HTML. Set false to get only the plain text.'],
                ],
            ],
            'output_schema'       => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['post_id', 'blocks'],
                'properties'           => [
                    'post_id' => ['type' => 'integer'],
                    'blocks'  => [
                        'type'  => 'array',
                        'items' => [
                            'type'                 => 'object',
                            'additionalProperties' => false,
                            'required'             => ['index', 'block_type', 'text'],
                            'properties'           => [
                                'index'      => ['type' => 'integer'],
                                'block_type' => ['type' => 'string'],
                                'label'      => ['type' => 'string'],
                                'html'       => ['type' => 'string'],
                                'text'       => ['type' => 'string', 'description' => 'Rendered text with tags stripped and whitespace collapsed.'],
                            ],
                        ],
                    ],
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
        $post_id      = (int) $input['post_id'];
        $all          = ! empty($input['all']);
        $include_html = ! array_key_exists('include_html', $input) || ! empty($input['include_html']);
        $only_index   = isset($input['block_index']) ? (int) $input['block_index'] : null;

        $post = get_post($post_id);
        if (! $post) {
            return new WP_Error('post_not_found', __('Post not found.', 'ai-by-roadmap'), ['status' => 404]);
        }
        if (! current_user_can('edit_post', $post_id)) {
            return new WP_Error('forbidden', __('You are not allowed to edit this post.', 'ai-by-roadmap'), ['status' => 403]);
        }
        if ($only_index === null && ! $all) {
            return new WP_Error('missing_target', __('Pass block_index, or all: true to render every block.', 'ai-by-roadmap'), ['status' => 400]);
        }

        $blocks    = parse_blocks((string) $post->post_content);
        $positions = BlockPatcher::acf_positions($blocks);

        if ($only_index !== null && ! isset($positions[$only_index])) {
            return new WP_Error('block_not_found', sprintf(
                /* translators: 1: requested block index, 2: number of ACF blocks */
                __('No ACF block at index %1$d on this post (it has %2$d).', 'ai-by-roadmap'),
                $only_index,
                count($positions)
            ), ['status' => 404]);
        }

        // Block templates read the current post (get_the_ID(), get_field on
        // post meta, breadcrumbs), so render inside that post's context.
        $previous        = $GLOBALS['post'] ?? null;
        $GLOBALS['post'] = $post;
        setup_postdata($post);

        $out = [];
        try {
            foreach ($positions as $index => $raw) {
                if ($only_index !== null && $index !== $only_index) {
                    continue;
                }
                $html  = (string) render_block($blocks[$raw]);
                $text  = trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags($html, true)));
                $entry = [
                    'index'      => $index,
                    'block_type' => (string) $blocks[$raw]['blockName'],
                    'label'      => AcfBlockFields::label((array) ($blocks[$raw]['attrs']['data'] ?? [])),
                    'text'       => $text,
                ];
                if ($include_html) {
                    $entry['html'] = $html;
                }
                $out[] = $entry;
            }
        } finally {
            wp_reset_postdata();
            $GLOBALS['post'] = $previous;
        }

        return ['post_id' => $post_id, 'blocks' => $out];
    }
}
