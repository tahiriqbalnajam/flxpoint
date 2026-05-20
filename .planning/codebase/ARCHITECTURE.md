<!-- refreshed: 2026-05-20 -->
# Architecture

**Analysis Date:** 2026-05-20

## System Overview

```text
┌─────────────────────────────────────────────────────────────┐
│                    Bootstrap Entry Point                     │
│                       `flxpnt.php`                           │
├─────────────────────────────────────────────────────────────┤
│  define FLXPNT_VERSION   |   register_activation_hook       │
│  register_deactivation_hook  |  require core   |  run_flxpnt│
└────────────────────────────┬────────────────────────────────┘
                             │ instantiates
                             ▼
┌─────────────────────────────────────────────────────────────┐
│              Core Orchestrator (Composition Root)             │
│                `includes/class-flxpnt.php`                    │
│                      Class: Flxpnt                            │
├──────────────────┬──────────────────┬───────────────────────┤
│  Flxpnt_i18n     │  Flxpnt_Admin     │  Flxpnt_Public        │
│  `includes/      │  `admin/          │  `public/              │
│   class-flxpnt-  │   class-flxpnt-   │   class-flxpnt-       │
│   i18n.php`      │   admin.php`      │   public.php`          │
└────────┬─────────┴────────┬─────────┴──────────┬────────────┘
         │                  │                     │
         │    all hooks registered via            │
         ▼                  ▼                     ▼
┌─────────────────────────────────────────────────────────────┐
│                 Hook Registry / Loader                       │
│           `includes/class-flxpnt-loader.php`                 │
│                  Class: Flxpnt_Loader                         │
├─────────────────────────────────────────────────────────────┤
│  $actions[]   →   add_action()  (batch registered on run()) │
│  $filters[]   →   add_filter()  (batch registered on run()) │
└─────────────────────────────────────────────────────────────┘
         │
         ▼  (all hooks fire in WordPress lifecycle)
┌─────────────────────────────────────────────────────────────┐
│                    WordPress Core                             │
│  admin_enqueue_scripts | admin_menu | admin_init             │
│  wp_ajax_flxpnt_test_connection  |  wp_enqueue_scripts       │
│  plugins_loaded                                               │
└─────────────────────────────────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────────────────────────────┐
│  Flxpoint REST API  (External)                               │
│  `https://api.flxpoint.com`                                   │
└─────────────────────────────────────────────────────────────┘
```

## Component Responsibilities

| Component | Responsibility | File |
|-----------|----------------|------|
| Bootstrap | Version constant, activation/deactivation hook registration, kicking off the plugin | `flxpnt.php` |
| Core Orchestrator | Composition root: loads dependencies, wires hooks, holds plugin identity (name, version), exposes `run()` | `includes/class-flxpnt.php` |
| Loader | Deferred hook registry: collects actions/filters in arrays, batch-registers with WordPress on `run()` | `includes/class-flxpnt-loader.php` |
| Admin | Admin-side hooks: enqueues styles/scripts, registers admin menu/settings, handles AJAX connection test | `admin/class-flxpnt-admin.php` |
| Public | Public-side hooks: enqueues styles/scripts (currently boilerplate only) | `public/class-flxpnt-public.php` |
| i18n | Loads the plugin text domain for translations | `includes/class-flxpnt-i18n.php` |
| Activator | Stub for activation-time setup logic | `includes/class-flxpnt-activator.php` |
| Deactivator | Stub for deactivation-time cleanup logic | `includes/class-flxpnt-deactivator.php` |

## Pattern Overview

**Overall:** WordPress Plugin Boilerplate (WPPB) Pattern

**Key Characteristics:**
- Single `Flxpnt` class acts as the composition root, instantiating all sub-components and wiring them to the loader
- All WordPress integration is done through the action/filter hook system; no direct WordPress function calls outside of `define_admin_hooks()` and `define_public_hooks()`
- Deferred registration: hooks are collected in arrays within the Loader, then batch-registered only when `run()` is called
- Constructor-based dependency injection: `Flxpnt_Admin` and `Flxpnt_Public` receive `$plugin_name` and `$version` via their constructors
- Clean admin/public separation: the plugin has two distinct "sides", each with its own class, CSS, JS, and partials directories
- All classes use static methods or instance methods registered as callbacks in `[$object, 'method']` format via the Loader

## Layers

**Bootstrap Layer:**
- Purpose: Plugin entry point -- defines constants, registers lifecycle hooks, loads core class, and starts execution
- Location: `flxpnt.php`
- Contains: Version constant definition, `activate_flxpnt()`, `deactivate_flxpnt()`, `run_flxpnt()`
- Depends on: `includes/class-flxpnt.php`, `includes/class-flxpnt-activator.php`, `includes/class-flxpnt-deactivator.php`
- Used by: WordPress core (loaded as a plugin)

**Core/Includes Layer:**
- Purpose: Plugin-wide concerns -- hook orchestration, internationalization, plugin identity
- Location: `includes/`
- Contains: `Flxpnt` (orchestrator), `Flxpnt_Loader` (hook registry), `Flxpnt_i18n` (translations), `Flxpnt_Activator`, `Flxpnt_Deactivator`
- Depends on: `admin/class-flxpnt-admin.php`, `public/class-flxpnt-public.php`
- Used by: `flxpnt.php` (bootstrap)

**Admin Layer:**
- Purpose: WordPress admin panel integration -- settings page, API connection management
- Location: `admin/`
- Contains: `Flxpnt_Admin` class, settings partial, admin CSS/JS
- Depends on: WordPress admin APIs (`admin_menu`, `admin_enqueue_scripts`, `admin_init`, `wp_ajax_`), WordPress HTTP API (`wp_remote_get`), WordPress Transients API, WordPress Options API
- Used by: Core orchestrator (instantiated in `define_admin_hooks()`)

**Public Layer:**
- Purpose: Frontend/public-facing site integration (currently boilerplate/stub)
- Location: `public/`
- Contains: `Flxpnt_Public` class, display partial, public CSS/JS
- Depends on: WordPress enqueue APIs
- Used by: Core orchestrator (instantiated in `define_public_hooks()`)

## Data Flow

### Primary Request Path: Admin Settings Page Load

1. WordPress loads the plugin, calls `run_flxpnt()` in `flxpnt.php:82`
2. `Flxpnt` constructor runs: loads dependencies, sets locale, defines admin hooks, defines public hooks (`includes/class-flxpnt.php:69-81`)
3. `Flxpnt::run()` calls `$this->loader->run()` (`includes/class-flxpnt.php:186-188`)
4. Loader iterates `$actions` and `$filters` arrays, calling `add_action()` / `add_filter()` for each (`includes/class-flxpnt-loader.php:117-127`)
5. When admin user visits WP Admin, `admin_menu` fires; `Flxpnt_Admin::add_plugin_admin_menu()` registers menu pages (`admin/class-flxpnt-admin.php:33-52`)
6. When the Flxpoint settings page renders, `admin_init` fires; `Flxpnt_Admin::register_settings()` registers `flxpnt_api_base_url` and `flxpnt_api_token` with the WordPress Settings API (`admin/class-flxpnt-admin.php:54-57`)
7. `Flxpnt_Admin::display_plugin_settings_page()` reads options and transient, then includes the settings partial (`admin/class-flxpnt-admin.php:59-70`)
8. Admin CSS and JS are enqueued via `admin_enqueue_scripts` hook (`admin/class-flxpnt-admin.php:19-31`)

### Test Connection Flow (AJAX)

1. User clicks "Test Connection" button on the settings page (`admin/partials/flxpnt-admin-settings.php:42-44`)
2. JavaScript handler in `admin/js/flxpnt-admin.js:14-37` sends a POST request to `admin-ajax.php` with action `flxpnt_test_connection`, nonce, API base URL, and token
3. WordPress AJAX routing maps `wp_ajax_flxpnt_test_connection` to `Flxpnt_Admin::handle_test_connection()` (hook registered in `includes/class-flxpnt.php:161`)
4. Handler validates nonce, checks capability, sanitizes inputs (`admin/class-flxpnt-admin.php:72-84`)
5. Makes HTTP GET to `{base_url}products?limit=1` with `Authorization: Bearer {token}` header (`admin/class-flxpnt-admin.php:86-92`)
6. On success: stores connection status in `flxpnt_connection_status` transient (60s TTL), returns JSON success (`admin/class-flxpnt-admin.php:105-114`)
7. On failure: stores error in same transient, returns JSON error (`admin/class-flxpnt-admin.php:115-129`)
8. JavaScript displays the result as a WordPress admin notice (`admin/js/flxpnt-admin.js:26-31`)

**State Management:**
- Persistent config: WordPress Options API (`get_option`/`update_option`) for `flxpnt_api_base_url` and `flxpnt_api_token`
- Ephemeral state: WordPress Transients API (`get_transient`/`set_transient`) for `flxpnt_connection_status` (60-second TTL)
- No database tables; all state managed through WordPress core APIs
- No global variables or singletons beyond standard WordPress APIs

## Key Abstractions

**Flxpnt_Loader (Hook Registry):**
- Purpose: Decouples hook registration from the WordPress lifecycle. All hooks are collected first, then batch-registered. This allows the orchestrator to define hooks declaratively without calling `add_action`/`add_filter` directly.
- File: `includes/class-flxpnt-loader.php`
- Pattern: Collector pattern -- accumulates hook definitions in arrays via `add_action()` and `add_filter()` instance methods, then iterates to register them in `run()`

**Plugin Identity (plugin_name + version):**
- Purpose: The `$plugin_name` string (`'flxpnt'`) and `$version` string (`'1.0.0'`) serve as a shared identity passed through the system. Used for CSS/JS handles, text domain, menu slugs, and option names.
- Values: `$plugin_name = 'flxpnt'`, `$version` from `FLXPNT_VERSION` constant
- Defined in: `flxpnt.php:38` (constant), `includes/class-flxpnt.php:75-76` (instance properties)
- Used by: Every component that enqueues assets, registers menus, or sets options

## Entry Points

**Plugin Bootstrap:**
- Location: `flxpnt.php`
- Triggers: WordPress plugin loader (activated plugin)
- Responsibilities: Define version constant, register activation/deactivation hooks, load core class, instantiate and run the plugin

**Plugin Activation:**
- Location: `flxpnt.php:44-46` → `includes/class-flxpnt-activator.php`
- Triggers: User activates plugin in WordPress admin
- Responsibilities: Currently a stub (empty `activate()` method)

**Plugin Deactivation:**
- Location: `flxpnt.php:53-55` → `includes/class-flxpnt-deactivator.php`
- Triggers: User deactivates plugin in WordPress admin
- Responsibilities: Currently a stub (empty `deactivate()` method)

**Plugin Uninstall:**
- Location: `uninstall.php`
- Triggers: User deletes plugin from WordPress admin
- Responsibilities: Currently only checks `WP_UNINSTALL_PLUGIN` constant, no cleanup logic

**Admin AJAX Endpoint:**
- Location: `admin/class-flxpnt-admin.php:72-130` (registered via `wp_ajax_flxpnt_test_connection`)
- Triggers: AJAX POST from admin settings page JavaScript
- Responsibilities: Validate nonce, check capabilities, test Flxpoint API connection, return JSON result

**Admin Settings Page:**
- Location: `admin/partials/flxpnt-admin-settings.php` (rendered by `Flxpnt_Admin::display_plugin_settings_page()`)
- Triggers: User navigates to Flxpoint menu in WP Admin
- Responsibilities: Display API configuration form, connection test button, and connection status

**Public Frontend:**
- Location: `public/class-flxpnt-public.php` (hooks registered for `wp_enqueue_scripts`)
- Triggers: Any frontend page load
- Responsibilities: Currently only enqueues empty CSS/JS (boilerplate)

## Architectural Constraints

- **Threading:** Single-threaded PHP execution within the WordPress request lifecycle. No background workers, cron jobs, or async processing in use.
- **Global state:** Only WordPress core globals (`$wpdb`, `$wp_version`, etc.). The plugin itself uses no global variables. Plugin identity flows through constructor injection.
- **Circular imports:** None detected. Dependencies flow strictly: Bootstrap → Core → {Admin, Public, i18n}. No reverse dependencies.
- **Plugin identity:** The string `'flxpnt'` is used as the unique identifier throughout -- for text domain, option names, CSS/JS handles, menu slugs, and transients. Any new code must use this same prefix for naming to avoid collisions with other plugins.
- **No autoloader:** All class files are loaded via explicit `require_once` calls in the Core orchestrator's `load_dependencies()` method. There is no Composer autoloader or PSR-4 structure.

## Anti-Patterns

### Stub Methods with Placeholder Comments

**What happens:** `Flxpnt_Activator::activate()`, `Flxpnt_Deactivator::deactivate()`, and `uninstall.php` contain only boilerplate comments and empty method bodies. The activate and deactivate methods have placeholders like "Short Description. (use period)" from the WPPB generator template.
**Why it's wrong:** These methods are registered with WordPress but do nothing. If a developer assumes activation has run and set up database tables or options, they will encounter missing-state bugs. The placeholder comments are noise and should be replaced with actual docblocks or removed.
**Do this instead:** Either implement the actual lifecycle logic needed (database table creation, option defaults, scheduled event cleanup) or remove the placeholder comments and leave the methods intentionally empty with a clear docblock explaining why.

### Empty POT File

**What happens:** `languages/flxpnt.pot` exists as an empty file (0 bytes). The i18n class loads the text domain pointing to the `languages/` directory.
**Why it's wrong:** The plugin uses translation functions (`__()`, `_e()`) throughout, but there is no actual POT file for translators. WordPress.org plugin directory requires a valid POT file. Any translation tooling will fail or produce empty results.
**Do this instead:** Generate a real POT file using WP-CLI (`wp i18n make-pot`) or a tool like Poedit, including all translatable strings from the plugin source.

## Error Handling

**Strategy:** Defensive with early returns and WordPress-native error patterns.

**Patterns:**
- Direct-file-access guard: All PHP files check `defined( 'WPINC' )` or `defined( 'WP_UNINSTALL_PLUGIN' )` and `die`/`exit` if accessed directly
- Capability check: `current_user_can( 'manage_options' )` before rendering settings or processing AJAX
- Nonce verification: `check_ajax_referer( 'flxpnt_test_connection', 'nonce' )` before processing AJAX
- WP_Error handling: `is_wp_error( $response )` check after `wp_remote_get()` with descriptive error messages
- HTTP status code validation: Range check `$status_code >= 200 && $status_code < 300` for API responses
- Input sanitization: `esc_url_raw()` for URLs, `sanitize_text_field()` for tokens, `esc_attr()` / `esc_html()` in views
- Output escaping: All view output uses `esc_html()`, `esc_attr()`, or `esc_url()` as appropriate

## Cross-Cutting Concerns

**Logging:** No logging framework or custom logging in use. Debug output not configured. Relies on WordPress debug log (`WP_DEBUG`) if enabled by the site administrator.

**Validation:** Input validation is inline within the AJAX handler. No dedicated validation layer or class. The only validated inputs are the API base URL (`esc_url_raw` + `trailingslashit`) and API token (`sanitize_text_field` + empty check).

**Authentication:** Admin access gated by WordPress capability `manage_options`. API authentication uses Bearer token pattern with the token stored in WordPress options. The AJAX endpoint uses WordPress nonce verification to prevent CSRF.

**Internationalization:** All user-facing strings use `__()` or `_e()` with the `'flxpnt'` text domain. The domain is loaded via `load_plugin_textdomain()` on the `plugins_loaded` hook. However, the `languages/flxpnt.pot` file is empty (0 bytes).

---

*Architecture analysis: 2026-05-20*
