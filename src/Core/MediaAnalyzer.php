<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Core;

use Roadmap\AiByRoadmap\Core\Agents\MediaAnalyzerAgent;
use WordPress\AiClient\ProviderModels\Enums\CapabilityEnum;
use WP_Error;

/**
 * Automatically analyses images when they are uploaded to the media library
 * and writes SEO metadata + a vector embedding to the attachment.
 *
 * Embedding generation is gated by VectorSupport — on older MariaDB versions
 * the metadata still gets written but the embedding step is skipped silently.
 */
final class MediaAnalyzer
{
    private const CRON_HOOK = 'ai_by_roadmap_analyze_image';

    public function register(): void
    {
        add_action('add_attachment', [$this, 'schedule_analysis']);
        add_action(self::CRON_HOOK, [$this, 'analyze']);

        add_filter('attachment_fields_to_edit', [$this, 'add_usage_field'], 10, 2);

        add_action('admin_post_ai_by_roadmap_reindex', [$this, 'handle_reindex_request']);
        add_action('delete_attachment', [VectorStore::class, 'delete']);
    }

    public function add_usage_field(array $fields, \WP_Post $post): array
    {
        $value = get_post_meta($post->ID, '_ai_usage_suggestion', true);

        $fields['ai_usage_suggestion'] = [
            'label'         => __('Website Usage', 'ai-by-roadmap'),
            'input'         => 'html',
            'html'          => $value
                ? '<p style="margin:0;padding:4px 0;color:#3c434a;">' . esc_html($value) . '</p>'
                : '<p style="margin:0;padding:4px 0;color:#999;font-style:italic;">' . esc_html__('Pending analysis…', 'ai-by-roadmap') . '</p>',
            'show_in_edit'  => false,
            'show_in_modal' => true,
        ];

        return $fields;
    }

    public function schedule_analysis(int $attachment_id): void
    {
        if (! wp_attachment_is_image($attachment_id)) {
            return;
        }

        if (! wp_supports_ai()) {
            return;
        }

        wp_schedule_single_event(time(), self::CRON_HOOK, [$attachment_id]);
        spawn_cron();
    }

    public function analyze(int $attachment_id): void
    {
        $file_path = get_attached_file($attachment_id);
        if (! $file_path || ! file_exists($file_path)) {
            error_log("AI by Roadmap: attachment file not found for ID {$attachment_id}.");
            return;
        }

        try {
            $result = (new MediaAnalyzerAgent())->analyze_file($file_path);
        } catch (\Throwable $e) {
            error_log("AI by Roadmap: image analysis failed for ID {$attachment_id}: " . $e->getMessage());
            return;
        }

        $post_data = ['ID' => $attachment_id];

        if (! empty($result['seo_filename'])) {
            $seo = sanitize_text_field($result['seo_filename']);
            $post_data['post_title'] = $seo;
            $post_data['post_name']  = sanitize_title($seo);
            update_post_meta($attachment_id, '_ai_seo_filename', $seo);
        }

        if (! empty($result['description'])) {
            $description = sanitize_textarea_field($result['description']);
            $post_data['post_content'] = $description;
            update_post_meta($attachment_id, '_wp_attachment_image_alt', $description);
            update_post_meta($attachment_id, '_ai_description', $description);
        }

        if (! empty($result['usage_suggestion'])) {
            update_post_meta($attachment_id, '_ai_usage_suggestion', sanitize_text_field($result['usage_suggestion']));
        }

        if (count($post_data) > 1) {
            wp_update_post($post_data);
        }

        $this->maybe_store_embedding($attachment_id, $result);
    }

    private function maybe_store_embedding(int $attachment_id, array $result): void
    {
        if (! VectorSupport::is_available()) {
            return;
        }

        $embed_text = trim(implode(' ', array_filter([
            $result['description']      ?? '',
            $result['usage_suggestion'] ?? '',
            $result['seo_filename']     ?? '',
        ])));

        if ($embed_text === '') {
            return;
        }

        try {
            $embedding = self::generate_embedding($embed_text);
            if ($embedding !== null) {
                VectorStore::upsert($attachment_id, $embedding);
            }
        } catch (\Throwable $e) {
            error_log("AI by Roadmap: embedding generation failed for ID {$attachment_id}: " . $e->getMessage());
        }
    }

    /**
     * @return float[]|null
     */
    public static function generate_embedding(string $text): ?array
    {
        $builder = wp_ai_client_prompt($text);
        $result  = $builder->generate_result(CapabilityEnum::embeddingGeneration());
        if ($result instanceof WP_Error) {
            error_log('AI by Roadmap: embedding call failed: ' . $result->get_error_message());
            return null;
        }

        // Pull the first embedding from the result. The exact accessor depends on
        // the SDK's GenerativeAiResult; toArray() is the documented fallback.
        $raw = $result->toArray();
        $vec = self::extract_first_vector($raw);
        return $vec;
    }

    /**
     * Walks the generic result payload to find the first numeric vector.
     *
     * @return float[]|null
     */
    private static function extract_first_vector(array $raw): ?array
    {
        $stack = [$raw];
        while ($stack) {
            $node = array_shift($stack);
            if (! is_array($node)) {
                continue;
            }

            if (self::is_numeric_vector($node)) {
                return array_map('floatval', $node);
            }

            foreach ($node as $value) {
                if (is_array($value)) {
                    $stack[] = $value;
                }
            }
        }

        return null;
    }

    private static function is_numeric_vector(array $values): bool
    {
        if (count($values) < 16) {
            return false;
        }

        if (array_keys($values) !== range(0, count($values) - 1)) {
            return false;
        }

        foreach ($values as $v) {
            if (! is_numeric($v)) {
                return false;
            }
        }

        return true;
    }

    public function reindex_all(): int
    {
        if (! VectorSupport::is_available()) {
            return 0;
        }

        $attachments = get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => -1,
            'post_mime_type' => 'image',
            'meta_query'     => [
                ['key' => '_ai_description', 'compare' => 'EXISTS'],
            ],
        ]);

        global $wpdb;
        $table = VectorStore::table_name();
        $count = 0;

        foreach ($attachments as $attachment) {
            $already_indexed = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE attachment_id = %d",
                $attachment->ID
            ));
            if ($already_indexed) {
                continue;
            }

            $embed_text = trim(implode(' ', array_filter([
                get_post_meta($attachment->ID, '_ai_description', true),
                get_post_meta($attachment->ID, '_ai_usage_suggestion', true),
                get_post_meta($attachment->ID, '_ai_seo_filename', true),
            ])));

            if ($embed_text === '') {
                continue;
            }

            try {
                $embedding = self::generate_embedding($embed_text);
                if ($embedding !== null) {
                    VectorStore::upsert($attachment->ID, $embedding);
                    $count++;
                }
            } catch (\Throwable $e) {
                error_log("AI by Roadmap: reindex failed for ID {$attachment->ID}: " . $e->getMessage());
            }
        }

        return $count;
    }

    public function handle_reindex_request(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('ai_by_roadmap_reindex');

        $count = $this->reindex_all();

        wp_redirect(add_query_arg([
            'page'             => 'ai-by-roadmap-log',
            'reindex_complete' => $count,
        ], admin_url('options-general.php')));
        exit;
    }
}
