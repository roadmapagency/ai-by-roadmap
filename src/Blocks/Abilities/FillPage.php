<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks\Abilities;

use Roadmap\AiByRoadmap\Blocks\ACFTransformer;
use Roadmap\AiByRoadmap\Blocks\Agents\PageFillerAgent;
use Roadmap\AiByRoadmap\Plugin;

/**
 * Fills an entire page of pre-chosen blocks in one call. Caller is responsible
 * for picking the block list (typically via choose-blocks). Most callers will
 * prefer the higher-level compose-page ability which runs the whole pipeline.
 */
final class FillPage
{
    public const ID = 'ai-by-roadmap/fill-page';

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'           => \Roadmap\AiByRoadmap\Plugin::ability_meta(true, false, true, false),
            'category'           => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'              => __('Fill a page of blocks', 'ai-by-roadmap'),
            'description'         => __('Given a chosen block list and source content, fill every block in a single LLM call and return the serialized ACF block markup.', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['content', 'blocks'],
                'properties'           => [
                    'content'         => ['type' => 'string'],
                    'target_audience' => ['type' => 'string'],
                    'blocks'          => [
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
            'output_schema'       => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['blocks'],
                'properties'           => [
                    'blocks' => [
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
        $chosen   = (array) $input['blocks'];
        $audience = (string) ($input['target_audience'] ?? '');

        $prompt = 'Content:' . "\n" . (string) $input['content'];
        if ($audience !== '') {
            $prompt .= "\n\nTarget Audience: " . $audience;
        }

        $page_data   = (new PageFillerAgent($chosen))->chat($prompt);
        $transformer = new ACFTransformer();

        // ai_content is emitted per-slot by the LLM (per the page schema)
        // and holds the verbatim source slice for THIS block. Pass through.
        $serialized = [];
        foreach ($chosen as $index => $block) {
            $key    = "{$index}_{$block['type']}";
            $fields = (array) ($page_data[$key] ?? []);
            $fields[Plugin::AI_CONTENT_FIELD] = (string) ($fields[Plugin::AI_CONTENT_FIELD] ?? '');

            $serialized[] = $transformer->convert([$block['type'] => $fields]);
        }

        return ['blocks' => $serialized];
    }
}
