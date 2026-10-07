<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Icons;

/**
 * An icon set the AI can pick from: which ACF field types hold its icons,
 * how such a field is described to the model, and how to search it.
 */
interface IconProvider
{
    public function id(): string;

    /** @return string[] ACF field types that store icons from this set. */
    public function field_types(): array;

    /**
     * @param  array<string, mixed> $field ACF field definition.
     * @return array<string, mixed>        JSON schema fragment.
     */
    public function schema(array $field): array;

    /** @return array<string, mixed> Matches the search-icons ability's output_schema(). */
    public function search(string $query): array;

    /** @return array<string, mixed> */
    public function output_schema(): array;
}
