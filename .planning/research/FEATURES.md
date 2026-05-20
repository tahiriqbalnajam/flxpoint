# Feature Research

**Domain:** WooCommerce image sync plugin (Flxpoint API to WooCommerce, SKU-matched, images only)
**Researched:** 2026-05-21
**Confidence:** HIGH

## Feature Landscape

### Table Stakes (Users Expect These)

Features users assume exist. Missing these = product feels incomplete or broken.

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| SKU-based product matching | Every competitor (WP All Import, WebToffee, Stock Sync, WP Desk) matches by SKU. Flxpoint itself recommends SKU mapping. Users expect "I set my SKU, images show up." | MEDIUM | Use `wc_get_product_id_by_sku()`. Handle parent vs. variant SKU — Flxpoint sends both. Must handle: matched, unmatched, duplicate SKU edge cases. |
| Featured image sync | Every sync plugin sets `_thumbnail_id`. Users need this to show product thumbnails in catalog grids. | LOW | One call to `set_post_thumbnail()` per product. |
| Gallery image sync | WP All Import, WebToffee, and WooCommerce native CSV importer all handle multiple images (featured + gallery). Missing gallery = only one image per product. | MEDIUM | Set `_product_image_gallery` meta (comma-separated attachment IDs). Flxpoint returns image arrays — first is featured, rest are gallery. |
| Variation image sync | WP All Import has dedicated variation image tab. Flxpoint's WooCommerce channel maps variation images. Stores with variable products (color/size swatches) need variation-specific images. | HIGH | Each variation has its own SKU and its own image set. Must match variation SKU → variation post → set `_thumbnail_id`. Variation gallery requires additional plugin or custom handling. |
| Manual "Sync Now" trigger | Every sync plugin has it — WP Crontrol has "Run Now," Stock Sync has manual trigger, WP All Import has "Run Import." Users want control over when sync happens. | LOW | AJAX endpoint + button. Reuse existing `wp_ajax_` pattern from connection test. |
| Scheduled sync (automated) | WebToffee Pro, WP Desk Dropshipping, and every paid competitor offer scheduled/cron imports. Users expect "set and forget." PROJECT.md specifies hourly. | MEDIUM | Register WP-Cron interval. Must handle: cron fires on low-traffic site (events late), overlapping runs, and memory limits for large catalogs. |
| Download images to Media Library | WP All Import, WebToffee, and WooCommerce core CSV importer all download external images to the Media Library. Users expect images in their uploads, generating thumbnails and appearing in the Media Library. | MEDIUM | `media_handle_sideload()` handles download, thumbnail generation, and attachment creation. Must handle: timeout on large images, invalid URLs, non-image content at URLs. |
| Sync summary/log | Every competitor has some form of log — WooCommerce core has `WC_Admin_Log_Table_List`, Stock Sync has per-run log, WP All Import shows import summary. Users need to know what happened. PROJECT.md requires this. | MEDIUM | Custom DB table with run entries. Use `WP_List_Table` pattern (matches WooCommerce conventions). Store: timestamp, SKU, product ID, image count, status (success/skipped/failed), error message. |
| Skip unmatched SKUs safely | Flxpoint may have SKUs not in WooCommerce. Every competitor handles this — WP All Import has "Update existing only" mode. Silently crashing or creating junk products on unmatched SKUs is a critical UX failure. | LOW | `wc_get_product_id_by_sku()` returns 0 for unmatched. Log as "skipped" with reason "no matching product." |
| Update images in-place (not delete/recreate) | PROJECT.md explicitly requires this. Users expect existing products keep their other data intact. This is standard behavior — `set_post_thumbnail()` and `update_post_meta('_product_image_gallery')` overwrite without touching product data. | LOW | Only modify `_thumbnail_id` and `_product_image_gallery`. Never touch product post content, price, or other meta. |
| Configurable image handling: download vs. link | PROJECT.md explicitly requires this. Users on limited-storage hosting need external URLs; others want local copies for CDN/backup. This is rare in competitors — most force one approach. | MEDIUM | Per-run or global toggle. When "link": store external URL in `_product_image_gallery` directly (no attachment). When "download": `media_handle_sideload()`. Must handle: external URLs breaking if origin changes. |

### Differentiators (Competitive Advantage)

Features that set the product apart. Not required by users, but valued.

