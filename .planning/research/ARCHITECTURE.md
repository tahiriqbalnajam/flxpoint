# Architecture Research

**Domain:** WooCommerce image sync plugin (WordPress Plugin Boilerplate)
**Researched:** 2026-05-20
**Confidence:** HIGH (WooCommerce/WordPress APIs) / MEDIUM (Flxpoint API product field structure)

## Recommended Architecture

### System Overview

```
┌──────────────────────────────────────────────────────────────────────┐
│                        EXISTING PLUGIN SHELL                          │
│                                                                       │
│  flxpnt.php (bootstrap)                                               │
│  Flxpnt (orchestrator) ──→ Flxpnt_Loader (hook registry)             │
│  Flxpnt_Admin (settings, AJAX)    Flxpnt_Public (stub)               │
│  Flxpnt_i18n (translations)                                          │
└───────────────────────────┬──────────────────────────────────────────┘
                            │
                            │ new classes wired in define_admin_hooks()
                            ▼
┌──────────────────────────────────────────────────────────────────────┐
│                    SYNC ORCHESTRATION LAYER (NEW)                     │
│                     includes/class-flxpnt-sync.php                    │
│                       Flxpnt_Sync_Controller                          │
├──────────────────────────────────────────────────────────────────────┤
│                                                                       │
│  ┌─────────────────┐  ┌──────────────────┐  ┌───────────────────┐    │
│  │ Flxpnt_API_     │  │ Flxpnt_Image_    │  │ Flxpnt_Sync_      │    │
│  │ Client           │  │ Processor         │  │ Logger             │    │
│  │                  │  │                   │  │                    │    │
│  │ Fetches products │  │ Downloads images  │  │ Writes structured  │    │
│  │ and variations   │  │ to media library  │  │ logs to custom DB  │    │
│  │ from Flxpoint    │  │ or stores URLs    │  │ table              │    │
│  └────────┬─────────┘  └────────┬──────────┘  └────────┬───────────┘    │
│           │                     │                       │               │
│           │    ┌────────────────┴───────────────┐       │               │
│           │    │  Flxpnt_WC_Updater              │       │               │
│           │    │                                 │       │               │
│           │    │  • Matches by SKU via            │       │               │
│           │    │    wc_get_product_id_by_sku()    │       │               │
│           │    │  • Sets product/variation        │       │               │
│           │    │    image via set_image_id()       │       │               │
│           │    │  • Replaces, never deletes        │       │               │
│           │    └──────────────────────────────────┘       │               │
│           │                                               │               │
└───────────┴───────────────────────────────────────────────┴───────────────┘
                            │
                            ▼
┌──────────────────────────────────────────────────────────────────────┐
│                    WORDPRESS / WOOCOMMERCE LAYER                      │
│                                                                       │
│  wp_options          wp_posts/wp_postmeta      {$wpdb->prefix}        │
│  (API creds,          (products, variations,    sync_logs              │
│   sync state)          attachment IDs)          (custom table)         │
│                                                                       │
│  Action Scheduler ← Flxpoint API → wp_media (uploads)                 │
│  (batch jobs)        api.flxpoint.com          wp-content/uploads     │
└──────────────────────────────────────────────────────────────────────┘
```

### Component Boundaries and Responsibilities

