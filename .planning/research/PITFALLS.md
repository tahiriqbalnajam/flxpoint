# Pitfalls Research: Flxpoint to WooCommerce Image Sync

**Domain:** WooCommerce product/variation image sync from external API
**Researched:** 2026-05-21
**Confidence:** HIGH

---

## Critical Pitfalls

Mistakes that cause rewrites, data corruption, or security incidents.

### Pitfall 1: `download_url()` Default 300-Second Timeout Destroys Batch Sync

**What goes wrong:**
Each call to `download_url()` blocks for up to 300 seconds (5 minutes) per image by default. During a batch sync of 500 products, if the Flxpoint CDN is slow, behind a WAF, or unreachable for even 10 images, the sync process hangs for up to 50 minutes before the PHP `max_execution_time` kills the process. All progress is lost -- no partial results are saved, and WooCommerce products remain in an inconsistent state (some updated, some not).

**Why it happens:**
`download_url()` wraps `wp_safe_remote_get()` which defaults to a 300-second timeout. Developers assume "it just works" and call `download_url( $url )` without the second parameter. The WordPress HTTP API only kills the connection after the full timeout -- there is no progressive backoff or early abort for hung connections. On shared hosting with `max_execution_time` set to 30 seconds, the PHP process dies before the timeout even fires.

**How to avoid:**
- Always pass an explicit timeout: `download_url( $url, 15 )` -- 15 seconds is enough for a healthy CDN, enough to fail fast for a bad one.
- Apply the `http_request_timeout` filter to 15 seconds at the start of the sync batch, restore it afterward (do NOT leave it modified globally -- it affects all WordPress HTTP requests including updates and cron).
- For the hourly WP-Cron trigger: increase `max_execution_time` at runtime via `set_time_limit( 300 )` or `ini_set( 'max_execution_time', 300 )` at the top of the sync handler. Note: `set_time_limit()` does not work when PHP is in safe mode (rare on modern hosting) or when `max_execution_time` is enforced at the OS/container level.
- Wrap each image download in a try/catch-like pattern -- `is_wp_error( $tmp )` is the primary check, but also track per-image success/failure so one bad image URL does not abort the entire batch.

**Warning signs:**
- Sync runs take progressively longer but import fewer images each cycle.
- Server error logs show "Maximum execution time exceeded" in `wp-includes/class-http.php` or `wp-admin/includes/file.php`.
- Action Scheduler queue (if used) shows "action timed out" or "cancelled" for sync actions.
- WooCommerce products have featured images set but gallery images missing (process died mid-product).

**Phase to address:**
Image sync implementation phase -- this must be designed into the download loop from day one. It cannot be retrofitted without rewriting the image fetching logic.

---

### Pitfall 2: Duplicate Media Library Entries on Every Sync Cycle

**What goes wrong:**
Every time the sync runs (hourly), images that have not changed on Flxpoint are re-downloaded and added to the WordPress media library as brand-new attachments. After one week, a catalog of 200 products with 4 images each generates up to 134,400 duplicate files. The `wp_posts` and `wp_postmeta` tables bloat, media library browsing becomes unusable, and backups grow exponentially.

**Why it happens:**
WordPress `media_handle_sideload()` has no built-in deduplication. When you pass it an image URL, it downloads the file, creates a new attachment post, generates all thumbnail sizes, and stores metadata -- every single time. The `guid` column in `wp_posts` stores the original URL, but `guid` is not a reliable key for deduplication (it can change, and WordPress explicitly warns against treating it as a URL).

Flxpoint may serve images from a CDN with rotating URLs, query parameters for cache busting, or different domains for the same file -- making URL-based deduplication unreliable.

**How to avoid:**
- Generate a content hash (SHA-256) of the downloaded file before inserting it into the media library. Store the hash as post meta (`_flxpnt_image_hash`) on the attachment. Before downloading any image, check if an attachment with that hash already exists.
- As a fallback for when you have not yet downloaded the file, check by filename and file size from the Flxpoint API response metadata (if available).
- For the "link externally" mode (no download), store the Flxpoint image URL as product post meta (`_flxpnt_external_image`) and use the `woocommerce_product_get_image` filter to serve it. This avoids the media library entirely for linked images but means images are not served from the local server.
- When calling `set_image_id()` or `set_gallery_image_ids()` during re-sync, pass the existing attachment ID (from the hash lookup) instead of creating a new attachment. WooCommerce's `$product->save()` will not duplicate images if you reuse the same attachment ID.

**Warning signs:**
- Media library shows identical images with `-1`, `-2`, `-3` suffixes on filenames.
- `wp_postmeta` table grows faster than expected (each attachment generates 10-20 postmeta rows for EXIF, sizes, etc.).
- Disk usage on `/wp-content/uploads/` increases linearly with number of sync cycles, not with number of unique images.
- `set_post_thumbnail()` / `set_image_id()` is called with a fresh `$attachment_id` on every sync cycle.

