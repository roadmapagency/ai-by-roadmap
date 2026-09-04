<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks;

use Roadmap\AiByRoadmap\Blocks\Agents\BlockChooserAgent;
use Roadmap\AiByRoadmap\Blocks\Agents\BlockScorerAgent;
use Roadmap\AiByRoadmap\Blocks\Agents\PageFillerAgent;
use Roadmap\AiByRoadmap\Core\Agents\ContentAnalyzerAgent;
use Roadmap\AiByRoadmap\Core\EvaluationLogger;
use Roadmap\AiByRoadmap\Plugin;

/**
 * Runs the five-step pipeline: analyze → choose → score(+retry) → fill →
 * transform. Each step is filterable so theme/plugin code can splice in
 * custom behaviour without forking the orchestrator.
 */
final class Orchestrator
{
    private ACFTransformer $transformer;

    /**
     * @var array<string, array{callback:callable, description:string}>
     */
    private array $pipeline;

    public function __construct()
    {
        $this->transformer = new ACFTransformer();
        $this->pipeline    = $this->build_pipeline();
    }

    /**
     * @return array<string, array{callback:callable, description:string}>
     */
    private function build_pipeline(): array
    {
        $pipeline = [
            'analyze_content' => [
                'callback'    => [$this, 'analyze_content'],
                'description' => 'Analyze content to extract structured signals.',
            ],
            'choose_blocks' => [
                'callback'    => [$this, 'choose_blocks'],
                'description' => 'Choose which blocks to use based on content.',
            ],
            'llm_score_and_retry' => [
                'callback'    => [$this, 'llm_score_and_retry'],
                'description' => 'Score chosen blocks and retry once if needed.',
            ],
            'fill_blocks' => [
                'callback'    => [$this, 'fill_blocks'],
                'description' => 'Fill each block with appropriate content.',
            ],
            'transform_blocks' => [
                'callback'    => [$this, 'transform_blocks'],
                'description' => 'Transform JSON to serialized ACF block markup.',
            ],
        ];

        return (array) apply_filters('ai_by_roadmap_pipeline', $pipeline);
    }

    /**
     * @return string[] Array of serialized block comments (ACF blocks, plus any
     *                  fixed rows a locked CPT template declares, e.g. synced patterns).
     */
    public function generate_content(string $content, string $target_audience = '', ?int $post_id = null): array
    {
        @set_time_limit(300);

        $data = [
            'content'         => $content,
            'target_audience' => $target_audience,
            'post_id'         => $post_id,
            'blocks'          => [],
            'chosen_blocks'   => [],
            'filled_blocks'   => [],
        ];

        $data = (array) apply_filters('ai_by_roadmap_pre_pipeline', $data);

        foreach ($this->pipeline as $step_id => $step) {
            if (! isset($step['callback']) || ! is_callable($step['callback'])) {
                continue;
            }
            $data = (array) apply_filters("ai_by_roadmap_before_{$step_id}", $data);
            $data = (array) call_user_func($step['callback'], $data);
            $data = (array) apply_filters("ai_by_roadmap_after_{$step_id}", $data);
        }

        $data = (array) apply_filters('ai_by_roadmap_post_pipeline', $data);

        return $data['blocks'];
    }

    public function analyze_content(array $data): array
    {
        try {
            $signals = (new ContentAnalyzerAgent())->chat('Content: ' . $data['content']);
        } catch (\Throwable $e) {
            error_log('AI by Roadmap: ContentAnalyzerAgent failed; using defaults — ' . $e->getMessage());
            $signals = ['section_count' => 3, 'detected_sections' => []];
        }

        if (! isset($signals['section_count'])) {
            $signals = ['section_count' => 3, 'detected_sections' => []];
        }

        $data['content_signals'] = $signals;
        return $data;
    }

    public function choose_blocks(array $data): array
    {
        // If the target post belongs to a post type with a registered locked
        // block template (a "rigid" CPT), that template is the single source of
        // truth for the layout — use it verbatim and skip the LLM chooser, so the
        // AI builder stays in lockstep with the editor's template_lock and every
        // entry of that type gets the SAME layout.
        $locked = $this->locked_template_for_post($data['post_id'] ?? null);
        if ($locked !== null) {
            $data['chosen_blocks']        = $locked['chosen'];
            $data['first_attempt_blocks'] = $locked['chosen'];
            $data['template_rows']        = $locked['rows'];
            $data['layout_locked']        = true;
            return $data;
        }

        $chosen = (new BlockChooserAgent($data['content_signals'] ?? []))
            ->chat('Content: ' . $data['content']);

        if (empty($chosen['blocks']) || ! is_array($chosen['blocks'])) {
            throw new \RuntimeException('BlockChooserAgent returned no blocks.');
        }

        $data['chosen_blocks']        = $chosen['blocks'];
        $data['first_attempt_blocks'] = $chosen['blocks'];
        return $data;
    }

    /**
     * If $post_id belongs to a post type registered with a locked block
     * template, return that template as the pipeline's [{type, intent}] block
     * list (`chosen` — fillable rows only) plus the full template `rows` so
     * fixed rows (synced patterns etc.) can be re-inserted when transforming.
     * Returns null for normal pages / flexible post types, where the LLM
     * chooser should decide the layout instead.
     *
     * @return array{chosen: array<int, array{type:string, intent:string}>, rows: array<int, array{type:string, attrs:array<string, mixed>, fixed:bool}>}|null
     */
    private function locked_template_for_post(?int $post_id): ?array
    {
        if (! $post_id) {
            return null;
        }

        $post_type = get_post_type($post_id);
        $obj       = $post_type ? get_post_type_object($post_type) : null;
        if (! $obj || empty($obj->template) || empty($obj->template_lock)) {
            return null; // not a locked CPT
        }

        // Only force the layout when the structure is genuinely locked.
        if (! in_array($obj->template_lock, ['all', 'insert', 'contentOnly'], true)) {
            return null;
        }

        $tpl = CptTemplate::for_post_type((string) $post_type);
        if (empty($tpl['rows'])) {
            return null;
        }

        $catalogue = BlockRegistry::get_block_descriptions();
        $chosen    = [];
        foreach ($tpl['blocks'] as $type) {
            $chosen[] = [
                'type'   => $type,
                'intent' => (string) ($catalogue[$type] ?? ''),
            ];
        }

        return ['chosen' => $chosen, 'rows' => $tpl['rows']];
    }

