<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Mcp;

use Roadmap\AiByRoadmap\Plugin;
use WP\MCP\Core\McpAdapter;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;
use WP\MCP\Transport\HttpTransport;

/**
 * Registers the plugin's MCP server — the single connector an external agent
 * (Claude Desktop / Claude Code / any MCP client) points at. It exposes:
 *
 *   - Plugin::PUBLIC_ABILITIES as first-class tools (fine-grained pipeline
 *     abilities such as fill-block stay REST-only).
 *   - BRIDGED_ABILITIES: abilities registered by other plugins / core that
 *     are useful alongside ours (Yoast SEO scores and post SEO data, core
 *     site info). Each is included only when it is actually registered, so
 *     a site without Yoast — or with Yoast indexables disabled — simply
 *     lacks those tools instead of logging errors.
 *
 * Every ability here also carries meta.mcp.public = true (see
 * Plugin::ability_meta()), so the MCP Adapter's generic default server at
 * /wp-json/mcp/mcp-adapter-default-server can reach them through its
 * discover-abilities / execute-ability meta-tools as well.
 */
final class Server
{
    public const SERVER_ID = 'ai-by-roadmap-mcp';

    /**
     * Third-party / core abilities surfaced as first-class tools when present.
     * Yoast registers its abilities only on WP ≥ 6.9 with indexables enabled;
     * get-/update-post-seo-data ship in a later Yoast release than 28.4.
     */
    public const BRIDGED_ABILITIES = [
        'core/get-site-info',
        'yoast-seo/get-seo-scores',
        'yoast-seo/get-readability-scores',
        'yoast-seo/get-inclusive-language-scores',
        'yoast-seo/get-post-seo-data',
        'yoast-seo/update-post-seo-data',
    ];

    public static function register(McpAdapter $adapter): void
    {
        $adapter->create_server(
            self::SERVER_ID,
            'ai-by-roadmap',
            'mcp',
            __('AI by Roadmap', 'ai-by-roadmap'),
            __('Build, edit and maintain pages of ACF blocks; media search and upload; SEO scores via Yoast when available.', 'ai-by-roadmap'),
            '0.2.0',
            [HttpTransport::class],
            ErrorLogMcpErrorHandler::class,
            NullMcpObservabilityHandler::class,
            self::tools(),
            [],
            []
        );
    }

    /**
     * The tool list: our public abilities plus whichever bridged abilities are
     * registered right now. Filterable so a site can add or remove tools.
     *
     * @return array<int, string>
     */
    public static function tools(): array
    {
        $bridged = array_values(array_filter(
            self::BRIDGED_ABILITIES,
            static fn(string $name): bool => function_exists('wp_get_ability') && wp_get_ability($name) !== null
        ));

        /**
         * Filters the ability names exposed as tools on the ai-by-roadmap MCP server.
         *
         * @param array<int, string> $tools   Ability names (ours first, then bridged ones that exist).
         * @param array<int, string> $bridged The bridged abilities that were found registered.
         */
        $tools = (array) apply_filters('ai_by_roadmap_mcp_tools', array_merge(Plugin::PUBLIC_ABILITIES, $bridged), $bridged);

        return array_values(array_unique(array_map('strval', $tools)));
    }
}
