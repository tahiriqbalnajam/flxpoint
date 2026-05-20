# Stack Research: Flxpoint Image Sync

**Domain:** WordPress plugin syncing product/variation images from Flxpoint REST API to WooCommerce
**Researched:** 2026-05-20
**Confidence:** HIGH

## Recommended Stack

### Core Technologies

| Technology | Version | Purpose | Why Recommended |
|------------|---------|---------|-----------------|
| WordPress Core APIs | 5.4+ | Media handling, HTTP, options, cron | No external dependencies needed. `_source_url` meta requires WP 5.4+; the existing plugin already declares WP 3.0.1 but effectively requires 5.4+ for this feature |
| WooCommerce CRUD API | 3.0+ | Product image assignment | `wc_get_product_id_by_sku()`, `set_image_id()`, `set_gallery_image_ids()` — the standard programmatic way to manage product images without touching SQL |
| WordPress HTTP API | Core | Flxpoint API communication | `wp_remote_get()` already in use. `download_url()` for image downloads with timeout control |
| WP-Cron (`wp_schedule_event`) | Core | Hourly sync scheduling | Built-in `hourly` recurrence. No background processing library needed for this scope |

### Image Handling: Recommended Approach

**Use `download_url()` + `media_handle_sideload()` (not `media_sideload_image()` directly).**

```php
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

// Step 1: Download with controlled timeout (10 seconds per image)
$tmp_file = download_url( $image_url, 10 );

if ( is_wp_error( $tmp_file ) ) {
    // Log error, skip this image
    return $tmp_file;
}

// Step 2: Build $_FILES-style array
$file_array = [
    'name'     => basename( $image_url ),
    'tmp_name' => $tmp_file,
];

// Step 3: Sideload into media library (not attached to any post)
$attachment_id = media_handle_sideload( $file_array, 0 );

if ( is_wp_error( $attachment_id ) ) {
    @unlink( $tmp_file ); // Clean up temp file on failure
    return $attachment_id;
}

// Done — $attachment_id is ready for set_image_id()
```

**Why not `media_sideload_image()`?**
- No timeout control (uses 300-second default — catastrophic for batch processing)
- Extension validation via regex fails on API URLs with no file extension (common with CDN-hosted images like `cdn.example.com/photos/12345`)
- Can't clean up temp file on failure
- No ability to inspect or validate the download before creating the attachment

The `#.jpg` URL-suffix trick for `media_sideload_image()` is a fragile workaround. The lower-level approach with `download_url()` gives full control and proper error handling.

### Duplicate Prevention

Query `_source_url` post meta before downloading:

```php
global $wpdb;

$existing_id = $wpdb->get_var( $wpdb->prepare(
    "SELECT post_id FROM {$wpdb->postmeta}
     WHERE meta_key = '_source_url' AND meta_value = %s
     LIMIT 1",
    $image_url
) );

if ( $existing_id ) {
    return (int) $existing_id; // Already imported, skip download
}
```

This meta key is stored automatically by both `media_sideload_image()` and `media_handle_sideload()` since WordPress 5.4.0. It provides O(1) duplicate detection without filename guessing or GUID comparison.

### WooCommerce Product Image Assignment

| Operation | Method | Notes |
|-----------|--------|-------|
| Find product by SKU | `wc_get_product_id_by_sku( $sku )` | Returns product or variation ID. Works for both types since WC 2.3.0 |
| Load product object | `wc_get_product( $id )` | Use cached instance within a single sync batch |
| Set featured image | `$product->set_image_id( $attachment_id )` | Requires attachment ID from media library |
| Set gallery images | `$product->set_gallery_image_ids( $ids_array )` | Array of attachment IDs |
| Set variation image | `$variation->set_image_id( $attachment_id )` | Same API as main product |
| Persist changes | `$product->save()` | Call once after all `set_*()` calls per product |

**Important:** `save()` writes to the database even for unchanged properties. Always check whether images actually changed before calling `save()` — compare `$product->get_image_id()` with the new attachment ID first.

### External Image URL (No Download) Approach