    public function llm_score_and_retry(array $data): array
    {
        // A locked CPT template is fixed — there is nothing to score or
        // re-choose, so skip the scorer/retry (and its metered LLM call).
        if (! empty($data['layout_locked'])) {
            return $data;
        }

        $signals  = $data['content_signals'] ?? [];
        $catalogue = BlockRegistry::get_block_descriptions();

        $scorer_prompt = $this->build_scorer_prompt($data['content'], $catalogue, $data['chosen_blocks']);
        $scorer        = new BlockScorerAgent();

        try {
            $result = $scorer->chat($scorer_prompt);
        } catch (\Throwable $e) {
            error_log('AI by Roadmap: BlockScorerAgent failed; skipping retry — ' . $e->getMessage());
            $result = ['pass' => true, 'score' => 5, 'issues' => [], 'suggestion' => ''];
        }

        $retry_happened    = false;
        $retry_prompt      = null;
        $prompt_suggestion = null;

        if (empty($result['pass'])) {
            $retry_happened = true;
            $retry_prompt   = (string) ($result['suggestion'] ?? '');

            try {
                $retry = (new BlockChooserAgent($signals, $retry_prompt))
                    ->chat('Content: ' . $data['content']);

                if (! empty($retry['blocks']) && is_array($retry['blocks'])) {
                    $data['chosen_blocks'] = $retry['blocks'];

                    $retry_score = $scorer->chat($this->build_scorer_prompt(
                        $data['content'],
                        $catalogue,
                        $data['chosen_blocks']
                    ));

                    if (empty($retry_score['pass']) && $retry_prompt !== '') {
                        $prompt_suggestion = $retry_prompt;
                    }
                }
            } catch (\Throwable $e) {
                error_log('AI by Roadmap: retry failed — ' . $e->getMessage());
            }
        }

        EvaluationLogger::log([
            'post_id'           => $data['post_id'] ?? null,
            'input_content'     => $data['content'],
            'content_signals'   => $signals,
            'chosen_blocks'     => $data['first_attempt_blocks'] ?? [],
            'scorer_result'     => $result,
            'retry_happened'    => $retry_happened,
            'retry_prompt'      => $retry_prompt,
            'prompt_suggestion' => $prompt_suggestion,
            'final_blocks'      => $data['chosen_blocks'],
        ]);

        return $data;
    }

    public function fill_blocks(array $data): array
    {
        // A locked template made entirely of fixed rows leaves nothing to fill.
        if (empty($data['chosen_blocks'])) {
            $data['filled_blocks'] = [];
            return $data;
        }

        $agent  = new PageFillerAgent($data['chosen_blocks']);

        $prompt = 'Content:' . "\n" . $data['content'];
        if (! empty($data['target_audience'])) {
            $prompt .= "\n\nTarget Audience: " . $data['target_audience'];
        }

        $page_data = $agent->chat($prompt);

        // The LLM populates ai_content per slot with the verbatim slice of
        // source content it used for THAT block (per the page schema), so
        // we just pass fields through. Fallback to empty string if missing.
        $filled = [];
        foreach ($data['chosen_blocks'] as $index => $block) {
            $key    = "{$index}_{$block['type']}";
            $fields = (array) ($page_data[$key] ?? []);
            $fields[Plugin::AI_CONTENT_FIELD] = (string) ($fields[Plugin::AI_CONTENT_FIELD] ?? '');

            $filled[] = [$block['type'] => $fields];
        }

        $data['filled_blocks'] = $filled;
        return $data;
    }

    public function transform_blocks(array $data): array
    {
        $blocks = [];
        foreach ($data['filled_blocks'] as $block_data) {
            $blocks[] = $this->transformer->convert($block_data);
        }

        // Locked CPT: put the template's fixed rows (synced patterns etc.) back
        // at their positions so the saved content matches the template exactly.
        if (! empty($data['template_rows'])) {
            $blocks = CptTemplate::merge_fixed_rows($data['template_rows'], $blocks);
        }

        $data['blocks'] = $blocks;
        return $data;
    }

    /**
     * @param array<string, string>             $catalogue
     * @param array<int, array<string, mixed>>  $blocks
     */
    private function build_scorer_prompt(string $content, array $catalogue, array $blocks): string
    {
        $catalogue_lines = [];
        foreach ($catalogue as $type => $description) {
            $catalogue_lines[] = '  - ' . $type . ': ' . $description;
        }

        $block_lines = array_map(
            static fn(array $b): string => '  - ' . ($b['type'] ?? '') . ': ' . ($b['intent'] ?? ''),
            $blocks
        );

        return implode("\n", array_filter([
            'Source Content:',
            $content,
            '',
            'Available Block Catalogue (type: when to use it):',
            implode("\n", $catalogue_lines),
            '',
            'Chosen Blocks:',
            implode("\n", $block_lines),
            '',
            'Evaluate whether each chosen block has clear support in the source content, based on its catalogue description.',
        ]));
    }
}
