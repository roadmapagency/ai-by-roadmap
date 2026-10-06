<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Core\Abilities;

use Roadmap\AiByRoadmap\Core\Seo;
use WP_Error;

/**
 * Site-wide SEO pass: which posts lack a meta description or keyphrase, have
 * titles/descriptions that will truncate, are set to noindex, or duplicate
 * another post's title/description. The work list for a pre-launch SEO
 * sweep; fix each hit with update-post-seo.
 */
final class AuditSeo
{
    public const ID = 'ai-by-roadmap/audit-seo';

    private const SCAN_CAP = 500;

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'                => \Roadmap\AiByRoadmap\Plugin::ability_meta(true),
            'category'            => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'               => __('Audit SEO data across posts (Yoast)', 'ai-by-roadmap'),
            'description'         => __('Audit the SEO data of many posts at once (all public post types by default, or one post_type): for each post the rendered title/description lengths, Yoast scores and issues — missing_description, description_too_long/short, title_too_long, missing_focus_keyphrase, noindex, duplicate_title, duplicate_description. Returns summary counts plus the per-post work list (only posts with issues unless only_issues: false). Fix items with update-post-seo; re-run to confirm. Read-only.', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'properties'           => [
                    'post_type'   => ['type' => 'string', 'description' => 'Restrict to one post type.'],
                    'status'      => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Statuses to include. Default: publish, draft, pending, private, future.'],
                    'only_issues' => ['type' => 'boolean', 'default' => true],
                    'limit'       => ['type' => 'integer', 'description' => 'Max posts (default 200, max 500).'],
                ],
            ],
            'output_schema'       => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['posts_scanned', 'posts_with_issues', 'issue_counts', 'posts'],
                'properties'           => [
                    'posts_scanned'     => ['type' => 'integer'],
                    'posts_with_issues' => ['type' => 'integer'],
                    'issue_counts'      => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
                    'duplicates'        => ['type' => 'object', 'additionalProperties' => true, 'description' => 'titles / descriptions → list of post_ids sharing them.'],
                    'posts'             => [
                        'type'  => 'array',
                        'items' => [
                            'type'                 => 'object',
                            'additionalProperties' => true,
                            'required'             => ['post_id', 'post_title', 'permalink', 'issues'],
                            'properties'           => [
                                'post_id'           => ['type' => 'integer'],
                                'post_title'        => ['type' => 'string'],
                                'post_type'         => ['type' => 'string'],
                                'status'            => ['type' => 'string'],
                                'permalink'         => ['type' => 'string'],
                                'title'             => ['type' => 'string', 'description' => 'Rendered SEO title.'],
                                'title_chars'       => ['type' => 'integer'],
                                'description'       => ['type' => 'string', 'description' => 'Rendered meta description.'],
                                'description_chars' => ['type' => 'integer'],
                                'focus_keyphrase'   => ['type' => 'string'],
                                'seo_score'         => ['type' => 'integer'],
                                'readability_score' => ['type' => 'integer'],
                                'issues'            => ['type' => 'array', 'items' => ['type' => 'string']],
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
        if (! Seo::available()) {
            return Seo::unavailable_error();
        }

        $limit  = isset($input['limit']) ? max(1, min(self::SCAN_CAP, (int) $input['limit'])) : 200;
        $types  = ! empty($input['post_type'])
            ? [(string) $input['post_type']]
            : array_values(array_diff(array_keys(get_post_types(['public' => true])), ['attachment']));
        $status = ! empty($input['status']) && is_array($input['status'])
            ? array_map('strval', $input['status'])
            : ['publish', 'draft', 'pending', 'private', 'future'];
        $only_issues = ! array_key_exists('only_issues', $input) || ! empty($input['only_issues']);

        $posts = get_posts([
            'post_type'        => $types,
            'post_status'      => $status,
            'posts_per_page'   => $limit,
            'orderby'          => 'modified',
            'order'            => 'DESC',
            'suppress_filters' => false,
        ]);

        $rows       = [];
        $by_title   = [];
        $by_desc    = [];
        foreach ($posts as $post) {
            if (! current_user_can('edit_post', (int) $post->ID)) {
                continue;
            }
            $stored   = Seo::stored((int) $post->ID);
            $rendered = Seo::rendered($post);
            $scores   = Seo::scores((int) $post->ID);

            $rows[(int) $post->ID] = [
                'post_id'           => (int) $post->ID,
                'post_title'        => (string) $post->post_title,
                'post_type'         => (string) $post->post_type,
                'status'            => (string) $post->post_status,
                'permalink'         => (string) get_permalink($post),
                'title'             => $rendered['title'],
                'title_chars'       => $rendered['title_chars'],
                'description'       => $rendered['description'],
                'description_chars' => $rendered['description_chars'],
                'focus_keyphrase'   => (string) $stored['focus_keyphrase'],
                'seo_score'         => $scores['seo_score'],
                'readability_score' => $scores['readability_score'],
                'issues'            => Seo::issues($stored, $rendered),
            ];

            $t = mb_strtolower(trim($rendered['title']));
            $d = mb_strtolower(trim($rendered['description']));
            if ($t !== '') {
                $by_title[$t][] = (int) $post->ID;
            }
            if ($d !== '') {
                $by_desc[$d][] = (int) $post->ID;
            }
        }

        $duplicates = ['titles' => [], 'descriptions' => []];
        foreach ($by_title as $t => $ids) {
            if (count($ids) > 1) {
                $duplicates['titles'][] = ['title' => $t, 'post_ids' => $ids];
                foreach ($ids as $id) {
                    $rows[$id]['issues'][] = 'duplicate_title';
                }
            }
        }
        foreach ($by_desc as $d => $ids) {
            if (count($ids) > 1) {
                $duplicates['descriptions'][] = ['description' => $d, 'post_ids' => $ids];
                foreach ($ids as $id) {
                    $rows[$id]['issues'][] = 'duplicate_description';
                }
            }
        }

        $counts = [];
        $with   = 0;
        foreach ($rows as $row) {
            if ($row['issues'] !== []) {
                $with++;
            }
            foreach ($row['issues'] as $code) {
                $counts[$code] = ($counts[$code] ?? 0) + 1;
            }
        }
        if ($only_issues) {
            $rows = array_filter($rows, static fn($r) => $r['issues'] !== []);
        }

        return [
            'posts_scanned'     => count($posts),
            'posts_with_issues' => $with,
            'issue_counts'      => $counts ?: new \stdClass(),
            'duplicates'        => $duplicates,
            'posts'             => array_values($rows),
        ];
    }
}