| Feature | Value Proposition | Complexity | Notes |
|---------|-------------------|------------|-------|
| Download-or-link choice (per sync run) | Most competitors force one mode. The discontinued WooCommerce External Images plugin offered external-only. WP All Import offers download-only. Giving users CHOICE per sync run is unique and directly addresses the storage-vs-performance tradeoff. | MEDIUM | Build this as a radio toggle in the sync UI, not buried in settings. "Download to Media Library" vs. "Link external URLs." Log which mode was used per run. |
| Images-only scope (no scope creep) | Competitors like WP All Import, WebToffee, and Stock Sync are massive multi-purpose tools. They sync everything or nothing. A focused "sync images only, skip everything else" plugin occupies an empty niche — lightweight, fast, does one thing well. | N/A (design choice) | This IS the differentiator. Don't dilute it by adding price sync or product creation later. |
| Zero external dependencies | Many sync plugins require Composer packages (guzzlehttp, monolog) or JavaScript build steps. This plugin uses only WordPress core APIs — `wp_remote_get`, `media_handle_sideload`, `WP_List_Table`. Easier to install, zero supply-chain risk, works on locked-down hosting. | N/A (architectural) | Already the case. Maintain this discipline. |
| Direct API integration (no CSV/XML intermediary) | WP All Import requires CSV/XML files. WebToffee supports file uploads. Flxpoint has a REST API — using it directly means no export/import dance, no stale data, no format mapping errors. | MEDIUM | Already using `wp_remote_get` for connection test. Extend to paginated product listing API. |
| Actionable sync log (not just a dump) | Most competitors show raw logs or generic "import complete" messages. A log that says "Product SKU-1234: 3 images downloaded, 1 failed (404)" and links to the product edit page is genuinely useful. | MEDIUM | Each log entry gets: SKU (clickable to product edit), action taken (featured/gallery updated), image count, status, error detail. Use `WP_List_Table` with SKU column linked via `get_edit_post_link()`. |
| Clean minimal admin (not overwhelming) | WP All Import is notoriously complex — 4-step wizard, drag-and-drop mapping, template system. For an images-only sync, the admin should be: Settings tab + Sync tab + Log tab. That's it. | LOW | Two new submenu pages: "Sync" and "Log." Keep settings page as-is. |
| Incremental sync awareness | Competitors' #1 pain point: full catalog scans every time. A 45-minute sync for 2,000 products to add 3 new images. Flxpoint API supports `?updated_after=` filter — use it for "sync only updated products." | MEDIUM | Store last sync timestamp. On scheduled sync, fetch only products modified since last run. Full sync only on manual "Sync Now." |

### Anti-Features (Commonly Requested, Often Problematic)

Features that seem good but create problems. Deliberately not building these.

| Feature | Why Requested | Why Problematic | Alternative |
|---------|---------------|-----------------|-------------|
| Create new WooCommerce products from Flxpoint | "Why do I have to create products manually first?" | Opens Pandora's box: product type mapping (simple/variable), attribute creation, category matching, price defaults, stock status, tax class. This is a full product import plugin, not an image sync. Also creates duplicate risk if SKUs are messy. | Document the workflow: create products in WooCommerce first (or use native import), then use this plugin to keep images in sync. |
| Sync pricing or inventory | "One plugin for everything Flxpoint." | Adds massive complexity: price tiers, sale prices, stock status, backorder settings, inventory management. Each of these has edge cases that require weeks of work. Also conflicts with other plugins that manage pricing/inventory. | Use a dedicated inventory/pricing sync plugin (e.g., WP Desk, Stock Sync) and let this plugin handle images only. These plugins can coexist. |
| Bidirectional sync (WooCommerce → Flxpoint) | "What if I upload images in WooCommerce?" | Flxpoint is the source of truth. Two-way sync creates conflict resolution problems: which image wins when both changed? How to detect origin of change? Adds undo/rollback complexity. | Document clearly: Flxpoint is authoritative. Images uploaded in WooCommerce WILL be overwritten on next sync. Warn in admin UI. |
| Real-time webhook sync | "Why wait for hourly cron?" | Webhooks add: (1) a publicly accessible endpoint (security surface), (2) webhook signature verification, (3) duplicate event handling, (4) queue management for backpressure. Image changes are not time-sensitive enough to justify this complexity. For a v1 plugin, hourly cron is sufficient. | Use hourly WP-Cron + manual "Sync Now" for urgent updates. Consider webhooks only for v2+ if users report latency issues. |
| Image optimization, resizing, or CDN | "Optimize images on import." | Image optimization is a separate problem domain with excellent dedicated plugins (EWWW, Smush, Imagify). Reinventing image optimization duplicates effort and creates conflicts. | Document: use a dedicated image optimization plugin. Downloaded images go through standard `media_handle_sideload()` which generates all WordPress thumbnail sizes — that's sufficient. |
| CSV/XML import file upload | "My catalog is in a file, not an API." | Flxpoint IS the API. Adding file import creates two code paths for the same operation, doubling maintenance and testing. Also introduces format parsing, column mapping, and encoding issues. | The Flxpoint REST API is the exclusive data source. If users have Flxpoint, they have the API. No need for files. |
| Bulk product creation from unmatched SKUs | "Just create the products if the SKU doesn't exist." | The #1 cause of broken stores: duplicate products, wrong product types, missing attributes, incomplete data. A user with 10,000 Flxpoint SKUs but only 500 WooCommerce products gets 9,500 garbage products created. | Skip unmatched SKUs and log them. Show "X products skipped (no match)" in sync summary. Optionally list unmatched SKUs so users can create them intentionally. |
| UI for editing Flxpoint data from WordPress | "Let me fix product images in WordPress and push back." | Breaks the one-way data flow. Creates an expectation that WordPress is a CMS for Flxpoint, which it explicitly is not. Adds authentication complexity for write operations. | All image management happens in Flxpoint. This plugin is read-only from Flxpoint's perspective. |