| Component | Responsibility | File (existing/new) | Communicates With |
|-----------|---------------|---------------------|-------------------|
| **Flxpnt (orchestrator)** | Composition root: wires sync controller into loader, passes plugin identity | `includes/class-flxpnt.php` (modified) | Flxpnt_Sync_Controller, Flxpnt_Admin, Flxpnt_Loader |
| **Flxpnt_Admin** | UI triggers: "Sync Now" button, sync log display page, image-handling config option | `admin/class-flxpnt-admin.php` (modified) | Flxpnt_Sync_Controller (via action hooks) |
| **Flxpnt_Sync_Controller** | Sync orchestration: receives trigger (manual or cron), manages batch lifecycle, enforces transient lock, delegates to API/WC/Logger | `includes/class-flxpnt-sync-controller.php` (NEW) | Flxpnt_API_Client, Flxpnt_WC_Updater, Flxpnt_Sync_Logger, Action Scheduler |
| **Flxpnt_API_Client** | Flxpoint HTTP communication: paginated product/variation fetching, Bearer auth, response parsing, error handling | `includes/class-flxpnt-api-client.php` (NEW) | Flxpoint REST API, Flxpnt_Sync_Controller |
| **Flxpnt_Image_Processor** | Image acquisition: downloads from URL to media library, or validates/stores external URL. Returns attachment ID or URL string | `includes/class-flxpnt-image-processor.php` (NEW) | WordPress HTTP API, WordPress Media API, Flxpnt_WC_Updater |
| **Flxpnt_WC_Updater** | WooCommerce mutation: SKU lookup, `set_image_id()`, `set_gallery_image_ids()`, saves product/variation. Never creates or deletes products | `includes/class-flxpnt-wc-updater.php` (NEW) | WooCommerce CRUD API, Flxpnt_Image_Processor, Flxpnt_Sync_Logger |
| **Flxpnt_Sync_Logger** | Persistent logging: writes structured sync records to custom DB table, provides query methods for admin display | `includes/class-flxpnt-sync-logger.php` (NEW) | Custom DB table (`{$wpdb->prefix}flxpnt_sync_logs`), Flxpnt_Admin (for display) |
| **Flxpnt_Activator** | Activation-time setup: WooCommerce dependency check, Action Scheduler availability check, creates sync log table via dbDelta, schedules WP-Cron event | `includes/class-flxpnt-activator.php` (modified) | WordPress Options API, $wpdb |
| **Flxpnt_Deactivator** | Deactivation cleanup: unschedules WP-Cron event, clears Action Scheduler pending actions for this plugin | `includes/class-flxpnt-deactivator.php` (modified) | WordPress Cron API, Action Scheduler |

### Interaction Contract

Each NEW class follows the same constructor pattern as the existing codebase:

```php
// Constructor receives plugin identity (same pattern as Flxpnt_Admin)
class Flxpnt_Sync_Controller {
    private $plugin_name;
    private $version;

    public function __construct( $plugin_name, $version ) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
    }
}
```

The orchestrator (`Flxpnt`) instantiates each component and registers its action hooks via the Loader, exactly as it does for `Flxpnt_Admin` and `Flxpnt_Public`.

---

## Data Flow: Image Sync Pipeline

### Flow 1: Manual "Sync Now" Trigger

```
User clicks "Sync Now" in WP Admin
    │
    ▼
Flxpnt_Admin::handle_sync_now()
    │  AJAX handler: validates nonce, checks capability
    │  Sets transient lock: flxpnt_sync_lock (5 min timeout)
    │  Sets initial state: flxpnt_sync_running = true
    │  Enqueues first batch via Action Scheduler
    ▼
as_enqueue_async_action( 'flxpnt_sync_batch', [ 'page' => 1 ] )
    │
    ▼
Flxpnt_Sync_Controller::process_batch( $page )
    │  1. Check lock still held (or hold if this is first batch)
    │  2. Call Flxpnt_API_Client::fetch_products( $page, $per_page=50 )
    │     → GET /products?page={page}&limit=50 with Bearer token
    │     → Parse response: extract product data array
    │     → For each product, extract: sku, images[], variations[]
    │  3. If products returned:
    │     a. For each product: Flxpnt_WC_Updater::update_product_images( $product_data )
    │     b. For each variation within product: Flxpnt_WC_Updater::update_variation_images( $variation_data )
    │     c. Log result per entity to Flxpnt_Sync_Logger
    │     d. Enqueue next batch: as_enqueue_async_action( 'flxpnt_sync_batch', [ 'page' => $page+1 ] )
    │  4. If no products returned (end of pagination):
    │     a. Release transient lock
    │     b. Set flxpnt_sync_running = false
    │     c. Store completion timestamp: flxpnt_last_sync_time
    │     d. Log aggregate summary
    │  5. If API error:
    │     a. Log failure, retry or abort depending on error type
    ▼
Flxpnt_WC_Updater::update_product_images( $product_data )
    │  1. $product_id = wc_get_product_id_by_sku( $sku )
    │     → Returns false if SKU not found in WooCommerce
    │  2. If no match: log "skipped - SKU not found", return
    │  3. Load product: $product = wc_get_product( $product_id )
    │  4. Extract primary image URL from $product_data['images']
    │  5. Flxpnt_Image_Processor::acquire_image( $url, $product_id, $mode )
    │     → $mode = 'download' or 'external' (from admin setting)
    │     → Returns attachment_id (download mode) or URL string (external mode)
    │  6. If download mode: $product->set_image_id( $attachment_id )
    │     If external mode: use WooCommerce external product image pattern?
    │  7. Process gallery images: same acquire flow for each
    │  8. $product->set_gallery_image_ids( $attachment_ids[] )
    │  9. $product->save()
    │ 10. Log: "updated product {sku}: {N} images set"
    ▼
Flxpnt_WC_Updater::update_variation_images( $variation_data )
    │  Same flow as above but:
    │  - Matches variation by variation SKU
    │  - Uses WC_Product_Variation instead of WC_Product
    │  - Variation has only one image (no gallery)
```

