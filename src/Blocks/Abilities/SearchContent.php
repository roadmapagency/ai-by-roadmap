<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks\Abilities;

use Roadmap\AiByRoadmap\Blocks\AcfBlockFields;
use Roadmap\AiByRoadmap\Blocks\BlockPatcher;
use Roadmap\AiByRoadmap\Blocks\TextFields;
use WP_Error;

/**
 * Find where a phrase appears across the site's block fields — the lookup
 * step for "change every X to Y" and "which pages mention Z". Returns the
 * exact post / block index / field path so the hit can be edited with
 * update-block-fields or rewritten in bulk with replace-text.
 */
final class SearchContent
{
    public const ID = 'ai-by-roadmap/search-content';

    public const SCAN_CAP = 300;

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'                => \Roadmap\AiByRoadmap\Plugin::ability_meta(true),
            'category'            => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'               => __('Search text in block fields', 'ai-by-roadmap'),
            'description'         => __('Search the text stored in ACF block fields across posts (pages, CPT entries, synced patterns) and return every hit with post_id, block_index, block_type and field path — ready to pass to update-block-fields, or to replace-text for a bulk rewrite. Matches stored HTML, so a phrase split by an inline tag will not match. Case-insensitive by default; set regex: true for a PCRE pattern (without delimiters). Excludes ai_content unless include_ai_content is true.', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['query'],
                'properties'           => [
                    'query'              => ['type' => 'string', 'description' => 'Text (or regex with regex: true) to find.'],
                    'regex'              => ['type' => 'boolean', 'default' => false],
                    'case_sensitive'     => ['type' => 'boolean', 'default' => false],
                    'post_id'            => ['type' => 'integer', 'description' => 'Search one post only.'],
                    'post_type'          => ['type' => 'string', 'description' => 'Restrict to one post type.'],
                    'include_ai_content' => ['type' => 'boolean', 'default' => false],
                    'limit'              => ['type' => 'integer', 'description' => 'Max posts to scan (default 150, max 300).'],
                ],
            ],
            'output_schema'       => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['posts_scanned', 'total_hits', 'hits'],
                'properties'           => [
                    'posts_scanned' => ['type' => 'integer'],
                    'total_hits'    => ['type' => 'integer'],
                    'hits'          => [
                        'type'  => 'array',
                        'items' => [
                            'type'                 => 'object',
                            'additionalProperties' => false,
                            'required'             => ['post_id', 'block_index', 'block_type', 'field', 'snippet'],
                            'properties'           => [
                                'post_id'     => ['type' => 'integer'],
                                'post_title'  => ['type' => 'string'],
                                'post_type'   => ['type' => 'string'],
                                'block_index' => ['type' => 'integer'],
                                'block_type'  => ['type' => 'string'],
                                'field'       => ['type' => 'string', 'description' => 'Field path, e.g. intro or items[1].body.'],
                                'matches'     => ['type' => 'integer', 'description' => 'Occurrences within this field.'],
                                'snippet'     => ['type' => 'string'],
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
        $pattern = self::pattern($input);
        if (is_wp_error($pattern)) {
            return $pattern;
        }

        $posts = self::posts_to_scan($input, self::SCAN_CAP, 150);
        if (is_wp_error($posts)) {
            return $posts;
        }

        $hits  = [];
        $total = 0;
        foreach ($posts as $post) {
            if (! current_user_can('edit_post', $post->ID)) {
                continue;
            }
            foreach (self::text_fields($post, ! empty($input['include_ai_content'])) as $entry) {
                $n = preg_match_all($pattern, $entry['value'], $m, PREG_OFFSET_CAPTURE);
                if (! $n) {
                    continue;
                }
                $total  += $n;
                $hits[] = [
                    'post_id'     => (int) $post->ID,
                    'post_title'  => (string) $post->post_title,
                    'post_type'   => (string) $post->post_type,
                    'block_index' => $entry['block_index'],
                    'block_type'  => $entry['block_type'],
                    'field'       => $entry['path'],
                    'matches'     => $n,
                    'snippet'     => TextFields::snippet($entry['value'], (int) $m[0][0][1], strlen((string) $m[0][0][0])),
                ];
            }
        }

        return ['posts_scanned' => count($posts), 'total_hits' => $total, 'hits' => $hits];
    }

    /**
     * Compile the search into a PCRE pattern.
     *
     * @param array<string, mixed> $input
     * @return string|WP_Error
     */
    public static function pattern(array $input)
    {
        $query = (string) ($input['query'] ?? $input['search'] ?? '');
        if ($query === '') {
            return new WP_Error('empty_query', __('query is empty.', 'ai-by-roadmap'), ['status' => 400]);
        }
        $flags   = 'u' . (empty($input['case_sensitive']) ? 'i' : '');
        $body    = ! empty($input['regex']) ? $query : preg_quote($query, '~');
        $pattern = '~' . $body . '~' . $flags;

        if (@preg_match($pattern, '') === false) {
            $err = error_get_last();
            return new WP_Error('invalid_regex', sprintf(
                /* translators: 1: the pattern, 2: PCRE error message */
                __('Invalid regex "%1$s": %2$s. Pass the pattern without delimiters; escape literal parentheses and dots.', 'ai-by-roadmap'),
                $query,
                $err ? preg_replace('/^preg_match\(\): /', '', (string) $err['message']) : preg_last_error_msg()
            ), ['status' => 400]);
        }
        return $pattern;
    }

    /**
     * Every text field of every ACF block on a post, with block coordinates.
     *
     * @return array<int, array{block_index:int, block_type:string, raw:int, path:string, value:string}>
     */
    public static function text_fields(\WP_Post $post, bool $include_ai_content): array
    {
        $out    = [];
        $blocks = parse_blocks((string) $post->post_content);
        foreach (BlockPatcher::acf_positions($blocks) as $index => $raw) {
            $type = (string) $blocks[$raw]['blockName'];
            $defs = AcfBlockFields::definitions($type);
            if ($defs === []) {
                continue;
            }
            $fields = AcfBlockFields::unflatten((array) ($blocks[$raw]['attrs']['data'] ?? []), $defs);
            foreach (TextFields::collect($fields, $defs, '', $include_ai_content) as $t) {
                $out[] = ['block_index' => $index, 'block_type' => $type, 'raw' => $raw, 'path' => $t['path'], 'value' => $t['value']];
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<int, \WP_Post>|WP_Error
     */
    public static function posts_to_scan(array $input, int $cap, int $default)
    {
        if (! empty($input['post_id'])) {
            $post = get_post((int) $input['post_id']);
            if (! $post) {
                return new WP_Error('post_not_found', __('Post not found.', 'ai-by-roadmap'), ['status' => 404]);
            }
            return [$post];
        }

        $limit = isset($input['limit']) ? max(1, min($cap, (int) $input['limit'])) : $default;
        $types = ! empty($input['post_type'])
            ? [(string) $input['post_type']]
            : array_merge(array_values(array_diff(array_keys(get_post_types(['public' => true])), ['attachment'])), ['wp_block']);

        return get_posts([
            'post_type'        => array_unique($types),
            'post_status'      => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page'   => $limit,
            'orderby'          => 'modified',
            'order'            => 'DESC',
            'suppress_filters' => false,
        ]);
    }
}
