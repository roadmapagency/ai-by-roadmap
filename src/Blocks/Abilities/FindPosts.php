<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks\Abilities;

/**
 * Discovery ability: find existing posts so the caller can decide whether to
 * update one (e.g. an empty placeholder left by an import) or create a new page.
 *
 * Matching is deliberately generous. WordPress's built-in search and exact
 * slug/title lookups are brittle — a full route or a slightly-off title returns
 * nothing. Instead we fetch the candidate set and fuzzy-rank it in PHP, always
 * returning the best candidates with a match_score so the LLM can judge.
 */
final class FindPosts
{
    public const ID = 'ai-by-roadmap/find-posts';

    private const SCAN_CAP = 300;

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'                => ['show_in_rest' => true],
            'category'            => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'               => __('Find existing posts', 'ai-by-roadmap'),
            'description'         => __('Find existing posts before deciding whether to create or update. Pass the source doc\'s route, slug, or title as "query"; results are returned as ranked candidates (match_score 0–1), NOT exact matches — review them and judge whether any is the page you mean, since slugs/titles often differ slightly. Imports often leave empty placeholder drafts (is_empty:true) that should be filled rather than duplicated: to fill one, call assemble-page with its post_id. Only create a new page when no candidate fits. Avoid overwriting pages where is_empty is false unless you intend to replace their content.', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'properties'           => [
                    'query'      => [
                        'type'        => 'string',
                        'description' => 'A route, slug, or title hint (e.g. "/programs/intensive-outpatient-program", "intensive outpatient", or "Intensive Outpatient Program"). Fuzzy-matched against each post\'s title and slug. Omit to list everything (browse mode).',
                    ],
                    'post_type'  => [
                        'type'        => 'string',
                        'description' => 'Restrict to a single post type (e.g. "program"). Defaults to all public types. Use list-post-types to see options.',
                    ],
                    'status'     => [
                        'type'        => 'array',
                        'items'       => ['type' => 'string'],
                        'description' => 'Post statuses to include. Defaults to draft, pending, publish, private.',
                    ],
                    'only_empty' => [
                        'type'        => 'boolean',
                        'description' => 'When true, return only empty placeholder posts (no ACF blocks).',
                    ],
                    'limit'      => [
                        'type'        => 'integer',
                        'description' => 'Maximum number of ranked candidates to return (default 10, max 50).',
                    ],
                ],
            ],
            'output_schema'       => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['posts'],
                'properties'           => [
                    'posts' => [
                        'type'  => 'array',
                        'items' => [
                            'type'                 => 'object',
                            'additionalProperties' => true,
                            'required'             => ['post_id', 'title', 'slug', 'post_type', 'status', 'is_empty'],
                            'properties'           => [
                                'post_id'     => ['type' => 'integer'],
                                'title'       => ['type' => 'string'],
                                'slug'        => ['type' => 'string'],
                                'post_type'   => ['type' => 'string'],
                                'status'      => ['type' => 'string'],
                                'permalink'   => ['type' => 'string'],
                                'edit_url'    => ['type' => 'string'],
                                'is_empty'    => ['type' => 'boolean'],
                                'block_count' => ['type' => 'integer'],
                                'modified'    => ['type' => 'string'],
                                'match_score' => ['type' => 'number'],
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
     * @return array<string, mixed>
     */
    public static function execute(array $input): array
    {
        $query      = isset($input['query']) ? trim((string) $input['query']) : '';
        $only_empty = ! empty($input['only_empty']);
        $limit      = isset($input['limit']) ? (int) $input['limit'] : 10;
        $limit      = max(1, min(50, $limit));

        $post_type = ! empty($input['post_type'])
            ? (string) $input['post_type']
            : array_values(array_diff(array_keys(get_post_types(['public' => true])), ['attachment']));

        $status = ! empty($input['status']) && is_array($input['status'])
            ? array_map('strval', $input['status'])
            : ['draft', 'pending', 'publish', 'private'];

        $posts = get_posts([
            'post_type'        => $post_type,
            'post_status'      => $status,
            'posts_per_page'   => self::SCAN_CAP,
            'orderby'          => 'modified',
            'order'            => 'DESC',
            'suppress_filters' => false,
        ]);

        $rows = [];
        foreach ($posts as $post) {
            $block_count = self::block_count($post->post_content);
            $is_empty    = $block_count === 0;

            if ($only_empty && ! $is_empty) {
                continue;
            }

            $rows[] = [
                'post_id'     => (int) $post->ID,
                'title'       => (string) $post->post_title,
                'slug'        => (string) $post->post_name,
                'post_type'   => (string) $post->post_type,
                'status'      => (string) $post->post_status,
                'permalink'   => (string) get_permalink($post),
                'edit_url'    => (string) get_edit_post_link($post->ID, 'raw'),
                'is_empty'    => $is_empty,
                'block_count' => $block_count,
                'modified'    => (string) $post->post_modified,
                'match_score' => $query === ''
                    ? 1.0
                    : self::score($query, (string) $post->post_title, (string) $post->post_name),
            ];
        }

        // Best candidates first. With no query, modified-DESC order is preserved
        // (every score is 1.0, so the sort is stable on the fetch order).
        usort($rows, static fn($a, $b) => $b['match_score'] <=> $a['match_score']);

        return ['posts' => array_slice($rows, 0, $limit)];
    }

    /**
     * Number of real blocks in the content (ACF blocks and fixed rows such as
     * synced patterns alike). Freeform whitespace "blocks" have no name and are
     * not counted, so a post made only of patterns is not reported as empty.
     */
    private static function block_count(string $content): int
    {
        if (trim($content) === '') {
            return 0;
        }
        $count = 0;
        foreach (parse_blocks($content) as $block) {
            if (! empty($block['blockName'])) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Generous 0–1 similarity of a query against a post's title and slug. Takes
     * the max across several signals so a strong match on any one ranks the
     * candidate up.
     */
    private static function score(string $query, string $title, string $slug): float
    {
        // A route query's meaningful part is its last path segment.
        $segment    = $query;
        if (str_contains($query, '/')) {
            $parts   = array_filter(explode('/', $query));
            $segment = $parts ? (string) end($parts) : $query;
        }

        $q_norm = self::normalize($query);
        $s_norm = self::normalize($segment);
        $q_toks = $q_norm === '' ? [] : explode(' ', $q_norm);

        $cand_norm = trim(self::normalize($title) . ' ' . self::normalize($slug));
        $c_toks    = $cand_norm === '' ? [] : array_values(array_unique(explode(' ', $cand_norm)));

        // Query-token containment: share of query tokens present in the candidate.
        $containment = 0.0;
        if ($q_toks) {
            $hit = count(array_intersect($q_toks, $c_toks));
            $containment = $hit / count($q_toks);
        }

        // Jaccard overlap of token sets.
        $jaccard = 0.0;
        if ($q_toks && $c_toks) {
            $inter   = count(array_intersect(array_unique($q_toks), $c_toks));
            $union   = count(array_unique(array_merge($q_toks, $c_toks)));
            $jaccard = $union ? $inter / $union : 0.0;
        }

        // Whole-string similarity against title and slug (catches typos/variants).
        $sim = 0.0;
        foreach ([self::normalize($title), self::normalize($slug)] as $target) {
            if ($target === '') {
                continue;
            }
            foreach ([$q_norm, $s_norm] as $needle) {
                if ($needle === '') {
                    continue;
                }
                $pct = 0.0;
                similar_text($needle, $target, $pct);
                $sim = max($sim, $pct / 100);
            }
        }

        return round(max($containment, $jaccard, $sim), 3);
    }

    private static function normalize(string $value): string
    {
        $value = strtolower($value);
        $value = (string) preg_replace('/[^a-z0-9]+/', ' ', $value);
        return trim($value);
    }
}
