# Project Research Summary

**Project:** Flxpnt -- Flxpoint to WooCommerce Image Sync
**Domain:** WordPress plugin syncing product/variation images from Flxpoint REST API to WooCommerce by SKU
**Researched:** 2026-05-21
**Confidence:** HIGH

## Executive Summary

Flxpnt is a focused WordPress plugin that syncs product and variation images from the Flxpoint REST API into WooCommerce, matching by SKU and updating images in-place without creating products. The market research shows no existing competitor does exactly this -- general-purpose sync tools (WP All Import, WebToffee) handle images as part of broader product import, while dedicated external-image plugins (FIFU, WooCommerce External Images) handle URLs but are either discontinued or add unwanted dependencies. There is a genuine gap for a lean, zero-dependency, images-only sync plugin.

The recommended approach is a batch-processing architecture using Action Scheduler (bundled with WooCommerce, not an external dependency) to process products in pages of 50, with a transient-based lock preventing concurrent runs. Images are downloaded via `download_url()` with an explicit 15-second timeout, deduplicated by content hash stored as post meta, and sideloaded into the WordPress media library via `media_handle_sideload()`. A configurable "external URL" mode lets store owners skip downloads entirely on storage-constrained hosting, storing CDN URLs directly and filtering WooCommerce image output hooks to serve them. The sync log is stored in a custom database table (not wp_options) with indexes for efficient querying and retention management.

The three critical risks are: (1) duplicate media library entries on every sync cycle if deduplication is not built into the download pipeline from day one, (2) variation SKU matching returning parent product IDs instead of variation IDs if the wrong WooCommerce lookup function is used, and (3) WP-Cron unreliability on low-traffic sites causing scheduled syncs to silently fail. All three are preventable with the design patterns documented in the pitfalls research. The existing codebase also has three security issues that must be fixed before new features ship: plaintext API token storage, XSS via raw API response bodies injected with `.html()`, and no WooCommerce dependency check on activation.

## Key Findings

### Recommended Stack

The entire stack uses only WordPress Core and WooCommerce built-in APIs. Zero external dependencies (no Composer, no npm, no third-party libraries). This matches the existing codebase's discipline and is itself a competitive differentiator against plugins that bundle guzzlehttp, monolog, or JavaScript build pipelines.

**Core technologies:**

- **`download_url($url, 15)` + `media_handle_sideload()`** for image acquisition -- provides timeout control, MIME validation, thumbnail generation, and temp file cleanup. The higher-level `media_sideload_image()` wrapper must be avoided: it uses a 300-second default timeout, fails on extensionless CDN URLs, and cannot clean up temp files on failure.
- **`wc_get_product_id_by_sku()`** for product lookup -- but only for simple and parent products. Variation SKU matching requires `wc_get_products(['sku' => $sku, 'type' => 'variation'])` because the general function returns the parent product ID for variation SKUs.
- **`$product->set_image_id()` / `$product->set_gallery_image_ids()`** for image assignment -- the WooCommerce CRUD API, not direct post meta manipulation. Each product must call `$product->save()` only once after all images are set, not once per image, to avoid triggering full WooCommerce CRUD overhead (cache invalidation, lookup table updates, term recounting) hundreds of times per sync.
- **Action Scheduler (`as_enqueue_async_action()`)** for batch processing -- ships with WooCommerce, no separate installation. Each batch processes 50 products and chains the next batch. Provides visibility via WooCommerce > Status > Scheduled Actions.
- **WP-Cron (`wp_schedule_event()`)** for hourly scheduling -- combined with a prominent admin notice about real server cron for reliability.
- **`_source_url` post meta** for initial duplicate detection -- automatically stored by WordPress media functions since WP 5.4. Content hash (`_flxpnt_image_hash`) provides the definitive deduplication key since CDN URLs may rotate.

**Critical version requirements:** WordPress 5.4+ (for `_source_url` auto-storage), WooCommerce 3.0+ (for CRUD API), PHP 7.0+ (implicit via WooCommerce requirement). The `download_url()` timeout parameter requires WP 5.9+; for WP 5.4-5.8, use `add_filter('http_request_timeout', ...)` as a pre-download workaround.