### Flow 2: Scheduled (WP-Cron) Trigger

```
WP-Cron fires 'flxpnt_hourly_sync' event
    │
    ▼
Flxpnt_Sync_Controller::handle_cron_sync()
    │  Checks: is cron sync enabled in settings?
    │  Checks: is flxpnt_sync_lock transient already set? → Abort if yes
    │  Sets transient lock
    │  Sets flxpnt_sync_running = true
    │  Enqueues first batch via Action Scheduler
    ▼
[Same batch processing flow as manual trigger]
```

### State Management

| State | Storage | Lifetime | Purpose |
|-------|---------|----------|---------|
| API credentials (base_url, token) | `wp_options` (`flxpnt_api_base_url`, `flxpnt_api_token`) | Persistent | Authentication for Flxpoint API |
| Image handling mode | `wp_options` (`flxpnt_image_mode`: `download` or `external`) | Persistent | Per-product image storage strategy |
| Sync lock | Transient (`flxpnt_sync_lock`) | 5 minutes, auto-expires | Prevent concurrent sync runs |
| Sync running flag | `wp_options` (`flxpnt_sync_running`) | Until sync completes | Admin UI state indicator |
| Last sync timestamp | `wp_options` (`flxpnt_last_sync_time`) | Persistent | Display in admin |
| Cron enabled flag | `wp_options` (`flxpnt_cron_enabled`) | Persistent | Toggle hourly sync |
| Sync progress (current page) | `wp_options` (`flxpnt_sync_progress`) | Until sync completes | Resume capability (future) |
| Individual sync records | Custom DB table (`flxpnt_sync_logs`) | Persistent, with retention policy | Queryable log of every sync action |
| Connection status | Transient (`flxpnt_connection_status`) | 60 seconds (existing) | Connection test result display |

### Sync Log Table Schema

```sql
CREATE TABLE {$wpdb->prefix}flxpnt_sync_logs (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    sync_batch VARCHAR(40) NOT NULL,
    entity_type VARCHAR(20) NOT NULL COMMENT 'product or variation',
    entity_sku VARCHAR(100) NOT NULL,
    wc_product_id BIGINT(20) UNSIGNED DEFAULT NULL,
    action VARCHAR(20) NOT NULL COMMENT 'updated, skipped, failed',
    image_count INT UNSIGNED DEFAULT 0,
    message TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY batch (sync_batch),
    KEY entity_sku (entity_sku),
    KEY action (action),
    KEY created_at (created_at)
) {$charset_collate};
```

---

## Recommended Project Structure

