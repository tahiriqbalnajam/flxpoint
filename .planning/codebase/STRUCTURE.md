# Codebase Structure

**Analysis Date:** 2026-05-20

## Directory Layout

```
flxpnt/
├── flxpnt.php                          # Plugin bootstrap / entry point
├── index.php                           # Directory guard ("Silence is golden")
├── uninstall.php                       # Uninstall handler (stub)
├── README.txt                          # WordPress.org readme (boilerplate)
├── LICENSE.txt                         # GPL-2.0+ license
├── admin/                              # Admin-facing functionality
│   ├── class-flxpnt-admin.php          # Admin hooks: menus, settings, AJAX
│   ├── index.php                       # Directory guard
│   ├── css/
│   │   └── flxpnt-admin.css            # Admin panel styles
│   ├── js/
│   │   └── flxpnt-admin.js             # Admin panel JavaScript (connection test)
│   └── partials/
│       ├── flxpnt-admin-display.php    # Admin display partial (unused stub)
│       └── flxpnt-admin-settings.php   # Settings page HTML template
├── includes/                           # Core plugin classes (shared logic)
│   ├── class-flxpnt.php                # Core orchestrator (composition root)
│   ├── class-flxpnt-loader.php         # Hook registry (deferred registration)
│   ├── class-flxpnt-i18n.php           # Internationalization (text domain)
│   ├── class-flxpnt-activator.php      # Activation hook handler (stub)
│   ├── class-flxpnt-deactivator.php    # Deactivation hook handler (stub)
│   └── index.php                       # Directory guard
├── public/                             # Public-facing functionality
│   ├── class-flxpnt-public.php         # Public hooks: enqueue assets
│   ├── index.php                       # Directory guard
│   ├── css/
│   │   └── flxpnt-public.css           # Public-facing styles (empty)
│   ├── js/
│   │   └── flxpnt-public.js            # Public-facing JavaScript (empty)
│   └── partials/
│       └── flxpnt-public-display.php   # Public display partial (unused stub)
└── languages/
    └── flxpnt.pot                      # Translation template (empty, 0 bytes)
```

## Directory Purposes

**`admin/`:**
- Purpose: All WordPress admin panel integration -- menu registration, settings page, AJAX handlers, admin-specific assets
- Contains: PHP class (`Flxpnt_Admin`), CSS, JavaScript, view partials
- Key files: `class-flxpnt-admin.php` (132 lines), `js/flxpnt-admin.js` (40 lines), `partials/flxpnt-admin-settings.php` (57 lines)

**`includes/`:**
- Purpose: Plugin-wide shared logic -- hook orchestration, internationalization, lifecycle handlers. This is the core of the plugin that does not belong exclusively to admin or public sides.
- Contains: `Flxpnt` orchestrator (221 lines), `Flxpnt_Loader` (129 lines), `Flxpnt_i18n` (47 lines), `Flxpnt_Activator` stub (36 lines), `Flxpnt_Deactivator` stub (36 lines)
- Key files: `class-flxpnt.php` (composition root), `class-flxpnt-loader.php` (hook registry)

**`public/`:**
- Purpose: Frontend/public-facing site integration -- enqueuing assets, display partials visible to site visitors
- Contains: `Flxpnt_Public` class (103 lines, mostly boilerplate), empty CSS/JS files, unused display partial
- Key files: `class-flxpnt-public.php`

**`languages/`:**
- Purpose: Translation files for internationalization
- Contains: `flxpnt.pot` (empty template, 0 bytes)
- Key files: `flxpnt.pot` (needs generation)

**`.planning/`:**
- Purpose: GSD planning artifacts and codebase documentation
- Contains: `codebase/` subdirectory for architecture/quality documentation
- Generated: Yes (by `/gsd:map-codebase` commands)
- Committed: Yes

## Key File Locations