WooCommerce has **no native support** for external image URLs. The recommended approach for the "link externally" configuration option:

1. Store external URL in a custom post meta: `_flxpnt_ext_image_url`
2. Set a dummy `_thumbnail_id` value (`0` or a known sentinel) so that product templates function correctly
3. Filter `post_thumbnail_html`, `woocommerce_product_get_image`, and `woocommerce_single_product_image_thumbnail_html` to output `<img>` tags with the external URL when `_flxpnt_ext_image_url` is present
4. The sync system checks the plugin setting (`flxpnt_image_handling`) — when set to `external`, it skips `download_url()` entirely and only writes the external URL to meta

This approach uses only WordPress core filter hooks. No third-party plugin needed.

### WP-Cron Scheduling

```php
// On plugin activation (or settings save):
if ( ! wp_next_scheduled( 'flxpnt_hourly_sync' ) ) {
    wp_schedule_event( time(), 'hourly', 'flxpnt_hourly_sync' );
}

// Hook the sync function:
add_action( 'flxpnt_hourly_sync', 'flxpnt_run_image_sync' );

// On plugin deactivation:
$timestamp = wp_next_scheduled( 'flxpnt_hourly_sync' );
if ( $timestamp ) {
    wp_unschedule_event( $timestamp, 'flxpnt_hourly_sync' );
}
```

**Reliability concern:** WP-Cron only fires on page visits. For low-traffic sites, recommend `DISABLE_WP_CRON` + a system crontab calling `wp cron event run --due-now`. The plugin should document this in its admin UI and detect when cron is consistently late (compare scheduled time vs actual run time).

### Supporting Libraries

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| None | — | — | All required functionality is in WordPress Core + WooCommerce. The project constraint explicitly forbids external dependencies. |

### Development Tools

| Tool | Purpose | Notes |
|------|---------|-------|
| None additional | — | ES5 JS, plain CSS, no build step per project constraints |

## Installation

No installation steps. The plugin uses only WordPress Core and WooCommerce built-in APIs. No Composer, no npm, no third-party packages.

## Alternatives Considered

| Category | Recommended | Alternative | Why Not |
|----------|-------------|-------------|---------|
| Image download API | `download_url()` + `media_handle_sideload()` | `media_sideload_image()` wrapper | Wrapper has no timeout control, fails on extensionless URLs, no temp file cleanup on error |
| Background processing | WP-Cron + batch processing + optional system cron | Action Scheduler library | External library; project constraint forbids it. WP-Cron is sufficient for an hourly sync with batch processing |
| Duplicate detection | Query `_source_url` post meta | Query `guid` column or filename match | `guid` can be modified; filename matching is fragile. `_source_url` is purpose-built and automatically populated by core |
| External URL support | Custom meta + filter hooks | FIFU plugin | Third-party dependency; violates project constraints |
| Product lookup | `wc_get_product_id_by_sku()` | `WP_Query` with meta query | `wc_get_product_id_by_sku()` is purpose-built, more performant (indexed), and handles both products and variations |
| Variation lookup | `wc_get_product_id_by_sku()` | Separate variation query | The built-in function already queries `product_variation` post type |

## What NOT to Use

| Avoid | Why | Use Instead |
|-------|-----|-------------|
| `media_sideload_image()` for batch sync | 300s default timeout, extension validation chokes on CDN URLs, no temp file cleanup | `download_url($url, 10)` + `media_handle_sideload()` |
| `wp_insert_attachment()` + `wp_generate_attachment_metadata()` manually | Reinventing `media_handle_sideload()`. You would need to handle MIME detection, thumbnail generation, and metadata yourself | `media_handle_sideload()` — it calls both internally |
| `update_post_meta( '_thumbnail_id' )` directly | Bypasses WooCommerce data store cache, object caching, and change tracking | `$product->set_image_id()` + `$product->save()` |
| Direct SQL for product lookup | Bypasses WooCommerce data store, fragile to schema changes | `wc_get_product_id_by_sku()` |
| Action Scheduler / WP Background Processing libraries | Project constraint: zero external dependencies | Batch processing via WP-Cron with intra-run rescheduling |
| FIFU (Featured Image From URL) plugin | External dependency | Custom meta + filter hooks for external URL support |
| `wget`/`curl` shell commands | Blocks PHP execution, different environments have different availability | `download_url()` from WordPress HTTP API |
| `wp_remote_get()` + `file_put_contents()` for image download | MIME validation, temp file handling, and security checks already built into `download_url()` | `download_url()` — it handles all of this |