```
flxpnt/
├── flxpnt.php                          # Bootstrap (modified: schedule cron on activation)
├── uninstall.php                       # Uninstall (modified: drop custom table)
├── index.php
├── README.txt
│
├── includes/                           # Core/Includes Layer
│   ├── class-flxpnt.php                # Orchestrator (modified: wire new components)
│   ├── class-flxpnt-loader.php         # Hook registry (unchanged)
│   ├── class-flxpnt-i18n.php           # i18n (unchanged)
│   ├── class-flxpnt-activator.php      # Activation (modified: check WC, create table, schedule cron)
│   ├── class-flxpnt-deactivator.php    # Deactivation (modified: unschedule cron, clear AS actions)
│   │
│   ├── class-flxpnt-sync-controller.php   # NEW: Sync orchestration
│   ├── class-flxpnt-api-client.php        # NEW: Flxpoint HTTP communication
│   ├── class-flxpnt-image-processor.php   # NEW: Image download/sideload
│   ├── class-flxpnt-wc-updater.php        # NEW: WooCommerce product/variation updates
│   └── class-flxpnt-sync-logger.php       # NEW: Structured sync logging
│
├── admin/                              # Admin Layer
│   ├── class-flxpnt-admin.php          # Admin (modified: add sync trigger, log display, image mode)
│   ├── js/
│   │   └── flxpnt-admin.js             # Admin JS (modified: sync button, progress polling)
│   ├── css/
│   │   └── flxpnt-admin.css            # Admin CSS (modified: sync UI styles)
│   └── partials/
│       ├── flxpnt-admin-settings.php   # Settings page (modified: image mode, cron toggle)
│       └── flxpnt-admin-sync-log.php   # NEW: Sync log display page
│
├── public/                             # Public Layer (unchanged, or stripped of dead assets)
│   ├── class-flxpnt-public.php
│   ├── js/
│   ├── css/
│   └── partials/
│
└── languages/
    └── flxpnt.pot
```

### Structure Rationale

- **New classes go in `includes/`** -- this matches the existing WordPress Plugin Boilerplate convention where the orchestrator's `load_dependencies()` requires files from `includes/`. Keeping sync logic here keeps it as a "plugin-wide concern" rather than tying it to admin or public.
- **Admin gets sync UI, not sync logic** -- `Flxpnt_Admin` gains AJAX handlers for manual sync triggers and log display, but delegates all business logic to `Flxpnt_Sync_Controller`. This preserves the existing separation: admin = WordPress integration, includes = business logic.
- **No new public-facing files** -- the plugin has no frontend user-facing functionality. Public JS/CSS should be un-enqueued until needed.
- **Explicit require_once chain** -- each new class file is loaded in `Flxpnt::load_dependencies()`, following the existing pattern exactly.

---

## Architectural Patterns

### Pattern 1: Loader-Based Hook Registration (Existing, Extended)

**What:** All WordPress hooks (actions, filters) are defined in the orchestrator's `define_admin_hooks()` and `define_public_hooks()` methods, collected by `Flxpnt_Loader`, and batch-registered on `run()`. New sync hooks follow the same pattern.

**When to use:** Any WordPress integration point -- admin AJAX, cron scheduling, Action Scheduler callbacks.

**How to extend for sync hooks:**

```php
// In Flxpnt::define_admin_hooks()
private function define_admin_hooks() {
    // ... existing hooks ...

    // Sync triggers
    $this->loader->add_action(
        'wp_ajax_flxpnt_sync_now',
        $this->sync_controller,
        'handle_manual_sync'
    );
    $this->loader->add_action(
        'flxpnt_hourly_sync',
        $this->sync_controller,
        'handle_cron_sync'
    );

    // Sync batch processing (Action Scheduler callback)
    $this->loader->add_action(
        'flxpnt_sync_batch',
        $this->sync_controller,
        'process_batch'
    );
}
```

### Pattern 2: Batch Processing Chain via Action Scheduler

**What:** Instead of loading ALL Flxpoint products in one request (which would timeout), the sync processes products in batches of 50. Each batch enqueues the next batch via `as_enqueue_async_action()`. This self-chaining pattern processes the full catalog without memory or timeout issues.

**When to use:** Processing paginated API data where total count is unknown or large.

**Trade-offs:** Slightly slower than bulk processing (sequential batches), but avoids PHP timeout and memory exhaustion. Progress is visible per-batch rather than all-at-once.

**Example (simplified flow):**