**Resolved conflict:** STACK.md lists Action Scheduler under "What NOT to Use" as an external dependency. ARCHITECTURE.md and PITFALLS.md correctly use it as a WooCommerce-bundled library. Since WooCommerce is a hard plugin dependency, Action Scheduler is available without additional installation. Using Action Scheduler is the correct approach -- it provides better lock handling, retry logic, and admin visibility than raw WP-Cron batch chaining.

### Expected Features

**Must have (table stakes -- P1, v1 launch):**

- SKU-based product matching (parent and variation) -- every competitor does this; users expect "I set my SKU, images show up"
- Featured image sync -- `set_image_id()` on matched products
- Gallery image sync -- `set_gallery_image_ids()` with array of attachment IDs
- Manual "Sync Now" trigger -- AJAX button matching the existing connection test pattern
- Image download to Media Library with deduplication -- `download_url()` + `media_handle_sideload()` with content hash check
- Update-in-place behavior -- overwrite `_thumbnail_id` and `_product_image_gallery` only, never touch product data, never create products
- Sync summary/log -- custom DB table with per-entity results (success/skipped/failed with actionable reasons)
- Skip unmatched SKUs safely -- log as "skipped: no matching product", never create products
- Flxpoint API Client -- paginated product listing endpoint, Bearer auth, error handling

**Should have (differentiators -- P2, add after validation):**

- Variation image sync -- extend SKU matching to variation SKUs with correct lookup function
- Scheduled hourly sync via WP-Cron -- with transient lock, overlap prevention, and admin documentation about real cron
- Configurable download-or-link toggle per sync run -- download to media library vs. store external URL (unique differentiator vs. competitors)
- Sync log admin UI with `WP_List_Table` -- sortable, filterable, searchable, with links to product edit pages
- Incremental sync awareness -- use Flxpoint's `?updated_after=` filter for scheduled syncs to avoid full catalog scans

**Defer (v2+):**

- Per-product selective sync -- useful for large catalogs but adds UI complexity
- Dry-run preview mode -- confidence builder, adds a parallel code path
- Webhook receiver for real-time sync -- security surface and queue management complexity
- Action Scheduler migration from WP-Cron -- only if cron reliability becomes a user pain point
- Image hash comparison to skip unchanged products -- optimization requiring a working sync engine first

### Architecture Approach

The sync system is composed of five new classes, all following the existing WordPress Plugin Boilerplate conventions (constructor injection of `$plugin_name` and `$version`, Loader-based hook registration). The orchestrator (`Flxpnt`) instantiates each component and wires its hooks through `Flxpnt_Loader`, exactly as the existing codebase does for `Flxpnt_Admin` and `Flxpnt_Public`.

**Major components:**

1. **Flxpnt_API_Client** -- owns all HTTP communication with Flxpoint (paginated product fetching, Bearer auth, response parsing, error handling). Admin handlers call the sync controller, which calls the API client. Direct API calls in AJAX handlers (as the existing connection test does) must not be the pattern for sync operations.

2. **Flxpnt_Image_Processor** -- acquires images from URLs: `download_url()` + `media_handle_sideload()` pipeline with timeout control, deduplication via content hash, or external URL storage via post meta. Returns attachment IDs or URL strings. One bad image must not abort the entire batch.

3. **Flxpnt_WC_Updater** -- matches products by SKU (distinguishing simple, variable parent, and variation), sets images via WooCommerce CRUD API, batches all assignments before calling `$product->save()` once per product. Never creates or deletes products.

4. **Flxpnt_Sync_Controller** -- orchestrates sync lifecycle: receives triggers (manual AJAX or cron), manages transient lock, chains Action Scheduler batches, handles completion and progress tracking.

5. **Flxpnt_Sync_Logger** -- writes structured sync records to a custom `flxpnt_sync_logs` table with indexed columns (sync_batch, entity_sku, action, created_at). Provides query methods for admin display. Never stored in wp_options.

**Data flow:** User clicks "Sync Now" -> AJAX handler validates nonce/capability, acquires transient lock -> enqueues first Action Scheduler batch (page=1) -> `process_batch()` fetches 50 products from API, processes each through WC Updater (which calls Image Processor for each image), logs results -> chains next batch via `as_enqueue_async_action()` -> releases lock on completion.