## Feature Dependencies

```
Sync Engine
    ├──requires──> Flxpoint API Client
    │                  └──requires──> API credentials (already built: settings + connection test)
    │
    ├──requires──> SKU Matcher
    │                  └──uses──> wc_get_product_id_by_sku() (WooCommerce core, zero cost)
    │
    ├──requires──> Image Handler
    │                  ├──download mode──> media_handle_sideload()
    │                  └──link mode──────> update_post_meta('_product_image_gallery')
    │
    └──requires──> Sync Logger
                       └──requires──> Custom DB table (flxpnt_sync_log)

Manual "Sync Now"
    └──requires──> Sync Engine

Scheduled Sync (WP-Cron)
    └──requires──> Sync Engine

Sync Admin UI
    ├──requires──> Manual "Sync Now" endpoint
    └──requires──> Sync Logger (to display last run summary)

Download-or-Link Toggle
    └──enhances──> Sync Engine (runtime configuration, not a separate code path)

Variation Image Sync
    ├──requires──> Sync Engine (same flow, different target)
    └──requires──> wc_get_product_id_by_sku() for variation SKUs
```

### Dependency Notes

- **Sync Engine requires Flxpoint API Client:** The API client must handle pagination, rate limiting, and error responses from Flxpoint. It is the foundation everything else depends on. Build this first.
- **Sync Logger requires a DB table:** `WP_List_Table` needs a real database table to query against. Custom post type is an alternative but adds wp_posts bloat. A dedicated `flxpnt_sync_log` table with indexed columns (timestamp, SKU, status) is cleaner and performant.
- **Variation Image Sync uses the same engine:** The only difference is the target — variation post ID instead of parent product post ID. Build product image sync first, then extend to variations. No architectural fork needed.
- **Download-or-Link toggles at runtime:** Not two separate engines. The Sync Engine reads a setting/parameter and branches the image handling. Keep the branching minimal — `if ( 'download' === $mode ) { media_handle_sideload() } else { update_post_meta() }`.
- **Sync UI depends on Logger + Sync endpoint:** Cannot display "last run: 3 succeeded, 1 failed" without the logger. Cannot provide "Sync Now" button without the AJAX handler. Build the logger table and sync endpoint before the admin UI.
- **Scheduled Sync depends on the same Sync Engine:** WP-Cron callback calls the same sync method as the manual trigger. The only difference is execution context (cron vs. AJAX) which affects error handling and user feedback.

## MVP Definition

### Launch With (v1)

Minimum viable product — what's needed to validate the concept.

- [ ] **Flxpoint API Client** — Paginated product listing endpoint, fetches products and their images by SKU. Foundation for everything else.
- [ ] **SKU Matcher** — `wc_get_product_id_by_sku()` for parent and variation products. Handles matched, unmatched, and duplicate cases.
- [ ] **Image Handler (download mode only)** — `media_handle_sideload()` for featured and gallery images. Download mode is the default expectation.
- [ ] **Manual "Sync Now" trigger** — Button in admin, AJAX handler, syncs all matched SKUs on demand. Essential for "does this actually work" validation.
- [ ] **Sync Summary/log** — After each run, show: X products synced, Y images, Z skipped, W failed. Stored in DB table with basic query. Without this, users have zero visibility.
- [ ] **Update-in-place behavior** — Overwrite `_thumbnail_id` and `_product_image_gallery`. Never touch product data. Never create new products.

