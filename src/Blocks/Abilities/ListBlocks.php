<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks\Abilities;

use Roadmap\AiByRoadmap\Blocks\BlockRegistry;

/**
 * Public discovery ability. Returns every ACF block that has been registered
 * for AI composition, with its description and full JSON schema. Used by
 * external agents (and our own UIs) to understand what blocks are available
 * before composing a page.
 */
final class ListBlocks
{
    public const ID = 'ai-by-roadmap/list-blocks';

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'           => ['show_in_rest' => true],
            'category'           => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'              => __('List available ACF blocks', 'ai-by-roadmap'),
            'description'         => __('Return every ACF block registered for AI composition. Each entry has the block ID, a description of what it is for, and the full JSON schema describing its fields.', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'properties'           => new \stdClass(),
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
                            'required'             => ['id', 'description', 'schema'],
                            'properties'           => [
                                'id'          => ['type' => 'string'],
                                'description' => ['type' => 'string'],
                                'schema'      => ['type' => 'object', 'additionalProperties' => true],
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
        $blocks = [];
        foreach (BlockRegistry::get_blocks() as $id => $schema) {
            $blocks[] = [
                'id'          => $id,
                'description' => (string) ($schema['description'] ?? ''),
                'schema'      => $schema,
            ];
        }
        return ['blocks' => $blocks];
    }
}
