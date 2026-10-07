<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks;

/**
 * Collects AI-compatible ACF blocks contributed by the active theme (or any
 * plugin) via the `ai_by_roadmap_register_block` filter.
 *
 * Expected filter shape:
 *   add_filter('ai_by_roadmap_register_block', function (array $blocks): array {
 *       $blocks[] = ['block_id' => 'acf/hero', 'schema' => [...]];
 *       return $blocks;
 *   });
 *
 * Each schema's top-level `description` is what BlockChooserAgent sees when
 * deciding which blocks to use.
 */
final class BlockRegistry
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function get_blocks(): array
    {
        $contributed = (array) apply_filters('ai_by_roadmap_register_block', []);

        $blocks = [];
        foreach ($contributed as $block) {
            $block = (array) apply_filters('ai_by_roadmap_pre_register_block', $block);
            if (empty($block['block_id']) || empty($block['schema'])) {
                continue;
            }

            $schema = $block['schema'];
            if (is_string($schema)) {
                $decoded = json_decode($schema, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    continue;
                }
                $schema = $decoded;
            }

            $blocks[(string) $block['block_id']] = self::normalize_enums((array) $schema);
        }

        return $blocks;
    }

    /**
     * ACF choice keys like "2" become PHP ints in array_keys(), so themes emit
     * `{"type":"string","enum":[2,3,4]}`. Gemini rejects that outright (and
     * strict OpenAI mode is inconsistent about it), so cast enum values to
     * strings wherever the declared type is string.
     *
     * @param  array<mixed> $node
     * @return array<mixed>
     */
    private static function normalize_enums(array $node): array
    {
        if (isset($node['enum']) && is_array($node['enum']) && ($node['type'] ?? null) === 'string') {
            $node['enum'] = array_values(array_map('strval', $node['enum']));
        }

        foreach ($node as $key => $child) {
            if (is_array($child)) {
                $node[$key] = self::normalize_enums($child);
            }
        }

        return $node;
    }

    /**
     * @return array<string, string>
     */
    public static function get_block_descriptions(): array
    {
        $descriptions = [];
        foreach (self::get_blocks() as $id => $schema) {
            $descriptions[$id] = (string) ($schema['description'] ?? '');
        }
        return $descriptions;
    }
}
