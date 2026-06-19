<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks\Abilities;

use Roadmap\AiByRoadmap\Blocks\Agents\BlockScorerAgent;
use Roadmap\AiByRoadmap\Blocks\BlockRegistry;

/**
 * Audits a chosen block selection against source content. Returns a pass/fail
 * verdict + a numeric score + actionable suggestion if it fails.
 */
final class ScoreBlocks
{
    public const ID = 'ai-by-roadmap/score-blocks';

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'           => ['show_in_rest' => true],
            'category'           => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'              => __('Score a block selection', 'ai-by-roadmap'),
            'description'         => __('Evaluate whether a chosen block list is well-justified by the source content. Returns pass/fail, a 1-10 score, and a one-line suggestion for correction.', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['content', 'blocks'],
                'properties'           => [
                    'content' => ['type' => 'string'],
                    'blocks'  => [
                        'type'  => 'array',
                        'items' => [
                            'type'                 => 'object',
                            'required'             => ['type', 'intent'],
                            'additionalProperties' => false,
                            'properties'           => [
                                'type'   => ['type' => 'string'],
                                'intent' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
            'output_schema'       => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['pass', 'score', 'issues', 'suggestion'],
                'properties'           => [
                    'pass'       => ['type' => 'boolean'],
                    'score'      => ['type' => 'integer'],
                    'issues'     => ['type' => 'array', 'items' => ['type' => 'string']],
                    'suggestion' => ['type' => 'string'],
                ],
            ],
            'permission_callback' => static fn(): bool => current_user_can('edit_posts'),
            'execute_callback'    => [self::class, 'execute'],
        ]);
    }

    public static function execute(array $input): array
    {
        $catalogue       = BlockRegistry::get_block_descriptions();
        $catalogue_lines = [];
        foreach ($catalogue as $type => $desc) {
            $catalogue_lines[] = '  - ' . $type . ': ' . $desc;
        }

        $block_lines = array_map(
            static fn(array $b): string => '  - ' . ($b['type'] ?? '') . ': ' . ($b['intent'] ?? ''),
            (array) $input['blocks']
        );

        $prompt = implode("\n", array_filter([
            'Source Content:',
            (string) $input['content'],
            '',
            'Available Block Catalogue (type: when to use it):',
            implode("\n", $catalogue_lines),
            '',
            'Chosen Blocks:',
            implode("\n", $block_lines),
            '',
            'Evaluate whether each chosen block has clear support in the source content.',
        ]));

        return (new BlockScorerAgent())->chat($prompt);
    }
}