**Phase to address:**
Image sync implementation phase -- must be part of the `media_handle_sideload()` wrapper. Deduplication logic is a core component of the download pipeline, not an optimization to add later.

---

### Pitfall 3: WP-Cron Misses Scheduled Syncs on Low-Traffic Sites

**What goes wrong:**
The hourly image sync is scheduled via `wp_schedule_event()`, but on sites with low traffic (common for B2B, wholesale, or new stores), WP-Cron never fires because it relies on page visits to trigger. A sync scheduled for 3:00 AM may not run until 11:00 AM when the first customer visits the site. On sites behind full-page caching (Varnish, WP Rocket, Cloudflare), every page visit serves a cached HTML response without executing PHP, meaning WP-Cron never fires at all.

**Why it happens:**
WP-Cron is not a real cron daemon. It is a PHP function that checks for due events on every page load. No page load = no cron. If `DISABLE_WP_CRON` is set to `true` in `wp-config.php` (common on managed WordPress hosting like WP Engine, Kinsta, Cloudways), WP-Cron never fires unless a real server cron job has been configured to call `wp-cron.php` -- and many site owners do not know they need to do this.

**How to avoid:**
- Document in the plugin's README and settings page that reliable sync requires either a real server cron job calling `wp-cron.php` every 5 minutes, or sufficient site traffic.
- Add a "Last sync:" timestamp displayed on the admin settings page so users can see at a glance if syncs are running.
- Add a manual "Sync Now" button as a fallback (already planned as a requirement).
- On plugin activation, run `wp_schedule_event()` but also log an admin notice if `DISABLE_WP_CRON` is detected, warning the user about cron reliability.
- On the sync status admin page, show a warning if the last sync was more than 2 hours ago ("Sync may be delayed -- check WP-Cron configuration").
- Consider using Action Scheduler (bundled with WooCommerce) instead of raw WP-Cron. Action Scheduler has better lock handling, retry logic, and visibility via WooCommerce > Status > Scheduled Actions.

**Warning signs:**
- "Last sync" timestamp shows hours or days of delay with no intervening syncs.
- Pending actions visible in Action Scheduler with "past-due" status.
- Manual "Sync Now" button works but automatic sync never runs.
- Site is on managed WordPress hosting (WP Engine, Kinsta, Flywheel, Cloudways) where `DISABLE_WP_CRON` is set by default.

**Phase to address:**
Scheduled sync phase -- must be addressed when WP-Cron integration is implemented. The "Sync Now" button (manual trigger phase) provides a fallback for verification.

---

### Pitfall 4: Memory Exhaustion and Thumbnail Regeneration During Batch Sync

**What goes wrong:**
When `media_handle_sideload()` processes an image, WordPress generates ALL registered thumbnail sizes synchronously within the same PHP process. A catalog with WooCommerce's default sizes (thumbnail, medium, medium_large, large, woocommerce_thumbnail, woocommerce_single, woocommerce_gallery_thumbnail) plus any theme-registered sizes means every uploaded image triggers 7-15 image resize operations. Each resize loads the full image into memory, processes it with GD or Imagick, and writes the result. After 30-50 images, PHP memory is exhausted and the process dies with "Allowed memory size exhausted."

**Why it happens:**
WordPress fires `wp_generate_attachment_metadata()` inside `media_handle_sideload()`, which generates all registered image sizes for every attachment. There is no built-in memory management between images -- each `media_handle_sideload()` call leaks some memory (WordPress core functions like `get_post()`, `wp_update_post()`, and `wp_insert_attachment()` are known to leave objects in memory).

**How to avoid:**
- Before the sync batch starts, call `wp_suspend_cache_addition( true )` to prevent WordPress from caching every intermediate result. Restore with `wp_suspend_cache_addition( false )` after the batch.
- After every 10-15 images, call `wp_cache_flush()` and `gc_collect_cycles()` (if available) to force garbage collection.
- Use the `intermediate_image_sizes_advanced` filter to limit sizes generated during sync. Only generate `woocommerce_single` and `woocommerce_thumbnail` during sync; defer other sizes to a background process.
- Increase memory: `ini_set( 'memory_limit', '512M' )` at the start of the sync handler. Note: some hosts prevent runtime `ini_set()` for memory.
- For very large catalogs (>1000 products), split the sync into sub-batches and process them via Action Scheduler's queue system, where each batch is a separate PHP process.
- Do NOT use `wp_suspend_cache_invalidation( true )` during image sync -- it only affects post/term caches, not media processing memory.
- Set `wp_defer_term_counting( true )` during sync to avoid term recount on every attachment (media library uses the `post` taxonomy structure).

