# Plan 01-02 Summary: Activation Infrastructure

**Plan:** 01-02 | **Phase:** 01-security-hardening-foundation  
**Completed:** 2026-05-21 | **Status:** Done

## What Was Changed

### 1. Activator: WooCommerce Dependency Check + DB Table Creation

**File:** `includes/class-flxpnt-activator.php`

Replaced the empty `activate()` stub with two-stage activation logic:

- **WC presence gate (D-01, D-02):** On activation, checks `class_exists('WooCommerce')`. If WC is not active, sets a 60-second transient (`flxpnt_wc_missing_notice`) to trigger an admin warning and calls `deactivate_plugins()` to self-deactivate. Returns early -- no table creation.
- **DB table creation (D-06, D-07):** If WC is active, creates `{$prefix}flxpnt_sync_logs` via `dbDelta()` with full schema: `id`, `batch_id`, `sku`, `entity_type`, `action`, `image_count`, `status`, `message`, `created_at`. Includes 5 indexes (`idx_batch_id`, `idx_sku`, `idx_entity_type`, `idx_status`, `idx_created_at`). Sets `flxpnt_db_version` option to `'1.0.0'` for future schema migrations.

### 2. Deactivator: Transient Cleanup

**File:** `includes/class-flxpnt-deactivator.php`

Replaced the empty `deactivate()` stub with a single `delete_transient('flxpnt_wc_missing_notice')` call. This prevents stale admin notices if the user reinstalls WooCommerce and reactivates the plugin.

## Files Modified

| File | Change |
|------|--------|
| `includes/class-flxpnt-activator.php` | WC check + `deactivate_plugins()` + `dbDelta()` table creation + version option |
| `includes/class-flxpnt-deactivator.php` | `delete_transient()` cleanup |

## Verification Results

All manual grep checks passed:

| Check | Result |
|-------|--------|
| `class_exists('WooCommerce')` with negation | Present (line 40) |
| `deactivate_plugins()` called in WC-missing branch | Present (line 42) |
| `set_transient('flxpnt_wc_missing_notice', ...)` | Present (line 41) |
| `flxpnt_sync_logs` table name in SQL | Present (line 51) |
| `dbDelta()` called | Present (line 72) |
| `update_option('flxpnt_db_version', '1.0.0')` | Present (line 74) |
| All 9 columns in CREATE TABLE | Confirmed |
| All 5 indexes in CREATE TABLE | Confirmed |
| `delete_transient('flxpnt_wc_missing_notice')` in deactivator | Present (line 37) |

## Deviations from Plan

None. Implementation follows the plan exactly:

- Computes plugin basename dynamically via `plugin_basename( dirname( __DIR__ ) . '/flxpnt.php' )` rather than hardcoding `'flxpnt/flxpnt.php'` -- more robust if the plugin directory name changes.
- SQL formatting follows dbDelta() rules: each column on its own line, `PRIMARY KEY  (id)` with two spaces, KEY definitions on separate lines, backtick-wrapped identifiers, `$wpdb->get_charset_collate()` for charset.
- All conventions followed: `array()` long form, tab indentation, DocBlocks with `@since`/`@package`/`@subpackage`, `__()` for translatable strings.
