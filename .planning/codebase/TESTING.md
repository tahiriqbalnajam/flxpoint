# Testing Patterns

**Analysis Date:** 2026-05-20

## Current State

**No testing infrastructure exists.** This codebase has zero automated tests of any kind. No test runner, no test files, no test configuration, no mocking library, and no CI pipeline.

This is a greenfield WordPress plugin scaffolded from the WordPress Plugin Boilerplate with minimal custom implementation. The codebase contains approximately 900 lines of PHP and 50 lines of JavaScript across 16 source files.

## What Needs Testing

### Testable Units (Priority Order)

**1. AJAX Connection Test (`admin/class-flxpnt-admin.php`, lines 72-130):**
- `handle_test_connection()` method is the most complex function in the codebase
- Tests needed: success response, HTTP error response, WP_Error response, missing parameters, unauthorized access, nonce verification

**2. API Request Construction (`admin/class-flxpnt-admin.php`, lines 86-92):**
- Correct URL construction with `trailingslashit()`
- Correct Authorization header format (`Bearer <token>`)
- Correct Accept header
- Timeout parameter

**3. Settings Registration (`admin/class-flxpnt-admin.php`, lines 54-57):**
- `register_settings()` registers correct option keys with correct settings group
- Options are retrievable via `get_option()` with defaults

**4. Admin Menu Registration (`admin/class-flxpnt-admin.php`, lines 33-52):**
- `add_plugin_admin_menu()` registers menu and submenu with correct capability check

**5. Hook Registration (`includes/class-flxpnt.php`, lines 153-178):**
- Admin hooks registered: `admin_enqueue_scripts` (x2), `admin_menu`, `admin_init`, `wp_ajax_flxpnt_test_connection`
- Public hooks registered: `wp_enqueue_scripts` (x2)
- i18n hook registered: `plugins_loaded`

**6. Loader Class (`includes/class-flxpnt-loader.php`):**
- `add_action()` adds to actions collection
- `add_filter()` adds to filters collection
- `run()` registers all actions and filters with WordPress
- Priority and argument count propagation

**7. Input Sanitization and Validation:**
- `sanitize_text_field()` applied to token input
- `esc_url_raw()` applied to URL input
- Nonce verification via `check_ajax_referer()`
- Capability check via `current_user_can()`

### Untestable Without Refactoring

- Direct `wp_remote_get()` calls in `handle_test_connection()` are not abstracted -- the HTTP client cannot be mocked without refactoring
- `set_transient()` / `get_transient()` calls in settings page display mix data retrieval with presentation
- No dependency injection for WordPress API functions (reliance on global functions)

## Recommended Testing Setup

### Framework

