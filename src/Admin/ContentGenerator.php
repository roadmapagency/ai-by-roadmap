<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Admin;

use Roadmap\AiByRoadmap\Blocks\Abilities\GetTargetAudience;
use Roadmap\AiByRoadmap\Plugin;

/**
 * "Generate AI Content" metabox on post/page edit screens. All backend work
 * (enqueuing the job, polling, replacing content) happens client-side via
 * the new Abilities REST endpoints — no PHP REST handler lives in this file.
 */
final class ContentGenerator
{
    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'add_metabox']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function add_metabox(): void
    {
        add_meta_box(
            'ai_by_roadmap_content',
            __('Generate AI Content', 'ai-by-roadmap'),
            [$this, 'render_metabox'],
            ['post', 'page'],
            'side',
            'high'
        );
    }

    public function render_metabox(\WP_Post $post): void
    {
        $audience = get_post_meta($post->ID, GetTargetAudience::META_KEY, true);
        ?>
        <div class="ai-by-roadmap-metabox" data-post-id="<?php echo esc_attr((string) $post->ID); ?>">
            <p><?php esc_html_e('Paste source content; an LLM will assemble a page of ACF blocks for you.', 'ai-by-roadmap'); ?></p>

            <p>
                <label for="ai-by-roadmap-content"><strong><?php esc_html_e('Content:', 'ai-by-roadmap'); ?></strong></label>
                <textarea id="ai-by-roadmap-content" rows="5" style="width:100%"></textarea>
            </p>

            <p>
                <label for="ai-by-roadmap-audience"><strong><?php esc_html_e('Target audience:', 'ai-by-roadmap'); ?></strong></label>
                <textarea id="ai-by-roadmap-audience" rows="3" style="width:100%"><?php echo esc_textarea((string) $audience); ?></textarea>
            </p>

            <p>
                <label>
                    <input type="checkbox" id="ai-by-roadmap-replace" checked>
                    <?php esc_html_e('Replace existing content', 'ai-by-roadmap'); ?>
                </label>
            </p>

            <p>
                <button type="button" class="button button-primary" id="ai-by-roadmap-generate">
                    <?php esc_html_e('Generate Content', 'ai-by-roadmap'); ?>
                </button>
                <span class="spinner" style="float:none;"></span>
            </p>

            <div id="ai-by-roadmap-status" class="ai-by-roadmap-status" aria-live="polite"></div>
        </div>
        <?php
    }

    public function enqueue_assets(string $hook): void
    {
        if (! in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }

        $js_path  = Plugin::plugin_path('assets/js/content-generator.js');
        $css_path = Plugin::plugin_path('assets/css/content-generator.css');

        wp_register_script(
            'ai-by-roadmap-content-generator',
            Plugin::plugin_url('assets/js/content-generator.js'),
            ['wp-api-fetch'],
            file_exists($js_path) ? (string) filemtime($js_path) : '0.1.0',
            true
        );
        wp_enqueue_script('ai-by-roadmap-content-generator');

        wp_register_style(
            'ai-by-roadmap-content-generator',
            Plugin::plugin_url('assets/css/content-generator.css'),
            [],
            file_exists($css_path) ? (string) filemtime($css_path) : '0.1.0'
        );
        wp_enqueue_style('ai-by-roadmap-content-generator');
    }
}
