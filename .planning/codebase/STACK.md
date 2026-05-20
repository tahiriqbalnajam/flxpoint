# Technology Stack

**Analysis Date:** 2026-05-20

## Languages

**Primary:**
- PHP (no version constraint declared) - All server-side logic (`flxpnt.php`, `includes/`, `admin/`, `public/`)
- WordPress Plugin Boilerplate patterns throughout; the codebase was scaffolded from the WordPress Plugin Boilerplate generator

**Secondary:**
- JavaScript (ES5, no transpilation or build step) - Admin-side AJAX connection testing
- CSS (plain, no preprocessor) - Admin styling

## Runtime

**Environment:**
- WordPress (any version >= 3.0.1 per `README.txt` header; untested beyond 3.4 per metadata)
- PHP runtime provided by the WordPress host environment

**Package Manager:**
- None detected. No `composer.json` or `package.json` present. No `node_modules/` or `vendor/` directories. All dependencies are zero-external-library, relying entirely on WordPress core and bundled jQuery.

## Frameworks

**Core:**
- WordPress Plugin API (actions, filters, hooks) - Plugin lifecycle and admin integration
  - Entry point: `flxpnt.php` (line 1)
  - Plugin header defined at `flxpnt.php` (lines 15-26)

**Testing:**
- No testing framework detected. No `phpunit.xml`, `jest.config.*`, or `vitest.config.*` files.

**Build/Dev:**
- No build pipeline. No bundler, transpiler, or minifier. All assets are served as raw source files.

## Key Dependencies

**Critical:**
- WordPress Core (all PHP APIs) - The plugin has zero external PHP dependencies; everything runs on built-in WordPress functions
- jQuery (bundled with WordPress) - Dependency declared in `admin/js/flxpnt-admin.js` and `public/js/flxpnt-public.js`

**Infrastructure:**
- WordPress HTTP API (`wp_remote_get`, `wp_remote_retrieve_response_code`, `wp_remote_retrieve_body`) - Used for Flxpoint API communication in `admin/class-flxpnt-admin.php` (lines 86-104)
- WordPress Transients API (`set_transient`, `get_transient`) - Caches connection status for 60 seconds
- WordPress AJAX API (`wp_ajax_flxpnt_test_connection`) - Handles async connection testing
- WordPress Options API (`register_setting`, `get_option`) - Persists plugin configuration

## Configuration

**Environment:**
- No `.env` files present (confirmed via scan)
- No `.env.*` files present
- Plugin settings stored in the WordPress `wp_options` table:
  - `flxpnt_api_base_url` (default: `https://api.flxpoint.com`) - registered at `admin/class-flxpnt-admin.php` (line 55)
  - `flxpnt_api_token` - registered at `admin/class-flxpnt-admin.php` (line 56)
- Connection test result cached via transient: `flxpnt_connection_status` (60-second TTL) set at `admin/class-flxpnt-admin.php` (lines 95, 107, 121)
- Constant: `FLXPNT_VERSION` defined as `1.0.0` in `flxpnt.php` (line 38)

**Build:**
- No build configuration files (`composer.json`, `package.json`, `tsconfig.json`, `.eslintrc*`, `.prettierrc*` all absent)
- No asset compilation or optimization pipeline

## Platform Requirements

**Development:**
- PHP (any version supported by the hosting WordPress instance)
- WordPress installation (the plugin lives at `wp-content/plugins/flxpnt/`)
- No external toolchain required (no Composer, no npm)

**Production:**
- Deployment target: any WordPress site via plugin upload/activation
- All runtime dependencies are satisfied by WordPress core; no additional server packages required

## WordPress-Specific APIs Used

| API Function | Purpose | Location |
|---|---|---|
| `wp_remote_get` | HTTP GET to Flxpoint API | `admin/class-flxpnt-admin.php:86` |
| `wp_remote_retrieve_response_code` | Extract HTTP status | `admin/class-flxpnt-admin.php:102` |
| `wp_remote_retrieve_body` | Extract response body | `admin/class-flxpnt-admin.php:103` |
| `wp_send_json_success` | AJAX success response | `admin/class-flxpnt-admin.php:111` |
| `wp_send_json_error` | AJAX error response | `admin/class-flxpnt-admin.php:76` |
| `check_ajax_referer` | Nonce verification | `admin/class-flxpnt-admin.php:73` |
| `wp_create_nonce` | Nonce generation | `admin/class-flxpnt-admin.php:27` |
| `wp_localize_script` | Pass PHP data to JS | `admin/class-flxpnt-admin.php:25` |
| `wp_enqueue_style` / `wp_enqueue_script` | Asset registration | `admin/class-flxpnt-admin.php:20,24` and `public/class-flxpnt-public.php:76,99` |
| `add_menu_page` / `add_submenu_page` | Admin menu registration | `admin/class-flxpnt-admin.php:34,44` |
| `register_setting` | Settings registration | `admin/class-flxpnt-admin.php:55-56` |
| `get_option` | Settings retrieval | `admin/class-flxpnt-admin.php:64-65` |
| `set_transient` / `get_transient` | Temporary cache | `admin/class-flxpnt-admin.php:67,95,107,121` |
| `register_activation_hook` / `register_deactivation_hook` | Plugin lifecycle | `flxpnt.php:58-59` |
| `load_plugin_textdomain` | i18n text domain loading | `includes/class-flxpnt-i18n.php:37` |

---

*Stack analysis: 2026-05-20*
