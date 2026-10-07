<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Icons;

/**
 * Font Awesome 6 free icons, searched through Font Awesome's public GraphQL
 * API. Stored by the `font-awesome` ACF field type (ACF Font Awesome plugin).
 */
final class FontAwesomeProvider implements IconProvider
{
    public function id(): string
    {
        return 'font-awesome';
    }

    public function field_types(): array
    {
        return ['font-awesome'];
    }

    public function schema(array $field): array
    {
        return [
            'type'                 => 'object',
            'description'          => 'A Font Awesome icon. Call the ' . Icons::ABILITY . ' ability to find the right icon by concept (e.g. "shield" for protection). Do not use the fa- prefix when querying.',
            'properties'           => [
                'style'   => ['type' => 'string'],
                'id'      => ['type' => 'string'],
                'label'   => ['type' => 'string'],
                'unicode' => ['type' => 'string'],
            ],
            'required'             => ['style', 'id', 'label', 'unicode'],
            'additionalProperties' => false,
        ];
    }

    public function search(string $query): array
    {
        $name = trim((string) preg_replace('/^fa[- ]/i', '', $query));
        $gql  = 'query { search(version: "6.x", query: ' . wp_json_encode($name) . ', first: 5) { id label unicode familyStylesByLicense { free { family prefix style } } } }';

        $remote = wp_remote_post('https://api.fontawesome.com/v6.0.0/icons', [
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 30,
            'body'    => wp_json_encode(['query' => $gql]),
        ]);

        if (! is_wp_error($remote)) {
            $result = json_decode(wp_remote_retrieve_body($remote), true);
            foreach ((array) ($result['data']['search'] ?? []) as $icon) {
                if (! empty($icon['familyStylesByLicense']['free'][0])) {
                    return [
                        'style'   => $icon['familyStylesByLicense']['free'][0]['style'],
                        'id'      => $icon['id'],
                        'label'   => $icon['label'],
                        'unicode' => $icon['unicode'],
                    ];
                }
            }
        }

        return ['style' => 'solid', 'id' => 'check', 'label' => 'Check', 'unicode' => 'f00c'];
    }

    public function output_schema(): array
    {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['style', 'id', 'label', 'unicode'],
            'properties'           => [
                'style'   => ['type' => 'string'],
                'id'      => ['type' => 'string'],
                'label'   => ['type' => 'string'],
                'unicode' => ['type' => 'string'],
            ],
        ];
    }
}