### Add After Validation (v1.x)

Features to add once core sync is working and validated.

- [ ] **Variation image sync** — Extend SKU matching to variation SKUs. Requires validation that users actually have variable products connected to Flxpoint.
- [ ] **Scheduled hourly sync (WP-Cron)** — Register custom cron schedule, hook sync engine to cron event. Important for "set and forget" but requires robust error handling and overlap prevention.
- [ ] **Download-or-link toggle** — Add the configurable image handling mode. Link mode may need validation that WooCommerce handles external URLs in gallery properly.
- [ ] **Sync Log admin table** — Replace raw summary with proper `WP_List_Table` UI. Filterable by date, status, SKU search. Link entries to product edit pages.
- [ ] **Selective sync (updated since last run)** — Use Flxpoint's `?updated_after=` filter for scheduled syncs to avoid full catalog scans every hour.

### Future Consideration (v2+)

Features to defer until product-market fit is established.

- [ ] **Per-product sync** — Select individual SKUs to sync instead of all-or-nothing. Useful for stores with large catalogs.
- [ ] **Skip matched SKUs with no image changes** — Hash comparison to skip products whose images haven't changed in Flxpoint. Optimizes sync time.
- [ ] **Dry-run mode** — Preview what WOULD be synced without actually modifying products. User confidence builder.
- [ ] **Sync retry on failure** — Exponential backoff for failed API calls or image downloads. Requires persistent queue.
- [ ] **Webhook receiver** — Real-time sync triggered by Flxpoint webhook on product update. Only if users report hourly is too slow.
- [ ] **Action Scheduler integration** — Replace WP-Cron with Action Scheduler for more reliable background processing and better queue management.

## Feature Prioritization Matrix

| Feature | User Value | Implementation Cost | Priority |
|---------|------------|---------------------|----------|
| Flxpoint API Client (paginated products endpoint) | HIGH | MEDIUM | P1 |
| SKU Matcher (parent + variation) | HIGH | LOW | P1 |
| Featured image sync (download mode) | HIGH | LOW | P1 |
| Gallery image sync (download mode) | HIGH | MEDIUM | P1 |
| Manual "Sync Now" button + AJAX handler | HIGH | LOW | P1 |
| Sync summary/log (basic DB + output) | HIGH | MEDIUM | P1 |
| Update-in-place (no deletes/recreates) | HIGH | LOW | P1 |
| Variation image sync | MEDIUM | MEDIUM | P2 |
| Scheduled hourly sync (WP-Cron) | MEDIUM | MEDIUM | P2 |
| Download-or-link toggle | MEDIUM | MEDIUM | P2 |
| Sync log admin UI (WP_List_Table) | MEDIUM | MEDIUM | P2 |
| Incremental sync (updated_after filter) | LOW | MEDIUM | P2 |
| Per-product/SKU-selective sync | MEDIUM | HIGH | P3 |
| Dry-run preview mode | LOW | MEDIUM | P3 |
| Webhook receiver | LOW | HIGH | P3 |
| Action Scheduler migration | LOW | HIGH | P3 |

**Priority key:**
- P1: Must have for launch (v1)
- P2: Should have, add when possible (v1.x)
- P3: Nice to have, future consideration (v2+)

## Competitor Feature Analysis

