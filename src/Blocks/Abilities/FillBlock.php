<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks\Abilities;

use Roadmap\AiByRoadmap\Blocks\ACFTransformer;
use Roadmap\AiByRoadmap\Blocks\Agents\BlockFillerAgent;
use Roadmap\AiByRoadmap\Plugin;

/**
 * Generates a single ACF block from source content. Powers the BlockSwapper
 * toolbar UI — editor picks a target block type, this ability re-fills with
 * the original AI source content so context isn't lost.
 */
final class FillBlock
{
    public const ID = 'ai-by-roadmap/fill-block';

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'           => \Roadmap\AiByRoadmap\Plugin::ability_meta(true, false, true, false),
            'category'           => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'              => __('Fill a single block', 'ai-by-roadmap'),
            'description'         => __('Generate one ACF block from source content. Returns both the JSON field data and the serialized ACF block markup ready to insert into post_content.', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['block_type', 'content'],
                'properties'           => [
                    'block_type'      => ['type' => 'string', 'description' => 'The ACF block ID (e.g. acf/hero).'],
                    'content'         => ['type' => 'string', 'description' => 'Source content to fill the block with.'],
                    'target_audience' => ['type' => 'string'],
                ],
            ],
            'output_schema'       => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['block_id', 'serialized'],
                'properties'           => [
                    'block_id'   => ['type' => 'string'],
                    'serialized' => ['type' => 'string', 'description' => 'Serialized <!-- wp:acf/... /--> comment.'],
                    'fields'     => ['type' => 'object', 'additionalProperties' => true],
                ],
            ],
            'permission_callback' => static fn(): bool => current_user_can('edit_posts'),
            'execute_callback'    => [self::class, 'execute'],
        ]);
    }

    public static function execute(array $input): array
    {
        $block_type = (string) $input['block_type'];
        $content    = (string) $input['content'];
        $audience   = (string) ($input['target_audience'] ?? '');

        $prompt = 'Content: ' . $content;
        if ($audience !== '') {
            $prompt .= "\n\nTarget Audience: " . $audience;
        }

        $fields = (new BlockFillerAgent($block_type))->chat($prompt);

        // The agent returns { "acf/...": { ...fields } }; merge in the ai_content marker.
        $inner = (array) ($fields[$block_type] ?? $fields);
        $inner[Plugin::AI_CONTENT_FIELD] = $content;

        $block_id   = uniqid('block_');
        $inner['id'] = $block_id;

        $serialized = (new ACFTransformer())->convert([$block_type => $inner]);

        return [
            'block_id'   => $block_id,
            'serialized' => $serialized,
            'fields'     => $inner,
        ];
    }
}
