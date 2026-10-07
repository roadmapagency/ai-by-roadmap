# Changelog

## 0.3.1 — 2026-10-07

### Fixed
- Block schemas with numeric ACF choice keys (e.g. SplitContent `subgrid_columns`) declared `type: string` with
  integer enum values. Gemini rejected these with a 400 error, which broke `fill-block`, `fill-page` and
  `compose-page` on Google. `BlockRegistry` now casts string-typed enum values to strings.
- Note: multi-turn tool calling on Gemini 3 also needs **AI Provider for Google ≥ 1.2.0**, which round-trips
  `thoughtSignature`.

## 0.3.0 — 2026-10-07

### Added
- OpenRouter Auto Router support (`Core/AI/ModelRouter`). When the OpenRouter connector is configured, agent
  requests go to `openrouter/auto`. Each task type sends a cost tier (classify=low, judge/fill_block/vision=medium,
  fill_page=high) and an allowlist of model families. If a routed call fails, it is retried once on the default
  provider. Filters: `ai_by_roadmap_use_openrouter`, `ai_by_roadmap_router_allowed_models`,
  `ai_by_roadmap_router_profiles`.
- A "Model routing" section on Settings → AI by Roadmap showing recent routing decisions.

### Fixed
- `MediaAnalyzerAgent` called the non-existent `File::fromBase64Data()`, so image analysis failed on every
  provider. It now builds a data-URI `File`.
- Parallel tool calls on OpenAI-compatible providers: the combined function-response message is split into one
  message per response when routing through OpenRouter.
