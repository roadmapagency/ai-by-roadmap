<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Hub;

use WP_Error;

/**
 * Authenticates requests from the Roadmap Hub.
 *
 * The hub sends `Authorization: Bearer <JWS>` signed with its Ed25519 key:
 *   { iss: hub origin, aud: this site's home_url, sub: staff email,
 *     aliases: [the same person on other staff domains], iat, exp, jti }
 *
 * When the signature, issuer, audience, lifetime and jti check out, the
 * request runs as the WordPress user whose email is `sub` (or one of the
 * aliases). Only the MCP endpoint and the hub whoami route accept these
 * tokens; cookie and application-password logins are untouched.
 */
final class HubAuth
{
    private const MAX_LIFETIME = 300;
    private const CLOCK_SKEW   = 60;

    private static ?WP_Error $error = null;
    private static bool $authenticated = false;

    public static function register(): void
    {
        add_filter('determine_current_user', [self::class, 'determine_current_user'], 20);
        add_filter('rest_authentication_errors', [self::class, 'rest_authentication_errors'], 20);
    }

    /** True when the current request was authenticated by a hub token. */
    public static function authenticated(): bool
    {
        return self::$authenticated;
    }

    /**
     * @param int|false $user_id
     * @return int|false
     */
    public static function determine_current_user($user_id)
    {
        if (! empty($user_id) || self::$authenticated) {
            return $user_id;
        }
        $token = self::bearer_token();
        if ($token === null || ! self::is_hub_route()) {
            return $user_id;
        }

        $result = self::verify($token);
        if ($result instanceof WP_Error) {
            self::$error = $result;
            return $user_id;
        }

        self::$authenticated = true;
        return $result;
    }

    /**
     * @param WP_Error|null|true $result
     * @return WP_Error|null|true
     */
    public static function rest_authentication_errors($result)
    {
        if (! empty($result)) {
            return $result;
        }
        if (self::$error !== null) {
            return self::$error;
        }
        return self::$authenticated ? true : $result;
    }

    /**
     * Verify a hub token and resolve the WordPress user.
     *
     * @return int|WP_Error User ID
     */
    public static function verify(string $token, ?int $now = null)
    {
        $now ??= time();
        $connection = Hub::connection();
        if ($connection === null) {
            return self::error('ai_by_roadmap_hub_not_connected', __('This site is not connected to the Roadmap Hub.', 'ai-by-roadmap'));
        }
        if (Hub::is_cloned()) {
            return self::error(
                'ai_by_roadmap_hub_not_connected',
                __('This site was copied from another environment. Reconnect it from Settings › Roadmap Hub.', 'ai-by-roadmap')
            );
        }
        if (! function_exists('sodium_crypto_sign_verify_detached')) {
            return self::error('ai_by_roadmap_hub_invalid_token', __('Ed25519 verification is unavailable on this server.', 'ai-by-roadmap'), 500);
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return self::invalid('malformed token');
        }
        [$h, $p, $s] = $parts;
        $header = json_decode((string) self::b64url_decode($h), true);
        $claims = json_decode((string) self::b64url_decode($p), true);
        $sig    = self::b64url_decode($s);
        if (! is_array($header) || ! is_array($claims) || $sig === null || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return self::invalid('malformed token');
        }
        if (($header['alg'] ?? '') !== 'EdDSA') {
            return self::invalid('unsupported algorithm');
        }

        $kid = (string) ($header['kid'] ?? '');
        $x   = $connection['keys'][$kid] ?? null;
        $pub = is_string($x) ? self::b64url_decode($x) : null;
        if ($pub === null || strlen($pub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return self::invalid('unknown signing key; reconnect the site if the hub key was rotated');
        }

        try {
            $ok = sodium_crypto_sign_verify_detached($sig, $h . '.' . $p, $pub);
        } catch (\Throwable $e) {
            $ok = false;
        }
        if (! $ok) {
            return self::invalid('bad signature');
        }

        if (($claims['iss'] ?? null) !== $connection['issuer']) {
            return self::invalid('wrong issuer');
        }
        if (untrailingslashit((string) ($claims['aud'] ?? '')) !== Hub::site_url()) {
            return self::invalid('token is for another site');
        }
        $iat = (int) ($claims['iat'] ?? 0);
        $exp = (int) ($claims['exp'] ?? 0);
        if ($exp < $now - self::CLOCK_SKEW || $iat > $now + self::CLOCK_SKEW || $exp - $iat > self::MAX_LIFETIME) {
            return self::invalid('token expired or not yet valid');
        }

        $jti = (string) ($claims['jti'] ?? '');
        if ($jti === '') {
            return self::invalid('missing jti');
        }
        $jti_key = 'aibr_hub_jti_' . md5($jti);
        if (get_transient($jti_key)) {
            return self::invalid('token replayed');
        }
        set_transient($jti_key, 1, self::MAX_LIFETIME + self::CLOCK_SKEW);

        $emails = array_merge([(string) ($claims['sub'] ?? '')], is_array($claims['aliases'] ?? null) ? $claims['aliases'] : []);
        foreach ($emails as $email) {
            if (! is_string($email) || ! is_email($email)) {
                continue;
            }
            $user = get_user_by('email', $email);
            if ($user) {
                return (int) $user->ID;
            }
        }

        return self::error(
            'ai_by_roadmap_hub_no_user',
            /* translators: %s: email address */
            sprintf(__('No WordPress user on this site has the email %s.', 'ai-by-roadmap'), (string) ($claims['sub'] ?? '')),
            401
        );
    }

    private static function invalid(string $why): WP_Error
    {
        /* translators: %s: reason */
        return self::error('ai_by_roadmap_hub_invalid_token', sprintf(__('Roadmap Hub token rejected: %s.', 'ai-by-roadmap'), $why));
    }

    private static function error(string $code, string $message, int $status = 401): WP_Error
    {
        return new WP_Error($code, $message, ['status' => $status]);
    }

    private static function bearer_token(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($header === '' && function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $name => $value) {
                if (strtolower((string) $name) === 'authorization') {
                    $header = (string) $value;
                    break;
                }
            }
        }
        if (! is_string($header) || stripos($header, 'Bearer ') !== 0) {
            return null;
        }
        $token = trim(substr($header, 7));
        return substr_count($token, '.') === 2 ? $token : null;
    }

    /**
     * Hub tokens only open the MCP endpoint and the hub whoami route.
     * determine_current_user runs before REST routing, so match the raw request.
     */
    private static function is_hub_route(): bool
    {
        $route = '';
        if (isset($_GET['rest_route'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $route = (string) wp_unslash($_GET['rest_route']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        } else {
            $path   = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
            $prefix = '/' . trim(rest_get_url_prefix(), '/') . '/';
            $pos    = strpos($path, $prefix);
            if ($pos === false) {
                return false;
            }
            $route = '/' . substr($path, $pos + strlen($prefix));
        }
        $route = '/' . trim(rawurldecode($route), '/');
        return in_array($route, ['/ai-by-roadmap/mcp', '/ai-by-roadmap/v1/hub/whoami'], true);
    }

    private static function b64url_decode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        return $decoded === false ? null : $decoded;
    }

    /** Test seam: forget per-request state. */
    public static function reset(): void
    {
        self::$error         = null;
        self::$authenticated = false;
    }
}