```php
// Flxpnt_Sync_Controller::process_batch( $page )
public function process_batch( $page ) {
    // 1. Fetch page from API
    $products = $this->api_client->fetch_products( $page, 50 );

    // 2. Process each product
    foreach ( $products as $product_data ) {
        $this->wc_updater->update_product_images( $product_data );
        // variations within the product follow the same pattern
    }

    // 3. Chain next batch or finish
    if ( ! empty( $products ) ) {
        as_enqueue_async_action( 'flxpnt_sync_batch', array( 'page' => $page + 1 ) );
    } else {
        $this->complete_sync();
    }
}
```

### Pattern 3: Transient Lock (Concurrency Guard)

**What:** A semaphore using the WordPress Transients API that prevents multiple sync processes from running simultaneously. The lock auto-expires after a timeout to prevent deadlock if a process crashes.

**When to use:** Any long-running background process that should not have concurrent instances.

**Trade-offs:** Simple, uses native WordPress APIs, no deadlock risk due to auto-expiry. Not suitable for sub-second lock granularity (object caching would be needed for that).

**Example:**

```php
// Flxpnt_Sync_Controller
private function acquire_lock() {
    if ( get_transient( 'flxpnt_sync_lock' ) ) {
        return false; // Another sync is already running
    }
    set_transient( 'flxpnt_sync_lock', true, 5 * MINUTE_IN_SECONDS );
    return true;
}

private function release_lock() {
    delete_transient( 'flxpnt_sync_lock' );
}
```

### Pattern 4: Constructor Injection of Plugin Identity

**What:** All new classes receive `$plugin_name` and `$version` via constructor, matching the existing pattern used by `Flxpnt_Admin` and `Flxpnt_Public`. This ensures consistent naming of options, transients, hooks, and asset handles.

**When to use:** Every new class that registers hooks, stores options, or enqueues assets.

**Why:** The `flxpnt` prefix constraint applies to ALL identifiers. Passing the plugin name as a constructor parameter makes it impossible to accidentally use a hardcoded prefix that drifts from the canonical value.

---

## Anti-Patterns to Avoid

### Anti-Pattern 1: Direct API Calls in Admin Handlers

**What people do:** Put `wp_remote_get()` calls directly in AJAX handlers or admin page rendering methods. The existing `Flxpnt_Admin::handle_test_connection()` already does this -- it is acceptable for a lightweight connection test but should NOT be the pattern for sync operations.

**Why it's wrong:** Blocks the PHP process for the full HTTP request duration. For sync operations with paginated API calls, this means the admin UI hangs until all pages are fetched. Also couples HTTP communication details (headers, error handling, pagination) with WordPress integration concerns.

**Do this instead:** `Flxpnt_API_Client` owns all HTTP communication with Flxpoint. Admin handlers call the sync controller, which calls the API client. This separation allows testing the API client in isolation and makes it possible to swap the HTTP transport if needed.

### Anti-Pattern 2: Storing Sync Logs in wp_options

**What people do:** Serialize sync log arrays into a single `wp_options` row, or create many individual option rows per log entry.

**Why it's wrong:** The `wp_options` table is not designed for write-heavy, append-only data. Serialized arrays cannot be queried with SQL (can't filter by status, date, or entity). Individual option rows pollute the options table namespace. Neither approach supports the "show me only failures from the last 7 days" query that admin log display requires.

**Do this instead:** Use a custom database table (created via `dbDelta()`) with proper indexes on `action`, `entity_sku`, `created_at`, and `sync_batch`. This enables efficient filtered queries and retention cleanup (`DELETE ... WHERE created_at < ...`). Use `$wpdb->prefix` for table naming.

### Anti-Pattern 3: Syncing Without a Lock

**What people do:** Schedule a cron event and let it fire without checking if a previous run is still executing.

**Why it's wrong:** If a sync takes longer than the cron interval (e.g., a large catalog that takes 90 minutes with hourly scheduling), the next cron tick starts a second sync process. Both processes update the same products simultaneously, wasting API quota and potentially creating inconsistent image states.

**Do this instead:** Use the transient lock pattern. Before starting any sync work, check for and acquire the lock. If the lock is held, log "sync skipped -- previous run still active" and return.