**Build order (dependency-driven):** Sync Logger (zero deps) -> API Client + Image Processor (zero deps on each other) -> WC Updater (depends on Image Processor + Logger) -> Sync Controller (depends on all three) -> Admin UI modifications.

### Critical Pitfalls

The top 5 pitfalls, drawn from the full research of 10 critical issues:

1. **`download_url()` default 300-second timeout destroys batch sync** -- Always pass explicit 15-second timeout as the second parameter (`download_url($url, 15)`). Wrap each download in `is_wp_error()` check so one bad image does not block the batch. For WP 5.4-5.8 where the timeout parameter does not exist, use a temporary `http_request_timeout` filter scoped to the download call only.

2. **Duplicate media library entries on every sync cycle** -- `media_handle_sideload()` has no built-in deduplication. Generate a SHA-256 content hash after download and store as `_flxpnt_image_hash` post meta. Before downloading any image, query for existing attachments with the same hash. If found, reuse the existing attachment ID. This must be part of the download pipeline from day one -- retrofitting requires cleanup scripts and product reference correction.

3. **Variation SKU matching returns parent product ID, not variation ID** -- `wc_get_product_id_by_sku($variation_sku)` returns the parent variable product's ID. For variation SKUs, use `wc_get_products(['sku' => $sku, 'type' => 'variation', 'limit' => 1])` instead. This is a design feature of WooCommerce's SKU uniqueness guarantee, not a bug, but it will silently set variation images on the parent product if not handled.

4. **`$product->save()` inside loops triggers full WooCommerce CRUD overhead per image** -- Each `save()` fires cache invalidation, lookup table updates, and term recounting. For 500 products with 4 images each saved individually, that is 2,000 full CRUD operations generating 40,000-100,000 database queries. Batch all image assignments per product and call `$product->save()` exactly once per product. Defer term counting (`wp_defer_term_counting(true)`) and suspend cache invalidation during the batch.

5. **Memory exhaustion during batch image processing** -- `media_handle_sideload()` generates all registered thumbnail sizes in the same PHP process. Call `wp_suspend_cache_addition(true)` before the batch, filter `intermediate_image_sizes_advanced` to limit sizes generated during sync, and force garbage collection every 10-15 images with `wp_cache_flush()` and `gc_collect_cycles()`. Increase memory limit to 512M at the start of the sync handler.

**Existing codebase security issues (fix before new features):** The XSS vulnerability via `.html()` insertion of unsanitized API response bodies (line 31 of `admin/js/flxpnt-admin.js`) and the plaintext API token storage in `wp_options` (visible in page source) are live security problems that must be addressed in the earliest phase.

## Implications for Roadmap

Based on the combined research, the build order driven by component dependencies, and the pitfall-to-phase mapping, the following phase structure is recommended:

### Phase 0: Foundation + Security Hardening

**Rationale:** Three existing security issues (plaintext token storage, XSS via `.html()`, no WooCommerce dependency check) affect every subsequent phase. The sync log database table has zero dependencies and must exist before any sync code runs. Fixing security first prevents these patterns from being copied into new code.

**Delivers:**
- Token encryption at rest (openssl_encrypt with AUTH_KEY)
- Token masked in settings page (never echoed to browser after save)
- `.html()` replaced with `.text()` in admin JS (eliminates XSS vector)
- `wp_strip_all_tags()` on API response body before output
- WooCommerce dependency check in `Flxpnt_Activator::activate()` with graceful deactivation
- Custom `flxpnt_sync_logs` database table created via dbDelta
- `Flxpnt_Activator` modified: WooCommerce check, table creation
- `Flxpnt_Deactivator` modified: unschedule cron, clear pending actions

**Addresses pitfalls:** 6 (XSS), 7 (token plaintext), 9 (missing WC check)

**Research flag:** Standard WordPress patterns, well-documented. Skip research-phase during planning.

### Phase 1: API Client + Image Processor

**Rationale:** These two components have zero dependencies on each other or on WooCommerce mutation logic. The API client can be tested with the existing connection test credentials. The image processor can be tested with known image URLs. Building them first allows independent validation.