**Warning signs:**
- Sync dies silently partway through with no error in the UI (only in PHP error log).
- The number of products/images synced varies wildly between runs due to memory pressure.
- Smaller images (thumbnails) are missing after sync but the full-size image exists.
- Products processed early in the batch get all images; products later in the batch get partial or no images.

**Phase to address:**
Image sync implementation phase -- the download loop structure must account for memory management from the start.

---

### Pitfall 5: Variation SKU Matching Returns Parent Product ID

**What goes wrong:**
When matching a variation image from Flxpoint to a WooCommerce variation, calling `wc_get_product_id_by_sku( $variation_sku )` returns the **parent variable product ID**, not the variation ID. The sync code then calls `$parent_product->set_image_id( $attachment_id )` on the parent product, setting the variation's image as the parent's featured image. Variation images are never set, parent product featured image gets overwritten with random variation images on each sync cycle.

**Why it happens:**
`wc_get_product_id_by_sku()` queries `wp_postmeta` for `meta_key = '_sku'` and `meta_value = $sku`, then returns the `post_id`. For variations, the `post_id` stored in postmeta is the variation's ID -- but `wc_get_product_id_by_sku()` internally applies a filter that maps variation IDs to their parent product ID. This is by design (WooCommerce considers SKUs unique across all products and variations). The function returns the "product ID" which for a variation means the parent.

Developers who do not read the source code of `wc_get_product_id_by_sku()` assume it returns the exact post ID from the meta query and proceed to use it directly.

**How to avoid:**
- For variation-only queries, use `wc_get_products( [ 'sku' => $variation_sku, 'type' => 'variation', 'limit' => 1 ] )` which returns a `WC_Product_Variation` object with the correct `get_id()`.
- Alternatively, query `wp_postmeta` directly for `_sku` and verify that the returned post has `post_type = 'product_variation'`.
- Maintain a separate lookup: when the Flxpoint API response includes a `parent_sku` field for variations, use it to find the parent product first, then iterate its children to find the matching variation SKU using `$product->get_available_variations()` or `$product->get_children()`.
- Store the `_flxpnt_last_sync` post meta on both parent products and variations with a timestamp, so the sync log can distinguish between parent and variation updates.

**Warning signs:**
- Parent variable product featured image changes to a random variation's image after sync.
- Variation images are never set (still showing parent product image or placeholder).
- Sync log shows "updated product image for SKU XYZ" but inspection reveals the wrong image on the parent.
- Using `wc_get_product_id_by_sku()` for variation SKUs anywhere in the codebase.

**Phase to address:**
Image sync implementation phase -- the SKU matching logic must differentiate between simple products, variable parent products, and variations from the start.

---

### Pitfall 6: XSS via Unsanitized API Response Inserted into DOM

**What goes wrong:**
The existing codebase at `admin/class-flxpnt-admin.php` line 119 includes the raw Flxpoint API response body in an error message string, which is returned via AJAX JSON. The JavaScript at `admin/js/flxpnt-admin.js` line 31 inserts this into the DOM using jQuery `.html()`. If the Flxpoint API returns HTML in its error response (e.g., a CDN error page, WAF challenge page, or a compromised endpoint), arbitrary scripts execute in the WordPress admin context with `manage_options` privileges.

**Why it happens:**
The code pattern is: `wp_remote_retrieve_body()` (raw, unsanitized) -> included in error string -> JSON response -> `.html()` insertion (executes any HTML/JS). Each step trusts the previous. The assumption is that `api.flxpoint.com` always returns clean JSON, but error responses from intermediate proxies (Cloudflare WAF, load balancer error pages, CDN 404 pages) are HTML, not JSON.

**How to avoid:**
- In `admin/js/flxpnt-admin.js` line 31: change `.html( html )` to `.text( html )`. This is the immediate, one-line fix that prevents HTML interpretation regardless of what the server sends.
- In `admin/class-flxpnt-admin.php`: apply `wp_strip_all_tags()` or `esc_html()` to `wp_remote_retrieve_body( $response )` before including it in any output string.
- Truncate the response body to 500 characters before including in error messages -- full API response bodies can be megabytes and break the admin UI layout.
- Check the response `Content-Type` header before treating the body as safe text. If the API returns `text/html` instead of `application/json`, the response is likely an error page from a proxy, not legitimate API data.

**Warning signs:**
- JavaScript `.html()` used anywhere to display API response data.
- `wp_remote_retrieve_body()` output passed directly to any output function without intermediate sanitization.
- No `Content-Type` validation on API responses before processing.

**Phase to address:**
Security hardening phase (immediate/early) -- this is a known vulnerability in the existing codebase that should be fixed before adding any new features that extend the pattern.

---

### Pitfall 7: API Token Plaintext Storage in Database

**What goes wrong:**
The Flxpoint Bearer token is stored as a plaintext WordPress option (`flxpnt_api_token`) in `wp_options`. Any SQL injection in any plugin on the site, a compromised database backup, or access to `wp-admin/options.php` reveals the live API token. The Settings page renders the full token in the HTML `value` attribute of the password field -- it is hidden on screen but visible in page source, to any browser extension with DOM access, and to anyone viewing a screen share.

