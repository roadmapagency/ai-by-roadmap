<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Core\Abilities;

use Roadmap\AiByRoadmap\Core\Agents\ContentAnalyzerAgent;

final class AnalyzeContent
{
    public const ID = 'ai-by-roadmap/analyze-content';

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'           => ['show_in_rest' => true],
            'category'           => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'              => __('Analyze content structure', 'ai-by-roadmap'),
            'description'         => __('Read raw website content and report its section count + a short description per section. Useful as a structured pre-step before page composition.', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['content'],
                'properties'           => [
                    'content' => [
                        'type'        => 'string',
                        'description' => 'The raw text content to analyse.',
                    ],
                ],
            ],
            'output_schema'       => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['section_count', 'detected_sections'],
                'properties'           => [
                    'section_count'     => ['type' => 'integer'],
                    'detected_sections' => [
                        'type'  => 'array',
                        'items' => ['type' => 'string'],
                    ],
                ],
            ],
            'permission_callback' => static fn(): bool => current_user_can('edit_posts'),
            'execute_callback'    => [self::class, 'execute'],
        ]);
    }

    public static function execute(array $input): array
    {
        return (new ContentAnalyzerAgent())->chat('Content: ' . $input['content']);
    }
}
