# Changelog

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
