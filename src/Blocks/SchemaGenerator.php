<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks;

final class SchemaGenerator
{
    /**
     * Schema describing an ordered list of {type, intent} block selections —
     * the BlockChooserAgent's output.
     *
     * @return array<string, mixed>
     */
    public static function block_id_schema(): array
    {
        $block_ids = array_keys(BlockRegistry::get_blocks());

        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['blocks'],
            'properties'           => [
                'blocks' => [
                    'type'        => 'array',
                    'description' => 'An ordered list of Gutenberg blocks representing the page layout.',
                    'items'       => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['type', 'intent'],
                        'properties'           => [
                            'type'   => [
                                'type'        => 'string',
                                'description' => 'The block type ID (e.g. acf/hero).',
                                'enum'        => $block_ids,
                            ],
                            'intent' => [
                                'type'        => 'string',
                                'description' => 'A one-sentence description of what this block should contain.',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Returns the registered schema for a single block type. Throws when the
     * block is not registered — the caller built the chosen list from this
     * same registry so a miss indicates a registration bug.
     *
     * @return array<string, mixed>
     */
    public static function block_schema(string $block_id): array
    {
        $blocks = BlockRegistry::get_blocks();
        if (! isset($blocks[$block_id])) {
            throw new \RuntimeException(sprintf(
                'Block %s not found. Registered: %s.',
                $block_id,
                implode(', ', array_keys($blocks))
            ));
        }
        return $blocks[$block_id];
    }

    /**
     * Combined "fill the whole page" schema. Keys are `{index}_{block_type}`
     * so duplicate block types work without collision and we can reconstruct
     * the ordered block list from the LLM response.
     *
     * @param  array<int, array{type:string,intent:string}> $chosen_blocks
     * @return array<string, mixed>
     */
    public static function page_schema(array $chosen_blocks): array
    {
        $properties = [];

        foreach ($chosen_blocks as $index => $block) {
            $schema    = self::block_schema($block['type']);
            $sub_props = $schema['properties'] ?? $schema;

            // Force every slot to also emit ai_content — the verbatim slice
            // of the source content the model used to fill THIS block. We
            // store it on the block so subsequent block-swaps can regenerate
            // from the same source slice without losing fidelity.
            $sub_props[\Roadmap\AiByRoadmap\Plugin::AI_CONTENT_FIELD] = [
                'type'        => 'string',
                'description' => 'The verbatim slice of the user-supplied source content that you used to fill THIS block. Copy the exact sentences/phrases used — do not paraphrase, do not summarise, do not include content used for sibling blocks. If the block legitimately needs no source content (e.g. a generic CTA), return an empty string.',
            ];

            $sub_keys = array_keys($sub_props);
            $key      = "{$index}_{$block['type']}";

            $properties[$key] = [
                'type'                 => 'object',
                'description'          => $block['intent'] ?? '',
                'additionalProperties' => false,
                'required'             => $sub_keys,
                'properties'           => $sub_props,
            ];
        }

        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array_keys($properties),
            'properties'           => $properties,
        ];
    }

    /**
     * Wraps an inner schema in the strict OpenAI envelope used by other agents.
     *
     * @return array<string, mixed>
     */
    public static function strict_object(array $properties): array
    {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array_keys($properties),
            'properties'           => $properties,
        ];
    }
}
