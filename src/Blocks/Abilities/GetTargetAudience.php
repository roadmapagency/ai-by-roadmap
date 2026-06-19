<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks\Abilities;

/**
 * Get the target audience description previously stored for a post. The
 * BlockSwapper UI calls this so subsequent block swaps preserve audience
 * context without forcing the editor to retype it.
 */
final class GetTargetAudience
{
    public const ID       = 'ai-by-roadmap/get-target-audience';
    public const META_KEY = '_ai_target_audience';

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'           => ['show_in_rest' => true],
            'category'           => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'              => __('Get target audience for a post', 'ai-by-roadmap'),
            'description'         => __('Retrieve the stored target-audience description for a post. Returns an empty string if none is set.', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['post_id'],
                'properties'           => [
                    'post_id' => ['type' => 'integer'],
                ],
            ],
            'output_schema'       => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['target_audience'],
                'properties'           => [
                    'target_audience' => ['type' => 'string'],
                ],
            ],
            'permission_callback' => static fn(): bool => current_user_can('edit_posts'),
            'execute_callback'    => [self::class, 'execute'],
        ]);
    }

    public static function execute(array $input): array
    {
        return [
            'target_audience' => (string) get_post_meta((int) $input['post_id'], self::META_KEY, true),
        ];
    }
}