| Feature | WP All Import + WC Add-On | WebToffee Import Export Pro | WP Desk Dropshipping XML | External Images (discontinued) | Our Approach (Flxpnt) |
|---------|---------------------------|-----------------------------|---------------------------|-------------------------------|----------------------|
| Image import source | CSV, XML, URL, FTP, Media Library | CSV, XML, XLSX, Google Sheets, URL | XML, CSV, FTP | CSV, JSON, XML, URL paste | Flxpoint REST API only (direct) |
| Featured + gallery images | Yes (pipe-separated URLs) | Yes | Yes | Yes | Yes (from API array, first = featured) |
| Variation images | Yes (dedicated tab) | Yes | Yes | Yes | Yes (match by variation SKU) |
| Image deduplication | Yes (by URL or filename) | Partial | No | No | Yes (check Media Library by source URL before download) |
| Image handling mode | Download only | Download only | Download only | External URL only | Both — toggle per run (differentiator) |
| Scheduled/automated sync | Yes (Pro, via cron) | Yes (Pro, via cron) | Yes (Pro) | No (discontinued) | Yes (hourly WP-Cron) |
| Manual trigger | Yes ("Run Import") | Yes | Yes | Manual import only | Yes ("Sync Now") |
| Sync log/history | Import summary + debug log | Import history | Error log | No | Dedicated DB table + WP_List_Table UI (differentiator) |
| Selective/partial sync | Yes (filter by any field) | Yes (filter by date range) | Yes (conditional logic) | No | Incremental by `updated_after` (v1.x) |
| Update existing products | Yes (match by SKU/ID) | Yes | Yes | Manual matching | Yes (SKU match, update-in-place) |
| Create new products | Yes | Yes | Yes | No | No (anti-feature for this plugin) |
| Admin UX complexity | HIGH (4-step wizard, drag-drop mapping, templates) | MEDIUM (wizard-based) | MEDIUM (feed configuration) | LOW (simple paste/upload) | LOW (Settings + Sync + Log tabs) |
| External dependencies | Composer packages | Composer packages | Composer packages | Unknown | Zero (WordPress core APIs only) |
| Pricing | $99-$299 | $69-$249 | $79 | Was $9/mo (discontinued) | Free (open source) |

## UX Patterns for Sync Operations (Evaluated)

### Pattern 1: "Sync Now" Button + AJAX Progress

**How it works:** A prominent button triggers an AJAX call. The response returns a summary (X synced, Y skipped, Z failed). No real-time progress bar.

**Examples:** WP Crontrol "Run Now", Stock Sync manual trigger, Flxpoint connection test (existing codebase).

**Why use this for v1:** Simple to implement, matches existing codebase patterns, sufficient for catalog sizes under 500 products.

**Limitation:** Long-running syncs leave the user staring at a spinning button with no feedback. Mitigated by: (a) incremental/batched sync, (b) logging each result as it happens so even partial results are visible.

**Implementation:**
```
[Sync Now] button → AJAX POST → Server processes batch → Returns JSON summary
                                                    → Updates log table per-item
                                                    → UI refreshes with last-run summary
```

### Pattern 2: Admin Submenu Tabs (Settings / Sync / Log)

**How it works:** The top-level "Flxpoint" menu has submenu items. Each page has a distinct purpose — no single-page "everything" UI.

**Examples:** WooCommerce core (Settings / Status / Logs tabs), Stock Sync (Settings / Sync / Log tabs).

**Why use this:** Separates concerns. Users configure once (Settings), sync when needed (Sync), and check results (Log). Avoids the "wall of settings" anti-pattern.

**Implementation:**
```
Flxpoint (top-level menu)
  ├── Settings (existing page — API credentials, connection test)
  ├── Sync (new — "Sync Now" button, download/link toggle, last run summary)
  └── Log (new — WP_List_Table with sync history)
```

### Pattern 3: WP_List_Table for Sync Log

**How it works:** Standard WordPress admin table with sortable columns, search, pagination, bulk actions. Used everywhere in WordPress core (Posts, Pages, Users, WC Orders, WC Logs).

**Examples:** `WC_Admin_Log_Table_List` (WooCommerce core), Stock Sync log, WP Crontrol event list.

**Why use this:** Users recognize it immediately. No learning curve. Built-in pagination handles 100K+ log entries. Provides column sorting and row actions for free.

**Implementation:**
```
Columns: Date | SKU (linked to product) | Action | Images | Status | Details
Row actions: View Product | View Error
Filters: Date range, Status (All | Success | Skipped | Failed)
Bulk actions: Delete selected
Per page: 20 | 50 | 100 (Screen Options)
```

### Pattern 4: Summary Panel After Sync

**How it works:** After a sync completes, display a colored summary bar with key metrics — not buried in a log table.

**Examples:** WP All Import (post-import summary with counts), WooCommerce CSV importer (success/warning/error counts).

**Why use this:** Immediate feedback. Users don't need to navigate to the Log tab to know what happened. Success (green), warnings (yellow), errors (red).

**Implementation:**
```
┌─────────────────────────────────────────────────────────┐
│  Sync completed in 42 seconds.                           │
│  ● 127 products updated    ● 3,421 images downloaded    │
│  ● 12 products skipped     ● 4 images failed (view)     │
└─────────────────────────────────────────────────────────┘
```

