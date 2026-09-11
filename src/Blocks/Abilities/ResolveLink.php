<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks\Abilities;

use Roadmap\AiByRoadmap\Blocks\Links;
use WP_Error;

/**
 * Turn "the Therapy page", "/therapy/", "therapy" or a full URL from another
 * environment into the right post and the value to store in a URL field: a
 * site-relative path that survives moving hosts.
 */
final class ResolveLink
{
    public const ID = 'ai-by-roadmap/resolve-link';

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'                => \Roadmap\AiByRoadmap\Plugin::ability_meta(true),
            'category'            => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'               => __('Resolve an internal link', 'ai-by-roadmap'),
            'description'         => __('Resolve a route, slug, page title, or a URL copied from another environment (Lovable preview, staging, localhost) to the matching post on THIS site, and return relative_path — the value to store in button/link URL fields. Always store internal links as relative paths (e.g. "/therapy/"), never absolute URLs, so they survive a domain change. Returns found: false with ranked candidates when nothing matches exactly; pick one and use its relative_path. External URLs come back as kind: external and should be stored unchanged.', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['target'],
                'properties'           => [
                    'target'    => ['type' => 'string', 'description' => 'A path ("/programs/iop/"), slug ("iop"), title ("Intensive Outpatient Program"), or absolute URL from any environment.'],
                    'post_type' => ['type' => 'string', 'description' => 'Optional: restrict fuzzy matching to one post type.'],
                ],
            ],
            'output_schema'       => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['found', 'kind', 'candidates'],
                'properties'           => [
                    'found'         => ['type' => 'boolean'],
                    'kind'          => ['type' => 'string', 'description' => 'internal-ok | internal-wrong-host | internal-missing | external | special | empty'],
                    'post_id'       => ['type' => 'integer'],
                    'title'         => ['type' => 'string'],
                    'post_type'     => ['type' => 'string'],
                    'status'        => ['type' => 'string'],
                    'permalink'     => ['type' => 'string'],
                    'relative_path' => ['type' => 'string', 'description' => 'Store this in URL fields for internal links.'],
                    'candidates'    => [
                        'type'  => 'array',
                        'items' => [
                            'type'                 => 'object',
                            'additionalProperties' => true,
                            'properties'           => [
                                'post_id'       => ['type' => 'integer'],
                                'title'         => ['type' => 'string'],
                                'post_type'     => ['type' => 'string'],
                                'status'        => ['type' => 'string'],
                                'relative_path' => ['type' => 'string'],
                                'match_score'   => ['type' => 'number'],
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
        $target = trim((string) $input['target']);
        if ($target === '') {
            return new WP_Error('empty_target', __('target is empty.', 'ai-by-roadmap'), ['status' => 400]);
        }

        $looks_like_url = str_contains($target, '/') || str_contains($target, '.');

        if ($looks_like_url) {
            $class = Links::classify(str_starts_with($target, 'http') || str_starts_with($target, '/') ? $target : '/' . $target);
            if (in_array($class['kind'], [Links::EXTERNAL, Links::SPECIAL], true)) {
                return ['found' => false, 'kind' => $class['kind'], 'candidates' => []];
            }
            if ($class['post_id'] > 0) {
                return self::hit($class['post_id'], $class['kind']);
            }
        }

        // Fuzzy: reuse find-posts ranking over titles and slugs.
        $args = ['query' => $target, 'limit' => 5];
        if (! empty($input['post_type'])) {
            $args['post_type'] = (string) $input['post_type'];
        }
        $found      = FindPosts::execute($args);
        $candidates = [];
        foreach ($found['posts'] as $row) {
            $candidates[] = [
                'post_id'       => (int) $row['post_id'],
                'title'         => (string) $row['title'],
                'post_type'     => (string) $row['post_type'],
                'status'        => (string) $row['status'],
                'relative_path' => Links::relative((int) $row['post_id']),
                'match_score'   => (float) $row['match_score'],
            ];
        }

        // A single, clearly best candidate counts as found.
        if ($candidates && $candidates[0]['match_score'] >= 0.9 && (count($candidates) === 1 || $candidates[0]['match_score'] - $candidates[1]['match_score'] >= 0.2)) {
            $hit               = self::hit($candidates[0]['post_id'], $looks_like_url ? Links::INTERNAL_MISSING : Links::INTERNAL_OK);
            $hit['candidates'] = $candidates;
            return $hit;
        }

        return [
            'found'      => false,
            'kind'       => $looks_like_url ? Links::INTERNAL_MISSING : Links::EMPTY,
            'candidates' => $candidates,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function hit(int $post_id, string $kind): array
    {
        $post = get_post($post_id);
        return [
            'found'         => true,
            'kind'          => $kind,
            'post_id'       => $post_id,
            'title'         => $post ? (string) $post->post_title : '',
            'post_type'     => $post ? (string) $post->post_type : '',
            'status'        => $post ? (string) $post->post_status : '',
            'permalink'     => (string) get_permalink($post_id),
            'relative_path' => Links::relative($post_id),
            'candidates'    => [],
        ];
    }
}
