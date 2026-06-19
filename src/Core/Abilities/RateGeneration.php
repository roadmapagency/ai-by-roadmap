<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Core\Abilities;

use Roadmap\AiByRoadmap\Core\EvaluationLogger;

/**
 * Lets an editor (or Claude itself, in batch mode) attach a 👍/👎 + free-text
 * note to a logged generation. Negative ratings are surfaced as
 * "previously-INCORRECT" examples in future BlockChooserAgent prompts.
 */
final class RateGeneration
{
    public const ID = 'ai-by-roadmap/rate-generation';

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'           => ['show_in_rest' => true],
            'category'           => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'              => __('Rate a generation run', 'ai-by-roadmap'),
            'description'         => __('Attach a positive (+1) or negative (-1) rating to a logged generation run, with optional notes. Negative ratings feed back into the BlockChooser prompt as cautionary examples.', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['id', 'rating'],
                'properties'           => [
                    'id'     => ['type' => 'integer'],
                    'rating' => ['type' => 'integer', 'enum' => [-1, 1]],
                    'notes'  => ['type' => 'string'],
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
            'permission_callback' => static fn(): bool => current_user_can('edit_posts'),
            'execute_callback'    => [self::class, 'execute'],
        ]);
    }

    public static function execute(array $input): array
    {
        EvaluationLogger::update_feedback(
            (int) $input['id'],
            (int) $input['rating'],
            (string) ($input['notes'] ?? '')
        );

        return ['success' => true];
    }
}