### Anti-Pattern 4: Creating/Mutating Products Not Matching by SKU

**What people do:** When a Flxpoint product's SKU is not found in WooCommerce, create a new product, or worse, skip silently without logging.

**Why it's wrong:** The project explicitly states "only update existing SKU matches -- never create products." Creating products violates project scope. Silently skipping without logging makes it impossible to diagnose why sync is "failing" for certain products.

**Do this instead:** When `wc_get_product_id_by_sku()` returns false, log a "skipped" entry with the SKU and reason "SKU not found in WooCommerce." This makes skipped products visible in the sync log, allowing the store owner to investigate whether the product should exist or the SKU is mismatched.

---

## Scaling Considerations

| Catalog Size | Sync Approach | Expected Duration | Risk |
|-------------|---------------|-------------------|------|
| 1-100 products | Single batch (50/page, 2 pages) | < 30 seconds | None |
| 100-1,000 products | Action Scheduler batches | 1-5 minutes | None |
| 1,000-10,000 products | Action Scheduler batches | 5-30 minutes | Transient lock timeout (mitigated by lock refresh in each batch) |
| 10,000-50,000 products | Action Scheduler batches with lock refresh | 30 min - 2 hours | WP-Cron overlap risk (mitigated by lock) |
| 50,000+ products | Same approach, but consider increasing Action Scheduler batch size and memory limits | 2+ hours | Host memory limits; consider WP-CLI sync option |

### Scaling Priorities

1. **First bottleneck: Image download time.** Each image is a separate `download_url()` HTTP call. With 5 images per product and 1,000 products, that's 5,000 HTTP requests. Mitigation: process images within each batch rather than accumulating all URLs first. The batch size of 50 products (~250 images max) keeps per-batch HTTP requests manageable.

2. **Second bottleneck: Flxpoint API rate limits.** The Flxpoint API may have rate limiting (unknown from public docs). Mitigation: add configurable delay between API calls if rate limiting is encountered. The batch processing pattern with `time() + N` delays between batches naturally throttles requests.

3. **Third bottleneck: WordPress media library size.** If "download" mode is used for thousands of products with multiple images each, the `wp-content/uploads` directory and `wp_posts`/`wp_postmeta` tables grow significantly. Mitigation: the "external URL" mode provides an escape hatch for hosts with storage constraints.

---

## Integration Points

### External Services

| Service | Integration Pattern | Notes |
|---------|---------------------|-------|
| Flxpoint REST API | `wp_remote_get()` via `Flxpnt_API_Client`, Bearer token auth | Pagination via `?page=N&limit=50` query params. Product/image field structure must be verified during implementation. Connection test already proves endpoint accessibility. |
| WooCommerce | `wc_get_product_id_by_sku()`, `wc_get_product()`, `set_image_id()`, `set_gallery_image_ids()`, `save()` | All WooCommerce functions available since WC is a hard dependency. No REST API calls needed -- use PHP CRUD API directly. |
| WordPress Media | `download_url()` + `media_handle_sideload()`, or `media_sideload_image()` | Requires `wp-admin/includes/media.php`, `file.php`, `image.php` to be loaded (already available in admin context, may need explicit requires in cron context). |
| Action Scheduler | `as_enqueue_async_action()`, `as_next_scheduled_action()` | No separate installation -- ships with WooCommerce. Available whenever WooCommerce is active. |

### Internal Boundaries

| Boundary | Communication | Notes |
|----------|---------------|-------|
| Flxpnt_Admin -> Flxpnt_Sync_Controller | WordPress action hooks (via Loader) | Admin triggers `wp_ajax_flxpnt_sync_now`; controller callback processes. No direct method calls between these classes. |
| Flxpnt_Sync_Controller -> Flxpnt_API_Client | Direct method calls | Controller instantiates and holds a reference to API Client. Constructor injection of plugin identity. |
| Flxpnt_Sync_Controller -> Action Scheduler | `as_enqueue_async_action()` | Controller uses Action Scheduler functions directly (no wrapper needed -- AS is a WordPress-native function library). |
| Flxpnt_WC_Updater -> Flxpnt_Image_Processor | Direct method calls | Updater calls `acquire_image($url, $parent_id, $mode)` and receives attachment ID or URL. |
| All components -> Flxpnt_Sync_Logger | Direct method calls | Logger is a write-only service. All components call `log($data)` during sync. Logger handles DB write. |

