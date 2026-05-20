# Coding Conventions

**Analysis Date:** 2026-05-20

## Naming Patterns

**Classes:**
- PascalCase with underscores separating the plugin prefix: `Flxpnt`, `Flxpnt_Loader`, `Flxpnt_i18n`, `Flxpnt_Admin`, `Flxpnt_Public`, `Flxpnt_Activator`, `Flxpnt_Deactivator`
- All classes live in their own file named `class-{lowercase-class-name}.php`

**Constants:**
- SCREAMING_SNAKE_CASE: `FLXPNT_VERSION`, `WPINC`, `WP_UNINSTALL_PLUGIN`

**Functions (standalone):**
- snake_case, prefixed with the plugin slug: `activate_flxpnt()`, `deactivate_flxpnt()`, `run_flxpnt()`

**Methods:**
- snake_case for nearly all methods: `load_dependencies`, `set_locale`, `define_admin_hooks`, `define_public_hooks`, `get_plugin_name`, `get_version`, `get_loader`, `enqueue_styles`, `enqueue_scripts`, `add_plugin_admin_menu`, `register_settings`, `display_plugin_settings_page`, `handle_test_connection`, `load_plugin_textdomain`
- camelCase used for two methods in `Flxpnt_Loader` that mirror WordPress core function names: `add_action`, `add_filter`
- Private utility method `add` in `Flxpnt_Loader` uses camelCase (inconsistent with the rest of the codebase)

**Properties:**
- snake_case: `$plugin_name`, `$version`, `$loader`, `$actions`, `$filters`, `$api_base_url`, `$api_token`, `$connection_status`
- Visibility explicitly declared: `private`, `protected`, or `public`

**Files:**
- PHP classes: `class-{name}.php` (e.g., `class-flxpnt-admin.php`, `class-flxpnt-loader.php`)
- JS files: `{plugin-name}-{context}.js` (e.g., `flxpnt-admin.js`, `flxpnt-public.js`)
- CSS files: `{plugin-name}-{context}.css` (e.g., `flxpnt-admin.css`, `flxpnt-public.css`)
- Partials: `{plugin-name}-{context}-display.php` or `{plugin-name}-{context}-settings.php`
- Main plugin file: `flxpnt.php` (matches the plugin directory name)
- Empty index.php files in each directory for directory listing protection

**Directories:**
- lowercase: `admin/`, `includes/`, `public/`, `languages/`
- Subdirectories: `admin/css/`, `admin/js/`, `admin/partials/`, `public/css/`, `public/js/`, `public/partials/`

**Text Domain:**
- All translatable strings use the text domain `'flxpnt'` (lowercase, matches plugin slug)

**Option Keys:**
- snake_case with plugin prefix: `flxpnt_api_base_url`, `flxpnt_api_token`, `flxpnt_connection_status`

**Hook Names:**
- snake_case with plugin prefix: `flxpnt_test_connection`

**JS Variables:**
- Plugin-localized data object: `flxpnt_admin` (snake_case)
- Local variables in IIFE closure use camelCase: `$btn`, `$spinner`, `$result`, `$dynamic`

## Code Style

**Formatting:**
- No auto-formatter configuration detected (no `.prettierrc`, `phpcs.xml`, `composer.json`, or `package.json`)
- Tab indentation for PHP (WordPress standard)
- Inline brace style: `if ( condition ) {` on same line
- Spacing inside parentheses for control structures: `if ( ! defined( 'WPINC' ) )`
- Array syntax: uses `array()` (long form), not `[]` short array syntax

**Linting:**
- No linter configuration detected (no ESLint, PHPCS, or PHPStan config)

**PHP Opening:**
- Every PHP file begins with `<?php` on line 1, with no closing `?>` tag (WordPress best practice to prevent trailing whitespace issues)

**Direct Access Prevention:**
- All standalone PHP files (not class files) guard against direct access using a constant check:
  ```php
  // In flxpnt.php:
  if ( ! defined( 'WPINC' ) ) { die; }

  // In uninstall.php:
  if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }
  ```
- Directory-listing prevention: empty `index.php` files in `admin/`, `includes/`, and `public/` directories

## Documentation

**PHPDoc (DocBlocks):**
- Every class has a file-level DocBlock with `@link`, `@since`, `@package`, and `@subpackage` tags
- Every class has a class-level DocBlock with description, `@since`, `@package`, `@subpackage`, `@author`
- Properties have `@since`, `@access`, `@var` tags
- Methods have `@since`, `@param`, `@return` tags, with `@access` for private methods
- The `@subpackage` tag follows the directory structure: `Flxpnt/includes`, `Flxpnt/admin`, `Flxpnt/public`, `Flxpnt/admin/partials`, `Flxpnt/public/partials`

**Inline Comments:**
- Sparse; used mainly for section markers (e.g., "check if called directly")
- Boilerplate comments from the WordPress Plugin Boilerplate template remain in several files (e.g., "This function is provided for demonstration purposes only", "This file should primarily consist of HTML with a little bit of PHP.")

