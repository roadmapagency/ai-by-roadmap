# AI by Roadmap

A WordPress plugin that exposes a curated set of **Abilities API** capabilities for assembling and editing pages of ACF Gutenberg blocks. Designed to be driven by LLMs (Claude Desktop, Claude Code, or any MCP client) for tasks like **migrating an external website's content into the Roadmap Starter theme**, but every ability is also callable via the standard WordPress REST API for in-editor UIs and scripted batch runs.

The same abilities are surfaced via two transports — input shape, output shape, and behaviour are identical between them:

- **REST API:** `POST /wp-abilities/v1/abilities/{name}/run`
- **MCP:** `ai-by-roadmap-mcp` custom server registered via the WordPress MCP Adapter.

This README is also the operational manual for any **Claude skill** that drives the plugin. The sections below mirror what a skill needs to know: how to discover the active block catalogue, the recommended workflow for converting raw content to a draft page, and the input/output shapes for every ability.

---

## Requirements

- **WordPress 6.9+** (for the Abilities API and bundled `php-ai-client`)
- **PHP 8.1+**
- **Advanced Custom Fields Pro** with at least one block registered to the active theme
- **MCP Adapter plugin** (only if you want the MCP transport — REST works without it)
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
- **Permission gates.** Most abilities require `edit_posts` (or `upload_files` for media, `manage_options` for admin operations like `reindex-media`). The MCP transport runs as the authenticated WP user.
- **Vector search degrades gracefully** on MariaDB <11.7 to keyword-only. The API surface doesn't change, just the relevance.
- **Generation log table is pruned daily** to ~1,000 most recent rows. Don't rely on old `id`s for `rate-generation` more than a few days after the run.

---

## When a skill should NOT use this plugin

- **Pure copy-edit on existing post_content.** Use direct REST `update post` calls. The plugin's pipeline is overkill for editing already-structured blocks.
- **Producing arbitrary block markup.** If you need a non-ACF block (core paragraph, image, columns), the plugin can't help — it only fills the registered ACF block schemas.
- **Cross-site migration.** The plugin operates within one WordPress install. For "scrape site A → publish to site B", do the scraping yourself and only call this plugin for the publish leg on site B.

---

## Quick reference — ability cheat sheet

| Ability | Purpose | Typical caller |
|---|---|---|
| `ai-by-roadmap/list-blocks` | Catalogue of registered ACF blocks | Discovery, once per migration |
| `ai-by-roadmap/compose-page` | Full async pipeline → draft page | Per-page migration |
| `ai-by-roadmap/get-job-status` | Poll compose-page result | Always paired with compose-page |
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

## Source map (for skill authors who want to inspect behaviour)

- Plugin entry point: [ai-by-roadmap.php](ai-by-roadmap.php)
- Abilities (one file each): [src/Core/Abilities/](src/Core/Abilities/) and [src/Blocks/Abilities/](src/Blocks/Abilities/)
- Orchestrator pipeline: [src/Blocks/Orchestrator.php](src/Blocks/Orchestrator.php)
- Agents (system prompts): [src/Core/Agents/](src/Core/Agents/) and [src/Blocks/Agents/](src/Blocks/Agents/)
- Action Scheduler job: [src/Jobs/ComposePageJob.php](src/Jobs/ComposePageJob.php)
- MCP server registration: [src/Mcp/Server.php](src/Mcp/Server.php)
- Theme-side block registration: [acf-blocks/AIForGutenbergProvider.php](../../themes/roadmap-starter/acf-blocks/AIForGutenbergProvider.php) (Roadmap Starter theme)