### Pattern 5: Settings + Sync Separation

**How it works:** Configuration lives on one page, operations on another. Users configure once, operate repeatedly. Common in WordPress but surprisingly missing in many sync plugins (which cram everything into one settings page).

**Why use this:** Reduces cognitive load. Settings page is visited rarely (initial setup). Sync page is visited frequently (manual syncs, checking status). The existing settings page stays as-is — no need to rebuild it.

### Anti-Patterns to Avoid

**Anti-Pattern: "Wizard" multi-step sync setup**
WP All Import's 4-step wizard (upload, map, configure, run) is powerful but intimidating for image-only sync. Our plugin needs zero configuration per sync run — credentials are already saved on the Settings page. One button, done.

**Anti-Pattern: Real-time progress bar with percentage**
Attractive UX but hard to implement correctly for image downloads (unknown total work when pages are infinite). Creates false expectations. Instead, show a spinner + "Processing..." then the summary.

**Anti-Pattern: Silent sync (no feedback)**
The #1 frustration from competitor reviews: "Sync completed but 0 products imported" with no explanation. Every outcome must be logged: matched, skipped (why?), failed (why?). Error messages must be actionable ("SKU ABC-123: image URL returned 404 — check Flxpoint" not "Error 404").

**Anti-Pattern: Page-blocking sync**
Synchronous HTTP requests during admin page load (e.g., `wp_remote_get` inside `display_plugin_settings_page`). Blocks the admin UI until the API responds. Use AJAX for manual sync and WP-Cron for scheduled sync. Never block admin page loads.

## Sources

- [WooCommerce External Images Plugin Documentation](https://woocommerce.com/document/external-images/) — Feature set of discontinued direct competitor (MEDIUM confidence, official docs but outdated/discontinued product)
- [Flxpoint WooCommerce Channel Documentation](https://support.flxpoint.com/en_US/sales-channels/woocommerce-as-a-channel) — Flxpoint's native WooCommerce integration capabilities (HIGH confidence, official vendor docs)
- [WP All Import WooCommerce Image Import Documentation](https://wpallimport.com/documentation/import-woocommerce-products-with-images) — Market leader feature set and UX patterns (MEDIUM confidence, vendor docs)
- [WebToffee Product Import Export Blog](https://www.webtoffee.com/blog/import-woocommerce-products-with-images/) — Best practices and competitor feature comparison (MEDIUM confidence, vendor blog)
- [WordPress Developer Reference: media_handle_sideload](https://developer.wordpress.org/reference/functions/media_handle_sideload) — Core image sideloading API (HIGH confidence, official WordPress docs)
- [WordPress Developer Reference: media_sideload_image](https://developer.wordpress.org/reference/functions/media_sideload_image) — High-level image download helper (HIGH confidence, official WordPress docs)
- [Action Scheduler Documentation](https://actionscheduler.org/) — Background job queue used by WooCommerce core (HIGH confidence, official library docs)
- [WooCommerce Code Reference: WC_Admin_Log_Table_List](https://woocommerce.github.io/code-reference/files/woocommerce-includes-admin-class-wc-admin-log-table-list.html) — Canonical log table UI pattern (HIGH confidence, official WooCommerce code reference)
- [WooCommerce GitHub Issue #26029](https://github.com/woocommerce/woocommerce/issues/26029) — Slow product sync via REST API, real-world pain points (MEDIUM confidence, user reports)
- [WordPress.org Support: Stock Sync for WooCommerce](https://wordpress.org/plugins/stock-sync-for-woocommerce/) — Sync plugin feature set and log patterns (MEDIUM confidence, plugin repository)
- [WordPress.org Support: Product Sync Reviews and Issues](https://wordpress.org/support/plugin/products-sync-for-woocommerce/reviews/) — Real user complaints about sync failures, silent errors, SKU issues (MEDIUM confidence, user reports)
- [Stack Overflow: WooCommerce programmatic image import](https://stackoverflow.com/questions/78390463/woocommerce-product-image-upload-import-programmatically) — Community implementation patterns (LOW confidence, unverified community content)
- [Existing codebase: Flxpnt_Admin, Flxpnt core class](file://includes/class-flxpnt.php) — Current plugin architecture and patterns (HIGH confidence, primary source)

---
*Feature research for: Flxpoint to WooCommerce Image Sync*
*Researched: 2026-05-21*
