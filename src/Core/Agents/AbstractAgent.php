<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Core\Agents;

use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WP_Ability;
use WP_AI_Client_Ability_Function_Resolver;
use WP_Error;

/**
 * Base class for every agent. Child classes describe:
 *   - The system prompt (instructions()).
 *   - The desired structured-output JSON schema (output_schema()).
 *   - The ability IDs the LLM may invoke as tools (tool_ability_ids()).
 *
 * The base class wires those into php-ai-client (shipped in WP core under
 * /wp-includes/ai-client/) and drives the tool-use loop — call model, run any
 * returned FunctionCalls, append FunctionResponses, repeat until the model
 * returns a plain message.
 *
 * Theme/plugin code can filter the system prompt and tool list via:
 *   - ai_by_roadmap_filter_instructions / ai_by_roadmap_filter_instructions_{Class}
 *   - ai_by_roadmap_filter_tools        / ai_by_roadmap_filter_tools_{Class}
 */
abstract class AbstractAgent
{
    private const MAX_TOOL_TURNS = 8;

    abstract protected function base_instructions(): string;

    /** @return array<string, mixed> JSON schema fragment, OpenAI-strict shape (top-level object with properties + required). */
    abstract protected function output_schema(): array;

    /** @return string[] Ability IDs to expose as LLM tools. */
    protected function tool_ability_ids(): array
    {
        return [];
    }

    public function instructions(): string
    {
        $prompt = $this->base_instructions();
        $prompt = (string) apply_filters('ai_by_roadmap_filter_instructions', $prompt, $this);
        $prompt = (string) apply_filters('ai_by_roadmap_filter_instructions_' . static::class, $prompt, $this);
        return $prompt;
    }

    /**
     * @return string[]
     */
    public function tools(): array
    {
        $ids = $this->tool_ability_ids();
        $ids = (array) apply_filters('ai_by_roadmap_filter_tools', $ids, $this);
        $ids = (array) apply_filters('ai_by_roadmap_filter_tools_' . static::class, $ids, $this);
        return array_values(array_filter(array_map('strval', $ids)));
    }

    /**
     * Run the agent. Returns the model's final structured response as a decoded array.
     *
     * @param  string $user_message The user prompt content.
     * @return array<string, mixed> The parsed JSON output.
     * @throws \RuntimeException If the model returns no parseable JSON within MAX_TOOL_TURNS.
     */
    public function chat(string $user_message): array
    {
        $abilities = $this->resolve_abilities($this->tools());
        $resolver  = new WP_AI_Client_Ability_Function_Resolver(...$abilities);

        // The SDK's PromptBuilder constructor accepts a list of Messages and
        // uses them verbatim as the conversation. `with_history()` PREPENDS,
        // which would corrupt role ordering across tool-call turns, so we
        // rebuild the builder each turn with the full history instead.
        $history = [new UserMessage([new MessagePart($user_message)])];

        for ($turn = 0; $turn < self::MAX_TOOL_TURNS; $turn++) {
            $builder = wp_ai_client_prompt($history);
            $builder->using_system_instruction($this->instructions());
            $builder->as_json_response($this->output_schema());
            if (! empty($abilities)) {
                $builder->using_abilities(...$abilities);
            }

            $result = $builder->generate_text_result();
            if ($result instanceof WP_Error) {
                throw new \RuntimeException('AI call failed: ' . $result->get_error_message());
            }

            $message = $result->toMessage();

            if ($resolver->has_ability_calls($message)) {
                $history[] = $message;
                $history[] = $resolver->execute_abilities($message);
                continue;
            }

            return $this->parse_json_message($message);
        }

        throw new \RuntimeException(sprintf(
            'Agent %s exceeded %d tool-call turns without returning a final response.',
            static::class,
            self::MAX_TOOL_TURNS
        ));
    }

    /**
     * @param  string[] $ids
     * @return WP_Ability[]
     */
    private function resolve_abilities(array $ids): array
    {
        $abilities = [];
        foreach ($ids as $id) {
            $ability = wp_get_ability($id);
            if ($ability instanceof WP_Ability) {
                $abilities[] = $ability;
            }
        }
        return $abilities;
    }

    /**
     * Extract the JSON payload from the model's final message.
     *
     * @return array<string, mixed>
     */
    private function parse_json_message($message): array
    {
        $text = '';
        foreach ($message->getParts() as $part) {
            if ($part->getType()->isText()) {
                $text .= $part->getText();
            }
        }

        $text    = trim($text);
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $text, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        throw new \RuntimeException(sprintf(
            'Agent %s returned a non-JSON response: %s',
            static::class,
            mb_substr($text, 0, 240)
        ));
    }
}
