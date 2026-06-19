<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks;

/**
 * Reads a post type's locked Gutenberg block template. Used both to advertise
 * the template (list-post-types) and to enforce it when a page is assembled,
 * so the two never drift.
 */
final class CptTemplate
{
    /**
     * @return array{blocks: array<int, string>, lock: string}
     */
    public static function for_post_type(string $post_type): array
    {
        $obj = get_post_type_object($post_type);

        $blocks = [];
        if ($obj && is_array($obj->template)) {
            // Each template entry is [block_name, attrs, innerBlocks?].
            foreach ($obj->template as $entry) {
                if (isset($entry[0])) {
                    $blocks[] = (string) $entry[0];
                }
            }
        }

        $lock = ($obj && $obj->template_lock) ? (string) $obj->template_lock : '';

        return ['blocks' => $blocks, 'lock' => $lock];
    }
}
