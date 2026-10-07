<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks\Agents;

use Roadmap\AiByRoadmap\Core\Agents\AbstractAgent;
use Roadmap\AiByRoadmap\Core\AI\ModelRouter;

/**
 * Audits a chosen block selection against the source content. When `pass`
 * is false the Orchestrator runs BlockChooserAgent a second time with the
 * scorer's `suggestion` injected as a correction prompt.
 */
final class BlockScorerAgent extends AbstractAgent
{
    protected function task_type(): string
    {
        return ModelRouter::TASK_JUDGE;
    }

    protected function output_schema(): array
    {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['pass', 'score', 'issues', 'suggestion'],
            'properties'           => [
                'pass'       => [
                    'type'        => 'boolean',
                    'description' => 'True if every chosen block has clear support in the source content.',
                ],
                'score'      => [
                    'type'        => 'integer',
                    'description' => 'Quality score 1-10. 10 = every block perfectly matches the source.',
                ],
                'issues'     => [
                    'type'        => 'array',
                    'items'       => ['type' => 'string'],
                    'description' => 'Specific problems — name the block type and what is missing from the source.',
                ],
                'suggestion' => [
                    'type'        => 'string',
                    'description' => 'One clear instruction for correcting the block selection on retry.',
                ],
            ],
        ];
    }

    protected function base_instructions(): string
    {
        return <<<PROMPT
You are a block-selection auditor for a WordPress page builder. Your job is to verify that every chosen block has clear justification in the source content.

You are given the source content, a catalogue of available blocks with their descriptions (explaining when each block should be used), and the list of blocks that were chosen.

Steps:
- For each chosen block: look at its description (which says when it should be used) and check whether the source content contains material that matches.
- A block FAILS if: (1) its description describes content type not present in the source, or (2) more blocks were chosen than the source warrants.
- A block PASSES if the source content clearly supports the block's stated purpose.

Output:
- Return valid JSON matching the provided schema.
- pass: true only if every single chosen block passes the check.
- score: integer 1-10 reflecting how well the chosen blocks are supported by the source content.
- issues: array of strings describing each failing block — name the block type and explain what is missing from the source.
- suggestion: one concise instruction telling the next attempt how to correct the block selection.
PROMPT;
    }
}
