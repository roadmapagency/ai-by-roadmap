<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Admin;

use Roadmap\AiByRoadmap\Blocks\BlockRegistry;
use Roadmap\AiByRoadmap\Plugin;

/**
 * Enqueues the block-editor JS that adds the "Swap with AI" toolbar button to
 * any ACF block flagged as AI-generated. All of the actual swap logic lives
 * in the ai-by-roadmap/fill-block ability — this class is wiring only.
 */
final class BlockSwapper
{
    public function register(): void
    {
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_assets']);
    }

    public function enqueue_assets(): void
    {
        $js_path = Plugin::plugin_path('assets/js/block-swapper.js');
        $version = file_exists($js_path) ? (string) filemtime($js_path) : '0.1.0';

        wp_register_script(
            'ai-by-roadmap-block-swapper',
            Plugin::plugin_url('assets/js/block-swapper.js'),
            ['wp-blocks', 'wp-element', 'wp-editor', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-data', 'wp-compose', 'wp-hooks', 'wp-api-fetch'],
            $version,
            true
        );

        wp_localize_script('ai-by-roadmap-block-swapper', 'aiByRoadmap', [
            'blockOptions' => $this->block_options(),
            'i18n'         => [
                'swapButton'   => __('Swap with AI', 'ai-by-roadmap'),
                'selectType'   => __('Select a block type', 'ai-by-roadmap'),
                'cancel'       => __('Cancel', 'ai-by-roadmap'),
                'swap'         => __('Swap', 'ai-by-roadmap'),
                'swapping'     => __('Swapping…', 'ai-by-roadmap'),
                'success'      => __('Block swapped.', 'ai-by-roadmap'),
                'error'        => __('Swap failed.', 'ai-by-roadmap'),
            ],
        ]);

        wp_enqueue_script('ai-by-roadmap-block-swapper');
    }

    /**
     * @return array<int, array{value:string,label:string}>
     */
    private function block_options(): array
    {
        $options = [['value' => '', 'label' => __('Select a block type', 'ai-by-roadmap')]];
        foreach (BlockRegistry::get_blocks() as $id => $schema) {
            // The schema's top-level `title` is rarely populated, so fall back
            // to ACF's block-type registration metadata, then to a humanised
            // slug as a last resort (e.g. "acf/image-and-text" → "Image And Text").
            $label = '';
            if (! empty($schema['title'])) {
                $label = (string) $schema['title'];
            } elseif (function_exists('acf_get_block_type')) {
                $meta = acf_get_block_type($id);
                if (! empty($meta['title'])) {
                    $label = (string) $meta['title'];
                }
            }
            if ($label === '') {
                $slug  = preg_replace('#^acf/#', '', $id);
                $label = ucwords(str_replace(['-', '_'], ' ', (string) $slug));
            }

            $options[] = ['value' => $id, 'label' => $label];
        }
        return $options;
    }
}