**Why it happens:**
The existing code uses `register_setting( 'flxpnt_settings', 'flxpnt_api_token' )` without an encryption layer and renders it with `esc_attr( $api_token )` in the input field. This is the simplest implementation and matches the WordPress Plugin Boilerplate default pattern. No sanitize_callback is registered.

**How to avoid:**
- Encrypt the token at rest using `openssl_encrypt()` with a key derived from `AUTH_KEY` or `NONCE_KEY` (WordPress constants defined in `wp-config.php`). Store the encrypted value in `wp_options` and decrypt on retrieval.
- Never send the stored token back to the browser. After initial save, replace the input field's `value` attribute with a masked placeholder (e.g., `value="••••••••••••••••"`). Add a "Change Token" flow: show an empty password field when the user wants to enter a new token, but never echo the stored value.
- Add `sanitize_callback` to `register_setting()` that validates token length and strips whitespace.
- As a premium option: support defining `FLXPNT_API_TOKEN` in `wp-config.php` as a constant that takes precedence over the database-stored value.

**Warning signs:**
- `get_option( 'flxpnt_api_token' )` returns readable plaintext with no decryption step.
- Settings page HTML source contains the full token string.
- No `sanitize_callback` parameter on `register_setting()` for the token option.

**Phase to address:**
Security hardening phase (immediate/early) -- the existing codebase has this vulnerability and it should be addressed before the plugin handles real product data.

---

### Pitfall 8: No Rate Limiting on External API Calls

**What goes wrong:**
The connection test handler (`handle_test_connection()`) makes an outbound HTTP request with no transient-based throttle. The future sync handler will make hundreds of outbound requests to fetch product images. If Flxpoint has rate limits (common for SaaS APIs -- typically 60-120 requests per minute), the sync will be throttled or blocked. Worse, if the API returns `429 Too Many Requests`, the sync has no backoff/retry logic and will fail all subsequent images in the batch. Additionally, a malicious or confused admin clicking "Sync Now" rapidly could trigger multiple concurrent sync processes.

**Why it happens:**
The existing codebase has only a single API call (connection test) so rate limiting was not a concern. When image sync is added, the code will make one API call per image (to download) plus one or more API calls to fetch the product catalog listing. Without rate awareness, the sync treats Flxpoint like a local filesystem.

**How to avoid:**
- Research Flxpoint API rate limits before implementing sync. Check response headers for `X-RateLimit-Remaining`, `X-RateLimit-Reset`, or `Retry-After`.
- Implement a configurable request delay between image downloads (default: 200ms) using `usleep()` or `sleep()`. This is a blunt but effective throttle.
- For the sync trigger, add a transient lock (`flxpnt_sync_running`) with a 10-minute TTL. If the transient exists, reject the new sync request with an admin notice ("Sync already in progress").
- For the connection test, add a transient throttle (`flxpnt_test_connection_lock`) lasting 10 seconds to prevent rapid retries.
- Implement exponential backoff: if a request fails with a 429 or 5xx status, wait 2^n seconds before retrying (with `n` capped at 4, so max 16-second wait).
- Respect `Retry-After` headers if present in API responses.

**Warning signs:**
- No delay between successive `wp_remote_get()` / `download_url()` calls in the sync loop.
- No transient-based lock to prevent concurrent sync runs.
- API responses returning 429 or 503 status codes that are treated as permanent failures.
- No configurable throttle setting -- hardcoded 0 delay between requests.

**Phase to address:**
Image sync implementation phase -- rate limiting must be part of the API client layer from the start.

---

### Pitfall 9: No WooCommerce Dependency Check Before Plugin Activation

**What goes wrong:**
The plugin activates and runs normally without WooCommerce installed. All admin pages, settings, and cron events are registered. When the sync fires, the first call to any WooCommerce function (`wc_get_product_id_by_sku()`, `wc_get_product()`, `WC_Product` class) causes a fatal PHP error "Class not found." WordPress catches the fatal error and displays the "critical error" screen. The site owner cannot access the admin to deactivate the plugin without database intervention.

**Why it happens:**
`flxpnt.php` calls `run_flxpnt()` unconditionally. The activator (`includes/class-flxpnt-activator.php`) has an empty `activate()` method. There is no check for `class_exists( 'WooCommerce' )` at any point in the bootstrap or activation flow. The plugin describes itself as a "bridge between Flxpoint and Woocommerce" but does not enforce the requirement.

