<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Icons;

/**
 * Lucide icons searched locally: slugs come from the theme's SVG directory,
 * tags from Lucide's tags.json. The theme passes both paths:
 *
 *     'icons' => ['provider' => 'lucide', 'svg_dir' => …, 'tags' => …]
 */
final class LucideProvider implements IconProvider
{
    /** @var array<string, string[]>|null */
    private ?array $index = null;

    public function __construct(private string $svg_dir, private string $tags_file)
    {
    }

    public function id(): string
    {
        return 'lucide';
    }

    public function field_types(): array
    {
        return ['lucide_icon'];
    }

    public function schema(array $field): array
    {
        return [
            'type'        => 'string',
            'description' => 'A Lucide icon slug, e.g. "heart-pulse". Call the ' . Icons::ABILITY . ' ability to find the right icon by concept (e.g. "shield" for protection) and use the returned id verbatim.',
            'pattern'     => '^[a-z0-9-]*$',
        ];
    }

    public function search(string $query): array
    {
        $matches = $this->find($query, 6);
        if (empty($matches)) {
            $matches = ['check'];
        }
        $id = array_shift($matches);
        return [
            'id'           => $id,
            'label'        => ucfirst(str_replace('-', ' ', $id)),
            'alternatives' => array_values($matches),
        ];
    }

    public function output_schema(): array
    {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['id', 'label', 'alternatives'],
            'properties'           => [
                'id'           => ['type' => 'string'],
                'label'        => ['type' => 'string'],
                'alternatives' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }

    /**
     * Slugs ranked: exact slug, slug prefix, slug substring, then tag match.
     *
     * @return string[]
     */
    public function find(string $query, int $limit): array
    {
        $index = $this->index();
        $q     = str_replace(' ', '-', strtolower(trim((string) preg_replace('/^(fa|lucide)[- ]/i', '', $query))));
        if ($q === '') {
            return array_slice(array_keys($index), 0, $limit);
        }

        $exact = $start = $sub = $tag = [];
        $qword = str_replace('-', ' ', $q);
        foreach ($index as $slug => $tags) {
            if ($slug === $q) {
                $exact[] = $slug;
            } elseif (str_starts_with($slug, $q)) {
                $start[] = $slug;
            } elseif (str_contains($slug, $q)) {
                $sub[] = $slug;
            } else {
                foreach ($tags as $t) {
                    if (str_contains(strtolower($t), $qword)) {
                        $tag[] = $slug;
                        break;
                    }
                }
            }
        }

        return array_slice(array_merge($exact, $start, $sub, $tag), 0, $limit);
    }

    /** @return array<string, string[]> */
    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $tags = [];
        if (is_readable($this->tags_file)) {
            $decoded = json_decode((string) file_get_contents($this->tags_file), true);
            $tags    = is_array($decoded) ? $decoded : [];
        }

        $this->index = [];
        foreach ((array) glob(rtrim($this->svg_dir, '/') . '/*.svg') as $path) {
            $slug               = basename((string) $path, '.svg');
            $this->index[$slug] = isset($tags[$slug]) && is_array($tags[$slug]) ? array_map('strval', $tags[$slug]) : [];
        }
        ksort($this->index);

        return $this->index;
    }
}
