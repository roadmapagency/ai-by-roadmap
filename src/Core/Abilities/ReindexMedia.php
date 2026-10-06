<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Core\Abilities;

use Roadmap\AiByRoadmap\Core\MediaAnalyzer;
use Roadmap\AiByRoadmap\Core\VectorSupport;

/**
 * Regenerates vector embeddings for every attachment that has AI metadata
 * but no embedding row yet. Admin-only — kicks off a potentially expensive
 * batch operation.
 */
final class ReindexMedia
{
    public const ID = 'ai-by-roadmap/reindex-media';

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'           => \Roadmap\AiByRoadmap\Plugin::ability_meta(false, false, false, false),
            'category'           => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'              => __('Reindex media embeddings', 'ai-by-roadmap'),
            'description'         => __('Generate vector embeddings for every analysed image that does not have one yet. Skipped automatically if the database does not support VECTOR columns.', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'properties'           => new \stdClass(),
            ],
            'output_schema'       => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['indexed', 'vector_supported'],
                'properties'           => [
                    'indexed'          => ['type' => 'integer'],
                    'vector_supported' => ['type' => 'boolean'],
                ],
            ],
            'permission_callback' => static fn(): bool => current_user_can('manage_options'),
            'execute_callback'    => [self::class, 'execute'],
        ]);
    }

    public static function execute(array $input): array
    {
        if (! VectorSupport::is_available()) {
            return ['indexed' => 0, 'vector_supported' => false];
        }

        $count = (new MediaAnalyzer())->reindex_all();

        return ['indexed' => $count, 'vector_supported' => true];
    }
}