**Delivers:**
- `Flxpnt_API_Client` -- paginated product/variation fetching from Flxpoint, Bearer auth, response parsing, error handling
- `Flxpnt_Image_Processor` -- `download_url($url, 15)` + `media_handle_sideload()` pipeline with:
  - SHA-256 content hash generation and storage as `_flxpnt_image_hash`
  - Deduplication: query for existing attachment by hash before downloading
  - `_source_url` fallback duplicate check
  - Per-image error isolation (one failed image does not abort the batch)
  - Timeout control with `http_request_timeout` filter fallback for WP < 5.9
  - External URL mode: store URL in `_flxpnt_ext_image_url` meta, skip download

**Uses stack:** `download_url()`, `media_handle_sideload()`, `wp_remote_get()`, WordPress media functions

**Addresses pitfalls:** 1 (timeout), 2 (duplicate entries), partially 8 (rate limiting infrastructure in API client)

**Research flag:** Flxpoint API product/image field structure has LOW confidence (public docs incomplete). Needs `/gsd:plan-phase --research-phase 1` during planning to verify actual response schema, pagination format, and image URL structure.

### Phase 2: WooCommerce Integration

**Rationale:** This is the core business logic -- matching SKUs to products and setting images. It depends on both the API client and image processor. Getting this right validates the entire sync pipeline with real WooCommerce data.

**Delivers:**
- `Flxpnt_WC_Updater` with:
  - SKU matching: `wc_get_product_id_by_sku()` for simple/parent, `wc_get_products(['type'=>'variation'])` for variations
  - Variation image handling via `WC_Product_Variation::set_image_id()`
  - Batched image assignment: all `set_image_id()` and `set_gallery_image_ids()` calls before a single `$product->save()` per product
  - `wp_defer_term_counting(true)` and `wp_suspend_cache_invalidation(true)` during batch
  - Memory management: `wp_suspend_cache_addition(true)`, periodic `wp_cache_flush()`, `gc_collect_cycles()`
  - Image size filtering via `intermediate_image_sizes_advanced` during sync
  - "External URL" mode: product image output filtered via `post_thumbnail_html`, `woocommerce_product_get_image`, `woocommerce_single_product_image_thumbnail_html`

**Implements architecture component:** `Flxpnt_WC_Updater`

**Addresses pitfalls:** 4 (memory exhaustion), 5 (variation SKU matching), 10 (`save()` in loops)

**Research flag:** Variation image handling with WooCommerce CRUD API is well-documented (HIGH confidence). Standard patterns. Skip research-phase during planning.

### Phase 3: Sync Orchestration

**Rationale:** Wires all components together. The Sync Controller depends on API Client, WC Updater, and Sync Logger -- all built in prior phases. The batch processing pattern with Action Scheduler is the backbone of the entire sync system.

**Delivers:**
- `Flxpnt_Sync_Controller` with:
  - Manual sync trigger via AJAX (matching existing `wp_ajax_` pattern)
  - Batch processing chain: fetch 50 products, process, enqueue next batch via `as_enqueue_async_action()`
  - Transient lock (`flxpnt_sync_lock`, 5-minute TTL) preventing concurrent runs
  - Sync state tracking: running flag, last sync timestamp, progress indicators
  - Rate limiting: configurable delay between image downloads (default 200ms)
  - Download-or-link mode toggle at runtime (reads `flxpnt_image_handling` option)
- Admin "Sync Now" button with spinner + summary result panel
- `Flxpnt_Sync_Logger` integration: every sync action logged with batch ID, entity SKU, action, image count, status, message

**Implements architecture components:** `Flxpnt_Sync_Controller`, `Flxpnt_Sync_Logger`

**Addresses pitfalls:** 3 (WP-Cron context -- lock prevents overlap), 8 (rate limiting)

**Research flag:** Action Scheduler self-chaining batch pattern is MEDIUM confidence. Action Scheduler is well-documented but the "enqueue next batch from within current batch" pattern may have edge cases with queue depth, memory, or timeout handling. Consider research-phase validation during planning.

### Phase 4: Scheduled Sync + Admin UI

**Rationale:** Scheduled sync depends on the working sync engine from Phase 3. The admin UI (log display, settings integration, cron toggle) depends on the logger and sync controller both being functional.

