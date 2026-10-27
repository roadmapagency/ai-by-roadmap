<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Hub;

use const Roadmap\AiByRoadmap\VERSION;

/**
 * Roadmap Hub connection state.
 *
 * The hub is a Cloudflare Worker that gives Roadmap staff one MCP connector
 * for every client site. A site connects once from Settings › Roadmap Hub:
 * the hub pins nothing here except its Ed25519 public key, and from then on
 * every hub request carries a 60-second token naming the staff member. The
 * plugin runs that request as the WordPress user with that email (see
 * HubAuth), so nobody manages application passwords.
 *
 * Options (not autoloaded):
 *   ai_by_roadmap_hub              the connection: issuer, keys, client, home_url, connected_at
 *   ai_by_roadmap_hub_pending      the one-time connect code (hashed) while a connect is in flight
 *   ai_by_roadmap_hub_environment  '' (follow WP_ENVIRONMENT_TYPE) or production|staging|development
 *   ai_by_roadmap_hub_url          hub URL when AI_BY_ROADMAP_HUB_URL isn't defined
 */
final class Hub
{
    public const OPTION_CONNECTION  = 'ai_by_roadmap_hub';
    public const OPTION_PENDING     = 'ai_by_roadmap_hub_pending';
    public const OPTION_ENVIRONMENT = 'ai_by_roadmap_hub_environment';
    public const OPTION_URL         = 'ai_by_roadmap_hub_url';

    public const ENVIRONMENTS = ['production', 'staging', 'development'];

    /** Default hub; overridden by the AI_BY_ROADMAP_HUB_URL constant or the Hub page. */
    public const DEFAULT_URL = '';

    private const PENDING_TTL = 600;

    public static function register(): void
    {
        HubAuth::register();
        add_action('rest_api_init', [HubRestController::class, 'register_routes']);
    }

    public static function hub_url(): string
    {
        if (defined('AI_BY_ROADMAP_HUB_URL') && is_string(AI_BY_ROADMAP_HUB_URL) && AI_BY_ROADMAP_HUB_URL !== '') {
            return untrailingslashit(AI_BY_ROADMAP_HUB_URL);
        }
        $url = (string) get_option(self::OPTION_URL, '');
        if ($url === '') {
            $url = self::DEFAULT_URL;
        }
        return untrailingslashit((string) apply_filters('ai_by_roadmap_hub_url', $url));
    }

    public static function hub_url_is_locked(): bool
    {
        return defined('AI_BY_ROADMAP_HUB_URL') && is_string(AI_BY_ROADMAP_HUB_URL) && AI_BY_ROADMAP_HUB_URL !== '';
    }

    /** Environment reported to the hub: the Hub page override, else WP_ENVIRONMENT_TYPE. */
    public static function environment(): string
    {
        $override = (string) get_option(self::OPTION_ENVIRONMENT, '');
        if (in_array($override, self::ENVIRONMENTS, true)) {
            return $override;
        }
        return self::detected_environment();
    }

    public static function detected_environment(): string
    {
        $type = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';
        return $type === 'local' ? 'development' : (in_array($type, self::ENVIRONMENTS, true) ? $type : 'production');
    }

    public static function site_url(): string
    {
        return untrailingslashit(home_url());
    }

    /**
     * The stored connection, or null.
     *
     * @return array{issuer: string, keys: array<string, string>, client_id: string, client_name: string, home_url: string, connected_at: string}|null
     */
    public static function connection(): ?array
    {
        $c = get_option(self::OPTION_CONNECTION);
        if (! is_array($c) || empty($c['issuer']) || empty($c['keys']) || ! is_array($c['keys'])) {
            return null;
        }
        return $c;
    }

    /**
     * A database copied from another environment carries that site's
     * connection. Tokens are addressed to the original URL, so they fail
     * here until this copy is reconnected.
     */
    public static function is_cloned(): bool
    {
        $c = self::connection();
        return $c !== null && untrailingslashit((string) ($c['home_url'] ?? '')) !== self::site_url();
    }

    public static function is_connected(): bool
    {
        return self::connection() !== null && ! self::is_cloned();
    }

    /** Start a connect: a single-use code, valid for 10 minutes, bound to the hub it was issued for. */
    public static function create_pending_code(): string
    {
        $code = wp_generate_password(40, false, false);
        update_option(
            self::OPTION_PENDING,
            [
                'hash'    => hash('sha256', $code),
                'hub'     => self::hub_url(),
                'expires' => time() + self::PENDING_TTL,
            ],
            false
        );
        return $code;
    }

    /**
     * Check and consume a connect code. Returns the hub URL it was issued for, or null.
     */
    public static function consume_pending_code(string $code): ?string
    {
        $pending = get_option(self::OPTION_PENDING);
        if (! is_array($pending) || empty($pending['hash']) || (int) ($pending['expires'] ?? 0) < time()) {
            return null;
        }
        if (! hash_equals((string) $pending['hash'], hash('sha256', $code))) {
            return null;
        }
        delete_option(self::OPTION_PENDING);
        return (string) ($pending['hub'] ?? '');
    }

    /**
     * @param array<string, string> $keys kid => base64url Ed25519 public key
     */
    public static function save_connection(string $issuer, array $keys, string $client_id, string $client_name): void
    {
        update_option(
            self::OPTION_CONNECTION,
            [
                'issuer'       => $issuer,
                'keys'         => $keys,
                'client_id'    => $client_id,
                'client_name'  => $client_name,
                'home_url'     => self::site_url(),
                'connected_at' => gmdate('c'),
            ],
            false
        );
    }

    public static function disconnect(): void
    {
        delete_option(self::OPTION_CONNECTION);
        delete_option(self::OPTION_PENDING);
    }

    /** What the hub learns about this site (connect reply and whoami). */
    public static function site_info(): array
    {
        return [
            'name'        => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            'version'     => VERSION,
            'environment' => self::environment(),
            'home_url'    => self::site_url(),
            'rest_url'    => rest_url(),
            'mcp_url'     => rest_url('ai-by-roadmap/mcp'),
        ];
    }
}
