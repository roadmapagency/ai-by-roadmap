# Changelog

## 0.4.0 — unreleased

### Added
- **The plugin↔theme contract now lives in the plugin** (`src/Theme/`, `src/Icons/`). A theme opts in with
  `add_theme_support('ai-by-roadmap', ['contract' => 1, 'min_plugin' => …, 'icons' => […], 'skip_blocks' => […]])`.
  The plugin then provides what forks used to copy in `AIForGutenbergProvider.php`:
  - ACF→JSON-schema conversion for every block (`SchemaConverter`, a verbatim port)
  - the Source Content (`ai_content`) field, with the same key and position as before
  - icon search as `ai-by-roadmap/search-icons`, through an `IconProvider` (Lucide, local; or Font Awesome),
    added to the page- and block-filler agents
- A contract/version handshake shows an admin notice on a mismatch.
- Themes that still ship their own provider (`roadmap_starter_register_blocks_with_ai`) are detected, and the plugin
  stays out of their way.
- **Roadmap Hub** (`src/Hub/`, Settings › Roadmap Hub). Connects the site to the Roadmap WordPress Hub worker, so staff
  reach every client site from Claude through one connector, signed in with Microsoft, without application passwords.
  - *Connect* sends you to the hub to sign in and pick the client. The hub then calls `POST ai-by-roadmap/v1/hub/connect`
    with the single-use code this site issued, and the site pins the hub's Ed25519 public key.
  - Hub requests carry a 60-second EdDSA token naming the staff member. `HubAuth` verifies it (key, issuer, audience =
    this `home_url`, lifetime, jti replay) and runs the request as the WordPress user with that email or one of its
    staff-domain aliases. It only applies to the MCP endpoint and `GET ai-by-roadmap/v1/hub/whoami`.
  - Each site reports an environment (`production`/`staging`/`development`), taken from `WP_ENVIRONMENT_TYPE` unless
    overridden on the Hub page, so Claude can tell staging from production.
  - A database copied to another environment keeps the old connection but is refused (wrong audience) until it is
    reconnected, and the Hub page says so.
  - The hub URL comes from `AI_BY_ROADMAP_HUB_URL`, the Hub page, or `Hub::DEFAULT_URL`.

### Fixed
- The MCP server reported version 0.2.0 regardless of the plugin version; it now reports `VERSION`.

## 0.3.4 — 2026-10-07

### Added
- Automatic updates from this repo's GitHub Releases (`src/Updater.php`, Plugin Update Checker 5). A newer
  release tag shows up in wp-admin as a normal plugin update and installs the release's `ai-by-roadmap.zip`.
  The `Update URI` header stops WordPress from checking WordPress.org. No token is needed: the repo is public,
  and `AI_BY_ROADMAP_GITHUB_TOKEN` is optional, to lift GitHub's API rate limit. The updater is off when the
  plugin directory is a git checkout.

### Changed
- Removed client-specific references from the README and code comments.

## 0.3.3 — 2026-10-07

### Fixed
- Installing GitHub's auto-generated "Source code" zip next to an existing install loaded a second copy of the
  plugin and caused a fatal error (`Cannot declare class …Jetpack\Autoloader…`). A second copy now stands down
  and shows an admin notice instead.
- The release asset is now named `ai-by-roadmap.zip` (no version), so it can't be confused with the "Source code"
  download. The release notes say which file to install.

## 0.3.2 — 2026-10-07

### Tooling
- Pushing a `v*` tag now publishes a GitHub Release with an installable `ai-by-roadmap-<version>.zip`
  (`.github/workflows/release.yml` → `bin/build-zip.sh`). The release notes come from this file. The build fails if
  the tag doesn't match the plugin version.

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