## Stack Patterns

**Image handling mode selection pattern:**
- Option set via plugin admin: `get_option( 'flxpnt_image_handling', 'download' )`
- `'download'`: Full `download_url()` + `media_handle_sideload()` pipeline, assign attachment ID to product
- `'external'`: Skip download entirely, store URL in `_flxpnt_ext_image_url` meta, filter output hooks to use external URL
- The same sync loop handles both modes — the branch is at the per-image level, not the sync architecture level

**Batch processing pattern:**
- Fetch a page of products from Flxpoint API (the existing code already uses `?limit=1` pattern)
- Process max 20 products/cron run to stay under 60-second cron lock timeout
- Track last-processed pagination cursor in a WordPress transient
- If more pages remain, reschedule a one-off cron event in 60 seconds with `wp_schedule_single_event()`

## Version Compatibility

| Component | Minimum Version | Notes |
|-----------|----------------|-------|
| WordPress | 5.4.0 | Required for `_source_url` auto-storage in media functions |
| WooCommerce | 3.0.0 | Required for CRUD API (`set_image_id()`, `wc_get_product_id_by_sku()`) |
| PHP | 7.0+ | Implicit — WordPress 5.4 requires PHP 5.6.20+, but WooCommerce 3.0+ effectively needs PHP 7.0 for realistic operation |
| `download_url()` timeout parameter | WordPress 5.9.0 | The `$timeout` parameter was added in WP 5.9. For WP 5.4-5.8, use `add_filter( 'http_request_timeout', ... )` as a workaround |

## Sources

- [media_handle_sideload() official docs](https://developer.wordpress.org/reference/functions/media_handle_sideload/) — HIGH confidence (verified API signature, internal call chain)
- [media_sideload_image() official docs](https://developer.wordpress.org/reference/functions/media_sideload_image/) — HIGH confidence (verified extension validation regex, return types, `_source_url` storage since 5.4.0)
- [download_url() timeout parameter](https://developer.wordpress.org/reference/functions/download_url/) — HIGH confidence ($timeout parameter added in WP 5.9.0, verified in source)
- [WooCommerce CRUD objects](https://woocommerce.com/document/developing-using-woocommerce-crud-objects/) — HIGH confidence (verified `set_image_id()`, `set_gallery_image_ids()`, save behavior)
- [wc_get_product_id_by_sku()](https://wp-kama.com/plugin/woocommerce/function/wc_get_product_id_by_sku) — MEDIUM confidence (third-party reference, but function signature verified in multiple sources)
- [WooCommerce product object caching (10.5+)](https://developer.woocommerce.com/2026/01/19/experimental-product-object-caching-in-woocommerce-10-5/) — HIGH confidence (official developer blog, confirms in-request caching)
- [WP-Cron scheduling documentation](https://developer.wordpress.org/reference/functions/wp_schedule_event/) — HIGH confidence (core function, well-documented)
- [Duplicate prevention via _source_url](https://gist.github.com/kingkool68/a66d2df7835a8869625282faa78b489a) — MEDIUM confidence (community pattern, verified against core source that stores this meta)
- [External image URL via filters](https://wordpress.stackexchange.com/questions/220574/) — MEDIUM confidence (community pattern, multiple implementations agree on approach)
- [WooCommerce external image URL (no native support)](https://github.com/woocommerce/woocommerce/issues/54253) — HIGH confidence (official WooCommerce developer confirms no plans for native external URL support)

---
*Stack research for: Flxpoint Image Sync*
*Researched: 2026-05-20*