**Delivers:**
- WP-Cron hourly sync scheduling: `wp_schedule_event()` + `flxpnt_hourly_sync` hook
- Cron reliability documentation: admin notice when `DISABLE_WP_CRON` detected, "Last Sync" timestamp with staleness warning
- Sync Log admin page with `WP_List_Table`:
  - Columns: Date, SKU (linked to product edit page), Action, Images, Status, Message
  - Filters: date range, status (All/Success/Skipped/Failed)
  - Row actions: View Product, View Error
  - Pagination: 20/50/100 per page
- Settings page integration: download/link mode radio toggle, cron enabled/disabled toggle
- Incremental sync via Flxpoint `?updated_after=` filter for scheduled runs
- "Cancel Sync" button (checks cancel flag before each batch)

**Implements architecture components:** Admin UI modifications, `WP_List_Table` subclass

**Addresses pitfalls:** 3 (WP-Cron unreliability -- documented, detected, with manual fallback)

**Research flag:** `WP_List_Table` pattern is well-documented throughout WordPress core (HIGH confidence). Standard patterns. Skip research-phase during planning.

### Phase Ordering Rationale

- **Dependency chain drives order:** Sync Logger (0 deps) -> API Client + Image Processor (0 deps on each other) -> WC Updater (depends on Image Processor) -> Sync Controller (depends on all three) -> Admin UI (depends on everything). This is the only order that allows independent testing of each component.
- **Security must come first:** The existing XSS and plaintext token issues must be fixed before new code copies the vulnerable patterns. Phase 0 prevents this.
- **Image pipeline before WooCommerce integration:** The image processor can be tested with known URLs independent of WooCommerce. Building it first means the WC Updater is built on a validated foundation.
- **WooCommerce integration before orchestration:** The sync controller needs a working WC Updater. Building WC Updater independently allows unit-testing SKU matching and image assignment without the complexity of batch orchestration.
- **Orchestration before scheduling:** The manual "Sync Now" trigger is the fastest way to validate the entire pipeline end-to-end. Adding cron scheduling before validation would hide bugs in silent background processes.
- **Pitfall prevention is front-loaded:** Pitfalls 1-2 (timeout, deduplication) are addressed in Phase 1 (Image Processor). Pitfalls 4-5-10 (memory, variation matching, save loops) are addressed in Phase 2 (WC Updater). Critical pitfalls are not deferred to later phases.

### Research Flags

**Phases likely needing deeper research during planning:**

- **Phase 1 (API Client + Image Processor):** Flxpoint API response schema for products and variations has LOW confidence -- public Stoplight docs are incomplete. During planning, verify actual field names, image URL structure (CDN? signed URLs?), pagination format (cursor vs. offset), and rate limit headers. The Flxpoint API's product/image data model is the single biggest research gap.

- **Phase 3 (Sync Orchestration):** Action Scheduler self-chaining batch pattern in a WordPress plugin context is MEDIUM confidence. Action Scheduler is well-documented but the "enqueue next batch from within current batch" pattern may have edge cases with queue depth, memory, or timeout handling that need validation.

**Phases with standard patterns (skip research-phase):**

- **Phase 0 (Foundation + Security):** WordPress options encryption, sanitization, and activation guards are well-documented core patterns. HIGH confidence.
- **Phase 2 (WooCommerce Integration):** WooCommerce CRUD API, `set_image_id()`, variation handling, and performance optimization (defer term counting, suspend cache invalidation) are well-documented and community-validated. HIGH confidence.
- **Phase 4 (Scheduled Sync + Admin UI):** WP-Cron scheduling and `WP_List_Table` are core WordPress patterns with official documentation and abundant examples. HIGH confidence.

## Confidence Assessment

| Area | Confidence | Notes |
|------|------------|-------|
| Stack | HIGH | WordPress Core APIs and WooCommerce CRUD APIs are official, well-documented. `download_url()` + `media_handle_sideload()` pipeline verified against WordPress source code. `_source_url` behavior confirmed since WP 5.4. The Action Scheduler vs. "zero external deps" conflict is resolved: AS ships with WooCommerce, the hard dependency. |
| Features | HIGH | Competitor feature analysis based on official docs and community reports across 7 products. Table-stakes features confirmed by multiple sources. Differentiators validated against gaps in competitor offerings. P1/P2/P3 prioritization follows standard product management frameworks. |
| Architecture | HIGH | Component boundaries driven by dependency analysis. Data flows modeled from existing codebase patterns. Build order is dependency-determined, not arbitrary. One gap: Flxpoint API product/image response schema (LOW confidence) -- field structure must be verified against live API. |
| Pitfalls | HIGH | All 10 critical pitfalls verified against multiple sources (WordPress StackExchange, WooCommerce GitHub issues, community reports, codebase analysis). Recovery strategies defined. Pitfall-to-phase mapping ensures each pitfall has a prevention phase. "Looks Done But Isn't" checklist covers all known failure modes. |

