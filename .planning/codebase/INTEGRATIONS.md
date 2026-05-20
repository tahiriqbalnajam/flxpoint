# External Integrations

**Analysis Date:** 2026-05-20

## APIs & External Services

**Flxpoint API:**
- Service: Flxpoint (inventory/order management platform)
  - SDK/Client: None. Communication is direct HTTP via WordPress HTTP API (`wp_remote_get`)
  - Auth: Bearer token, sent as `Authorization: Bearer {token}` header
  - Auth credentials stored in: WordPress option `flxpnt_api_token` (registered in `admin/class-flxpnt-admin.php:56`)
  - Base URL default: `https://api.flxpoint.com` (configurable via `flxpnt_api_base_url` option, registered in `admin/class-flxpnt-admin.php:55`)
  - Content type: `Accept: application/json` header sent with requests

**Currently implemented endpoints:**
| Method | Endpoint | Purpose | Location |
|--------|----------|---------|----------|
| GET | `{base_url}/products?limit=1` | Connection test / health check | `admin/class-flxpnt-admin.php:86` |

**Connection test flow:**
1. Admin user saves `flxpnt_api_base_url` and `flxpnt_api_token` via WordPress Settings API (form at `admin/partials/flxpnt-admin-settings.php`)
2. User clicks "Test Connection" button (JavaScript handler at `admin/js/flxpnt-admin.js:14`)
3. AJAX POST to `admin-ajax.php` with action `flxpnt_test_connection` (JS at `admin/js/flxpnt-admin.js:20-24`)
4. Server handler `Flxpnt_Admin::handle_test_connection()` at `admin/class-flxpnt-admin.php:72` validates nonce, checks permissions, fires `wp_remote_get` to Flxpoint
5. Result cached in transient `flxpnt_connection_status` for 60 seconds; success/error returned to UI

## Data Storage

**Databases:**
- WordPress MySQL/MariaDB database (standard WordPress `wp_options` table)
  - No custom database tables created by this plugin
  - Activation hook (`includes/class-flxpnt-activator.php`) is an empty stub with no schema creation
  - Uninstall hook (`uninstall.php`) performs no cleanup of options or transients

**File Storage:**
- Local filesystem only (plugin assets: CSS, JS, PHP partials). No external file storage service.

**Caching:**
- WordPress Transients API only (server-side, stored in `wp_options` or object cache if configured)
  - Key: `flxpnt_connection_status`
  - TTL: 60 seconds
  - Stores array: `{ success: bool, message: string }`
- No persistent object cache, no Redis, no CDN

## Authentication & Identity

**Auth Provider:**
- WordPress native authentication (the plugin has no custom auth layer)
  - Admin pages restricted via `manage_options` capability check (`admin/class-flxpnt-admin.php:37,60,75`)
  - AJAX actions protected by WordPress nonce (`flxpnt_test_connection`) created at `admin/class-flxpnt-admin.php:27` and verified at line 73
- Flxpoint API authentication uses Bearer token model (token stored as WordPress option)

## Monitoring & Observability

**Error Tracking:**
- None detected. No Sentry, Bugsnag, or equivalent integration.

**Logs:**
- No explicit logging. Errors from `wp_remote_get` are surfaced to the admin UI as transient-stored messages and AJAX responses.
- `is_wp_error()` check at `admin/class-flxpnt-admin.php:94` handles connection failures but does not write to any log file.

## CI/CD & Deployment

**Hosting:**
- Any WordPress-compatible hosting. No platform-specific deployment configuration.

**CI Pipeline:**
- None detected. No CI config files (`.github/workflows/`, `.gitlab-ci.yml`, `Jenkinsfile`, etc.).

## Environment Configuration

**Required env vars:**
- None. All configuration is stored in the WordPress `wp_options` table:
  - `flxpnt_api_base_url` (string, default: `https://api.flxpoint.com`)
  - `flxpnt_api_token` (string, the Bearer token for Flxpoint API)

**Secrets location:**
- The Flxpoint API token is stored as a WordPress option (`flxpnt_api_token`) in the WordPress database
- The settings form renders the token field as `type="password"` (`admin/partials/flxpnt-admin-settings.php:26`) for UI masking
- No `.env` file or external secrets manager is used

## Webhooks & Callbacks

**Incoming:**
- None. The plugin does not register any REST API endpoints or webhook receivers.

**Outgoing:**
- None. The plugin only makes outbound HTTP GET calls for connection testing; no webhook callbacks or push notifications are dispatched.

## WooCommerce Integration

**Status:** Not yet implemented. The plugin metadata (`flxpnt.php:18`) states it is "A bridge between Flxpoint and Woocommerce" but no WooCommerce-specific hooks, filters, or API calls are present in the current codebase. The admin settings and connection test are the only implemented features. No WooCommerce product sync, order sync, or inventory sync logic exists yet.

---

*Integration audit: 2026-05-20*
