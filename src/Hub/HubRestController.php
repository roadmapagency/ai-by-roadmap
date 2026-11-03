<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Hub;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST routes the Roadmap Hub calls.
 *
 *   POST /ai-by-roadmap/v1/hub/connect   server to server, during Connect. Public, but only
 *                                        accepts the single-use code this site issued.
 *   GET  /ai-by-roadmap/v1/hub/whoami    with a hub token: who the staff member is here.
 */
final class HubRestController
{
    public const NAMESPACE = 'ai-by-roadmap/v1';

    public static function register_routes(): void
    {
        register_rest_route(self::NAMESPACE, '/hub/connect', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'connect'],
            'permission_callback' => '__return_true',
            'args'                => [
                'code'        => ['type' => 'string', 'required' => true],
                'issuer'      => ['type' => 'string', 'required' => true],
                'jwks'        => ['type' => 'object', 'required' => true],
                'client_id'   => ['type' => 'string', 'default' => ''],
                'client_name' => ['type' => 'string', 'default' => ''],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/hub/whoami', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'whoami'],
            'permission_callback' => [self::class, 'require_hub_user'],
        ]);
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public static function connect(WP_REST_Request $request)
    {
        $hub = Hub::consume_pending_code((string) $request->get_param('code'));
        if ($hub === null) {
            return new WP_Error(
                'ai_by_roadmap_hub_bad_code',
                __('This connect request has expired or was already used. Start again from Settings › Roadmap Hub.', 'ai-by-roadmap'),
                ['status' => 403]
            );
        }

        // The issuer must be the hub the admin clicked Connect for.
        $issuer = untrailingslashit((string) $request->get_param('issuer'));
        if ($issuer !== self::origin($hub)) {
            return new WP_Error(
                'ai_by_roadmap_hub_wrong_issuer',
                /* translators: %s: hub URL */
                sprintf(__('This site expected the hub at %s.', 'ai-by-roadmap'), $hub),
                ['status' => 403]
            );
        }

        $keys = self::ed25519_keys($request->get_param('jwks'));
        if (! $keys) {
            return new WP_Error('ai_by_roadmap_hub_bad_key', __('The hub sent no usable Ed25519 key.', 'ai-by-roadmap'), ['status' => 400]);
        }

        Hub::save_connection(
            $issuer,
            $keys,
            sanitize_key((string) $request->get_param('client_id')),
            sanitize_text_field((string) $request->get_param('client_name'))
        );

        return new WP_REST_Response(Hub::site_info());
    }

    public static function whoami(): WP_REST_Response
    {
        $user = wp_get_current_user();
        $info = Hub::site_info();
        return new WP_REST_Response([
            'user_login'     => $user->user_login,
            'roles'          => array_values($user->roles),
            'can_edit'       => current_user_can('edit_posts'),
            'plugin_version' => $info['version'],
            'site_name'      => $info['name'],
            'environment'    => $info['environment'],
            'mcp_url'        => $info['mcp_url'],
        ]);
    }

    /**
     * @return true|WP_Error
     */
    public static function require_hub_user()
    {
        if (HubAuth::authenticated() && is_user_logged_in()) {
            return true;
        }
        return new WP_Error('ai_by_roadmap_hub_no_user', __('A Roadmap Hub token for a user of this site is required.', 'ai-by-roadmap'), ['status' => 401]);
    }

    /**
     * @param mixed $jwks
     * @return array<string, string> kid => x
     */
    private static function ed25519_keys($jwks): array
    {
        $out = [];
        $list = is_array($jwks) && isset($jwks['keys']) && is_array($jwks['keys']) ? $jwks['keys'] : [];
        foreach ($list as $jwk) {
            if (! is_array($jwk) || ($jwk['kty'] ?? '') !== 'OKP' || ($jwk['crv'] ?? '') !== 'Ed25519') {
                continue;
            }
            $kid = sanitize_text_field((string) ($jwk['kid'] ?? ''));
            $x   = (string) ($jwk['x'] ?? '');
            if ($kid === '' || ! preg_match('/^[A-Za-z0-9_-]{43}$/', $x)) {
                continue;
            }
            $out[$kid] = $x;
        }
        return $out;
    }

    private static function origin(string $url): string
    {
        $parts = wp_parse_url($url);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