**Overall confidence:** HIGH

The research is thorough across all four dimensions. The only significant gap is the Flxpoint API's specific product and image field structure, which cannot be resolved without access to a live Flxpoint instance or more detailed API documentation. This is flagged for Phase 1 planning research.

### Gaps to Address

- **Flxpoint API product/image field structure:** The Stoplight docs page returned empty content. Field names, image URL format (CDN? signed/expiring?), variation nesting structure, and pagination format are inferred from general REST API conventions but not verified. **Mitigation:** Phase 1 planning must include a dedicated research sub-phase to inspect live API responses. If a test Flxpoint account is available, call the products endpoint and document the actual schema. If not, flag this as a risk for implementation and build the API client with configurable field mapping.

- **Flxpoint API rate limits:** No rate limit documentation found. The research assumes standard SaaS API patterns (60-120 req/min) but this is unverified. **Mitigation:** Build configurable request delay (default 200ms) into the API client from the start. Log response headers during initial sync runs to discover actual rate limits. If rate limits are aggressive, the batch size can be reduced.

- **WP-Cron reliability on managed hosting:** `DISABLE_WP_CRON` is common on WP Engine, Kinsta, Flywheel, and Cloudways. **Mitigation:** Phase 4 admin UI must detect `DISABLE_WP_CRON` and display a prominent warning with instructions for configuring real server cron. The manual "Sync Now" button (built in Phase 3) provides a reliable fallback independent of cron.

## Sources

### Primary (HIGH confidence)

- WordPress Developer Reference: `download_url()`, `media_handle_sideload()`, `media_sideload_image()`, `wp_schedule_event()`, `WP_List_Table` -- official API documentation with verified function signatures and internal call chains
- WooCommerce Developer Documentation: CRUD Objects, `wc_get_product_id_by_sku()`, `set_image_id()`, `set_gallery_image_ids()` -- official docs with verified method signatures
- WooCommerce Developer Blog: "Experimental Product Object Caching in WooCommerce 10.5" -- confirms in-request caching behavior
- Action Scheduler Documentation -- official library docs, ships with WooCommerce
- WordPress Plugin Handbook: Custom Database Tables, Scheduling WP-Cron Events -- official patterns
- Existing codebase: `includes/class-flxpnt.php`, `admin/class-flxpnt-admin.php`, `admin/js/flxpnt-admin.js` -- primary source for existing architecture and vulnerabilities
- Codebase analysis: `.planning/codebase/ARCHITECTURE.md`, `.planning/codebase/CONCERNS.md` -- validated existing codebase findings
- WooCommerce GitHub Issues: #26029 (API performance), #27249 (bulk save), #9588 (variation images), #54253 (no native external URL support) -- official issue tracker with developer confirmations

### Secondary (MEDIUM confidence)

- Flxpoint WooCommerce Channel Documentation -- official vendor docs, confirms integration patterns
- WP All Import WooCommerce Image Import Documentation -- competitor feature analysis
- WebToffee Product Import Export Blog -- competitor comparison
- WordPress StackExchange: timeout issues, programmatic product images, transient locks, external image URLs -- community consensus across multiple answers
- WordPress Support Forums: `media_sideload_image` problems, duplicate upload avoidance, variation image assignment -- real user reports and solutions
- Community patterns: duplicate prevention via `_source_url` (GitHub Gist), external image URL via filters (StackExchange), Action Scheduler batch processing (developer blog)

### Tertiary (LOW confidence -- needs validation)

- Flxpoint API Stoplight Documentation -- product/image field structure page returned empty; API behavior inferred from general REST conventions
- Stack Overflow: WooCommerce programmatic image import -- unverified community content, single-source answers

---
*Research completed: 2026-05-21*
*Ready for roadmap: yes*
