<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Core\Agents;

use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Files\Enums\FileTypeEnum;
use WP_Error;

/**
 * Analyses an uploaded image and returns SEO filename + description +
 * suggested website usage.
 *
 * Overrides the parent chat loop because it sends a file (image bytes) to the
 * model rather than a plain text prompt — and uses a vision-capable model.
 */
final class MediaAnalyzerAgent extends AbstractAgent
{
    protected function output_schema(): array
    {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['seo_filename', 'description', 'usage_suggestion'],
            'properties'           => [
                'seo_filename'     => [
                    'type'        => 'string',
                    'description' => 'SEO-friendly filename without extension, lowercase words separated by hyphens.',
                ],
                'description'      => [
                    'type'        => 'string',
                    'description' => 'A concise, accurate description of what is in the image.',
                ],
                'usage_suggestion' => [
                    'type'        => 'string',
                    'description' => 'One sentence describing where this image would best be used on a website.',
                ],
            ],
        ];
    }

    protected function base_instructions(): string
    {
        return <<<PROMPT
You are an AI assistant that analyzes images for use on websites. You produce structured metadata that helps content editors and AI page-builders find and use the right images.

Steps:
1. Examine the image carefully.
2. Generate a short, descriptive SEO-friendly filename (no extension, lowercase, hyphens only).
3. Write a concise description of what is depicted in the image.
4. Write one sentence suggesting where on a website this image would best be used.

Output:
- Return valid JSON matching the provided schema.
- seo_filename: lowercase words joined by hyphens, no extension, no special characters.
- description: 1-3 sentences describing the image content factually.
- usage_suggestion: one sentence on the ideal website placement (e.g. hero banner, team section, product gallery).
PROMPT;
    }

    /**
     * @param  string $file_path  Absolute path to the image file on disk.
     * @return array<string, mixed>
     */
    public function analyze_file(string $file_path): array
    {
        $mime_type = mime_content_type($file_path) ?: 'image/jpeg';
        $data      = base64_encode((string) file_get_contents($file_path));
        $file      = File::fromBase64Data($data, $mime_type, FileTypeEnum::inline());

        $builder = wp_ai_client_prompt();
        $builder->using_system_instruction($this->instructions());
        $builder->as_json_response($this->output_schema());
        $builder->with_text('Analyze this image and return the structured metadata.');
        $builder->with_file($file, $mime_type);

        $result = $builder->generate_text_result();
        if ($result instanceof WP_Error) {
            throw new \RuntimeException('Image analysis failed: ' . $result->get_error_message());
        }

        $text    = trim($result->toText());
        $decoded = json_decode($text, true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('Image analyzer returned non-JSON: ' . mb_substr($text, 0, 240));
        }

        return $decoded;
    }
}
