<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap;

use Roadmap\AiByRoadmap\Admin\BlockSwapper;
use Roadmap\AiByRoadmap\Admin\ContentGenerator;
use Roadmap\AiByRoadmap\Admin\GenerationLogPage;
use Roadmap\AiByRoadmap\Blocks\Abilities as BlockAbilities;
use Roadmap\AiByRoadmap\Core\Abilities as CoreAbilities;
use Roadmap\AiByRoadmap\Core\EvaluationLogger;
use Roadmap\AiByRoadmap\Core\MediaAnalyzer;
use Roadmap\AiByRoadmap\Core\VectorStore;
use Roadmap\AiByRoadmap\Core\VectorSupport;
use Roadmap\AiByRoadmap\Jobs\ComposePageJob;
use Roadmap\AiByRoadmap\Jobs\JobStore;
use Roadmap\AiByRoadmap\Mcp\Server as McpServer;

final class Plugin
{
    public const ABILITY_NAMESPACE = 'ai-by-roadmap';

    public const PUBLIC_ABILITIES = [
        'ai-by-roadmap/create-page',
        'ai-by-roadmap/list-blocks',
        'ai-by-roadmap/compose-page',
        'ai-by-roadmap/get-job-status',
        'ai-by-roadmap/search-media',
        'ai-by-roadmap/analyze-content',
        'ai-by-roadmap/rate-generation',
    ];

    public const AI_CONTENT_FIELD = 'ai_content';

    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function boot(): void
    {
        EvaluationLogger::create_table();
        JobStore::create_table();
        if (VectorSupport::is_available()) {
            VectorStore::create_table();
        }

        // The SDK ships a 30s default request timeout. compose-page makes
        // 4–5 sequential LLM calls, so each individual call is allowed up to
        // 5 minutes here. Filterable in case a host wants to tune it.
        add_filter('wp_ai_client_default_request_timeout', static fn() => (float) apply_filters('ai_by_roadmap_ai_request_timeout', 300.0));

        (new MediaAnalyzer())->register();
        (new ComposePageJob())->register();
        EvaluationLogger::register_prune_cron();

        add_action('wp_abilities_api_categories_init', [Categories::class, 'register']);

        add_action('wp_abilities_api_init', [CoreAbilities\AnalyzeContent::class,   'register']);
        add_action('wp_abilities_api_init', [CoreAbilities\SearchMedia::class,      'register']);
        add_action('wp_abilities_api_init', [CoreAbilities\AnalyzeMedia::class,     'register']);
        add_action('wp_abilities_api_init', [CoreAbilities\ReindexMedia::class,     'register']);
        add_action('wp_abilities_api_init', [CoreAbilities\RateGeneration::class,   'register']);

        add_action('wp_abilities_api_init', [BlockAbilities\CreatePage::class,          'register']);
        add_action('wp_abilities_api_init', [BlockAbilities\ListBlocks::class,         'register']);
        add_action('wp_abilities_api_init', [BlockAbilities\ChooseBlocks::class,       'register']);
        add_action('wp_abilities_api_init', [BlockAbilities\ScoreBlocks::class,        'register']);
        add_action('wp_abilities_api_init', [BlockAbilities\FillBlock::class,          'register']);
        add_action('wp_abilities_api_init', [BlockAbilities\FillPage::class,           'register']);
        add_action('wp_abilities_api_init', [BlockAbilities\ComposePage::class,        'register']);
        add_action('wp_abilities_api_init', [BlockAbilities\GetJobStatus::class,       'register']);
        add_action('wp_abilities_api_init', [BlockAbilities\GetTargetAudience::class,  'register']);
        add_action('wp_abilities_api_init', [BlockAbilities\SetTargetAudience::class,  'register']);

        add_action('mcp_adapter_init', [McpServer::class, 'register']);

        if (is_admin()) {
            (new GenerationLogPage())->register();
            (new ContentGenerator())->register();
            (new BlockSwapper())->register();
        }

    }

    public static function plugin_url(string $relative = ''): string
    {
        return plugins_url(ltrim($relative, '/'), dirname(__DIR__) . '/ai-by-roadmap.php');
    }

    public static function plugin_path(string $relative = ''): string
    {
        return dirname(__DIR__) . '/' . ltrim($relative, '/');
    }
}
