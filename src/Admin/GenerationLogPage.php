<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Admin;

use Roadmap\AiByRoadmap\Core\AI\ModelRouter;
use Roadmap\AiByRoadmap\Core\EvaluationLogger;
use Roadmap\AiByRoadmap\Core\VectorSupport;

/**
 * Shows the recent generation runs with expandable detail rows and a
 * 👍/👎 + notes panel. Feedback submission goes through the
 * ai-by-roadmap/rate-generation ability — no bespoke REST handler.
 */
final class GenerationLogPage
{
    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function add_menu(): void
    {
        add_submenu_page(
            'options-general.php',
            __('AI by Roadmap — Log', 'ai-by-roadmap'),
            __('AI by Roadmap', 'ai-by-roadmap'),
            'manage_options',
            'ai-by-roadmap-log',
            [$this, 'render']
        );
    }

    public function enqueue_assets(string $hook): void
    {
        if ($hook !== 'settings_page_ai-by-roadmap-log') {
            return;
        }

        wp_register_script('ai-by-roadmap-log', false, ['wp-api-fetch'], '0.1.0', true);
        wp_enqueue_script('ai-by-roadmap-log');
        wp_add_inline_script('ai-by-roadmap-log', $this->inline_js());
        wp_add_inline_style('wp-admin', $this->inline_css());
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'ai-by-roadmap'));
        }

        $rows = EvaluationLogger::get_recent(100);

        echo '<div class="wrap ai-by-roadmap-log">';
        echo '<h1>' . esc_html__('AI Generation Log', 'ai-by-roadmap') . '</h1>';

        $this->render_capability_notice();
        $this->render_routing();
        $this->render_reindex_form();

        if (empty($rows)) {
            echo '<p>' . esc_html__('No generation runs yet. Trigger one from the editor metabox or via the compose-page ability.', 'ai-by-roadmap') . '</p>';
            echo '</div>';
            return;
        }

        echo '<p>' . esc_html(sprintf(__('%d most recent runs shown.', 'ai-by-roadmap'), count($rows))) . '</p>';
        echo '<table class="widefat striped ai-by-roadmap-log-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Date', 'ai-by-roadmap') . '</th>';
        echo '<th>' . esc_html__('Post', 'ai-by-roadmap') . '</th>';
        echo '<th>' . esc_html__('Sections', 'ai-by-roadmap') . '</th>';
        echo '<th>' . esc_html__('Blocks', 'ai-by-roadmap') . '</th>';
        echo '<th>' . esc_html__('Score', 'ai-by-roadmap') . '</th>';
        echo '<th>' . esc_html__('Retry', 'ai-by-roadmap') . '</th>';
        echo '<th>&nbsp;</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $this->render_row($row);
        }

        echo '</tbody></table></div>';
    }

    private function render_capability_notice(): void
    {
        if (VectorSupport::is_available()) {
            return;
        }

        echo '<div class="notice notice-warning"><p>';
        esc_html_e('Vector image search is disabled — your database does not support MariaDB VECTOR columns (requires 11.7+). Image search will use keyword matching only.', 'ai-by-roadmap');
        echo '</p></div>';
    }

    private function render_routing(): void
    {
        echo '<h2>' . esc_html__('Model routing', 'ai-by-roadmap') . '</h2><p>';
        if (ModelRouter::is_openrouter_available()) {
            esc_html_e('Requests go through the OpenRouter Auto Router (openrouter/auto), with a cost tier per task. If a routed call fails, it is retried once on the default provider.', 'ai-by-roadmap');
        } else {
            esc_html_e('OpenRouter is not connected, so WordPress picks the model from the first configured AI provider.', 'ai-by-roadmap');
        }
        echo '</p>';

        $entries = array_slice(ModelRouter::recent(), 0, 15);
        if (empty($entries)) {
            return;
        }

        echo '<table class="widefat striped" style="max-width:960px;margin-bottom:2em">';
        echo '<thead><tr>';
        foreach ([__('Time', 'ai-by-roadmap'), __('Task', 'ai-by-roadmap'), __('Tier', 'ai-by-roadmap'), __('Provider', 'ai-by-roadmap'), __('Model', 'ai-by-roadmap'), __('Tokens', 'ai-by-roadmap'), __('Error', 'ai-by-roadmap')] as $label) {
            echo '<th>' . esc_html($label) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($entries as $entry) {
            echo '<tr>';
            echo '<td>' . esc_html(wp_date('Y-m-d H:i:s', (int) $entry['time'])) . '</td>';
            echo '<td>' . esc_html((string) $entry['task']) . '</td>';
            echo '<td>' . esc_html((string) ($entry['tier'] ?? '—')) . '</td>';
            echo '<td>' . esc_html((string) $entry['provider']) . '</td>';
            echo '<td><code>' . esc_html((string) $entry['model']) . '</code></td>';
            echo '<td>' . esc_html((string) ($entry['tokens'] ?? '—')) . '</td>';
            echo '<td>' . esc_html((string) ($entry['error'] ?? '')) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private function render_reindex_form(): void
    {
        if (! VectorSupport::is_available()) {
            return;
        }

        if (isset($_GET['reindex_complete'])) {
            echo '<div class="notice notice-success inline"><p>';
            echo esc_html(sprintf(__('Reindex complete — %d images indexed.', 'ai-by-roadmap'), (int) $_GET['reindex_complete']));
            echo '</p></div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom:1em">';
        echo '<input type="hidden" name="action" value="ai_by_roadmap_reindex">';
        wp_nonce_field('ai_by_roadmap_reindex');
        submit_button(__('Reindex All Images', 'ai-by-roadmap'), 'secondary', 'submit', false);
        echo '</form>';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function render_row(array $row): void
    {
        $signals     = json_decode((string) $row['content_signals'], true) ?: [];
        $chosen      = json_decode((string) $row['chosen_blocks'], true) ?: [];
        $final       = json_decode((string) $row['final_blocks'], true) ?: [];
        $scorer      = json_decode((string) $row['scorer_result'], true) ?: [];
        $pass        = ! empty($scorer['pass']);
        $badge_class = $pass ? 'ai-by-roadmap-pass' : 'ai-by-roadmap-fail';
        $badge_label = $pass ? 'PASS' : 'FAIL';
        $post_link   = ! empty($row['post_id'])
            ? '<a href="' . esc_url((string) get_edit_post_link((int) $row['post_id'])) . '">#' . esc_html((string) $row['post_id']) . '</a>'
            : '—';

        $row_id = 'ai-by-roadmap-row-' . $row['id'];

        echo '<tr>';
        echo '<td>' . esc_html((string) $row['created_at']) . '</td>';
        echo '<td>' . $post_link . '</td>';
        echo '<td>' . esc_html((string) ($signals['section_count'] ?? '?')) . '</td>';
        echo '<td>' . esc_html(count($chosen) . ' → ' . count($final)) . '</td>';
        echo '<td><span class="ai-by-roadmap-badge ' . esc_attr($badge_class) . '">' . esc_html($badge_label) . '</span></td>';
        echo '<td>' . ($row['retry_happened'] ? '<span class="ai-by-roadmap-badge ai-by-roadmap-retry">RETRY</span>' : '—') . '</td>';
        echo '<td><button class="button button-small ai-by-roadmap-toggle" data-target="' . esc_attr($row_id) . '">' . esc_html__('Expand', 'ai-by-roadmap') . '</button></td>';
        echo '</tr>';

        echo '<tr id="' . esc_attr($row_id) . '" class="ai-by-roadmap-detail-row" style="display:none;"><td colspan="7">';
        $this->render_detail($row, $signals, $chosen, $final, $scorer);
        echo '</td></tr>';
    }

    private function render_detail(array $row, array $signals, array $chosen, array $final, array $scorer): void
    {
        echo '<div class="ai-by-roadmap-detail">';

        echo '<h4>' . esc_html__('Input Content', 'ai-by-roadmap') . '</h4>';
        echo '<pre class="ai-by-roadmap-pre">' . esc_html((string) wp_trim_words((string) $row['input_content'], 80, '…')) . '</pre>';

        echo '<h4>' . esc_html__('Detected Sections', 'ai-by-roadmap') . '</h4>';
        echo '<ul>';
        foreach ((array) ($signals['detected_sections'] ?? []) as $section) {
            echo '<li>' . esc_html((string) $section) . '</li>';
        }
        echo '</ul>';

        echo '<h4>' . esc_html__('Blocks Chosen (first attempt)', 'ai-by-roadmap') . '</h4>';
        echo '<ul>';
        foreach ($chosen as $b) {
            echo '<li><code>' . esc_html((string) ($b['type'] ?? '')) . '</code> — ' . esc_html((string) ($b['intent'] ?? '')) . '</li>';
        }
        echo '</ul>';

        if (! empty($row['retry_happened'])) {
            echo '<h4>' . esc_html__('Retry suggestion', 'ai-by-roadmap') . '</h4>';
            echo '<pre class="ai-by-roadmap-pre">' . esc_html((string) ($row['retry_prompt'] ?? '')) . '</pre>';

            echo '<h4>' . esc_html__('Final blocks (after retry)', 'ai-by-roadmap') . '</h4>';
            echo '<ul>';
            foreach ($final as $b) {
                echo '<li><code>' . esc_html((string) ($b['type'] ?? '')) . '</code> — ' . esc_html((string) ($b['intent'] ?? '')) . '</li>';
            }
            echo '</ul>';
        }

        $rating       = (int) ($row['feedback_rating'] ?? 0);
        $good_style   = $rating === 1  ? ' style="background:#d4edda"' : '';
        $bad_style    = $rating === -1 ? ' style="background:#f8d7da"' : '';

        echo '<h4>' . esc_html__('Your Feedback', 'ai-by-roadmap') . '</h4>';
        echo '<div class="ai-by-roadmap-feedback" data-id="' . esc_attr((string) $row['id']) . '">';
        echo '<button class="button ai-by-roadmap-thumb" data-rating="1"' . $good_style . '>👍 Good</button> ';
        echo '<button class="button ai-by-roadmap-thumb" data-rating="-1"' . $bad_style . '>👎 Bad</button>';
        echo '<textarea class="ai-by-roadmap-notes" placeholder="' . esc_attr__('Optional notes (injected into future BlockChooser prompts when 👎)…', 'ai-by-roadmap') . '" style="display:block;margin-top:8px;width:100%;height:60px;">' . esc_textarea((string) ($row['feedback_notes'] ?? '')) . '</textarea>';
        echo '<button class="button button-primary ai-by-roadmap-save-feedback" style="margin-top:6px;">' . esc_html__('Save Feedback', 'ai-by-roadmap') . '</button>';
        echo '<span class="ai-by-roadmap-feedback-status" style="margin-left:8px;font-size:12px;color:#555;"></span>';
        echo '</div>';
        echo '</div>';
    }

    private function inline_css(): string
    {
        return '
        .ai-by-roadmap-badge { display:inline-block; padding:2px 8px; border-radius:3px; font-size:11px; font-weight:700; }
        .ai-by-roadmap-pass  { background:#d4edda; color:#155724; }
        .ai-by-roadmap-fail  { background:#f8d7da; color:#721c24; }
        .ai-by-roadmap-retry { background:#fff3cd; color:#856404; }
        .ai-by-roadmap-detail { padding:16px; background:#f9f9f9; border-left:4px solid #0073aa; }
        .ai-by-roadmap-detail h4 { margin:12px 0 4px; font-size:13px; text-transform:uppercase; color:#555; }
        .ai-by-roadmap-pre { background:#fff; padding:8px; border:1px solid #ddd; white-space:pre-wrap; word-break:break-word; font-size:12px; }
        ';
    }

    private function inline_js(): string
    {
        return <<<'JS'
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.ai-by-roadmap-toggle').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var target = document.getElementById(this.dataset.target);
                    if (target.style.display === 'none') {
                        target.style.display = '';
                        this.textContent = 'Collapse';
                    } else {
                        target.style.display = 'none';
                        this.textContent = 'Expand';
                    }
                });
            });

            document.querySelectorAll('.ai-by-roadmap-feedback').forEach(function (box) {
                var selected = 0;
                box.querySelectorAll('.ai-by-roadmap-thumb').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        selected = parseInt(this.dataset.rating, 10);
                        box.querySelectorAll('.ai-by-roadmap-thumb').forEach(function (b) { b.style.background = ''; });
                        this.style.background = selected === 1 ? '#d4edda' : '#f8d7da';
                    });
                });

                box.querySelector('.ai-by-roadmap-save-feedback').addEventListener('click', function () {
                    if (!selected) { alert('Please select 👍 or 👎 first.'); return; }
                    var status = box.querySelector('.ai-by-roadmap-feedback-status');
                    status.textContent = 'Saving…';
                    wp.apiFetch({
                        path: '/wp-abilities/v1/abilities/ai-by-roadmap/rate-generation/run',
                        method: 'POST',
                        data: {
                            input: {
                                id: parseInt(box.dataset.id, 10),
                                rating: selected,
                                notes: box.querySelector('.ai-by-roadmap-notes').value,
                            },
                        },
                    })
                        .then(function () { status.textContent = 'Saved ✓'; })
                        .catch(function (e) { status.textContent = 'Error: ' + (e.message || 'unknown'); });
                });
            });
        });
JS;
    }
}