**How to avoid:**
- In the `Flxpnt_Activator::activate()` method, check `class_exists( 'WooCommerce' )`. If not found, deactivate the plugin and trigger `wp_die()` with a message: "Flxpnt requires WooCommerce to be installed and active."
- In `run_flxpnt()` (bootstrap), add a check: if `! class_exists( 'WooCommerce' )`, return early without registering any hooks.
- Add an admin notice on the plugins page if WooCommerce is inactive: "Flxpnt is inactive because WooCommerce is not active."
- Consider using `is_plugin_active( 'woocommerce/woocommerce.php' )` as a secondary check (requires `wp-admin/includes/plugin.php` to be loaded).

**Warning signs:**
- Plugin activates without any "Plugin requires WooCommerce" notice.
- Empty `activate()` method in `Flxpnt_Activator`.
- No `class_exists( 'WooCommerce' )` check in `flxpnt.php` or `includes/class-flxpnt.php`.

**Phase to address:**
Foundation/security phase (immediate) -- this is a one-line check that prevents a fatal error on every activation without WooCommerce.

---

### Pitfall 10: `$product->save()` Inside Loops Triggers Full WooCommerce CRUD Overhead

**What goes wrong:**
Setting an image on a product and calling `$product->save()` inside a loop over hundreds of products causes:
- **Cache invalidation** on every save -- WooCommerce flushes the product cache and related caches.
- **Lookup table updates** -- `wc_update_product_lookup_tables()` fires on every save, running multiple INSERT/UPDATE queries on `wc_product_meta_lookup`.
- **Term recounting** -- if the product has categories or attributes, `wp_defer_term_counting()` is not active by default, so term counts are recalculated on every save.
- **Action Scheduler jobs spawned** -- various WooCommerce hooks fire on `save()` that may schedule follow-up actions.

A sync of 500 products with 4 images each calling `$product->save()` once per image results in 2,000 full CRUD operations, each performing 20-50 database queries. Total: 40,000-100,000 queries for one sync cycle.

**Why it happens:**
The intuitive pattern is: "download image, set on product, save product, repeat." WooCommerce's `$product->save()` is designed for single-product editing flows (admin screens, REST API single-product endpoints), not for batch operations.

**How to avoid:**
- Batch all image assignments per product before calling `$product->save()` once per product. Do not save after setting each individual image.
- Before the sync loop: `wp_defer_term_counting( true )` and `wp_suspend_cache_invalidation( true )`. Restore after the batch completes.
- After the entire sync batch: call `wc_update_product_lookup_tables()` once manually (if needed) rather than letting it fire per-product.
- For very large syncs (>500 products), save in sub-batches of 50-100 products, flushing caches between sub-batches.
- Use `wp_cache_delete( $product_id, 'products' )` manually instead of relying on automatic cache invalidation per-save.
- Consider writing image IDs directly to post meta (`update_post_meta( $product_id, '_thumbnail_id', $attachment_id )` and `update_post_meta( $product_id, '_product_image_gallery', implode( ',', $attachment_ids ) )`) for bulk operations, then triggering a single cache clear at the end. This bypasses the WC product CRUD overhead entirely for image-only updates.

**Warning signs:**
- `$product->save()` called inside `foreach` over products or images.
- Sync takes 30+ seconds for <100 products.
- MySQL slow query log shows repeated `INSERT INTO wp_wc_product_meta_lookup` and `UPDATE wp_term_taxonomy SET count = ...` during sync.
- `wp_defer_term_counting()` and `wp_suspend_cache_invalidation()` not used anywhere.

**Phase to address:**
Image sync implementation phase -- the product update strategy must be designed for batch operation from the start.

---

## Technical Debt Patterns

Shortcuts that seem reasonable but create long-term problems.

| Shortcut | Immediate Benefit | Long-term Cost | When Acceptable |
|----------|-------------------|----------------|-----------------|
| Using `wp_remote_get()` + `file_put_contents()` instead of `download_url()` + `media_handle_sideload()` | Fewer function calls, direct control | No file validation, no MIME type detection, no thumbnail generation, no attachment metadata, corrupt files if response is not raw bytes | Never |
| Matching images by URL alone for deduplication | Simple string comparison | Flxpoint CDN URLs may rotate, change query params, or use different domains -- dedup breaks | Never; use content hash |
| Storing sync progress in transients only | Easy, no schema design | Transients can be evicted by object cache at any time, TTL means progress disappears mid-sync, no audit trail | Only for lock/temporary state, not progress tracking |
| Hardcoding `memory_limit` to 512M in the plugin | Prevents memory exhaustion | Breaks on hosts that enforce lower limits, does not work if `ini_set()` is disabled, masks the real problem (no batching) | Temporary dev workaround; not in production |
| Setting `set_time_limit(0)` (infinite) | Sync never times out | A stuck download hangs forever, PHP process never terminates, can fill up PHP-FPM pool | Never; use a high but finite limit (300-600s) |
| Running sync on `admin_init` or `init` hooks | Simple trigger mechanism | Sync blocks every admin page load, admin becomes unusable during sync, multiple concurrent syncs triggered by multiple admin tabs | Never; only manual button (AJAX) or WP-Cron |
| Storing sync results only in PHP error_log | No additional code | Admin never sees sync status, debugging requires server access, no way to know if sync worked | Never; log to a custom DB table or custom post type |