**JS Comments:**
- `'use strict'` directive at top of every IIFE
- Boilerplate comment block in `public/js/flxpnt-public.js` explaining jQuery usage patterns (leftover from template)
- No JSDoc comments observed

## Internationalization

**Framework:** WordPress core i18n functions

**Patterns:**
- All user-facing strings use translation functions with the `'flxpnt'` text domain:
  - `__()` for returning translated strings
  - `_e()` in the admin settings partial (for echoed translations)
  - `esc_html__( )` not used directly but strings are escaped at output time with `esc_html()` and `esc_attr()`
- Translation file: `languages/flxpnt.pot` (currently empty/stub)
- Text domain loading registered via `Flxpnt_i18n::load_plugin_textdomain()` hooked to `plugins_loaded`

**Required convention for new code:**
- Wrap ALL user-facing strings in `__()` or `_e()` with text domain `'flxpnt'`
- Use `sprintf()` or `printf()` with `__()` for strings with placeholders (as seen in `class-flxpnt-admin.php` line 116-118)

## Input/Output Handling

**Input Sanitization:**
- `sanitize_text_field()` for plain text inputs (API token)
- `esc_url_raw()` for URL inputs (API base URL), combined with `trailingslashit()`
- `check_ajax_referer()` for nonce verification on AJAX endpoints
- `wp_create_nonce()` to generate nonces

**Output Escaping:**
- `esc_html()` for plain text output in HTML context (e.g., admin page title, connection status message)
- `esc_attr()` for attribute context (e.g., input values, HTML attribute values)
- Use `esc_html()` or `esc_attr()` depending on context -- do not mix

**Settings:**
- `register_setting()` with settings group name (e.g., `'flxpnt_settings'`)
- `get_option()` with defaults provided as the second argument
- `settings_fields()` called in the form rendering partial

## Function and Method Design

**Size:** Methods are small and focused. The largest method is `handle_test_connection()` at 58 lines in `admin/class-flxpnt-admin.php`.

**Visibility:** All methods and properties have explicit visibility declarations (`public`, `private`, `protected`). Most methods are `public` by convention in this codebase.

**Constructor injection:** The `Flxpnt_Admin` and `Flxpnt_Public` classes receive `$plugin_name` and `$version` via constructor, set by the core `Flxpnt` class. This is the recommended pattern for new classes that need plugin identity.

**Static methods:** Used only for activation/deactivation hooks (`Flxpnt_Activator::activate()`, `Flxpnt_Deactivator::deactivate()`). New code should prefer instance methods unless WordPress lifecycle requires static callbacks.

**Return values:** 
- `wp_send_json_success()` / `wp_send_json_error()` for AJAX responses (these call `wp_die()` internally)
- `get_option()` returns the stored value or the provided default
- Methods that register hooks or enqueue assets return void (no explicit return)

## Module Design

**Exports:** Each PHP class file defines exactly one class. No namespace usage (WordPress plugin conventions).

**File organization by layer:**
- `includes/` -- core plugin logic (loader, i18n, activator, deactivator, main class)
- `admin/` -- admin-facing functionality (admin class, partials, CSS, JS)
- `public/` -- public-facing functionality (public class, partials, CSS, JS)
- `languages/` -- translation files

**Dependency loading:** All dependencies are loaded via `require_once` calls in `Flxpnt::load_dependencies()`. No autoloader or Composer.

## JS Conventions

**Module pattern:** IIFE (Immediately Invoked Function Expression) wrapping all JS:
```javascript
(function( $ ) {
    'use strict';
    // code here
})( jQuery );
```

**DOM ready:** `$(function() { ... })` for DOM-ready initialization

**AJAX:** jQuery `$.post()` with the `'json'` dataType parameter. Uses WordPress AJAX URL from localized data.

**Server communication:**
- AJAX URL obtained from `wp_localize_script()` data object (`flxpnt_admin.ajax_url`)
- Nonce obtained from same localized object (`flxpnt_admin.nonce`)
- Always sends both `action` and `nonce` parameters in AJAX POST data

**UI state management:**
- Loading states via button `disabled` property and jQuery `.spinner` addClass/removeClass
- Results displayed by constructing HTML strings with jQuery `.html()` calls

## CSS Conventions

**Selector naming:** Class-based selectors using the plugin prefix: `.flxpnt-settings-wrap`, `#flxpnt-test-connection`, `#flxpnt-connection-result`, `#flxpnt-connection-dynamic`

**Organization:** Minimal; one rule per visual concern. CSS files are tiny (under 10 meaningful rules).

## Where Conventions Differ From WordPress Core

- WordPress core coding standards recommend spaces, but this plugin uses tabs (also acceptable by WP standards)
- PHP array syntax uses `array()` rather than `[]` (compatible with PHP 5.2+)
- Yoda conditions NOT used (e.g., `if ( $x === null )` instead of `if ( null === $x )`) -- this is a deviation from WordPress PHP coding standards
- The private `add` method in `Flxpnt_Loader` uses camelCase while all other methods use snake_case

---

*Convention analysis: 2026-05-20*
