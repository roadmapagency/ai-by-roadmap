<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks\Agents;

use Roadmap\AiByRoadmap\Blocks\BlockRegistry;
use Roadmap\AiByRoadmap\Blocks\SchemaGenerator;
use Roadmap\AiByRoadmap\Core\Agents\AbstractAgent;
use Roadmap\AiByRoadmap\Core\EvaluationLogger;
use Roadmap\AiByRoadmap\Core\AI\ModelRouter;

/**
 * Picks an ordered list of {type, intent} block selections for a page.
 * Hard layout rules + negative-example injection from past 👎-rated runs
 * live in the system prompt.
 */
final class BlockChooserAgent extends AbstractAgent
{
    protected function task_type(): string
    {
        return ModelRouter::TASK_CLASSIFY;
    }

    /**
     * @param array<string, mixed> $signals       Output of ContentAnalyzerAgent.
     * @param string               $retry_reason  Suggestion from BlockScorerAgent on the retry pass.
     */
    public function __construct(
        private readonly array $signals = [],
        private readonly string $retry_reason = ''
    ) {
    }

    protected function output_schema(): array
    {
        return SchemaGenerator::block_id_schema();
    }

    protected function base_instructions(): string
    {
        $descriptions = BlockRegistry::get_block_descriptions();
        $catalogue    = [];
        foreach ($descriptions as $id => $desc) {
            $catalogue[] = "  - {$id}: {$desc}";
        }

        $signal_lines = [];
        if (! empty($this->signals)) {
            $section_count     = $this->signals['section_count']     ?? null;
            $detected_sections = $this->signals['detected_sections'] ?? [];

            $signal_lines[] = 'Content analysis (use as hard constraints):';
            $signal_lines[] = '  - Distinct content sections: ' . ($section_count ?? 'unknown');
            $signal_lines[] = '  - Detected sections:';
            if (is_int($section_count)) {
                $signal_lines[] = '  - Choose at most ' . ($section_count + 1) . ' blocks total.';
            }
            foreach ((array) $detected_sections as $section) {
                $signal_lines[] = '    * ' . $section;
            }
            $signal_lines[] = '  - Only choose blocks whose catalogue description matches one of the detected sections above.';

            if ($this->retry_reason !== '') {
                $signal_lines[] = 'CORRECTION REQUIRED: ' . $this->retry_reason;
            }
        }

        $feedback_lines = [];
        foreach (EvaluationLogger::get_negative_examples(3) as $example) {
            $blocks  = json_decode((string) $example['final_blocks'], true) ?: [];
            $types   = implode(', ', array_column($blocks, 'type'));
            $excerpt = wp_trim_words((string) $example['input_content'], 20, '…');
            $note    = ! empty($example['feedback_notes']) ? ' — Reason: ' . $example['feedback_notes'] : '';
            $feedback_lines[] = '  - Content like "' . $excerpt . '" → chose [' . $types . '] (wrong)' . $note;
        }
        if ($feedback_lines) {
            array_unshift($feedback_lines, 'Patterns previously marked INCORRECT by users (avoid these):');
        }

        $background = [
            'You are an AI Agent specialized in assembling WordPress Gutenberg page layouts.',
            'You will be given source content and must decide which blocks to use and in what order.',
            'Available blocks and their purposes:',
            implode("\n", $catalogue),
        ];

        $steps = array_merge(
            [
                'Read the full source content before making any decisions.',
                'Choose the block types that best match the content and assemble a visually logical page flow.',
            ],
            $signal_lines,
            $feedback_lines,
            [
                'Page layout rules you MUST follow:',
                '  1. If there is hero-style content (main headline / tagline), always place a hero block FIRST.',
                '  2. Callout / CTA blocks must appear in the LOWER THIRD of the page (never blocks 1, 2, or 3). Use at most 2 per page and never place two CTA blocks adjacent to each other.',
                '  3. Do not place the same block type back-to-back. Vary the block types to create visual rhythm.',
                '  4. Testimonial blocks work best AFTER the main value proposition has been established — place them toward the bottom.',
                '  5. FAQ blocks should be near the END of the page, before the final CTA if one exists.',
                '  6. Distribute content evenly — no single block should carry more than one main idea.',
                '  7. Prefer repeater-based blocks (Key Features, Image Stacked) when the source has a list of parallel items.',
                'For each chosen block write a short intent note (one sentence) describing what content it should contain.',
            ]
        );

        $output = [
            'Return a JSON object with a top-level "blocks" array in page order.',
            'Each item must have "type" (block ID) and "intent" (one-sentence description).',
            'Do NOT include the source content itself — only the intent note.',
        ];

        return "Background:\n" . implode("\n", $background)
            . "\n\nSteps:\n" . implode("\n", $steps)
            . "\n\nOutput:\n" . implode("\n", $output);
    }
}
