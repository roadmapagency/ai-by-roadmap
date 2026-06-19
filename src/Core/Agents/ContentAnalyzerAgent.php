<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Core\Agents;

/**
 * Reads raw website content and reports its structure (section count + a short
 * description per section). Used by the Orchestrator as a hard constraint on
 * the block-chooser step.
 */
final class ContentAnalyzerAgent extends AbstractAgent
{
    protected function output_schema(): array
    {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['section_count', 'detected_sections'],
            'properties'           => [
                'section_count'     => [
                    'type'        => 'integer',
                    'description' => 'Number of distinct content sections detected.',
                ],
                'detected_sections' => [
                    'type'        => 'array',
                    'description' => 'Short description of each detected section, in reading order.',
                    'items'       => ['type' => 'string'],
                ],
            ],
        ];
    }

    protected function base_instructions(): string
    {
        return <<<PROMPT
You are a content analyst. Your job is to read raw website content and identify its structure.
You must be conservative — only report what is explicitly present in the content. Do NOT infer or invent sections that are not directly stated.

Steps:
1. Read the full content carefully.
2. Count how many distinct sections or ideas are present.
3. For each section, write a short description of what it contains (e.g. "hero intro with CTA button", "team description", "FAQ section").

Output:
- Return valid JSON matching the provided schema.
- section_count: integer — count only clearly distinct sections.
- detected_sections: array of short descriptions, one per detected section, in reading order.
PROMPT;
    }
}
