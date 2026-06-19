<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks\Abilities;

final class SetTargetAudience
{
    public const ID = 'ai-by-roadmap/set-target-audience';

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'           => ['show_in_rest' => true],
            'category'           => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'              => __('Set target audience for a post', 'ai-by-roadmap'),
            'description'         => __('Persist a target-audience description against a post so subsequent generations and block swaps use the same audience context.', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['post_id', 'target_audience'],
                'properties'           => [
                    'post_id'         => ['type' => 'integer'],
                    'target_audience' => ['type' => 'string'],
                ],
            ],
            'output_schema'       => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['success'],
                'properties'           => [
                    'success' => ['type' => 'boolean'],
                ],
            ],
            'permission_callback' => static fn(array $input): bool => current_user_can('edit_post', (int) ($input['post_id'] ?? 0)),
            'execute_callback'    => [self::class, 'execute'],
        ]);
    }

    public static function execute(array $input): array
    {
        update_post_meta(
            (int) $input['post_id'],
            GetTargetAudience::META_KEY,
            sanitize_textarea_field((string) $input['target_audience'])
        );

        return ['success' => true];
    }
}
