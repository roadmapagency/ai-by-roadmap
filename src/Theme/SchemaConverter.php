<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Theme;

use Roadmap\AiByRoadmap\Icons\Icons;

/**
 * ACF field definition → JSON schema fragment, as fed to BlockRegistry.
 *
 * Ported verbatim from the theme's roadmap_starter_process_acf_field() so a
 * theme moving to plugin mode produces the same schemas; icon fields are
 * described by the configured IconProvider.
 */
final class SchemaConverter
{
    /**
     * Schema for one ACF block type, or null when it has no fields.
     *
     * @return array<string, mixed>
     */
    public static function block(string $block_name): array
    {
        $block_data = acf_get_block_type($block_name);
        $schema     = [
            'type'        => 'object',
            'description' => $block_data['description'] ?? '',
            'properties'  => [],
        ];

        foreach (acf_get_field_groups(['block' => $block_name]) as $field_group) {
            foreach ((array) acf_get_fields($field_group) as $field) {
                if ($field['name'] === SourceField::NAME) {
                    continue;
                }
                $schema['additionalProperties']         = false;
                $schema['properties'][$field['name']] = self::field($field);
                $schema['required'][]                   = $field['name'];
            }
        }

        return $schema;
    }

    /**
     * @param  array<string, mixed> $field
     * @return array<string, mixed>
     */
    public static function field(array $field): array
    {
        $description = $field['instructions'] ?? '';

        $icon = Icons::provider();
        if ($icon !== null && in_array($field['type'], $icon->field_types(), true)) {
            return $icon->schema($field);
        }

        switch ($field['type']) {
            case 'repeater':
                $items = [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => [],
                ];
                foreach ($field['sub_fields'] ?? [] as $sub_field) {
                    $items['properties'][$sub_field['name']] = self::field($sub_field);
                }
                $items['required'] = array_keys($items['properties']);
                return [
                    'type'        => 'array',
                    'description' => $description,
                    'items'       => $items,
                ];

            case 'group':
                $properties = [];
                foreach ($field['sub_fields'] ?? [] as $sub_field) {
                    $properties[$sub_field['name']] = self::field($sub_field);
                }
                return [
                    'type'                 => 'object',
                    'description'          => $description,
                    'additionalProperties' => false,
                    'required'             => array_keys($properties),
                    'properties'           => $properties,
                ];

            case 'select':
            case 'radio':
            case 'button_group':
                return [
                    'type'        => 'string',
                    'description' => $description,
                    'enum'        => isset($field['choices']) ? array_keys($field['choices']) : [],
                ];

            case 'checkbox':
                return [
                    'type'        => 'array',
                    'description' => $description,
                    'items'       => [
                        'type' => 'string',
                        'enum' => isset($field['choices']) ? array_keys($field['choices']) : [],
                    ],
                ];

            case 'true_false':
                return [
                    'type'        => 'boolean',
                    'description' => $description,
                ];

            case 'number':
                return [
                    'type'        => 'number',
                    'description' => $description,
                ];

            case 'range':
                $schema = [
                    'type'        => 'number',
                    'description' => $description,
                ];
                if (isset($field['min'])) {
                    $schema['minimum'] = $field['min'];
                }
                if (isset($field['max'])) {
                    $schema['maximum'] = $field['max'];
                }
                return $schema;

            default:
                return [
                    'type'        => 'string',
                    'description' => $description,
                ];
        }
    }
}