---

## Build Order Implications

The dependency graph among new components:

```
Flxpnt_Sync_Logger     ←  No dependencies (needs only $wpdb + dbDelta table)
Flxpnt_API_Client      ←  No dependencies (needs only wp_remote_get + stored credentials)
Flxpnt_Image_Processor ←  No dependencies (needs only WordPress media functions)
Flxpnt_WC_Updater      ←  Depends on: Flxpnt_Image_Processor, Flxpnt_Sync_Logger
Flxpnt_Sync_Controller ←  Depends on: Flxpnt_API_Client, Flxpnt_WC_Updater, Flxpnt_Sync_Logger
```

**Recommended build order:**

1. **Foundation phase:** `Flxpnt_Sync_Logger` + custom DB table + `Flxpnt_Activator` modifications (WooCommerce dependency check, table creation, cron scheduling). These have zero dependencies and establish the logging infrastructure and lifecycle hooks.

2. **API layer phase:** `Flxpnt_API_Client` + `Flxpnt_Image_Processor`. These are independent of each other and of WooCommerce mutation logic. The API client can be tested with the existing connection test credentials.

3. **WooCommerce integration phase:** `Flxpnt_WC_Updater`. This ties the API data to WooCommerce mutations. Requires the image processor (to convert URLs to attachment IDs) and the logger (to record results).

4. **Orchestration phase:** `Flxpnt_Sync_Controller` + `Flxpnt_Admin` modifications. This wires everything together: manual trigger, cron trigger, batch chaining, lock management, and sync log display in admin.

5. **UI and polish phase:** Admin UI for sync progress, log display, image mode setting, cron toggle. JavaScript polling for sync status updates.

---

## Sources

- [WooCommerce: Programmatically set product image from URL](https://dev.micka39.info/woocommerce/plugin/php/2022/02/28/how-to-upload-programmatically-product-image-woocommerce.html) -- HIGH confidence
- [WordPress: media_handle_sideload() reference](https://developer.wordpress.org/reference/functions/media_handle_sideload/) -- HIGH confidence
- [WordPress: media_sideload_image() reference](https://developer.wordpress.org/reference/functions/media_sideload_image/) -- HIGH confidence
- [WooCommerce: Action Scheduler background processing](https://actionscheduler.org/) -- HIGH confidence
- [Action Scheduler: Batch processing pattern example](https://pramodjodhani.com/2023/12/21/using-action-scheduler-to-process-large-data-in-multiple-small-batches/) -- MEDIUM confidence
- [WordPress: Scheduling WP-Cron events (Plugin Handbook)](https://developer.wordpress.org/plugins/cron/scheduling-wp-cron-events/) -- HIGH confidence
- [WooCommerce: Getting product by SKU (wc_get_product_id_by_sku)](https://rudrastyh.com/woocommerce/get-product-or-variation-by-sku.html) -- MEDIUM confidence
- [WooCommerce: Setting variation image programmatically](https://wordpress.org/support/topic/assign-variation-image-programmatically/) -- MEDIUM confidence
- [WordPress: Transient-based lock for cron job concurrency](https://wordpress.stackexchange.com/questions/308785/how-to-make-sure-that-only-one-wp-cron-runs-at-a-time) -- MEDIUM confidence
- [WordPress: Custom database tables in plugins (Plugin Handbook)](https://developer.wordpress.org/plugins/creating-tables-with-plugins/) -- HIGH confidence
- [Flxpoint API v2 documentation](https://flxpoint.stoplight.io/docs/flxpoint-api) -- LOW confidence (specific product/image field structure not available from public docs; must verify during implementation)

---

*Architecture research for: Flxpnt -- Flxpoint-to-WooCommerce image sync*
*Researched: 2026-05-20*
