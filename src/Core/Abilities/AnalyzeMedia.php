<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Core\Abilities;

use Roadmap\AiByRoadmap\Core\MediaAnalyzer;

/**
 * On-demand counterpart to the add_attachment cron handler. Re-runs analysis
 * for a single attachment (useful for retrying failures or refreshing
 * descriptions after a model upgrade).
 */
final class AnalyzeMedia
{
    public const ID = 'ai-by-roadmap/analyze-media';

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'           => \Roadmap\AiByRoadmap\Plugin::ability_meta(false, false, true, false),
            'category'           => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'              => __('Analyze a media attachment', 'ai-by-roadmap'),
            'description'         => __('Run the vision model against an existing attachment and write SEO filename, description, and usage suggestion. Also regenerates the vector embedding when supported.', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['attachment_id'],
                'properties'           => [
                    'attachment_id' => [
                        'type'        => 'integer',
                        'description' => 'The WordPress attachment post ID.',
                    ],
                ],
            ],
            'output_schema'       => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['success', 'attachment_id'],
                'properties'           => [
                    'success'       => ['type' => 'boolean'],
                    'attachment_id' => ['type' => 'integer'],
                ],
            ],
            'permission_callback' => static fn(): bool => current_user_can('upload_files'),
            'execute_callback'    => [self::class, 'execute'],
        ]);
    }

    public static function execute(array $input): array
    {
        $id = (int) $input['attachment_id'];
        (new MediaAnalyzer())->analyze($id);

        return ['success' => true, 'attachment_id' => $id];
    }
}
