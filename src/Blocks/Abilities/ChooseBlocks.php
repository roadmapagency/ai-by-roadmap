<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks\Abilities;

use Roadmap\AiByRoadmap\Blocks\Agents\BlockChooserAgent;

/**
 * Fine-grained ability — just the chooser step. Useful when an external agent
 * wants to pick the block layout itself and then decide what to do with it
 * (e.g. preview a structure before paying for the fill pass).
 */
final class ChooseBlocks
{
    public const ID = 'ai-by-roadmap/choose-blocks';

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'           => ['show_in_rest' => true],
            'category'           => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'              => __('Choose blocks for content', 'ai-by-roadmap'),
            'description'         => __('Decide which ACF blocks to use for given source content. Returns an ordered array of {type, intent} pairs.', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['content'],
                'properties'           => [
                    'content' => [
                        'type'        => 'string',
                        'description' => 'The raw source content.',
                    ],
                    'signals' => [
                        'type'                 => 'object',
                        'description'          => 'Optional pre-computed content signals (output of analyze-content).',
                        'additionalProperties' => true,
                    ],
                ],
            ],
            'output_schema'       => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['blocks'],
                'properties'           => [
                    'blocks' => [
                        'type'  => 'array',
                        'items' => [
                            'type'                 => 'object',
                            'additionalProperties' => false,
                            'required'             => ['type', 'intent'],
                            'properties'           => [
                                'type'   => ['type' => 'string'],
                                'intent' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
            'permission_callback' => static fn(): bool => current_user_can('edit_posts'),
            'execute_callback'    => [self::class, 'execute'],
        ]);
    }

    public static function execute(array $input): array
    {
        $signals = (array) ($input['signals'] ?? []);
        $agent   = new BlockChooserAgent($signals);
        $result  = $agent->chat('Content: ' . (string) $input['content']);

        return ['blocks' => (array) ($result['blocks'] ?? [])];
    }
}