---

## Integration Gotchas

Common mistakes when connecting to external services.

| Integration | Common Mistake | Correct Approach |
|-------------|----------------|------------------|
| Flxpoint API pagination | Assuming all products/return in a single response; not implementing cursor/offset pagination | Fetch products in pages (likely 50-100 per page per Flxpoint API conventions), accumulate SKUs, then process in a second pass. Respect `Link` headers or `total_count` fields in API responses. |
| Flxpoint image URL expiration | Assuming image URLs are permanent; CDN signed URLs may expire after minutes/hours | Check if Flxpoint image URLs contain expiry tokens. If they do, images must be downloaded during the same API session, or fresh URLs fetched per sync cycle. |
| WooCommerce image property names | Using `"images"` (plural) for variation images; variations use `"image"` (singular) | Variations: `$variation->set_image_id( $attachment_id )`. Products: `$product->set_image_id( $id )` for featured, `$product->set_gallery_image_ids( $ids )` for gallery. |
| Flxpoint variation data model | Assuming variations have the same image fields as parent products; the Flxpoint response structure may differ | Inspect actual Flxpoint API response for variation objects. Map variation fields explicitly. Do not assume parent-product field names apply to variations. |
| WordPress HTTP transport | Assuming cURL is available; some hosts use PHP streams or sockets which may not support HTTPS | Check `wp_http_supports( [ 'ssl' => true ] )` on activation. Show admin warning if SSL transport is unavailable. Use `wp_remote_get()` (not raw cURL) for transport-agnostic requests. |
| Image filename encoding | Not sanitizing filenames from URL basename; special chars, spaces, or Unicode cause `media_handle_sideload()` to fail | Use `sanitize_file_name( basename( parse_url( $url, PHP_URL_PATH ) ) )` before passing to `media_handle_sideload()`. Strip query strings from the filename. |

---

## Performance Traps

Patterns that work at small scale but fail as usage grows.

| Trap | Symptoms | Prevention | When It Breaks |
|------|----------|------------|----------------|
| `media_handle_sideload()` called synchronously for every image in a single PHP process | Memory exhaustion, timeout, process death | Batch into Action Scheduler jobs of 10-15 images each as separate PHP processes | 100+ images per sync cycle |
| Full WooCommerce CRUD save per image assignment | Slow sync, database write spikes, lookup table churn | Batch all image assignments per product, save once, defer term counting and cache invalidation | 50+ products |
| No pagination on Flxpoint product listing API calls | PHP out of memory loading massive JSON response, timeout on large responses | Paginate with page/limit params, process one page at a time | 500+ products in Flxpoint |
| Generating all thumbnail sizes during sync | Long per-image processing time, memory per image spikes | Filter `intermediate_image_sizes_advanced` to only generate WooCommerce-specific sizes during sync; defer others | 50+ images with 10+ registered sizes |
| Loading all product data into an array before processing | Memory exhaustion storing full API responses for every product | Stream-process: fetch one page of products, process it, discard, fetch next page | 1000+ products |
| `wp_cache_flush()` after every product save | Cache becomes ineffective, all subsequent queries miss | Only flush cache at the end of the batch or between sub-batches of 50-100 products | Any batch size with persistent object cache |
| No garbage collection between images | Memory creeps up linearly with image count, process dies at inconsistent points | Call `wp_suspend_cache_addition( true )` during batch and `wp_cache_flush()` + `gc_collect_cycles()` every 10-15 images | 30+ images per batch |

---

## Security Mistakes

Domain-specific security issues beyond general web security.

| Mistake | Risk | Prevention |
|---------|------|------------|
| Flxpoint API token stored in plaintext `wp_options` | Token exfiltration via SQL injection, DB backup theft, or options.php access | Encrypt with `openssl_encrypt()` using `AUTH_KEY`; support `FLXPNT_API_TOKEN` constant in `wp-config.php` |
| API token rendered in settings page HTML source | Token visible in browser DevTools, page source, and screenshots | Mask the displayed value after save; never send stored token to browser as `value` attribute |
| XSS via API error body -> `.html()` insertion (existing bug) | Arbitrary JS execution in WP admin if Flxpoint returns HTML error page | Use `.text()` instead of `.html()`; `wp_strip_all_tags()` on API response body before output |
| No nonce check on future sync endpoints | CSRF allows attacker to trigger syncs, potentially exhausting API rate limits or server resources | Always use `check_ajax_referer()` (AJAX) or `wp_verify_nonce()` (GET/POST) for any action endpoint |
| No capability check on sync trigger | Subscriber-level users could trigger resource-intensive sync | Enforce `manage_options` (or `manage_woocommerce`) capability on all sync trigger endpoints |
| Flxpoint API response used without schema validation | Malformed or malicious API response causes PHP warnings, unexpected behavior, or data corruption | Validate API response structure before processing (check for expected keys, data types, non-empty values) |
| Image MIME type not validated against allowed types | Malicious file with spoofed MIME type uploaded to media library; potential RCE via polyglot files | Use `wp_check_filetype_and_ext()` after download; reject files that do not match allowed image MIME types |