**Runner:** PHPUnit (WordPress's official test framework via WP-CLI `wp scaffold plugin-tests`)
- Install: `composer require --dev phpunit/phpunit`
- WordPress integration test suite: `wp scaffold plugin-tests flxpnt` generates `phpunit.xml.dist`, `tests/bootstrap.php`
- Config: `phpunit.xml.dist` in plugin root

**WordPress Testing Library:** WP_UnitTestCase (from WordPress core test suite or `wp-phpunit/wp-phpunit`)
- Provides factory methods for creating posts, users, etc.
- Handles WordPress environment bootstrap

**Mocking:** PHPUnit's built-in `createMock()` and `getMockBuilder()` or Brain\Monkey for mocking WordPress functions

**Assertion Library:** PHPUnit assertions (`assertEquals`, `assertTrue`, `assertWPError`, etc.)

### Run Commands

```bash
# Install dev dependencies
composer install

# Install WordPress test suite (one-time)
bash bin/install-wp-tests.sh wordpress_test root '' localhost latest

# Run all tests
vendor/bin/phpunit

# Run a specific test file
vendor/bin/phpunit tests/test-admin.php

# Run with coverage report
vendor/bin/phpunit --coverage-html coverage/
```

### Composer Setup

A `composer.json` should be created at the plugin root:
```json
{
    "name": "tahir/flxpnt",
    "type": "wordpress-plugin",
    "require-dev": {
        "phpunit/phpunit": "^9.0",
        "brain/monkey": "^2.6",
        "wp-phpunit/wp-phpunit": "^6.0"
    },
    "autoload-dev": {
        "psr-4": {
            "Flxpnt\\Tests\\": "tests/"
        }
    }
}
```

## Test File Organization

**Location:** Co-located test directory at plugin root:
```
flxpnt/
├── tests/
│   ├── bootstrap.php            # WordPress test environment bootstrap
│   ├── test-plugin.php          # Main plugin file tests
│   ├── admin/
│   │   └── test-class-admin.php # Admin class tests
│   ├── includes/
│   │   ├── test-class-flxpnt.php        # Core class tests
│   │   ├── test-class-loader.php        # Loader class tests
│   │   ├── test-class-activator.php     # Activator tests
│   │   └── test-class-deactivator.php   # Deactivator tests
│   └── public/
│       └── test-class-public.php # Public class tests
```

**Naming:** Test classes use the prefix `test-` and mirror the source file name.

**Class naming:** Test classes follow PHPUnit convention of matching file name, e.g., `Test_Flxpnt_Admin` or `Flxpnt_Admin_Test`.

## Test Structure Patterns

### Unit Test for a Class Method

```php
<?php
/**
 * Tests for Flxpnt_Admin class.
 *
 * @package Flxpnt\Tests\Admin
 */

use Brain\Monkey\Functions;

class Flxpnt_Admin_Test extends WP_UnitTestCase {

    private $admin;

    public function setUp(): void {
        parent::setUp();
        $this->admin = new Flxpnt_Admin( 'flxpnt', '1.0.0' );
    }

    public function tearDown(): void {
        parent::tearDown();
    }

    public function test_register_settings_adds_options() {
        $this->admin->register_settings();

        // Verify settings are registered by checking they exist in the global registry
        global $wp_registered_settings;
        $this->assertArrayHasKey( 'flxpnt_api_base_url', $wp_registered_settings );
        $this->assertArrayHasKey( 'flxpnt_api_token', $wp_registered_settings );
    }
}
```

### Integration Test for AJAX Handler

```php
<?php
class Flxpnt_Ajax_Test extends WP_Ajax_UnitTestCase {

    public function test_handle_test_connection_requires_nonce() {
        // Set up a user with manage_options capability
        $user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $user_id );

        // Send AJAX request without nonce
        try {
            $this->_handleAjax( 'flxpnt_test_connection' );
        } catch ( WPAjaxDieStopException $e ) {
            // Expected - wp_die() was called
        }

        // Verify error response
        $this->assertTrue( isset( $e ) );
    }

    public function test_handle_test_connection_rejects_subscriber() {
        $user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
        wp_set_current_user( $user_id );

        $_POST['nonce'] = wp_create_nonce( 'flxpnt_test_connection' );

        try {
            $this->_handleAjax( 'flxpnt_test_connection' );
        } catch ( WPAjaxDieStopException $e ) {
            // Expected
        }

        $response = json_decode( $this->_last_response, true );
        $this->assertFalse( $response['success'] );
    }
}
```

### Mocking WordPress HTTP

```php
<?php
use Brain\Monkey\Functions;

class Flxpnt_Admin_Connection_Test extends WP_UnitTestCase {

    public function test_connection_success_returns_json_success() {
        // Mock nonce check to pass
        Functions\expect( 'check_ajax_referer' )
            ->once()
            ->andReturn( true );

        Functions\expect( 'current_user_can' )
            ->once()
            ->with( 'manage_options' )
            ->andReturn( true );

        // Mock HTTP response
        Functions\expect( 'wp_remote_get' )
            ->once()
            ->andReturn( array(
                'response' => array( 'code' => 200 ),
                'body'     => '{"products":[]}',
            ) );

        Functions\expect( 'wp_remote_retrieve_response_code' )
            ->once()
            ->andReturn( 200 );

        Functions\expect( 'wp_remote_retrieve_body' )
            ->once()
            ->andReturn( '{"products":[]}' );

        Functions\expect( 'wp_send_json_success' )
            ->once()
            ->with( \Mockery::type( 'array' ) );

        Functions\expect( 'set_transient' )
            ->once();

        $admin = new Flxpnt_Admin( 'flxpnt', '1.0.0' );

        $_POST['api_base_url'] = 'https://api.flxpoint.com/';
        $_POST['api_token']    = 'test-token';

        $admin->handle_test_connection();
    }
}
```

### Mocking Guidelines

**What to Mock:**
- WordPress HTTP functions (`wp_remote_get`, `wp_remote_post`) -- external API calls should never be made in tests
- Transient functions (`set_transient`, `get_transient`) when testing code that stores transient data
- WordPress AJAX termination functions (`wp_send_json_success`, `wp_send_json_error`, `wp_die`)
- Time-sensitive functions if testing transient expiry logic

**What NOT to Mock:**
- Simple WordPress option functions (`get_option`, `update_option`) -- use WordPress test environment
- Capability checks (`current_user_can`) -- create real users with real roles in tests
- Nonce functions (`wp_create_nonce`, `wp_verify_nonce`) -- use real nonces
- Escaping/sanitization functions (`esc_attr`, `sanitize_text_field`) -- test actual values

### Fixtures and Factories

**Test Data Location:** `tests/fixtures/` for JSON or static data files

```php
// Example: API response fixture
// tests/fixtures/flxpoint-product-response.json
{
    "products": [
        {
            "id": 1,
            "sku": "TEST-SKU-001",
            "name": "Test Product"
        }
    ]
}
```

**WordPress Factories:** Use WP_UnitTestCase factory methods:
```php
$user_id  = $this->factory->user->create( array( 'role' => 'administrator' ) );
$post_id  = $this->factory->post->create();
```

## Test Commands

```bash
# Full test suite
vendor/bin/phpunit

# Single test file
vendor/bin/phpunit tests/admin/test-class-admin.php

# Single test method
vendor/bin/phpunit --filter test_connection_success

# With coverage
vendor/bin/phpunit --coverage-text --coverage-html coverage/
```

## Test Types

**Unit Tests:**
- Scope: Individual class methods, isolated from WordPress where possible
- Files: `tests/admin/`, `tests/includes/`, `tests/public/`
- Approach: Mock WordPress function dependencies using Brain\Monkey, inject real values for simple operations

**Integration Tests:**
- Scope: AJAX handlers, hook registration chain (Flxpnt -> Flxpnt_Loader -> WordPress hooks)
- Files: Same as unit tests but extending `WP_Ajax_UnitTestCase` or `WP_UnitTestCase` with full WordPress bootstrap
- Approach: Let the WordPress test environment handle core functions; only mock external HTTP calls

**E2E Tests:**
- Not used and not recommended at this stage (plugin is too small; no browser UI beyond WordPress admin)

## Coverage

**Requirements:** None currently enforced. Target should be:
- 80%+ line coverage for `admin/class-flxpnt-admin.php` (contains all business logic)
- 90%+ for `includes/class-flxpnt-loader.php` (pure data structure, easy to test)
- 60%+ overall as a starting point

**View Coverage:**
```bash
vendor/bin/phpunit --coverage-text
```

## Common Patterns

**Async Testing:**
Not applicable -- the plugin has no async PHP operations. The only async operation is the JavaScript AJAX call to `admin-ajax.php`, which is tested synchronously in PHPUnit integration tests.

**Error Testing:**
```php
// Test WP_Error path from wp_remote_get
public function test_connection_handles_wp_error() {
    Functions\expect( 'check_ajax_referer' )->once()->andReturn( true );
    Functions\expect( 'current_user_can' )->once()->andReturn( true );

    $wp_error = new WP_Error( 'http_request_failed', 'Connection refused' );
    Functions\expect( 'wp_remote_get' )->once()->andReturn( $wp_error );

    Functions\expect( 'is_wp_error' )->once()->andReturn( true );
    Functions\expect( 'wp_send_json_error' )->once();

    $admin = new Flxpnt_Admin( 'flxpnt', '1.0.0' );
    $_POST['api_base_url'] = 'https://api.flxpoint.com/';
    $_POST['api_token']    = 'test-token';

    $admin->handle_test_connection();
}

// Test HTTP error status codes
public function test_connection_handles_http_400() {
    // ... mock wp_remote_get to return 400 response
    // Expect wp_send_json_error with formatted error message
}
```

**Capability Testing:**
```php
public function test_settings_page_hidden_from_subscribers() {
    $user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
    wp_set_current_user( $user_id );

    ob_start();
    $admin = new Flxpnt_Admin( 'flxpnt', '1.0.0' );
    $admin->display_plugin_settings_page();
    $output = ob_get_clean();

    // Should produce no output for unauthorized users
    $this->assertEmpty( $output );
}
```

---

*Testing analysis: 2026-05-20*
