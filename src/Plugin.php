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
        'ai-by-roadmap/list-post-types',
        'ai-by-roadmap/find-posts',
        'ai-by-roadmap/compose-page',
        'ai-by-roadmap/assemble-page',
        'ai-by-roadmap/get-job-status',
        'ai-by-roadmap/search-media',
        'ai-by-roadmap/upload-media',
        'ai-by-roadmap/analyze-content',
        'ai-by-roadmap/rate-generation',
        'ai-by-roadmap/get-post-blocks',
        'ai-by-roadmap/set-block-image',
        'ai-by-roadmap/update-block-fields',
        'ai-by-roadmap/insert-block',
        'ai-by-roadmap/remove-block',
        'ai-by-roadmap/move-block',
        'ai-by-roadmap/update-post',
        'ai-by-roadmap/get-preview-link',
        'ai-by-roadmap/render-block',
        'ai-by-roadmap/resolve-link',
        'ai-by-roadmap/audit-links',
        'ai-by-roadmap/search-content',
        'ai-by-roadmap/replace-text',
        'ai-by-roadmap/list-revisions',
        'ai-by-roadmap/restore-revision',
        'ai-by-roadmap/update-blocks',
        'ai-by-roadmap/duplicate-post',
        'ai-by-roadmap/update-media',
        'ai-by-roadmap/get-navigation',
        'ai-by-roadmap/add-menu-item',
        'ai-by-roadmap/update-mega-nav',
    ];

    public const AI_CONTENT_FIELD = 'ai_content';

    private static ?self $instance = null;

    /**
     * Standard `meta` for an ability registration.
     *
     * - show_in_rest: callable via core's wp-abilities/v1 REST routes.
     * - annotations: MCP tool hints (readOnlyHint / destructiveHint /
     *   idempotentHint) so clients can ask before destructive calls.
     * - mcp.public: discoverable and executable on the MCP Adapter's
     *   generic default server (discover-abilities / execute-ability).
     *   Fine-grained pipeline abilities pass false so they stay REST-only;
     *   everything in PUBLIC_ABILITIES should pass true.
     *
     * @return array<string, mixed>
     */
    public static function ability_meta(bool $readonly, bool $destructive = false, bool $idempotent = true, bool $mcp_public = true): array
    {
        return [
            'show_in_rest' => true,
            'annotations'  => [
                'readonly'    => $readonly,
                'destructive' => $destructive,
                'idempotent'  => $idempotent,
            ],
            'mcp'          => [
                'public' => $mcp_public,
            ],
        ];
    }

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
        add_action('wp_abilities_api_init', [CoreAbilities\UploadMedia::class,      'register']);
        add_action('wp_abilities_api_init', [CoreAbilities\AnalyzeMedia::class,     'register']);
        add_action('wp_abilities_api_init', [CoreAbilities\ReindexMedia::class,     'register']);
        add_action('wp_abilities_api_init', [CoreAbilities\RateGeneration::class,   'register']);

        add_action('wp_abilities_api_init', [BlockAbilities\CreatePage::class,          'register']);
        add_action('wp_abilities_api_init', [BlockAbilities\ListBlocks::class,         'register']);
        add_action('wp_abilities_api_init', [BlockAbilities\ListPostTypes::class,      'register']);
        add_action('wp_abilities_api_init', [BlockAbilities\FindPosts::class,          'register']);
        add_action('wp_abilities_api_init', [BlockAbilities\ChooseBlocks::class,       'register']);
        add_action('wp_abilities_api_init', [BlockAbilities\ScoreBlocks::class,        'register']);
        add_action('wp_abilities_api_init', [BlockAbilities\FillBlock::class,          'register']);
        add_action('wp_abilities_api_init', [BlockAbilities\FillPage::class,           'register']);
        add_action('wp_abilities_api_init', [BlockAbilities\ComposePage::class,        'register']);
        add_action('wp_abilities_api_init', [BlockAbilities\AssemblePage::class,       'register']);
        add_action('wp_abilities_api_init', [BlockAbilities\GetJobStatus::class,       'register']);
        add_action('wp_abilities_api_init', [BlockAbilities\GetPostBlocks::class,      'register']);
        add_action('wp_abilities_api_init', [BlockAbilities\SetBlockImage::class,      'register']);
        add_action('wp_abilities_api_init', [BlockAbilities\UpdateBlockFields::class,  'register']);
        add_action('wp_abilities_api_init', [BlockAbilities\InsertBlock::class,        'register']);
        add_action('wp_abilities_api_init', [BlockAbilities\RemoveBlock::class,        'register']);
        add_action('wp_abilities_api_init', [BlockAbilities\MoveBlock::class,          'register']);
        add_action('wp_abilities_api_init', [BlockAbilities\UpdatePost::class,         'register']);
        add_action('wp_abilities_api_init', [BlockAbilities\GetPreviewLink::class,     'register']);
        add_action('wp_abilities_api_init', [BlockAbilities\RenderBlock::class,        'register']);
        add_action('wp_abilities_api_init', [BlockAbilities\ResolveLink::class,        'register']);
        add_action('wp_abilities_api_init', [BlockAbilities\AuditLinks::class,         'register']);
        add_action('wp_abilities_api_init', [BlockAbilities\SearchContent::class,      'register']);
        add_action('wp_abilities_api_init', [BlockAbilities\ReplaceText::class,        'register']);
        add_action('wp_abilities_api_init', [BlockAbilities\ListRevisions::class,      'register']);
        add_action('wp_abilities_api_init', [BlockAbilities\RestoreRevision::class,    'register']);
        add_action('wp_abilities_api_init', [BlockAbilities\UpdateBlocks::class,       'register']);
        add_action('wp_abilities_api_init', [BlockAbilities\DuplicatePost::class,      'register']);
        add_action('wp_abilities_api_init', [CoreAbilities\UpdateMedia::class,         'register']);
        add_action('wp_abilities_api_init', [CoreAbilities\GetNavigation::class,       'register']);
        add_action('wp_abilities_api_init', [CoreAbilities\AddMenuItem::class,         'register']);
        add_action('wp_abilities_api_init', [CoreAbilities\UpdateMegaNav::class,       'register']);
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
