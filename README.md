# AI by Roadmap

A WordPress plugin that exposes a curated set of **Abilities API** capabilities for assembling and editing pages of ACF Gutenberg blocks. Designed to be driven by LLMs (Claude Desktop, Claude Code, or any MCP client) for tasks like **migrating an external website's content into the Roadmap Starter theme**, but every ability is also callable via the standard WordPress REST API for in-editor UIs and scripted batch runs.

The same abilities are surfaced via three transports — input shape, output shape, and behaviour are identical between them (see [How agents connect](#how-agents-connect)):

- **REST API:** `POST /wp-abilities/v1/abilities/{name}/run`
- **MCP (recommended):** the `ai-by-roadmap-mcp` server at `/wp-json/ai-by-roadmap/mcp`, which also bridges Yoast SEO and core abilities.
- **MCP (generic):** the MCP Adapter's default server at `/wp-json/mcp/mcp-adapter-default-server`.

This README is also the operational manual for any **Claude skill** that drives the plugin. The sections below mirror what a skill needs to know: how to discover the active block catalogue, the recommended workflow for converting raw content to a draft page, and the input/output shapes for every ability.

---

## Requirements

- **WordPress 6.9+** (for the Abilities API and bundled `php-ai-client`)
- **PHP 8.1+**
- **Advanced Custom Fields Pro** with at least one block registered to the active theme (declared via `Requires Plugins`; WordPress blocks activation until it is installed and active)
- **MCP Adapter plugin** (`mcp-adapter`, also declared via `Requires Plugins`) — provides the MCP transport and the Abilities API bridge; the REST routes would work without it, but the plugin is built to be driven over MCP
- **MariaDB 11.7+** is optional. If present, image search is vector-ranked. On older versions, the plugin transparently degrades to keyword-only search; no other functionality is affected.

API credentials for the underlying LLM provider are managed by the shared WordPress AI settings shipped with `php-ai-client` — this plugin does not own its own credentials UI.

---

## Installation

1. Clone or copy the plugin into `wp-content/plugins/ai-by-roadmap/`.
2. From the plugin root, run `composer install` — this fetches Action Scheduler and the Jetpack Autoloader.
3. Activate the plugin from **Plugins** in wp-admin (or `wp plugin activate ai-by-roadmap`).
4. Open the shared WordPress AI settings and paste a provider API key (one-time, applies across every AI-capable plugin on the site).

The plugin creates three tables on activation: `{prefix}ai_generation_log`, `{prefix}ai_by_roadmap_jobs`, and (only on MariaDB 11.7+) `{prefix}ai_image_embeddings`. A daily WP-Cron job prunes the generation log to ~1,000 most recent rows.

The active theme contributes its block schemas via the `ai_by_roadmap_register_block` filter; without that wiring, `list-blocks` will return an empty catalogue. The Roadmap Starter theme already does this in [acf-blocks/AIForGutenbergProvider.php](../../themes/roadmap-starter/acf-blocks/AIForGutenbergProvider.php).

---

## How agents connect

WordPress core does **not** ship an MCP server. It ships the **Abilities API** (WordPress 6.9+): a registry every plugin
registers into with `wp_register_ability()`, plus REST routes to list and run them. MCP is layered on top by the
separate **MCP Adapter** plugin. That gives three ways in — all authenticate as a normal WordPress user (login cookie or
an Application Password), and an ability's own `permission_callback` applies on every path.

| Path | Endpoint | What an agent sees | Use it when |
|---|---|---|---|
| **Core REST** | `GET /wp-json/wp-abilities/v1/abilities`, `POST …/abilities/{name}/run` | Every ability with `show_in_rest` | Scripts, in-editor UIs, curl |
| **Our MCP server** (recommended) | `/wp-json/ai-by-roadmap/mcp` | Our public abilities as first-class tools with full schemas and workflow descriptions, **plus** bridged tools from other plugins when they are registered: `core/get-site-info`, Yoast's `yoast-seo/get-seo-scores`, `get-readability-scores`, `get-inclusive-language-scores`, and `get-/update-post-seo-data` once Yoast ships them | Any LLM agent driving this site |
| **Adapter default server** (generic) | `/wp-json/mcp/mcp-adapter-default-server` | Three meta-tools only — `discover-abilities`, `get-ability-info`, `execute-ability` — over every ability flagged `meta.mcp.public`, from any plugin | A client that must work on any WordPress site without knowing its plugins |

Our abilities set `meta.mcp.public = true` (via `Plugin::ability_meta()`), so they are reachable on the generic server
too; the fine-grained pipeline abilities (`fill-block`, `choose-blocks`, …) are deliberately REST-only. The tool list on
our server is filterable with `ai_by_roadmap_mcp_tools`.

**SEO.** Post-level SEO data lives in Yoast SEO. Three of our tools read and write it through Yoast's own API so
agents can do the pre-launch SEO pass: `ai-by-roadmap/get-post-seo`, `update-post-seo` (seo_title,
meta_description, focus_keyphrase, canonical, noindex/nofollow, Open Graph / Twitter overrides, cornerstone) and
`audit-seo` (site-wide work list: missing/too-long descriptions, long titles, missing keyphrases, noindex, duplicates).
Field names match Yoast's own forthcoming `yoast-seo/get-post-seo-data` / `update-post-seo-data` abilities, which are
bridged into our server the moment Yoast registers them.

Yoast registers its abilities — and builds the indexables its scores need — **only when `WP_ENVIRONMENT_TYPE` is
`production`**. For local/staging SEO work, drop a must-use plugin that returns true for the
`Yoast\WP\SEO\should_index_indexables` filter on non-production environments (see
`wp-content/mu-plugins/local-yoast-indexables.php` on The Newly dev site), then run **SEO → Tools → Optimize SEO
data** once so scores exist. Yoast's `get-seo-scores` / `get-readability-scores` / `get-inclusive-language-scores`
then appear as bridged tools.

---

## Model routing (OpenRouter Auto Router)

The plugin never names a model itself. Without OpenRouter, WordPress core picks the first model of the first
configured AI provider that can do the request. That provider is Anthropic → Google → OpenAI, in plugin load order.

When the **OpenRouter** connector is configured, every agent request goes to
[`openrouter/auto`](https://openrouter.ai/openrouter/auto) instead (`Core/AI/ModelRouter.php`). The OpenRouter
provider plugin does no routing of its own, and it doesn't declare JSON-schema/tool support, so core would never pick
it. The router passes the Auto Router a cost tier for each type of task, plus an allowlist of model families that
handle strict JSON output and tool calls reliably:

| Task | Agents | Cost tier |
|---|---|---|
| `classify` | ContentAnalyzer, BlockChooser | low |
| `judge` | BlockScorer | medium |
| `fill_block` | BlockFiller | medium |
| `fill_page` | PageFiller | high |
| `vision` | MediaAnalyzer | medium (vision-capable families only) |

- **Allowed models** (default): `anthropic/*`, `openai/gpt-5*`, `google/gemini-*`. The router also sends
  `provider.require_parameters = true`, so OpenRouter only uses endpoints that honour `response_format` and `tools`.
- **Fallback**: if a routed call fails, it is retried once on the default provider.
- **Embeddings** always use the default provider. The OpenRouter plugin has no embedding model.
- **Visibility**: **Settings → AI by Roadmap** lists the last routing decisions: task, tier, the model OpenRouter
  actually chose, tokens, and any errors.
- **Filters**:
  - `ai_by_roadmap_use_openrouter`: return false to switch routing off.
  - `ai_by_roadmap_router_allowed_models`: the default allowlist patterns.
  - `ai_by_roadmap_router_profiles`: the per-task `cost_tier` / `allowed_models`.

---

## Releasing

1. Bump the version in **both** places in `ai-by-roadmap.php` (the `Version:` header and `const VERSION`).
2. Add a `## x.y.z — YYYY-MM-DD` section to `CHANGELOG.md`.
3. Merge to `main`, then `git tag -a vX.Y.Z -m "…" && git push origin main vX.Y.Z`.

The **Release** GitHub Action then builds `ai-by-roadmap.zip` and attaches it to a GitHub Release whose notes
are that changelog section. The zip has a top-level `ai-by-roadmap/` folder and a `vendor/` rebuilt from
`composer.lock` with no dev dependencies. Upload it with **Plugins → Add New → Upload** to install or update.
- The build fails if the tag doesn't match the plugin version.
- To build the same zip locally, run `bin/build-zip.sh [vX.Y.Z]`. The output goes to `build/`.
- To (re)publish an existing tag, use **Actions → Release → Run workflow**.

---

## What the plugin is for

The plugin sits between **raw content** (typically scraped or copy-pasted from a legacy site) and **published WordPress pages** built from the Roadmap Starter theme's ACF blocks (Hero, FAQs, Image and Text, etc.).

Two roles, depending on the task:

1. **Composition** — given a chunk of source content for one page, decide which blocks to use, distribute the content across them, and create a draft WordPress page. This is the "easy button" for migrations.
2. **Refinement** — once a page exists as a draft, swap any block to a different type while preserving the original source content. This is iterative; the swap always re-uses the per-block source slice that was stored at creation time.

Every generated block carries its own `ai_content` field — the **verbatim slice of source content used to fill THAT block**. Swaps re-use that slice, so source fidelity is preserved across N swaps.

---

## Where to start: the discovery ability

Before composing anything, fetch the current block catalogue:

- **`ai-by-roadmap/list-blocks`** — returns every ACF block the active theme has registered for AI composition, including each block's ID (e.g. `acf/hero`), description (when to use it), and full JSON schema (fields, types, repeaters, choices).

This is the source of truth for what blocks exist. Don't assume the theme has any particular block; call this first.

---

## The primary workflow: compose a new page from source content

For a typical migration task ("here is the content from `oldsite.com/about` — turn it into a draft page"):

### Recommended path — one call, asynchronous

**`ai-by-roadmap/compose-page`** runs the full pipeline (analyze content → choose blocks → score & retry → fill → transform) in the background via Action Scheduler. It returns a `job_id` immediately.

Input:
```
{
  "content": "<raw page content as a single string>",
  "target_audience": "<optional, e.g. 'homeowners 35-55'>",
  "post_id": <optional integer — if omitted, a new draft page is created>,
  "replace_content": <boolean — only honoured when post_id is provided>,
  "title": "<optional title for the new draft page>"
}
```

Output:
```
{ "job_id": "uuid-string", "status": "queued" }
```

Then poll:

**`ai-by-roadmap/get-job-status`** with `{ "job_id": "..." }` until `status` is one of `done` / `failed`. On `done`, the result contains:
```
{
  "status": "done",
  "result": {
    "blocks": ["<!-- wp:acf/hero ... /-->", ...],
    "post_id": 42,
    "edit_link": "https://site/wp-admin/post.php?post=42&action=edit"
  }
}
```

**Polling cadence:** wait ~3-5 seconds between polls. The pipeline typically takes 30-90 seconds end-to-end. Don't poll faster than once per second — there's no benefit and it wastes the request budget.

### Behaviour notes for compose-page

- **No `post_id` supplied:** the job creates a new draft `page` (post status `draft`) and reports back `post_id` and `edit_link`. **This is the default for migrations.**
- **`post_id` + `replace_content: true`:** the job overwrites that post's `post_content` with the generated blocks. Use this when you want to populate an existing empty page.
- **`post_id` + `replace_content: false`:** blocks are generated but nothing is persisted. The blocks come back in the result for the caller to use however.
- **`target_audience`** is persisted as post meta on the resulting post, so subsequent operations on the same page (e.g. swaps) reuse it automatically.

### When NOT to use compose-page

`compose-page` is async and opinionated. Use the fine-grained abilities below when you need:

- Multi-page batch runs where you want to short-circuit on failures (use `choose-blocks` + `score-blocks` then decide whether to proceed)
- A specific composition that doesn't fit the standard pipeline (e.g. you already know the block order)
- Per-step transparency for debugging or A/B testing prompts

---

## The fine-grained pipeline (advanced)

These mirror the steps inside `compose-page`. Each is a single synchronous call.

### 1. `ai-by-roadmap/analyze-content`
Reads source content and reports its structure. Use as a precheck before composing.

Input: `{ "content": "..." }`
Output: `{ "section_count": 4, "detected_sections": ["hero intro with CTA", "features grid", "testimonials", "FAQ"] }`

### 2. `ai-by-roadmap/choose-blocks`
Picks an ordered list of `{type, intent}` block selections from the registered catalogue.

Input: `{ "content": "...", "signals": <output of analyze-content, optional> }`
Output: `{ "blocks": [{ "type": "acf/hero", "intent": "..." }, ...] }`

### 3. `ai-by-roadmap/score-blocks`
Audits a chosen block list against the source content. Returns pass/fail + suggestion.

Input: `{ "content": "...", "blocks": [{type, intent}, ...] }`
Output: `{ "pass": true|false, "score": 1-10, "issues": [...], "suggestion": "..." }`

### 4. `ai-by-roadmap/fill-page`
Given a chosen block list, fills every block in a single LLM call and returns the serialized ACF block markup. This is what produces the actual `<!-- wp:acf/... /-->` comments.

Input:
```
{
  "content": "...",
  "target_audience": "...",
  "blocks": [{ "type": "acf/hero", "intent": "..." }, ...]
}
```
Output: `{ "blocks": ["<!-- wp:acf/hero ... /-->", "<!-- wp:acf/faqs ... /-->", ...] }`

Caller is responsible for `wp_update_post` (or equivalent) if they want the blocks to land on a page.

### 5. `ai-by-roadmap/fill-block`
Single-block version. Generates ONE ACF block from source content. This is what the editor's "Swap with AI" sidebar uses.

Input: `{ "block_type": "acf/hero", "content": "...", "target_audience": "..." }`
Output: `{ "block_id": "block_xxx", "serialized": "<!-- wp:acf/hero ... /-->", "fields": {...} }`

---

## Media handling

Two abilities, plus an automatic background flow on every upload.

### Automatic (no ability needed)
When any image is uploaded to the media library, the plugin runs a vision model against it and writes:
- `post_title` and `post_name` → SEO-friendly filename
- `post_content` (the WP "Description" field) → factual description
- `_wp_attachment_image_alt` → alt text
- `_ai_seo_filename`, `_ai_description`, `_ai_usage_suggestion` → searchable meta
- A 1536-dim embedding in `{prefix}ai_image_embeddings` (only if MariaDB 11.7+)

This means images uploaded *before* a composition pass will be richly described and findable by the time `search-media` runs.

### `ai-by-roadmap/search-media`
Semantic + keyword search over the media library. Used by `fill-page` and `fill-block` internally when a block schema requests an image, but Claude can also call it directly to pre-select images for a page.

Input: `{ "query": "happy family outdoors", "limit": 5 }`
Output: `{ "candidates": [{ "id": 123, "title": "...", "description": "...", "usage": "..." }, ...] }`

Degrades to keyword-only on MariaDB versions older than 11.7. The fallback is transparent — same response shape.

### `ai-by-roadmap/analyze-media`
On-demand re-analysis of a single attachment. Useful when the auto-analysis failed (e.g. provider was rate-limited) or you want to refresh after a model upgrade.

Input: `{ "attachment_id": 123 }`
Output: `{ "success": true, "attachment_id": 123 }`

### `ai-by-roadmap/reindex-media`
Admin operation. Regenerates embeddings for every attachment that has AI metadata but no embedding row. Skipped automatically on older MariaDB.

Input: `{}`
Output: `{ "indexed": N, "vector_supported": true|false }`

---

## Target audience

Two abilities for per-post audience tracking. The compose pipeline reads/writes these automatically, so most callers won't touch them — they exist for batch migrations where the audience is constant across many pages.

- **`ai-by-roadmap/get-target-audience`** — `{ "post_id": N }` → `{ "target_audience": "..." }`
- **`ai-by-roadmap/set-target-audience`** — `{ "post_id": N, "target_audience": "..." }` → `{ "success": true }`

---

## Feedback loop

- **`ai-by-roadmap/rate-generation`** — attaches a 👍 (+1) or 👎 (-1) rating with optional notes to a logged generation run. Negative ratings are injected as cautionary examples into future `choose-blocks` prompts.

Input: `{ "id": N, "rating": -1|1, "notes": "<optional>" }`
Output: `{ "success": true }`

The `id` comes from the `wp_ai_generation_log` table. In batch migrations, Claude can self-score its own runs to improve future generations on the same site.

---

## Theme-contributed ability

The Roadmap Starter theme registers one ability of its own:

### `roadmap-starter/search-icons`
Find a Font Awesome icon by concept. Used by `fill-page` and `fill-block` when a block schema includes an icon field. Claude rarely needs to call this directly — it's a tool used by the page-filling agents internally — but it can be invoked when prepping content.

Input: `{ "query": "shield" }` *(search by concept, not literal display text; do not include the `fa-` prefix)*
Output: `{ "style": "solid", "id": "shield", "label": "Shield", "unicode": "f132" }`

Key rule: **search by concept** — e.g. `"shield"` for protection, `"rocket"` for speed, `"handshake"` for partnership. Searching for the literal text being displayed produces worse results.

---

## End-to-end migration recipe

For converting an external website page to a draft Roadmap Starter page, the canonical sequence is:

1. **(Optional, once per site)** Call `ai-by-roadmap/list-blocks` so you know what's available.
2. **(Optional)** Call `ai-by-roadmap/set-target-audience` against a parent / draft post if the audience is consistent across the migration.
3. For each source page:
   1. Call `ai-by-roadmap/compose-page` with the source content (and `title` matching the source page's `<h1>` or URL slug). Omit `post_id` to get a fresh draft.
   2. Poll `ai-by-roadmap/get-job-status` every 3-5 seconds.
   3. On `status: done`, capture `result.post_id` and `result.edit_link`. Surface the edit link to the user.
   4. On `status: failed`, the `error` field has the message. Report it; don't auto-retry without changing input — the same content + prompts will produce the same failure.
4. **(Optional)** After review, the editor may swap individual blocks via the "Swap with AI" sidebar — that uses `ai-by-roadmap/fill-block` under the hood with the block's own `ai_content` slice. Claude doesn't need to do anything for this; it Just Works™.

---

## Constraints Claude should know about

- **`compose-page` is async.** Never block waiting for it inline; always go through `get-job-status`. Typical run is 30-90 seconds.
- **One LLM call per ~5 minutes max.** The SDK request timeout is 300s.
- **The block catalogue is theme-dependent.** Don't hardcode `acf/hero` etc. — always discover via `list-blocks` first if you don't already know the active theme's blocks.
- **`ai_content` on each block is the per-block source slice.** Do not overwrite it manually; it's how subsequent swaps stay accurate.
- **Locked post types may contain fixed rows.** A rigid CPT's template can include rows that are not ACF blocks — typically a synced pattern (`core/block` with a `ref`) shared across every entry. `list-post-types` reports each template row with `fixed: true|false` and a separate `fillable_blocks` list. Supply only the fillable blocks to `assemble-page`, in order; the server inserts the fixed rows at their template positions (and `compose-page` does the same automatically when `post_id` belongs to a locked type). Passing a fixed row yourself is rejected. The pattern's own content is edited once, in the pattern.
- **Two block counts, one index space.** `find-posts.block_count` (and `get-post-blocks.total_blocks`) count every named block, fixed rows included. Every `block_index` / `at_index` parameter, and `get-post-blocks.blocks[].index`, counts **ACF blocks only** — fixed rows are skipped and do not consume an index (`acf_block_count` / `acf_blocks`). `get-post-blocks` lists the skipped rows under `fixed_rows` so the two numbers reconcile.
- **`assemble-page` will not overwrite a page that has content.** A `post_id` whose post already has blocks returns `post_not_empty` unless you pass `replace_content: true`. Edit existing pages with the block tools below instead.
- **Structural edits respect the template lock.** `insert-block` / `remove-block` are refused on `template_lock: all` and `insert` types; `move-block` is refused only on `all`. `update-block-fields` works everywhere.
- **Permission gates.** Most abilities require `edit_posts` (or `upload_files` for media, `manage_options` for admin operations like `reindex-media`). The MCP transport runs as the authenticated WP user.
- **Vector search degrades gracefully** on MariaDB <11.7 to keyword-only. The API surface doesn't change, just the relevance.
- **Generation log table is pruned daily** to ~1,000 most recent rows. Don't rely on old `id`s for `rate-generation` more than a few days after the run.

---

## Maintaining an existing page (partial edits)

The compose/assemble tools build whole pages. Once a page exists, change it with the partial-edit tools — they
re-serialize only the block you touch and leave every other block byte-identical.

1. `ai-by-roadmap/find-posts` with the route or title → `post_id`, `modified`, `acf_block_count`.
2. `ai-by-roadmap/get-post-blocks` with `include_fields: true` (add `block_index` to fetch one block) → each block's
   `index`, `block_type`, `label`, and `fields` in the same human shape `list-blocks` describes (repeaters as arrays of
   rows, images as attachment IDs). `ai_content` is omitted unless `include_ai_content: true`.
3. Pick the tool:
   - **Copy / link / setting change** → `ai-by-roadmap/update-block-fields` with only the fields to change
     (merge-patch; repeaters and groups are replaced whole). Unknown field names come back with the valid list.
   - **Image swap** → `ai-by-roadmap/set-block-image` (top-level image fields) or `update-block-fields`.
   - **Add / drop / reorder a section** → `insert-block`, `remove-block`, `move-block` (flexible post types only).
   - **Title, slug, status, excerpt** → `ai-by-roadmap/update-post`.
4. Pin the write: pass `expected_block_type` (from step 2) and `expected_modified` (from step 1 or 2). A reordered
   block list or a concurrent edit returns a 409 instead of writing over it.
5. Check the result: `ai-by-roadmap/render-block` returns the block's front-end HTML and plain text;
   `ai-by-roadmap/get-preview-link` returns the permalink / authenticated preview URL to open or screenshot.

Rich-text fields take inline HTML (`<p>`, `<strong>`, `<em>`) — never Markdown. Re-running the same patch is a no-op
(`changed_fields: []`, no new revision).

**Across pages.** `ai-by-roadmap/search-content` finds a phrase in every block field site-wide (synced patterns
included) and returns post / block index / field path; `ai-by-roadmap/replace-text` rewrites it in bulk — it is a dry
run by default, so call it once to review the before/after list and again with `dry_run: false` to apply. Several
patches on one page go through `ai-by-roadmap/update-blocks` (validated together, saved once).

**Undo.** Every write creates a revision. `ai-by-roadmap/list-revisions` shows them; `ai-by-roadmap/restore-revision`
rolls back (and is itself reversible).

**Links.** Store internal links as site-relative paths (`/therapy/`), never absolute URLs — `ai-by-roadmap/resolve-link`
turns a route, title, slug or a URL from another environment into the right `relative_path`. `ai-by-roadmap/audit-links`
scans URL fields, link fields and `<a href>` inside rich text and flags links written with a stale host or pointing at
nothing; `fix_hosts: true` rewrites the stale-host ones. `assemble-page` and `insert-block` accept `dry_run: true` to
validate a whole page or block (field names, choices, attachment IDs, template) without writing.

**Synced patterns** (`wp_block` posts, the `fixed_rows` in `get-post-blocks`) are edited with the same tools: pass the
pattern's `ref` id as `post_id`.

---

## Creating a new page, end to end

1. **Pick the type** with `list-post-types`; **check for an existing one** with `find-posts`.
2. **Build it**: on a locked type (program, location, team member…) prefer `ai-by-roadmap/duplicate-post` on the
   closest existing entry, then `update-post` (title, slug, parent, featured image, terms) and `update-block-fields`
   for the differences. On a free type, `assemble-page` (try `dry_run: true` first) or `insert-block` per section.
3. **Link it**: `ai-by-roadmap/get-navigation` shows every menu and the megamenu; `add-menu-item` (footer / menus)
   and `update-mega-nav` (header panels) put the page where people will find it. Internal URLs are relative paths.
4. **Media**: `search-media` / `upload-media` for images, `update-media` for alt text.
5. **SEO**: `get-post-seo` → `update-post-seo` (title, meta description, focus keyphrase); `audit-seo` for the whole
   site before launch.
6. **Review**: `render-block` / `get-preview-link`, then `update-post` `status: publish`.

---

## When a skill should NOT use this plugin

- **Producing arbitrary block markup.** If you need a non-ACF block (core paragraph, image, columns), the plugin can't help — it only fills the registered ACF block schemas.
- **Cross-site migration.** The plugin operates within one WordPress install. For "scrape site A → publish to site B", do the scraping yourself and only call this plugin for the publish leg on site B.

---

## Quick reference — ability cheat sheet

| Ability | Purpose | Typical caller |
|---|---|---|
| `ai-by-roadmap/list-blocks` | Catalogue of registered ACF blocks | Discovery, once per migration |
| `ai-by-roadmap/list-post-types` | Post types, rewrite slugs, locked templates + fixed rows | Choosing the destination type |
| `ai-by-roadmap/find-posts` | Fuzzy-find existing posts (is_empty, block counts, modified) | Before every create/update |
| `ai-by-roadmap/compose-page` | Full async pipeline → draft page | Per-page migration |
| `ai-by-roadmap/assemble-page` | Persist blocks you filled yourself (no LLM); refuses non-empty posts | LLM-driven migration |
| `ai-by-roadmap/get-job-status` | Poll compose-page result | Always paired with compose-page |
| `ai-by-roadmap/get-post-blocks` | List a post's ACF blocks (+ `include_fields` for values) | Before any partial edit |
| `ai-by-roadmap/update-block-fields` | Merge-patch fields on one block | Copy edits, link fixes |
| `ai-by-roadmap/set-block-image` | Set a top-level image field on one block | Image swaps |
| `ai-by-roadmap/insert-block` | Add a filled block at an index | Structural edits (unlocked types) |
| `ai-by-roadmap/remove-block` | Remove a block by index | Structural edits (unlocked types) |
| `ai-by-roadmap/move-block` | Reorder a block | Structural edits |
| `ai-by-roadmap/update-blocks` | Several block patches, one save | Multi-block edits |
| `ai-by-roadmap/update-post` | Title / slug / status / excerpt | Post metadata fixes |
| `ai-by-roadmap/render-block` | Front-end HTML + text of a block | Checking an edit |
| `ai-by-roadmap/get-preview-link` | Permalink / preview / edit URLs | Handing off for review |
| `ai-by-roadmap/search-content` | Find text across block fields | "Where does X appear?" |
| `ai-by-roadmap/replace-text` | Bulk find/replace (dry run by default) | Renames, wording changes |
| `ai-by-roadmap/list-revisions` | Undo history of a post | Before/after risky edits |
| `ai-by-roadmap/restore-revision` | Roll a post back | Undo |
| `ai-by-roadmap/resolve-link` | Route/title/URL → post + relative path | Writing internal links |
| `ai-by-roadmap/audit-links` | Classify / fix links in block fields | After porting, before a domain move |
| `ai-by-roadmap/duplicate-post` | Clone a post (blocks, meta, image, terms) to a draft | New entries on locked CPTs |
| `ai-by-roadmap/get-navigation` | Menus by location + megamenu panels | Before linking a new page |
| `ai-by-roadmap/add-menu-item` | Add/remove a WP menu item | Footer / menu links |
| `ai-by-roadmap/update-mega-nav` | Edit a megamenu panel (ACF option) | Header navigation |
| `ai-by-roadmap/upload-media` | Sideload an image into the media library | Media import |
| `ai-by-roadmap/update-media` | Alt text / title / caption / description | Accessibility fixes |
| `ai-by-roadmap/get-post-seo` | One post's Yoast SEO data, rendered output, scores, issues | SEO review |
| `ai-by-roadmap/update-post-seo` | Write Yoast SEO fields (title, description, keyphrase, robots, social) | SEO fixes |
| `ai-by-roadmap/audit-seo` | Site-wide SEO work list | Pre-launch SEO sweep |
| `core/get-site-info`, `yoast-seo/*` | Bridged from core / Yoast when registered | Site facts, SEO scores & post SEO data |
| `ai-by-roadmap/analyze-content` | Section count + structure | Pre-pipeline diagnostic |
| `ai-by-roadmap/choose-blocks` | Pick block list (no fill) | Custom pipelines |
| `ai-by-roadmap/score-blocks` | Audit a block list | Custom pipelines / QA |
| `ai-by-roadmap/fill-page` | Sync fill-only (no analyze, no draft creation) | Custom pipelines |
| `ai-by-roadmap/fill-block` | Sync single-block fill | Block swap (editor JS handles this) |
| `ai-by-roadmap/search-media` | Find images by description | Pre-fetch media before/during composition |
| `ai-by-roadmap/analyze-media` | Re-analyze one attachment | Retry failed auto-analysis |
| `ai-by-roadmap/reindex-media` | Backfill missing embeddings | Admin maintenance |
| `ai-by-roadmap/get-target-audience` | Read stored audience | Inspecting a post |
| `ai-by-roadmap/set-target-audience` | Persist audience meta | Once per migration |
| `ai-by-roadmap/rate-generation` | Feedback (👍/👎 + notes) | Self-evaluation in batch runs |
| `roadmap-starter/search-icons` | Icon by concept | Used inside fill-page; rarely direct |

---

## Block field validation

`ACFTransformer::convert()` serializes whatever field names it is given, emitting a
`field_<slug>_<name>` reference for each. An unknown name therefore produces a block that
looks valid, binds to nothing in ACF, and renders an empty slot — a silent failure that a
markup or `strlen()` check cannot see.

Any caller that accepts externally authored block data must validate first:

```php
$report = BlockValidator::validate('acf/hero', $fields);   // [] when clean
if ($report !== []) {
    return BlockValidator::to_wp_error('acf/hero', $index, $report);
}
```

`BlockValidator` reads the block's real ACF field tree (`acf_get_field_groups()` +
`acf_get_fields()`), not the JSON schema contributed to `BlockRegistry` — that one marks
every field required, which is right for constraining a model and wrong for validating
input. It reports unknown field paths (with `did_you_mean` suggestions), enum violations
and empty required fields. `assemble-page` enforces it; the LLM fill paths
(`fill-page`, `fill-block`, `compose-page`) are already constrained by
`additionalProperties: false` schemas.

---

## Source map (for skill authors who want to inspect behaviour)

- Plugin entry point: [ai-by-roadmap.php](ai-by-roadmap.php)
- Abilities (one file each): [src/Core/Abilities/](src/Core/Abilities/) and [src/Blocks/Abilities/](src/Blocks/Abilities/)
- Partial-edit plumbing: [src/Blocks/BlockPatcher.php](src/Blocks/BlockPatcher.php) (ACF index space, guards, `patch_data()` merge, save + undo point), [src/Blocks/AcfBlockFields.php](src/Blocks/AcfBlockFields.php) (ACF introspection, unflatten, validate), [src/Blocks/Links.php](src/Blocks/Links.php), [src/Blocks/TextFields.php](src/Blocks/TextFields.php), [src/Core/Navigation.php](src/Core/Navigation.php)
- Orchestrator pipeline: [src/Blocks/Orchestrator.php](src/Blocks/Orchestrator.php)
- Block serializer + field validation: [src/Blocks/ACFTransformer.php](src/Blocks/ACFTransformer.php), [src/Blocks/BlockValidator.php](src/Blocks/BlockValidator.php)
- Agents (system prompts): [src/Core/Agents/](src/Core/Agents/) and [src/Blocks/Agents/](src/Blocks/Agents/)
- Action Scheduler job: [src/Jobs/ComposePageJob.php](src/Jobs/ComposePageJob.php)
- MCP server registration: [src/Mcp/Server.php](src/Mcp/Server.php)
- Theme-side block registration: [acf-blocks/AIForGutenbergProvider.php](../../themes/roadmap-starter/acf-blocks/AIForGutenbergProvider.php) (Roadmap Starter theme)
