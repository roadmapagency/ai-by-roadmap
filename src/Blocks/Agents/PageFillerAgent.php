<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks\Agents;

use Roadmap\AiByRoadmap\Blocks\SchemaGenerator;
use Roadmap\AiByRoadmap\Core\Abilities\SearchMedia;
use Roadmap\AiByRoadmap\Core\Agents\AbstractAgent;
use Roadmap\AiByRoadmap\Core\AI\ModelRouter;

/**
 * Fills an entire page of blocks in a single call. Receives a combined
 * schema built from every chosen block so the model can distribute content
 * evenly across the page.
 */
final class PageFillerAgent extends AbstractAgent
{
    protected function task_type(): string
    {
        return ModelRouter::TASK_FILL_PAGE;
    }

    /**
     * @param array<int, array{type:string,intent:string}> $chosen_blocks
     */
    public function __construct(private readonly array $chosen_blocks)
    {
    }

    protected function output_schema(): array
    {
        return SchemaGenerator::page_schema($this->chosen_blocks);
    }

    protected function tool_ability_ids(): array
    {
        return [SearchMedia::ID];
    }

    protected function base_instructions(): string
    {
        $block_list = [];
        foreach ($this->chosen_blocks as $i => $block) {
            $block_list[] = '  ' . ($i + 1) . '. ' . $block['type'] . ' — ' . ($block['intent'] ?? '');
        }
        $block_list_str = implode("\n", $block_list);

        return <<<PROMPT
You are a web content specialist filling an entire WordPress page with Gutenberg blocks.
You have been given the full page source content and a JSON schema with one slot per block.
Your job is to distribute the source content intelligently across all blocks so the finished page looks visually balanced and professional.
The page block order is fixed. Fill each slot with the portion of content that best matches its intent.

The page layout is:
{$block_list_str}

Steps:
- Read all of the source content before filling any block.
- Distribute content so that no single block is overloaded — each block should feel self-contained.
- Never repeat the same sentence or idea in two different blocks.
- For every block, also populate its `ai_content` field with the VERBATIM slice of the source content you drew from for that block — exact sentences/phrases, no paraphrasing, no summarisation, no overlap with sibling blocks. This is used for later block swaps, so accuracy matters more than completeness. If a block legitimately needs no source content (e.g. a generic CTA), return an empty string.
- Content density rules:
  - title / overline fields: 3–7 words maximum.
  - subtext / description fields (non-repeater): 1–2 sentences maximum.
  - WYSIWYG / description fields inside repeater rows: 1 short sentence each; keep length consistent across sibling rows.
  - Button text fields: 2–4 words maximum.
- Icon selection rules:
  - When an icon is needed, use any icon-search tool that has been made available to you.
  - Search by concept, not the literal text being displayed.
- Image rules:
  - Use the search-media tool to find an appropriate image attachment ID.
  - Pass a descriptive keyword (e.g. "fibre cable", "family at home") rather than a generic term.
- If asked for a URL and unsure, use #.
- Strip all emojis from the text.
- If a field does not support multiple values but the source has several, pick the single best option.

Output:
- Return a single JSON object matching the full-page schema.
- Every block slot in the schema must be filled — do not omit any.
PROMPT;
    }
}
