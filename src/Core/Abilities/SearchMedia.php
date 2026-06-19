<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Core\Abilities;

use Roadmap\AiByRoadmap\Core\MediaAnalyzer;
use Roadmap\AiByRoadmap\Core\VectorStore;
use Roadmap\AiByRoadmap\Core\VectorSupport;

/**
 * Semantic + keyword image search over the media library. Returns up to
 * `limit` candidate attachments with their ID, title, description, and
 * AI-generated usage suggestion. Degrades to keyword-only when MariaDB
 * vector storage is unavailable.
 */
final class SearchMedia
{
    public const ID            = 'ai-by-roadmap/search-media';
    private const DEFAULT_LIMIT = 5;

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'           => ['show_in_rest' => true],
            'category'           => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'              => __('Search the media library', 'ai-by-roadmap'),
            'description'         => __('Find images in the media library by natural-language description. Returns up to N candidate attachments with IDs, titles, descriptions, and suggested usage.', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['query'],
                'properties'           => [
                    'query' => [
                        'type'        => 'string',
                        'description' => 'A natural-language description of the image needed (e.g. "happy family outdoors", "modern office interior").',
                    ],
                    'limit' => [
                        'type'        => 'integer',
                        'description' => 'Maximum number of candidates to return.',
                        'default'     => self::DEFAULT_LIMIT,
                    ],
                ],
            ],
            'output_schema'       => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['candidates'],
                'properties'           => [
                    'candidates' => [
                        'type'  => 'array',
                        'items' => [
                            'type'                 => 'object',
                            'additionalProperties' => false,
                            'required'             => ['id', 'title', 'description', 'usage'],
                            'properties'           => [
                                'id'          => ['type' => 'integer'],
                                'title'       => ['type' => 'string'],
                                'description' => ['type' => 'string'],
                                'usage'       => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
            'permission_callback' => static fn(): bool => current_user_can('upload_files'),
            'execute_callback'    => [self::class, 'execute'],
        ]);
    }

    public static function execute(array $input): array
    {
        $query = (string) $input['query'];
        $limit = max(1, (int) ($input['limit'] ?? self::DEFAULT_LIMIT));

        $candidates = [];

        if (VectorSupport::is_available() && VectorStore::has_rows()) {
            try {
                $embedding = MediaAnalyzer::generate_embedding($query);
                if ($embedding !== null) {
                    foreach (VectorStore::search($embedding, $limit) as $id) {
                        $candidate = self::candidate_for($id);
                        if ($candidate !== null) {
                            $candidates[] = $candidate;
                        }
                    }
                }
            } catch (\Throwable $e) {
                error_log('AI by Roadmap: vector search failed, falling back to keyword: ' . $e->getMessage());
            }
        }

        if (count($candidates) < $limit) {
            $exclude   = array_map(static fn($c) => (int) $c['id'], $candidates);
            $remaining = $limit - count($candidates);

            $args = [
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'post_mime_type' => 'image',
                'posts_per_page' => $remaining,
                's'              => $query,
            ];
            if ($exclude) {
                $args['post__not_in'] = $exclude;
            }

            foreach ((new \WP_Query($args))->posts as $post) {
                $candidate = self::candidate_for((int) $post->ID);
                if ($candidate !== null) {
                    $candidates[] = $candidate;
                }
            }
        }

        return ['candidates' => $candidates];
    }

    /**
     * @return array{id:int,title:string,description:string,usage:string}|null
     */
    private static function candidate_for(int $id): ?array
    {
        $post = get_post($id);
        if (! $post) {
            return null;
        }

        return [
            'id'          => $id,
            'title'       => (string) $post->post_title,
            'description' => (string) (get_post_meta($id, '_ai_description', true) ?: $post->post_content),
            'usage'       => (string) get_post_meta($id, '_ai_usage_suggestion', true),
        ];
    }
}