**Entry Points:**
- `flxpnt.php`: Primary plugin bootstrap. Loaded by WordPress when the plugin is activated. Defines `FLXPNT_VERSION`, registers activation/deactivation hooks, requires core class, calls `run_flxpnt()`.
- `uninstall.php`: Runs when the plugin is deleted from WordPress admin. Currently only a security guard.
- `index.php`: Directory listing guard in root, `admin/`, `includes/`, and `public/` directories. All contain the same "Silence is golden" pattern.

**Configuration:**
- `flxpnt.php:38`: `FLXPNT_VERSION` constant definition
- `admin/class-flxpnt-admin.php:55-56`: Settings registration (`flxpnt_api_base_url`, `flxpnt_api_token`) via WordPress Settings API
- `admin/partials/flxpnt-admin-settings.php`: Settings page HTML form with API Base URL and API Token fields

**Core Logic:**
- `includes/class-flxpnt.php`: Composition root. `load_dependencies()`, `set_locale()`, `define_admin_hooks()`, `define_public_hooks()`, `run()`
- `includes/class-flxpnt-loader.php`: Hook registry with `add_action()`, `add_filter()`, and batch `run()` methods
- `includes/class-flxpnt-i18n.php`: Text domain loading via `load_plugin_textdomain()`

**Admin Logic:**
- `admin/class-flxpnt-admin.php:19-30`: Asset enqueuing (CSS + JS with `wp_localize_script`)
- `admin/class-flxpnt-admin.php:33-52`: Admin menu registration (top-level menu at position 56 + Settings submenu)
- `admin/class-flxpnt-admin.php:54-57`: Settings field registration
- `admin/class-flxpnt-admin.php:59-70`: Settings page rendering (reads options, includes partial)
- `admin/class-flxpnt-admin.php:72-130`: AJAX handler for connection test (nonce check, capability check, HTTP GET to Flxpoint API)

**Public Logic:**
- `public/class-flxpnt-public.php:62-77`: Public CSS enqueuing
- `public/class-flxpnt-public.php:85-101`: Public JS enqueuing

**Testing:**
- No test files exist in the codebase. No test framework configured.

## Naming Conventions

**Files:**
- `class-flxpnt-{component}.php`: PHP class files. Dashed lowercase with `class-` prefix. Example: `class-flxpnt-admin.php`
- `flxpnt-{component}.css` / `flxpnt-{component}.js`: Asset files. Dashed lowercase. Example: `flxpnt-admin.css`, `flxpnt-admin.js`
- `flxpnt-{component}-{view}.php`: View partials. Dashed lowercase. Example: `flxpnt-admin-settings.php`, `flxpnt-public-display.php`
- `index.php`: Directory guard files (prevent directory listing)
- `{name}.php`: Top-level bootstrap and utility files. Example: `flxpnt.php`, `uninstall.php`

**Directories:**
- Lowercase, single word: `admin`, `includes`, `public`, `languages`
- Subdirectories follow the same pattern: `css`, `js`, `partials`

**PHP Classes:**
- `Flxpnt_{Component}`: StudlyCaps/PascalCase with underscore separator matching WordPress coding style. Example: `Flxpnt_Admin`, `Flxpnt_Loader`, `Flxpnt_i18n`, `Flxpnt_Public`
- The underscore convention mirrors the WordPress class naming used in WPPB, avoiding namespace conflicts without PHP namespaces

**PHP Methods:**
- `snake_case`: All methods use lowercase_with_underscores. Example: `load_dependencies()`, `define_admin_hooks()`, `enqueue_styles()`, `handle_test_connection()`, `add_plugin_admin_menu()`

**PHP Variables:**
- `$snake_case`: All variables use lowercase_with_underscores. Example: `$plugin_name`, `$api_base_url`, `$api_token`, `$connection_status`, `$status_code`

**Constants:**
- `UPPERCASE_WITH_UNDERSCORES`: Example: `FLXPNT_VERSION`, `WPINC`, `WP_UNINSTALL_PLUGIN`