---

## UX Pitfalls

Common user experience mistakes in this domain.

| Pitfall | User Impact | Better Approach |
|---------|-------------|-----------------|
| Sync runs silently with no progress indicator | User clicks "Sync Now", sees nothing happen for 30+ seconds, assumes it is broken, clicks again (double sync) | Show progress bar or spinner during sync; display running count "Processing 23/500 products" via periodic AJAX polls |
| Sync failure produces generic "An error occurred" message | User cannot diagnose the problem, files support ticket, blames plugin | Log specific error per product: "SKU ACME-001: image download failed (HTTP 403)" vs "SKU ACME-002: no matching WooCommerce product found" |
| No "Last Sync" timestamp visible | User does not know if sync is working or if scheduled sync has silently failed | Display "Last sync: May 21, 2026 14:30 UTC. Next: 15:30 UTC" on settings page and in admin bar |
| "Sync Now" does not warn about operation duration | User triggers sync during peak traffic, server slows down, customers experience timeouts | Show estimated duration based on product count: "Syncing ~200 products. Expected: 2-3 minutes. Continue?" |
| API credentials test succeeds but sync fails silently | User has valid credentials for reading products but not for downloading images (different permissions) | Test connection should verify access to both `/products` and an image URL endpoint if possible; log specific HTTP response codes during sync |
| No "stop sync" or "cancel" option | Long-running sync cannot be stopped except by restarting the server or deleting the lock transient | Use a cancel flag (option or transient) checked before each batch; "Cancel Sync" button in admin that sets the flag |

---

## "Looks Done But Isn't" Checklist

Things that appear complete but are missing critical pieces.

- [ ] **Image download works for 10 test products:** But 500-product sync times out -- verify timeout and memory management at scale
- [ ] **Images show on product pages after sync:** But old images were not cleaned up -- verify old attachment handling (replace, not accumulate)
- [ ] **Sync runs when clicking "Sync Now":** But WP-Cron hourly sync never fires -- verify on staging environment with low/no traffic for 2+ hours
- [ ] **Featured images set correctly:** But variation images are missing -- verify variation images specifically, not just parent products
- [ ] **Gallery images saved:** But in wrong order -- verify gallery image order matches Flxpoint order (first = featured, rest = gallery)
- [ ] **Sync works on developer machine:** But fails on shared hosting with 128M memory limit -- verify with realistic memory limits
- [ ] **Images downloaded:** But identical images downloaded again next sync hour -- verify deduplication over multiple sync cycles
- [ ] **Sync log shows "Success":** But 30% of images silently failed (timeout, 404, 403) -- verify error tracking captures all failure modes, not just the last one
- [ ] **API connection test passes:** But image download fails due to per-image ACL -- verify the token has read access to the image/CDN URLs not just the products endpoint
- [ ] **Plugin works with WooCommerce active:** But activates without WooCommerce and causes fatal error -- verify activation guard
- [ ] **Settings page renders API token field:** But full token appears in page source -- verify masked display after save
- [ ] **Error messages shown to admin:** But raw API HTML injected via `.html()` -- verify `.text()` in JS and `wp_strip_all_tags()` in PHP

---

## Recovery Strategies

When pitfalls occur despite prevention, how to recover.

| Pitfall | Recovery Cost | Recovery Steps |
|---------|---------------|----------------|
| Duplicate media library entries (Pitfall 2) | MEDIUM | Query all attachments with `_flxpnt_image_hash` meta; group by hash; delete all but the oldest attachment per hash; update all product `_thumbnail_id` and `_product_image_gallery` references to point to the kept attachment |
| Variation images set on parent product (Pitfall 5) | MEDIUM | Query postmeta for `_thumbnail_id` on variable products; for each, check if the attachment filename matches a variation SKU; if so, move the `_thumbnail_id` to the correct variation; if parent product had a legitimate featured image before, restore from backup |
| WP-Cron missed syncs for days (Pitfall 3) | LOW | Click "Sync Now" to catch up; document in admin that real cron is recommended; install WP Crontrol to verify schedule registration |
| Memory exhaustion mid-sync (Pitfall 4) | LOW | Products already updated are fine; incomplete products show placeholder images; re-run sync (idempotent design means re-running fixes the gaps) |
| API token compromised (Pitfall 7) | HIGH | Rotate token in Flxpoint immediately; rotate WordPress salts if DB was the attack vector; audit API access logs for unauthorized use; implement token encryption before re-storing |
| XSS executed via API error (Pitfall 6) | HIGH | Rotate all admin user sessions (potential session theft); audit admin actions during the window of exploitation; fix the `.html()` -> `.text()` change immediately |
| Fatal error from missing WooCommerce (Pitfall 9) | LOW | Install and activate WooCommerce; if plugin activation failed, delete the plugin directory and reinstall after WooCommerce is active |

