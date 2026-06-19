<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks\Agents;

use Roadmap\AiByRoadmap\Blocks\SchemaGenerator;
use Roadmap\AiByRoadmap\Core\Abilities\SearchMedia;
use Roadmap\AiByRoadmap\Core\Agents\AbstractAgent;

/**
 * Fills a single block's content. Used by the Block Swapper, which lets an
 * editor remap one AI-generated block to a different type while keeping the
 * original source content.
 *
 * Tools: search-media (Core). The theme may filter in additional tools
 * (e.g. roadmap-starter/search-icons) via ai_by_roadmap_filter_tools_*.
 */
final class BlockFillerAgent extends AbstractAgent
{
    public function __construct(private readonly string $block_id)
    {
    }

    public function block_id(): string
    {
        return $this->block_id;
    }

    protected function output_schema(): array
    {
        return SchemaGenerator::strict_object([
            $this->block_id => SchemaGenerator::block_schema($this->block_id),
        ]);
    }

    protected function tool_ability_ids(): array
    {
        return [SearchMedia::ID];
    }

    protected function base_instructions(): string
    {
        return <<<PROMPT
You are a website specialist remapping existing block content to a new block type.
Do your best to match the content provided to the JSON output schema, changing the content as little as possible.
Take into account the target audience when analysing and generating content.

Steps:
- Analyse the provided content and fill the block schema fields.
- Content density rules:
  - title / overline fields: 3–7 words maximum.
  - subtext / description fields (non-repeater): 1–2 sentences maximum.
  - WYSIWYG / description fields inside repeater rows: 1 short sentence each; keep length consistent across sibling rows.
  - Button text fields: 2–4 words maximum.
- If asked for a URL but unsure, use #.
- If asked for an image, call the search-media tool to find an attachment ID.
- Strip all emojis from the supplied text.
- If the source has multiple values but the block does not support an array, pick the single best option.
- Icon selection rules:
  - When an icon field is needed, use any icon-search tool that has been made available to you.
  - Search by concept, not the literal text being displayed (e.g. "shield" for protection, "rocket" for speed, "handshake" for partnership).

Output:
- Provide a JSON output of the block content matching the schema exactly.
PROMPT;
    }
}
