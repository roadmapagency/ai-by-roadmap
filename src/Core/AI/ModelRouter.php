<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Core\AI;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WP_AI_Client_Prompt_Builder;
use WP_Error;

/**
 * Sends agent requests through the OpenRouter Auto Router when the OpenRouter
 * connector is configured; otherwise leaves model selection to core.
 *
 * The OpenRouter provider plugin does no routing of its own and declares no
 * JSON-schema / tool-calling support, so core's auto-discovery never picks it
 * for our agents. We therefore pin `openrouter/auto` explicitly (usingModel()
 * skips the capability check) and pass a per-task cost tier + model allowlist
 * through `customOptions`, which the OpenAI-compatible base merges into the
 * request body. If the routed call fails, the request is retried once on the
 * default provider.
 *
 * Filters:
 *   - ai_by_roadmap_use_openrouter          (bool)  kill switch.
 *   - ai_by_roadmap_router_allowed_models   (array) default allowlist patterns.
 *   - ai_by_roadmap_router_profiles         (array) task => {cost_tier, allowed_models?}.
 */
final class ModelRouter
{
    public const PROVIDER   = 'openrouter';
    public const AUTO_MODEL = 'openrouter/auto';

    public const TASK_CLASSIFY   = 'classify';
    public const TASK_JUDGE      = 'judge';
    public const TASK_FILL_BLOCK = 'fill_block';
    public const TASK_FILL_PAGE  = 'fill_page';
    public const TASK_VISION     = 'vision';

    private const LOG_OPTION = 'ai_by_roadmap_route_log';
    private const LOG_KEEP   = 50;

    private static ?bool $available = null;

    public static function is_openrouter_available(): bool
    {
        if (self::$available === null) {
            try {
                self::$available = AiClient::defaultRegistry()->isProviderConfigured(self::PROVIDER);
            } catch (\Throwable $e) {
                self::$available = false;
            }
        }

        return (bool) apply_filters('ai_by_roadmap_use_openrouter', self::$available);
    }

    /**
     * @return array{cost_tier: string, allowed_models: string[]}
     */
    public static function profile(string $task): array
    {
        $allowed = (array) apply_filters('ai_by_roadmap_router_allowed_models', [
            'anthropic/*',
            'openai/gpt-5*',
            'google/gemini-*',
        ]);

        $profiles = (array) apply_filters('ai_by_roadmap_router_profiles', [
            self::TASK_CLASSIFY   => ['cost_tier' => 'low'],
            self::TASK_JUDGE      => ['cost_tier' => 'medium'],
            self::TASK_FILL_BLOCK => ['cost_tier' => 'medium'],
            self::TASK_FILL_PAGE  => ['cost_tier' => 'high'],
            // Families whose current models all accept image input.
            self::TASK_VISION     => [
                'cost_tier'      => 'medium',
                'allowed_models' => ['anthropic/claude-*', 'openai/gpt-5*', 'google/gemini-*'],
            ],
        ], $allowed);

        $profile = (array) ($profiles[$task] ?? $profiles[self::TASK_FILL_BLOCK] ?? []);

        return [
            'cost_tier'      => (string) ($profile['cost_tier'] ?? 'medium'),
            'allowed_models' => array_values(array_map('strval', (array) ($profile['allowed_models'] ?? $allowed))),
        ];
    }

    /**
     * Point a builder at the Auto Router with the task's routing hints.
     */
    public static function apply(WP_AI_Client_Prompt_Builder $builder, string $task): WP_AI_Client_Prompt_Builder
    {
        $profile = self::profile($task);
        $plugin  = ['id' => 'auto-router', 'cost_tier' => $profile['cost_tier']];
        if (! empty($profile['allowed_models'])) {
            $plugin['allowed_models'] = $profile['allowed_models'];
        }

        $model = AiClient::defaultRegistry()->getProviderModel(self::PROVIDER, self::AUTO_MODEL);
        $builder->using_model($model);
        $builder->using_model_config(ModelConfig::fromArray([
            ModelConfig::KEY_CUSTOM_OPTIONS => [
                'plugins'  => [$plugin],
                // Only route to endpoints that honour response_format / tools.
                'provider' => ['require_parameters' => true],
            ],
        ]));

        return $builder;
    }

    /**
     * Build a prompt via $make_builder, route it when OpenRouter is available,
     * and generate a text result — retrying once on the default provider if
     * the routed call fails.
     *
     * @param callable(bool): WP_AI_Client_Prompt_Builder $make_builder Returns a fresh, fully configured
     *        builder; receives true when the request will be routed through OpenRouter.
     * @throws \RuntimeException When generation fails.
     */
    public static function generate_text(callable $make_builder, string $task): GenerativeAiResult
    {
        if (self::is_openrouter_available()) {
            $result = self::apply($make_builder(true), $task)->generate_text_result();
            if ($result instanceof GenerativeAiResult) {
                self::record($task, $result, null);
                return $result;
            }

            $error = $result instanceof WP_Error ? $result->get_error_message() : 'unknown error';
            self::record($task, null, $error);
        }

        $result = $make_builder(false)->generate_text_result();
        if ($result instanceof WP_Error) {
            throw new \RuntimeException('AI call failed: ' . $result->get_error_message());
        }

        self::record($task, $result, null);
        return $result;
    }

    /**
     * The OpenAI-compatible adapter only accepts one function response per
     * message (each becomes its own `tool` message), so split the combined
     * response the ability resolver returns after parallel tool calls.
     *
     * @param  Message[] $history
     * @return Message[]
     */
    public static function split_function_responses(array $history): array
    {
        $split = [];
        foreach ($history as $message) {
            $parts = $message->getParts();
            $is_responses = count($parts) > 1;
            foreach ($parts as $part) {
                $is_responses = $is_responses && $part->getType()->isFunctionResponse();
            }
            if (! $is_responses) {
                $split[] = $message;
                continue;
            }
            foreach ($parts as $part) {
                $split[] = new Message($message->getRole(), [$part]);
            }
        }
        return $split;
    }

    /**
     * Recent routing decisions, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function recent(): array
    {
        return (array) get_option(self::LOG_OPTION, []);
    }

    private static function record(string $task, ?GenerativeAiResult $result, ?string $error): void
    {
        $routed = $result === null || $result->getProviderMetadata()->getId() === self::PROVIDER;
        $entry  = [
            'time'     => time(),
            'task'     => $task,
            'tier'     => $routed ? self::profile($task)['cost_tier'] : null,
            'provider' => $result ? $result->getProviderMetadata()->getId() : self::PROVIDER,
            // For openrouter/auto the response's `model` field names the model actually used.
            'model'    => $result ? (string) ($result->getAdditionalData()['model'] ?? $result->getModelMetadata()->getId()) : self::AUTO_MODEL,
            'tokens'   => $result ? $result->getTokenUsage()->getTotalTokens() : null,
            'error'    => $error !== null ? mb_substr($error, 0, 300) : null,
        ];

        $log = self::recent();
        array_unshift($log, $entry);
        update_option(self::LOG_OPTION, array_slice($log, 0, self::LOG_KEEP), false);
    }
}
