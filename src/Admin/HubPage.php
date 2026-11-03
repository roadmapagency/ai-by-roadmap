<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Admin;

use Roadmap\AiByRoadmap\Hub\Hub;

/**
 * Settings › Roadmap Hub: connect this site to the hub, choose which
 * environment it reports (production / staging / development), disconnect.
 */
final class HubPage
{
    public const SLUG = 'ai-by-roadmap-hub';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_post_ai_by_roadmap_hub_save', [$this, 'save']);
        add_action('admin_post_ai_by_roadmap_hub_connect', [$this, 'connect']);
        add_action('admin_post_ai_by_roadmap_hub_disconnect', [$this, 'disconnect']);
    }

    public function add_menu(): void
    {
        add_submenu_page(
            'options-general.php',
            __('Roadmap Hub', 'ai-by-roadmap'),
            __('Roadmap Hub', 'ai-by-roadmap'),
            'manage_options',
            self::SLUG,
            [$this, 'render']
        );
    }

    public static function url(array $args = []): string
    {
        return add_query_arg($args, admin_url('options-general.php?page=' . self::SLUG));
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'ai-by-roadmap'));
        }

        $connection = Hub::connection();
        $override   = (string) get_option(Hub::OPTION_ENVIRONMENT, '');
        $detected   = Hub::detected_environment();
        $hub_url    = Hub::hub_url();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Roadmap Hub', 'ai-by-roadmap') . '</h1>';
        echo '<p>' . esc_html__('Connecting lets Roadmap staff edit this site from Claude through one shared connector. Each person acts as their own WordPress user, matched by email. Nobody needs an application password.', 'ai-by-roadmap') . '</p>';

        $this->render_notices();

        if (Hub::is_cloned() && $connection) {
            printf(
                '<div class="notice notice-warning"><p>%s</p></div>',
                esc_html(sprintf(
                    /* translators: 1: original URL, 2: this URL */
                    __('This site’s connection belongs to %1$s, but this site is %2$s. It was probably copied from another environment. Reconnect to register this copy on its own.', 'ai-by-roadmap'),
                    (string) $connection['home_url'],
                    Hub::site_url()
                ))
            );
        }

        echo '<h2>' . esc_html__('Status', 'ai-by-roadmap') . '</h2><table class="form-table" role="presentation"><tbody>';
        if (Hub::is_connected() && $connection) {
            $this->row(__('Connection', 'ai-by-roadmap'), '<strong style="color:#00a32a">' . esc_html__('Connected', 'ai-by-roadmap') . '</strong>');
            $this->row(__('Client', 'ai-by-roadmap'), esc_html($connection['client_name'] ?: $connection['client_id']));
            $this->row(__('Hub', 'ai-by-roadmap'), '<code>' . esc_html($connection['issuer']) . '</code>');
            $this->row(__('Connected', 'ai-by-roadmap'), esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), get_date_from_gmt($connection['connected_at']))));
        } else {
            $this->row(__('Connection', 'ai-by-roadmap'), esc_html__('Not connected', 'ai-by-roadmap'));
        }
        $this->row(__('Site URL', 'ai-by-roadmap'), '<code>' . esc_html(Hub::site_url()) . '</code>');
        echo '</tbody></table>';

        // Settings.
        echo '<h2>' . esc_html__('Settings', 'ai-by-roadmap') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('ai_by_roadmap_hub_save');
        echo '<input type="hidden" name="action" value="ai_by_roadmap_hub_save">';
        echo '<table class="form-table" role="presentation"><tbody>';

        $select  = '<select name="environment" id="aibr-hub-environment">';
        /* translators: %s: detected environment */
        $select .= '<option value=""' . selected($override, '', false) . '>' . esc_html(sprintf(__('Automatic (WP_ENVIRONMENT_TYPE: %s)', 'ai-by-roadmap'), $detected)) . '</option>';
        foreach (Hub::ENVIRONMENTS as $env) {
            $select .= '<option value="' . esc_attr($env) . '"' . selected($override, $env, false) . '>' . esc_html(ucfirst($env)) . '</option>';
        }
        $select .= '</select><p class="description">' . esc_html__('Claude shows this to staff so they know whether they are changing the live site or staging.', 'ai-by-roadmap') . '</p>';
        $this->row(__('Environment', 'ai-by-roadmap'), $select, 'aibr-hub-environment');

        if (Hub::hub_url_is_locked()) {
            $this->row(__('Hub URL', 'ai-by-roadmap'), '<code>' . esc_html($hub_url) . '</code> <span class="description">' . esc_html__('(set by AI_BY_ROADMAP_HUB_URL)', 'ai-by-roadmap') . '</span>');
        } else {
            $this->row(
                __('Hub URL', 'ai-by-roadmap'),
                '<input type="url" class="regular-text code" name="hub_url" id="aibr-hub-url" value="' . esc_attr($hub_url) . '" placeholder="https://…workers.dev">',
                'aibr-hub-url'
            );
        }
        echo '</tbody></table>';
        submit_button(__('Save settings', 'ai-by-roadmap'), 'secondary');
        echo '</form>';

        // Connect / disconnect.
        echo '<h2>' . esc_html__('Connection', 'ai-by-roadmap') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:.5rem">';
        wp_nonce_field('ai_by_roadmap_hub_connect');
        echo '<input type="hidden" name="action" value="ai_by_roadmap_hub_connect">';
        $label = Hub::is_connected() ? __('Reconnect to Roadmap Hub', 'ai-by-roadmap') : __('Connect to Roadmap Hub', 'ai-by-roadmap');
        submit_button($label, 'primary', 'submit', false, $hub_url === '' ? ['disabled' => 'disabled'] : []);
        echo '</form>';

        if ($connection) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block">';
            wp_nonce_field('ai_by_roadmap_hub_disconnect');
            echo '<input type="hidden" name="action" value="ai_by_roadmap_hub_disconnect">';
            submit_button(__('Disconnect', 'ai-by-roadmap'), 'delete', 'submit', false, ['onclick' => "return confirm('" . esc_js(__('Disconnect this site from the Roadmap Hub? Staff will lose access to it from Claude.', 'ai-by-roadmap')) . "')"]);
            echo '</form>';
        }
        if ($hub_url === '') {
            echo '<p class="description">' . esc_html__('Enter the hub URL above first.', 'ai-by-roadmap') . '</p>';
        } else {
            echo '<p class="description">' . esc_html__('You will sign in with your Roadmap Microsoft account and choose which client this site belongs to.', 'ai-by-roadmap') . '</p>';
        }
        echo '</div>';
    }

    public function save(): void
    {
        $this->guard('ai_by_roadmap_hub_save');
        $env = sanitize_key((string) wp_unslash($_POST['environment'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() checked the nonce.
        update_option(Hub::OPTION_ENVIRONMENT, in_array($env, Hub::ENVIRONMENTS, true) ? $env : '', false);
        if (! Hub::hub_url_is_locked() && isset($_POST['hub_url'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            update_option(Hub::OPTION_URL, untrailingslashit(esc_url_raw((string) wp_unslash($_POST['hub_url']))), false); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        }
        wp_safe_redirect(self::url(['hub_saved' => 1]));
        exit;
    }

    public function connect(): void
    {
        $this->guard('ai_by_roadmap_hub_connect');
        $hub = Hub::hub_url();
        if ($hub === '') {
            wp_safe_redirect(self::url(['hub_error' => __('Set the hub URL first.', 'ai-by-roadmap')]));
            exit;
        }
        $target = add_query_arg(
            array_map('rawurlencode', [
                'site'   => Hub::site_url(),
                'rest'   => rest_url(),
                'code'   => Hub::create_pending_code(),
                'return' => self::url(),
                'name'   => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
                'env'    => Hub::environment(),
            ]),
            $hub . '/connect'
        );
        wp_redirect($target); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the hub is an external host by design.
        exit;
    }

    public function disconnect(): void
    {
        $this->guard('ai_by_roadmap_hub_disconnect');
        Hub::disconnect();
        wp_safe_redirect(self::url(['hub_disconnected' => 1]));
        exit;
    }

    private function guard(string $action): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'ai-by-roadmap'));
        }
        check_admin_referer($action);
    }

    private function render_notices(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only flags set by our own redirects.
        if (isset($_GET['hub_connected'])) {
            $slug = sanitize_text_field(wp_unslash((string) $_GET['hub_connected']));
            /* translators: %s: site identifier like acme/staging */
            printf('<div class="notice notice-success"><p>%s</p></div>', esc_html(sprintf(__('Connected. Staff can now use this site from Claude as %s.', 'ai-by-roadmap'), $slug)));
        }
        if (isset($_GET['hub_error'])) {
            printf('<div class="notice notice-error"><p>%s</p></div>', esc_html(sanitize_text_field(wp_unslash((string) $_GET['hub_error']))));
        }
        if (isset($_GET['hub_saved'])) {
            echo '<div class="notice notice-success"><p>' . esc_html__('Settings saved. The hub picks up an environment change within 15 minutes.', 'ai-by-roadmap') . '</p></div>';
        }
        if (isset($_GET['hub_disconnected'])) {
            echo '<div class="notice notice-success"><p>' . esc_html__('Disconnected from the Roadmap Hub.', 'ai-by-roadmap') . '</p></div>';
        }
        // phpcs:enable
    }

    private function row(string $label, string $html, string $for = ''): void
    {
        $th = $for !== '' ? '<label for="' . esc_attr($for) . '">' . esc_html($label) . '</label>' : esc_html($label);
        echo '<tr><th scope="row">' . $th . '</th><td>' . $html . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- callers escape.
    }
}