**WordPress Hooks:**
- Action names: `flxpnt_*` prefix for custom hooks. Example: `flxpnt_test_connection` (AJAX action). Standard WordPress hook names used otherwise: `admin_menu`, `admin_enqueue_scripts`, `wp_ajax_*`, `plugins_loaded`

**WordPress Options:**
- `flxpnt_*` prefix: All option names use the plugin slug as prefix. Example: `flxpnt_api_base_url`, `flxpnt_api_token`, `flxpnt_connection_status` (transient)

**CSS Classes:**
- `flxpnt-{component}-{element}`: Dashed lowercase with plugin prefix. Example: `.flxpnt-settings-wrap`

**Text Domain / i18n:**
- The string `'flxpnt'` is used consistently as the text domain for all translation functions (`__()`, `_e()`)

## Where to Add New Code

**New Admin Page or Feature:**
- Primary code: `admin/class-flxpnt-admin.php` (add new public methods for hook callbacks)
- Hook registration: `includes/class-flxpnt.php` → `define_admin_hooks()` (add `$this->loader->add_action(...)` calls)
- View template: `admin/partials/flxpnt-admin-{feature}.php` (create new partial following `flxpnt-admin-settings.php` naming)

**New Public-Facing Feature:**
- Primary code: `public/class-flxpnt-public.php` (add new public methods for hook callbacks)
- Hook registration: `includes/class-flxpnt.php` → `define_public_hooks()`
- View template: `public/partials/flxpnt-public-{feature}.php`

**New Shared/Cross-Cutting Logic (used by both admin and public):**
- Implementation: `includes/class-flxpnt-{feature}.php` (create new class following `class-flxpnt-*.php` naming)
- Registration: `includes/class-flxpnt.php` → `load_dependencies()` (add `require_once`)
- Hook wiring: `includes/class-flxpnt.php` → appropriate `define_*_hooks()` method

**New Integration or API Client:**
- Implementation: `includes/class-flxpnt-{service}.php` (e.g., `class-flxpnt-api-client.php` for a dedicated API client)
- Settings: `admin/class-flxpnt-admin.php` → `register_settings()` (add new option names)
- Settings UI: `admin/partials/flxpnt-admin-settings.php` (add new form fields)

**New JavaScript/CSS:**
- Admin assets: `admin/js/` and `admin/css/` directories
- Public assets: `public/js/` and `public/css/` directories
- Enqueuing: `admin/class-flxpnt-admin.php` → `enqueue_scripts()`/`enqueue_styles()` or `public/class-flxpnt-public.php` equivalents

**New WordPress Options or Transients:**
- Always prefix with `flxpnt_` to avoid collisions
- Register in `admin/class-flxpnt-admin.php` → `register_settings()` for admin-managed options
- Use transients for temporary/cached data (following the `flxpnt_connection_status` pattern)

**Tests:**
- No test directory or framework exists. If added, follow standard WordPress plugin conventions:
  - Unit tests: `tests/unit/` or `tests/phpunit/`
  - JavaScript tests: `tests/js/`
  - Consider using WP-CLI scaffold test commands (`wp scaffold plugin-tests`)

## Special Directories

**`.planning/`:**
- Purpose: GSD workflow artifacts -- codebase maps, implementation plans, phase logs
- Generated: Yes (by GSD commands like `/gsd:map-codebase`, `/gsd:plan-phase`, `/gsd:execute-phase`)
- Committed: Yes (committed by orchestrator to track planning state)

**`.git/`:**
- Purpose: Git repository metadata
- Generated: Yes (created by `git init`)
- Committed: Not applicable (local-only)

**`languages/`:**
- Purpose: POT translation template and compiled `.mo`/`.po` translation files
- Generated: POT file should be generated (currently empty), MO/PO files are compiled from translations
- Committed: Yes (POT and PO files are committed; MO files are typically generated at build time)

---

*Structure analysis: 2026-05-20*
