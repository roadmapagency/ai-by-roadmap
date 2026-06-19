<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Mcp;

use Roadmap\AiByRoadmap\Plugin;
use WP\MCP\Core\McpAdapter;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;
use WP\MCP\Transport\HttpTransport;

/**
 * Registers a custom MCP server that exposes only the abilities Jennifer's
 * external Claude integration should see. Fine-grained abilities (fill-block,
 * choose-blocks, score-blocks, target-audience getters) stay REST-only.
 */
final class Server
{
    public const SERVER_ID = 'ai-by-roadmap-mcp';

    public static function register(McpAdapter $adapter): void
    {
        $adapter->create_server(
            self::SERVER_ID,
            'ai-by-roadmap',
            'mcp',
            __('AI by Roadmap', 'ai-by-roadmap'),
            __('AI-driven page composition, media search, and content analysis for WordPress.', 'ai-by-roadmap'),
            '0.1.0',
            [HttpTransport::class],
            ErrorLogMcpErrorHandler::class,
            NullMcpObservabilityHandler::class,
            Plugin::PUBLIC_ABILITIES,
            [],
            []
        );
    }
}