---

## Pitfall-to-Phase Mapping

How roadmap phases should address these pitfalls.

| Pitfall | Prevention Phase | Verification |
|---------|------------------|--------------|
| Pitfall 1: download_url 300s timeout | Image sync implementation | Sync 5 products with 1 intentionally invalid image URL; confirm total sync time <30 seconds and valid images still process |
| Pitfall 2: Duplicate media entries | Image sync implementation | Run sync 3 times in succession; confirm `wp_posts` count for attachments does not increase on runs 2 and 3 |
| Pitfall 3: WP-Cron unreliability | Scheduled sync phase | Leave staging site with no traffic for 3 hours; verify at least 3 syncs ran via "Last sync" timestamp |
| Pitfall 4: Memory exhaustion | Image sync implementation | Run sync for 200+ images on environment with 128M memory limit; verify all images processed without OOM |
| Pitfall 5: Variation SKU matching | Image sync implementation | Sync a product with 3 variations each having distinct SKUs and images; verify each variation gets the correct image |
| Pitfall 6: XSS via API response | Security hardening phase | Artificially return HTML from test API endpoint; verify admin page renders text, not executed HTML |
| Pitfall 7: Token plaintext storage | Security hardening phase | Inspect `wp_options` table after saving token; verify stored value is encrypted, not readable as plaintext |
| Pitfall 8: No rate limiting | Image sync implementation | Run sync with delay=0 (for testing); verify transient lock prevents concurrent trigger; add delay and verify spacing |
| Pitfall 9: Missing WooCommerce check | Foundation/security phase | Deactivate WooCommerce; attempt to activate Flxpnt; verify graceful deactivation with error message |
| Pitfall 10: save() inside loops | Image sync implementation | Profile sync with 100 products, 4 images each; verify <5 `$product->save()` calls per product (should be exactly 1) |

---

## Sources

- WooCommerce import documentation: [WooCommerce Product Image Import](https://woocommerce.com/document/import-woocommerce-product-images) (HIGH confidence)
- WordPress Developer Reference: [download_url()](https://developer.wordpress.org/reference/functions/download_url/) and [media_handle_sideload()](https://developer.wordpress.org/reference/functions/media_handle_sideload) (HIGH confidence)
- WooCommerce GitHub Issues: [#26029 API Performance](https://github.com/woocommerce/woocommerce/issues/26029), [#27249 Bulk Save](https://github.com/woocommerce/woocommerce/issues/27249), [#9588 Variation Images](https://github.com/woocommerce/woocommerce/issues/9588) (HIGH confidence)
- WordPress Support Forums: [media_sideload_image problems](https://wordpress.org/support/topic/problems-with-media_sideload_image/) and [Avoid uploading duplicates](https://wordpress.org/support/topic/avoid-uploading-duplicates-with-media_sideload_image/) (MEDIUM confidence)
- WordPress StackExchange: [timeout with stream_body](https://wordpress.stackexchange.com/questions/166214/max-execution-time-error-with-stream-body-in-wp-includes-class-http-php) and [programmatic product images](https://wordpress.stackexchange.com/questions/402920/insert-woocommerce-products-programmatically-with-featured-image-and-gallery) (MEDIUM confidence)
- WooCommerce Action Scheduler documentation: [Replace WP-Cron](https://woocommerce.com/document/automatewoo/replace-wordpress-cron-real-cron-job) (HIGH confidence)
- Flxpoint API docs: [Stoplight Docs](https://flxpoint.stoplight.io/docs/flxpoint-api/) (LOW confidence -- API docs page returned empty content; rate limits and pagination details not confirmed)
- Codebase concerns analysis: `.planning/codebase/CONCERNS.md` (HIGH confidence -- direct codebase analysis validated findings)
- Codebase architecture analysis: `.planning/codebase/ARCHITECTURE.md` (HIGH confidence -- direct codebase analysis)
- StackOverflow: [woocommerce product image import](https://stackoverflow.com/questions/78390463/woocommerce-product-image-upload-import-programmatically) (MEDIUM confidence)
- WP Kama: [wc_product_attach_featured_image()](https://wp-kama.com/plugin/woocommerce/function/wc_product_attach_featured_image) (MEDIUM confidence)

---

*Pitfalls research for: Flxpoint to WooCommerce Image Sync*
*Researched: 2026-05-21*
