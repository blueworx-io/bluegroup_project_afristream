# Retire ACF, Configurations Page, Auto-Assign Licences — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the licence data model out of Advanced Custom Fields and into this plugin, add a read-only Configurations page listing everything the plugin registers, and auto-assign licences to paying customers when stock exists — with multiple licences per user held safely.

**Architecture:** Ownership of a licence becomes a single scalar on the licence post (`postmeta afristream_assigned_user`), making double-assignment structurally impossible; the existing `usermeta active_license` array is kept as a derived mirror so every current reader keeps working. A dependency-free PHP test harness with in-memory WordPress stubs covers the pure logic, since the existing Playwright suite runs against a Node preview harness and cannot reach WordPress admin.

**Tech Stack:** PHP 8.3 (WordPress plugin, procedural, `afristream_` prefix), Playwright for front-end, plain PHP CLI for unit tests, no new npm or composer dependencies.

## Global Constraints

- **No new dependencies.** `approved-deps.json` gates them and nothing here needs one. The PHP test harness is hand-written and dependency-free.
- **Meta keys and value formats are frozen.** `app_password`, `license_provider`, `mobile_active` store their plain value; `expiry_date` stores `Ymd` (e.g. `20261231`) and reads back as `d/m/Y`; `usermeta active_license` stores a serialized array whose **values are strings**, not ints — `array( 0 => '123' )`. Storing ints would break the existing `LIKE '"123"'` reverse lookup and any Elementor or third-party reader.
- **Every function is prefixed `afristream_`.** Match the surrounding file's tone: real sentences in doc comments explaining *why*, not restatements of the code.
- **Escape on output, sanitise on input, nonce every form, capability-check every write.** The existing files do this; keep it.
- **SureCart access is guarded with `class_exists()`** and reads attributes via `afristream_affiliate_prop()` — SureCart models use `__get`, so `isset()` and `??` are unreliable on them.
- **Version:** `0.19.0` → `0.20.0` in `bluegroup-project-afristream.php` (header + `AFRISTREAM_PORTAL_VERSION`) and `package.json`, with a matching `CHANGELOG.md` entry. Done once, in the final task.
- **Lint runs once at the end**, findings reported to Luke, not auto-fixed in a loop.
- **Nothing auto-revokes a licence.** Over-allocation is reported only.

---

## File Structure

| File | Responsibility |
|---|---|
| `tests/php/bootstrap.php` | in-memory WordPress stubs (post meta, user meta, options, transients, sanitisers) |
| `tests/php/assert.php` | `af_assert`, `af_assert_same`, test registration and result tallying |
| `tests/php/run.php` | discovers and runs `tests/php/test-*.php`, exits non-zero on failure |
| `tests/php/test-*.php` | one file per unit under test |
| `scripts/run-php-tests.mjs` | runs the PHP suite, skips cleanly when `php` is not on PATH |
| `includes/fields.php` | licence post type, meta accessors, ownership primitives, lock, mirror, backfill |
| `includes/license-log.php` | per-licence event log: append, read, merge across licences |
| `includes/license-admin.php` | licence editor meta boxes (fields + history) and user profile licence field |
| `includes/auto-assign.php` | SureCart triggers, entitlement, top-up, pending queue, over-allocation |
| `includes/configurations.php` | `afristream_registry` filter, Configurations admin page, ACF readiness audit |
| `includes/licenses.php` | *rewritten* — admin columns and sorting on the new accessors, ACF filters gone |
| `includes/shortcodes.php` | *edited* — `[user_acf_fields]` keeps its tag, loses its ACF dependency |
| `bluegroup-project-afristream.php` | *edited* — require new files, registry entries, version bump |

`includes/fields.php` is the only file that touches licence meta directly. Everything else goes through its accessors.

---

## Task 1: PHP test harness

Nothing in this task changes plugin behaviour. It exists because the risky parts of this work — availability filtering, entitlement arithmetic, log capping, mirror derivation — are pure functions that the Playwright suite structurally cannot reach: `playwright.config.js` points at `scripts/preview-server.mjs`, a Node static server, with no WordPress anywhere in the loop.

**Files:**
- Create: `tests/php/bootstrap.php`
- Create: `tests/php/assert.php`
- Create: `tests/php/run.php`
- Create: `scripts/run-php-tests.mjs`
- Modify: `package.json` (scripts: `lint`, `test`, `test:php`)

**Interfaces:**
- Consumes: nothing.
- Produces: `af_test( string $name, callable $fn )`, `af_assert( bool $cond, string $message )`, `af_assert_same( mixed $expected, mixed $actual, string $message )`, `af_reset_store()`. Every later task's tests use these. The stubs make `get_post_meta`, `update_post_meta`, `delete_post_meta`, `get_user_meta`, `update_user_meta`, `delete_user_meta`, `get_option`, `update_option`, `get_transient`, `set_transient`, `delete_transient`, `current_time`, `sanitize_text_field`, `absint`, `get_the_title`, `get_posts`, `get_users`, `wp_list_pluck`, `apply_filters`, `add_filter`, `add_action`, `do_action` available to the code under test.

- [ ] **Step 1: Write the assertion helpers**

Create `tests/php/assert.php`:

```php
<?php
/**
 * The smallest test harness that does the job. Not PHPUnit, because adding a
 * composer dependency to a plugin that has none — and getting it approved in
 * approved-deps.json — costs more than the fifty lines below.
 */

$GLOBALS['af_tests']    = array();
$GLOBALS['af_failures'] = array();
$GLOBALS['af_current']  = '';
$GLOBALS['af_count']    = 0;

function af_test( $name, $fn ) {
	$GLOBALS['af_tests'][] = array( $name, $fn );
}

function af_fail( $message ) {
	$GLOBALS['af_failures'][] = $GLOBALS['af_current'] . ' — ' . $message;
}

function af_assert( $cond, $message ) {
	$GLOBALS['af_count']++;
	if ( ! $cond ) {
		af_fail( $message );
	}
}

function af_assert_same( $expected, $actual, $message ) {
	$GLOBALS['af_count']++;
	if ( $expected !== $actual ) {
		af_fail(
			$message . "\n      expected: " . var_export( $expected, true )
			. "\n      actual:   " . var_export( $actual, true )
		);
	}
}

function af_run_tests() {
	foreach ( $GLOBALS['af_tests'] as $test ) {
		list( $name, $fn ) = $test;
		$GLOBALS['af_current'] = $name;
		af_reset_store();
		$fn();
	}

	$failures = $GLOBALS['af_failures'];
	$tests    = count( $GLOBALS['af_tests'] );
	$asserts  = $GLOBALS['af_count'];

	if ( empty( $failures ) ) {
		echo "PHP tests: {$tests} tests, {$asserts} assertions, all passing\n";
		return 0;
	}

	echo "PHP tests: {$tests} tests, {$asserts} assertions, " . count( $failures ) . " FAILED\n\n";
	foreach ( $failures as $failure ) {
		echo "  ✗ {$failure}\n";
	}
	echo "\n";
	return 1;
}
```

- [ ] **Step 2: Write the WordPress stubs**

Create `tests/php/bootstrap.php`. These are in-memory equivalents of only the WordPress functions this plugin's pure logic calls — enough to exercise real behaviour, not a WordPress emulator:

```php
<?php
/**
 * In-memory stand-ins for the handful of WordPress functions the licence logic
 * calls. Deliberately not a WordPress emulator: each stub is the smallest thing
 * that behaves correctly for the code under test, so a passing test means the
 * logic is right rather than that the fake is generous.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['af_store'] = array();

function af_reset_store() {
	$GLOBALS['af_store'] = array(
		'postmeta'   => array(),
		'usermeta'   => array(),
		'options'    => array(),
		'transients' => array(),
		'posts'      => array(),
		'users'      => array(),
		'filters'    => array(),
		'actions'    => array(),
		'now'        => 1785024000, // 2026-07-26 08:00 UTC, fixed so date tests are stable.
	);
}
af_reset_store();

// -- Test-side seeding helpers ------------------------------------------------

function af_seed_post( $id, $title, $status = 'publish', $type = 'license' ) {
	$GLOBALS['af_store']['posts'][ $id ] = array(
		'ID'          => $id,
		'post_title'  => $title,
		'post_status' => $status,
		'post_type'   => $type,
	);
}

function af_seed_user( $id, $login = '', $registered = '2026-01-01 00:00:00' ) {
	$GLOBALS['af_store']['users'][ $id ] = array(
		'ID'              => $id,
		'user_login'      => $login ? $login : 'user' . $id,
		'display_name'    => $login ? $login : 'User ' . $id,
		'user_registered' => $registered,
	);
}

function af_set_now( $timestamp ) {
	$GLOBALS['af_store']['now'] = $timestamp;
}

// -- Meta ---------------------------------------------------------------------

function get_post_meta( $post_id, $key = '', $single = false ) {
	$all = isset( $GLOBALS['af_store']['postmeta'][ $post_id ] ) ? $GLOBALS['af_store']['postmeta'][ $post_id ] : array();
	if ( '' === $key ) {
		return $all;
	}
	if ( ! array_key_exists( $key, $all ) ) {
		return $single ? '' : array();
	}
	return $single ? $all[ $key ] : array( $all[ $key ] );
}

function update_post_meta( $post_id, $key, $value ) {
	$GLOBALS['af_store']['postmeta'][ $post_id ][ $key ] = $value;
	return true;
}

function delete_post_meta( $post_id, $key ) {
	unset( $GLOBALS['af_store']['postmeta'][ $post_id ][ $key ] );
	return true;
}

function get_user_meta( $user_id, $key = '', $single = false ) {
	$all = isset( $GLOBALS['af_store']['usermeta'][ $user_id ] ) ? $GLOBALS['af_store']['usermeta'][ $user_id ] : array();
	if ( '' === $key ) {
		return $all;
	}
	if ( ! array_key_exists( $key, $all ) ) {
		return $single ? '' : array();
	}
	return $single ? $all[ $key ] : array( $all[ $key ] );
}

function update_user_meta( $user_id, $key, $value ) {
	$GLOBALS['af_store']['usermeta'][ $user_id ][ $key ] = $value;
	return true;
}

function delete_user_meta( $user_id, $key ) {
	unset( $GLOBALS['af_store']['usermeta'][ $user_id ][ $key ] );
	return true;
}

// -- Options and transients ---------------------------------------------------

function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['af_store']['options'] ) ? $GLOBALS['af_store']['options'][ $key ] : $default;
}

function update_option( $key, $value ) {
	$GLOBALS['af_store']['options'][ $key ] = $value;
	return true;
}

function delete_option( $key ) {
	unset( $GLOBALS['af_store']['options'][ $key ] );
	return true;
}

function get_transient( $key ) {
	if ( ! array_key_exists( $key, $GLOBALS['af_store']['transients'] ) ) {
		return false;
	}
	list( $value, $expires ) = $GLOBALS['af_store']['transients'][ $key ];
	if ( $expires && $expires < $GLOBALS['af_store']['now'] ) {
		unset( $GLOBALS['af_store']['transients'][ $key ] );
		return false;
	}
	return $value;
}

function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['af_store']['transients'][ $key ] = array( $value, $ttl ? $GLOBALS['af_store']['now'] + $ttl : 0 );
	return true;
}

function delete_transient( $key ) {
	unset( $GLOBALS['af_store']['transients'][ $key ] );
	return true;
}

// -- Posts and users ----------------------------------------------------------

function get_the_title( $post_id ) {
	return isset( $GLOBALS['af_store']['posts'][ $post_id ] ) ? $GLOBALS['af_store']['posts'][ $post_id ]['post_title'] : '';
}

function get_post_status( $post_id ) {
	return isset( $GLOBALS['af_store']['posts'][ $post_id ] ) ? $GLOBALS['af_store']['posts'][ $post_id ]['post_status'] : false;
}

/**
 * Supports only the shapes this plugin actually asks for: licence posts by
 * status, returning IDs.
 */
function get_posts( $args = array() ) {
	$type   = isset( $args['post_type'] ) ? $args['post_type'] : 'post';
	$status = isset( $args['post_status'] ) ? (array) $args['post_status'] : array( 'publish' );
	$out    = array();
	foreach ( $GLOBALS['af_store']['posts'] as $post ) {
		if ( $post['post_type'] !== $type ) {
			continue;
		}
		if ( ! in_array( $post['post_status'], $status, true ) ) {
			continue;
		}
		$out[] = $post['ID'];
	}
	sort( $out );
	return $out;
}

function get_users( $args = array() ) {
	$out = array();
	foreach ( $GLOBALS['af_store']['users'] as $user ) {
		$out[] = (object) $user;
	}
	usort( $out, function ( $a, $b ) { return $a->ID <=> $b->ID; } );
	return $out;
}

function get_userdata( $user_id ) {
	return isset( $GLOBALS['af_store']['users'][ $user_id ] ) ? (object) $GLOBALS['af_store']['users'][ $user_id ] : false;
}

// -- Hooks --------------------------------------------------------------------

function add_filter( $tag, $fn, $priority = 10, $args = 1 ) {
	$GLOBALS['af_store']['filters'][ $tag ][] = $fn;
	return true;
}

function apply_filters( $tag, $value ) {
	$extra = array_slice( func_get_args(), 2 );
	if ( empty( $GLOBALS['af_store']['filters'][ $tag ] ) ) {
		return $value;
	}
	foreach ( $GLOBALS['af_store']['filters'][ $tag ] as $fn ) {
		$value = call_user_func_array( $fn, array_merge( array( $value ), $extra ) );
	}
	return $value;
}

function add_action( $tag, $fn, $priority = 10, $args = 1 ) {
	$GLOBALS['af_store']['actions'][ $tag ][] = $fn;
	return true;
}

function do_action( $tag ) {
	$extra = array_slice( func_get_args(), 1 );
	if ( empty( $GLOBALS['af_store']['actions'][ $tag ] ) ) {
		return;
	}
	foreach ( $GLOBALS['af_store']['actions'][ $tag ] as $fn ) {
		call_user_func_array( $fn, $extra );
	}
}

function has_action( $tag ) {
	return ! empty( $GLOBALS['af_store']['actions'][ $tag ] );
}

// -- Misc ---------------------------------------------------------------------

function current_time( $type = 'timestamp' ) {
	if ( 'timestamp' === $type || 'U' === $type ) {
		return $GLOBALS['af_store']['now'];
	}
	return gmdate( $type, $GLOBALS['af_store']['now'] );
}

function sanitize_text_field( $value ) {
	return trim( wp_strip_all_tags( (string) $value ) );
}

function wp_strip_all_tags( $value ) {
	return strip_tags( (string) $value );
}

function absint( $value ) {
	return abs( (int) $value );
}

function esc_html( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}

function __( $text, $domain = '' ) {
	return $text;
}

function esc_html__( $text, $domain = '' ) {
	return esc_html( $text );
}

function _n( $single, $plural, $number, $domain = '' ) {
	return 1 === (int) $number ? $single : $plural;
}

function _x( $text, $context = '', $domain = '' ) {
	return $text;
}

function esc_url( $url ) {
	return (string) $url;
}

function wp_unslash( $value ) {
	return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
}

function wp_kses_post( $value ) {
	return (string) $value;
}

function wp_date( $format, $timestamp = null ) {
	return gmdate( $format, null === $timestamp ? $GLOBALS['af_store']['now'] : $timestamp );
}

function human_time_diff( $from, $to = 0 ) {
	return abs( (int) $to - (int) $from ) . ' seconds';
}

function get_post_type( $post_id ) {
	return isset( $GLOBALS['af_store']['posts'][ $post_id ] ) ? $GLOBALS['af_store']['posts'][ $post_id ]['post_type'] : false;
}

function get_edit_user_link( $user_id ) {
	return 'user-edit.php?user_id=' . (int) $user_id;
}

function get_edit_post_link( $post_id ) {
	return 'post.php?post=' . (int) $post_id . '&action=edit';
}

function admin_url( $path = '' ) {
	return '/wp-admin/' . ltrim( (string) $path, '/' );
}

function rest_ensure_response( $data ) {
	return $data;
}

function wp_list_pluck( $list, $field ) {
	$out = array();
	foreach ( $list as $item ) {
		$out[] = is_object( $item ) ? $item->$field : $item[ $field ];
	}
	return $out;
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

class WP_Error {
	public $code;
	public $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_message() {
		return $this->message;
	}
	public function get_error_code() {
		return $this->code;
	}
}

// Admin-only functions the includes call at load time but tests never exercise.
function is_admin() { return false; }
function add_meta_box() {}
function add_menu_page() {}
function add_options_page() {}
function add_settings_section() {}
function add_settings_field() {}
function register_setting() {}
function register_post_type() {}
function register_meta() {}
function add_shortcode() {}
function wp_register_script() {}
function wp_register_style() {}
function wp_nonce_field() {}
function wp_verify_nonce() { return true; }
function wp_create_nonce() { return 'nonce'; }
function selected( $a, $b, $echo = true ) { return $a === $b ? ' selected' : ''; }
function checked( $a, $b, $echo = true ) { return $a === $b ? ' checked' : ''; }
function plugins_url( $path = '', $file = '' ) { return '/wp-content/plugins/' . ltrim( (string) $path, '/' ); }
function rest_url( $path = '' ) { return '/wp-json/' . ltrim( (string) $path, '/' ); }
function add_query_arg( $key, $value, $url = '' ) { return $url . '?' . $key . '=' . $value; }
function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function plugin_basename( $file ) { return basename( dirname( $file ) ) . '/' . basename( $file ); }
function current_user_can() { return true; }
function wp_get_current_user() { return (object) array( 'ID' => 0, 'display_name' => 'system' ); }
function get_current_user_id() { return 0; }
function is_user_logged_in() { return false; }
```

`MB_IN_BYTES` is used by the ACF audit in Task 11; add it alongside the other size constants at the top of this file:

```php
define( 'MB_IN_BYTES', 1048576 );
```

- [ ] **Step 3: Write the runner**

Create `tests/php/run.php`:

```php
<?php
/**
 * Runs every tests/php/test-*.php against the in-memory stubs.
 * Exit code 1 on any failure so CI and npm treat it as a failing build.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/assert.php';

foreach ( glob( __DIR__ . '/test-*.php' ) as $file ) {
	require_once $file;
}

exit( af_run_tests() );
```

- [ ] **Step 4: Write a self-test proving the harness works**

Create `tests/php/test-harness.php`:

```php
<?php
/**
 * Proves the harness itself reports truthfully — a test suite that cannot fail
 * is worse than none, because it reads as coverage.
 */

af_test( 'post meta round-trips through the stub', function () {
	update_post_meta( 5, 'app_password', 'hunter2' );
	af_assert_same( 'hunter2', get_post_meta( 5, 'app_password', true ), 'meta reads back' );
	delete_post_meta( 5, 'app_password' );
	af_assert_same( '', get_post_meta( 5, 'app_password', true ), 'deleted meta reads empty' );
} );

af_test( 'the store resets between tests', function () {
	af_assert_same( '', get_post_meta( 5, 'app_password', true ), 'previous test did not leak' );
} );

af_test( 'transients expire against the frozen clock', function () {
	set_transient( 'lock', '1', 30 );
	af_assert_same( '1', get_transient( 'lock' ), 'live transient reads back' );
	af_set_now( current_time( 'timestamp' ) + 31 );
	af_assert_same( false, get_transient( 'lock' ), 'expired transient reads false' );
} );
```

- [ ] **Step 5: Run the suite and confirm it passes**

Run: `php tests/php/run.php`
Expected: `PHP tests: 3 tests, 4 assertions, all passing`, exit code 0.

- [ ] **Step 6: Prove the harness reports failure**

Temporarily add `af_assert_same( 1, 2, 'deliberate failure' );` to the first test in `tests/php/test-harness.php`.

Run: `php tests/php/run.php; echo "exit=$?"`
Expected: output contains `1 FAILED`, `✗ post meta round-trips through the stub — deliberate failure`, and `exit=1`.

Remove the deliberate failure and re-run to confirm it passes again.

- [ ] **Step 7: Write the Node wrapper**

Create `scripts/run-php-tests.mjs`. It skips rather than fails when PHP is absent, so the shared CI workflow cannot break on a missing binary:

```javascript
// Runs the PHP unit suite (tests/php) from npm.
//
// Skips with a clear message when php is not on PATH: the shared CI workflow
// lives in bluegroup_core_foundation and this repo cannot guarantee a PHP
// binary there. A missing interpreter must not read as a passing suite, so the
// skip is loud, and locally — where PHP is present — the tests always run.

import { spawnSync } from 'node:child_process';
import process from 'node:process';

const probe = spawnSync('php', ['-v'], { stdio: 'ignore' });

if (probe.error) {
  console.log('PHP tests: SKIPPED — no `php` on PATH. Run them locally with `php tests/php/run.php`.');
  process.exit(0);
}

const run = spawnSync('php', ['tests/php/run.php'], { stdio: 'inherit' });
process.exit(run.status === null ? 1 : run.status);
```

- [ ] **Step 8: Wire it into npm, and add PHP syntax checking to lint**

The lint script currently checks only JavaScript, which is a gap in a plugin that is mostly PHP. Modify `package.json` — replace the `lint` and `test` lines and add `test:php`:

```json
    "lint": "node --check assets/portal.js && node --check scripts/preview-server.mjs && node --check scripts/build-plugin.mjs && node --check scripts/sync-watchlist.mjs && node --check scripts/bake-picks.mjs && node --check scripts/picks-list.mjs && node --check scripts/sync-listings.mjs && node scripts/lint-php.mjs",
    "test": "node scripts/run-php-tests.mjs && playwright test",
    "test:php": "node scripts/run-php-tests.mjs",
```

Create `scripts/lint-php.mjs`:

```javascript
// php -l over every PHP file in the plugin. Same skip-when-absent rule as the
// PHP test runner: a missing interpreter is reported, never silently passed.

import { spawnSync } from 'node:child_process';
import { readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';
import process from 'node:process';

const probe = spawnSync('php', ['-v'], { stdio: 'ignore' });
if (probe.error) {
  console.log('PHP lint: SKIPPED — no `php` on PATH.');
  process.exit(0);
}

const SKIP = new Set(['node_modules', 'dist', '.git', '.cache', 'playwright-report', 'test-results']);

function phpFiles(dir) {
  const out = [];
  for (const entry of readdirSync(dir)) {
    if (SKIP.has(entry)) continue;
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) out.push(...phpFiles(full));
    else if (entry.endsWith('.php')) out.push(full);
  }
  return out;
}

let failed = 0;
for (const file of phpFiles(process.cwd())) {
  const res = spawnSync('php', ['-l', file], { encoding: 'utf8' });
  if (res.status !== 0) {
    failed++;
    console.error(res.stdout.trim() || res.stderr.trim());
  }
}

console.log(failed ? `PHP lint: ${failed} file(s) with syntax errors` : 'PHP lint: clean');
process.exit(failed ? 1 : 0);
```

- [ ] **Step 9: Verify both npm entry points**

Run: `npm run test:php`
Expected: `PHP tests: 3 tests, 4 assertions, all passing`

Run: `npm run lint`
Expected: the existing JS checks produce no output, then `PHP lint: clean`, exit code 0.

- [ ] **Step 10: Commit**

```bash
git add tests/php scripts/run-php-tests.mjs scripts/lint-php.mjs package.json
git commit -m "Add a PHP test harness so the licence logic can be tested at all

The Playwright suite runs against scripts/preview-server.mjs, a Node static
server with no WordPress in it, so it structurally cannot reach the licence
assignment logic this work is about to rewrite. Rather than leave the risky
arithmetic untested, this adds an in-memory stub of the handful of WordPress
functions that logic calls, plus a fifty-line assertion harness.

Hand-written rather than PHPUnit because the plugin carries no composer
dependencies and adding one needs approval in approved-deps.json, which is a
lot of process for something this small.

Both the runner and the new php -l lint step skip loudly when php is not on
PATH, so the shared CI workflow cannot break on a missing binary while local
runs still get real coverage.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: The licence post type and read accessors

**Files:**
- Create: `includes/fields.php`
- Create: `tests/php/test-fields-read.php`
- Modify: `bluegroup-project-afristream.php:25` (require `fields.php` above `licenses.php`)

**Interfaces:**
- Consumes: the harness from Task 1 (`af_test`, `af_assert_same`, `af_seed_post`, `af_seed_user`, `af_set_now`).
- Produces:
  - `AFRISTREAM_LICENSE_OWNER_META` = `'afristream_assigned_user'`
  - `AFRISTREAM_USER_LICENSE_META` = `'active_license'`
  - `afristream_license_fields(): array` — field name ⇒ `['label'=>string,'type'=>string,'choices'=>string[]]`
  - `afristream_license_meta( int $post_id, string $key ): string` — ACF-compatible read; `expiry_date` converts `Ymd` → `d/m/Y`
  - `afristream_license_expiry_ymd( int $license_id ): string` — raw sortable form, `''` when unset or unparseable
  - `afristream_license_owner( int $license_id ): int` — 0 when free
  - `afristream_all_license_ids(): int[]`
  - `afristream_user_license_ids( int $user_id ): int[]` — ascending
  - `afristream_license_is_available( int $license_id ): bool`
  - `afristream_available_licenses( int $limit = 0 ): int[]` — soonest-expiring first

- [ ] **Step 1: Write the failing tests**

Create `tests/php/test-fields-read.php`:

```php
<?php
require_once __DIR__ . '/../../includes/fields.php';

af_test( 'expiry_date reads back in ACF display format', function () {
	af_seed_post( 10, 'alpha' );
	update_post_meta( 10, 'expiry_date', '20261231' );
	af_assert_same( '31/12/2026', afristream_license_meta( 10, 'expiry_date' ), 'Ymd becomes d/m/Y' );
	af_assert_same( '20261231', afristream_license_expiry_ymd( 10 ), 'raw form is unchanged' );
} );

af_test( 'a blank or malformed expiry_date does not become a fake date', function () {
	af_seed_post( 10, 'alpha' );
	af_assert_same( '', afristream_license_meta( 10, 'expiry_date' ), 'unset reads empty' );
	update_post_meta( 10, 'expiry_date', 'not-a-date' );
	af_assert_same( 'not-a-date', afristream_license_meta( 10, 'expiry_date' ), 'unparseable passes through, not invented' );
	af_assert_same( '', afristream_license_expiry_ymd( 10 ), 'but the sortable form refuses it' );
} );

af_test( 'plain fields read back verbatim', function () {
	af_seed_post( 10, 'alpha' );
	update_post_meta( 10, 'app_password', 'hunter2' );
	update_post_meta( 10, 'license_provider', 'Shockwave' );
	update_post_meta( 10, 'mobile_active', 'Yes' );
	af_assert_same( 'hunter2', afristream_license_meta( 10, 'app_password' ), 'password' );
	af_assert_same( 'Shockwave', afristream_license_meta( 10, 'license_provider' ), 'provider' );
	af_assert_same( 'Yes', afristream_license_meta( 10, 'mobile_active' ), 'mobile' );
} );

af_test( 'ownership is a single scalar on the licence', function () {
	af_seed_post( 10, 'alpha' );
	af_assert_same( 0, afristream_license_owner( 10 ), 'unowned reads 0' );
	update_post_meta( 10, AFRISTREAM_LICENSE_OWNER_META, 7 );
	af_assert_same( 7, afristream_license_owner( 10 ), 'owner reads back as int' );
} );

af_test( 'a user holding two licences gets both, ascending', function () {
	af_seed_user( 7 );
	af_seed_post( 30, 'gamma' );
	af_seed_post( 10, 'alpha' );
	update_post_meta( 30, AFRISTREAM_LICENSE_OWNER_META, 7 );
	update_post_meta( 10, AFRISTREAM_LICENSE_OWNER_META, 7 );
	af_assert_same( array( 10, 30 ), afristream_user_license_ids( 7 ), 'both licences, sorted' );
	af_assert_same( array(), afristream_user_license_ids( 8 ), 'a user with none gets an empty array' );
} );

af_test( 'availability requires unowned, published and unexpired', function () {
	// The harness clock is frozen at 2026-07-26.
	af_seed_post( 10, 'free-future' );
	update_post_meta( 10, 'expiry_date', '20271231' );

	af_seed_post( 11, 'free-expired' );
	update_post_meta( 11, 'expiry_date', '20250101' );

	af_seed_post( 12, 'free-no-expiry' );

	af_seed_post( 13, 'taken' );
	update_post_meta( 13, 'expiry_date', '20271231' );
	update_post_meta( 13, AFRISTREAM_LICENSE_OWNER_META, 7 );

	af_seed_post( 14, 'draft', 'draft' );

	af_seed_post( 15, 'expires-today' );
	update_post_meta( 15, 'expiry_date', '20260726' );

	af_assert( afristream_license_is_available( 10 ), 'future expiry is available' );
	af_assert( ! afristream_license_is_available( 11 ), 'past expiry is not' );
	af_assert( afristream_license_is_available( 12 ), 'no expiry is available' );
	af_assert( ! afristream_license_is_available( 13 ), 'owned is not' );
	af_assert( ! afristream_license_is_available( 14 ), 'draft is not' );
	af_assert( afristream_license_is_available( 15 ), 'expiring today is still available today' );
} );

af_test( 'available licences come soonest-expiring first, never-expiring last', function () {
	af_seed_post( 10, 'far' );
	update_post_meta( 10, 'expiry_date', '20281231' );
	af_seed_post( 11, 'soon' );
	update_post_meta( 11, 'expiry_date', '20260901' );
	af_seed_post( 12, 'never' );
	af_seed_post( 13, 'also-never' );

	af_assert_same( array( 11, 10, 12, 13 ), afristream_available_licenses(), 'dated stock is spent before undated' );
	af_assert_same( array( 11, 10 ), afristream_available_licenses( 2 ), 'the limit takes from the front' );
} );
```

- [ ] **Step 2: Run the tests and watch them fail**

Run: `php tests/php/run.php`
Expected: FAIL — `PHP Fatal error: Failed opening required '.../includes/fields.php'`

- [ ] **Step 3: Write the implementation**

Create `includes/fields.php`:

```php
<?php
/**
 * The licence data model, owned by this plugin rather than by ACF.
 *
 * ACF held three things: the `license` post type, four fields on it, and a
 * relationship field on the user pointing at licences. All three now live here,
 * reading and writing the exact meta keys and value formats ACF used, so no
 * data moves and nothing that already reads this data has to change.
 *
 * One thing does change, deliberately. ACF recorded an assignment only as an
 * entry in a serialized array in usermeta. That is a read-modify-write, so two
 * overlapping assignments silently lose one of them, and nothing structurally
 * stops the same licence appearing in two users' arrays. Now the licence itself
 * carries its owner in a single meta row — one licence, one owner, by
 * construction — and the usermeta array is rebuilt from it as a mirror for the
 * benefit of everything that still reads the old shape.
 *
 * @package bluegroup-project-afristream
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Authoritative owner of a licence: one user ID, stored on the licence post. */
define( 'AFRISTREAM_LICENSE_OWNER_META', 'afristream_assigned_user' );

/** The derived mirror ACF wrote, kept so existing readers keep working. */
define( 'AFRISTREAM_USER_LICENSE_META', 'active_license' );

/**
 * The four licence fields, in the order they appeared in ACF.
 *
 * @return array<string,array{label:string,type:string,choices?:string[]}>
 */
function afristream_license_fields() {
	return array(
		'app_password'     => array(
			'label' => 'App Password',
			'type'  => 'text',
		),
		'expiry_date'      => array(
			'label' => 'Expiry Date',
			'type'  => 'date',
		),
		'license_provider' => array(
			'label'   => 'License Provider',
			'type'    => 'select',
			'choices' => array( 'Shockwave', 'AfriStream' ),
		),
		'mobile_active'    => array(
			'label'   => 'Mobile Active',
			'type'    => 'radio',
			'choices' => array( 'Yes', 'No' ),
		),
	);
}

/**
 * Read a licence field exactly as ACF's get_field() returned it.
 *
 * The only field that is not a straight passthrough is expiry_date: ACF stores
 * a date picker as Ymd but was configured to return d/m/Y, and both the admin
 * column and the expiry parser downstream expect d/m/Y. An unparseable value is
 * handed back untouched rather than coerced — a wrong date shown as itself can
 * be found and fixed, one silently rewritten cannot.
 *
 * @param int    $post_id Licence post ID.
 * @param string $key     Field name.
 * @return string
 */
function afristream_license_meta( $post_id, $key ) {
	$raw = (string) get_post_meta( (int) $post_id, $key, true );

	if ( 'expiry_date' !== $key || '' === $raw ) {
		return $raw;
	}

	$date = DateTime::createFromFormat( 'Ymd', $raw );
	if ( ! $date || $date->format( 'Ymd' ) !== $raw ) {
		return $raw;
	}

	return $date->format( 'd/m/Y' );
}

/**
 * The stored, sortable form of the expiry date. Ymd strings compare correctly
 * as strings, which is what availability and ordering rely on.
 *
 * @param int $license_id Licence post ID.
 * @return string Ymd, or '' when unset or unparseable.
 */
function afristream_license_expiry_ymd( $license_id ) {
	$raw = (string) get_post_meta( (int) $license_id, 'expiry_date', true );
	if ( '' === $raw ) {
		return '';
	}
	$date = DateTime::createFromFormat( 'Ymd', $raw );
	return ( $date && $date->format( 'Ymd' ) === $raw ) ? $raw : '';
}

/**
 * The user holding this licence, or 0 if it is free.
 *
 * @param int $license_id Licence post ID.
 * @return int
 */
function afristream_license_owner( $license_id ) {
	return (int) get_post_meta( (int) $license_id, AFRISTREAM_LICENSE_OWNER_META, true );
}

/**
 * Every published licence ID. Small by nature — a licence is a thing someone
 * bought, and there are tens of them, not thousands.
 *
 * @return int[]
 */
function afristream_all_license_ids() {
	$ids = get_posts(
		array(
			'post_type'      => 'license',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		)
	);

	return array_map( 'intval', (array) $ids );
}

/**
 * Every licence a user holds, read from the authoritative side.
 *
 * Not from the usermeta mirror: the mirror is a convenience for other readers,
 * and if the two ever disagree the licence's own record is the one to trust.
 *
 * @param int $user_id User ID.
 * @return int[] Ascending licence IDs.
 */
function afristream_user_license_ids( $user_id ) {
	$user_id = (int) $user_id;
	if ( ! $user_id ) {
		return array();
	}

	$held = array();
	foreach ( afristream_all_license_ids() as $license_id ) {
		if ( afristream_license_owner( $license_id ) === $user_id ) {
			$held[] = $license_id;
		}
	}

	sort( $held );
	return $held;
}

/**
 * Whether a licence can be handed to someone: published, unowned, and not past
 * its expiry date. A licence expiring today is still usable today.
 *
 * @param int $license_id Licence post ID.
 * @return bool
 */
function afristream_license_is_available( $license_id ) {
	$license_id = (int) $license_id;

	if ( 'publish' !== get_post_status( $license_id ) ) {
		return false;
	}
	if ( afristream_license_owner( $license_id ) ) {
		return false;
	}

	$expiry = afristream_license_expiry_ymd( $license_id );
	if ( '' === $expiry ) {
		return true;
	}

	return $expiry >= current_time( 'Ymd' );
}

/**
 * Available licences, soonest-expiring first.
 *
 * Dated stock is spent before undated stock: a licence with an expiry date is
 * wasting away whether or not anyone is using it, whereas one without an expiry
 * keeps indefinitely. Undated licences therefore sort last, and ties break on ID
 * so the order is stable between calls.
 *
 * @param int $limit Most to return; 0 for all.
 * @return int[]
 */
function afristream_available_licenses( $limit = 0 ) {
	$available = array();
	foreach ( afristream_all_license_ids() as $license_id ) {
		if ( afristream_license_is_available( $license_id ) ) {
			$available[] = $license_id;
		}
	}

	usort(
		$available,
		function ( $a, $b ) {
			$ea = afristream_license_expiry_ymd( $a );
			$eb = afristream_license_expiry_ymd( $b );

			if ( ( '' === $ea ) !== ( '' === $eb ) ) {
				return '' === $ea ? 1 : -1;
			}
			if ( $ea !== $eb ) {
				return strcmp( $ea, $eb );
			}
			return $a <=> $b;
		}
	);

	$limit = (int) $limit;
	return $limit > 0 ? array_slice( $available, 0, $limit ) : $available;
}

/**
 * Register the licence post type.
 *
 * Arguments copied verbatim from ACF's own export so the admin URLs, menu
 * position, icon and REST visibility are exactly what they were.
 */
function afristream_register_license_post_type() {
	register_post_type(
		'license',
		array(
			'labels'           => array(
				'name'          => __( 'Licenses', 'bluegroup-project-afristream' ),
				'singular_name' => __( 'License', 'bluegroup-project-afristream' ),
				'menu_name'     => __( 'Licenses', 'bluegroup-project-afristream' ),
				'all_items'     => __( 'All Licenses', 'bluegroup-project-afristream' ),
				'edit_item'     => __( 'Edit License', 'bluegroup-project-afristream' ),
				'view_item'     => __( 'View License', 'bluegroup-project-afristream' ),
				'add_new_item'  => __( 'Add New License', 'bluegroup-project-afristream' ),
				'add_new'       => __( 'Add New License', 'bluegroup-project-afristream' ),
				'new_item'      => __( 'New License', 'bluegroup-project-afristream' ),
				'search_items'  => __( 'Search Licenses', 'bluegroup-project-afristream' ),
				'not_found'     => __( 'No licenses found', 'bluegroup-project-afristream' ),
			),
			'public'           => true,
			'show_in_rest'     => true,
			'menu_icon'        => 'dashicons-tickets-alt',
			'supports'         => array( 'title', 'custom-fields' ),
			'delete_with_user' => false,
		)
	);

	foreach ( array_keys( afristream_license_fields() ) as $key ) {
		register_meta(
			'post',
			$key,
			array(
				'object_subtype' => 'license',
				'type'           => 'string',
				'single'         => true,
				'show_in_rest'   => true,
				'auth_callback'  => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}
}
add_action( 'init', 'afristream_register_license_post_type', 5 );
```

- [ ] **Step 4: Run the tests and confirm they pass**

Run: `php tests/php/run.php`
Expected: `PHP tests: 10 tests, 27 assertions, all passing`

- [ ] **Step 5: Load the new file from the plugin, ahead of the old one**

`fields.php` must load before `licenses.php`, which is rewritten onto its accessors in Task 7. Modify `bluegroup-project-afristream.php` — insert one line above the existing `licenses.php` require so the block reads:

```php
require_once plugin_dir_path( __FILE__ ) . 'includes/fields.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/licenses.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/shortcodes.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/affiliates.php';
```

ACF is still active at this point and still registering the same post type. That is expected and harmless: `register_post_type` with identical arguments is idempotent. ACF's own copies are deleted in Task 14.

- [ ] **Step 6: Confirm the plugin still parses**

Run: `npm run lint`
Expected: `PHP lint: clean`

- [ ] **Step 7: Commit**

```bash
git add includes/fields.php tests/php/test-fields-read.php bluegroup-project-afristream.php
git commit -m "Take the licence post type and field reads off ACF

Registers the license post type with the arguments copied verbatim from ACF's
own export, so admin URLs, menu position, icon and REST visibility are
unchanged, and adds the accessors that will replace every get_field() call.

The one piece of translation is expiry_date. ACF stores a date picker as Ymd
but was configured to return d/m/Y, and both the admin column and the expiry
parser downstream expect d/m/Y, so afristream_license_meta() does that
conversion while afristream_license_expiry_ymd() exposes the raw sortable form
for availability checks. An unparseable date is passed through as itself rather
than coerced, because a wrong date shown plainly can be found and fixed whereas
one silently rewritten cannot.

Availability lands here too: published, unowned, and not past expiry, with
dated stock spent before undated. A licence with an expiry is wasting away
whether or not anyone holds it; one without keeps indefinitely.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Assignment primitives, the mirror, and the lock

This is where multi-licence safety is actually enforced. Two functions are the only things in the codebase permitted to change who holds a licence.

**Files:**
- Modify: `includes/fields.php` (append)
- Create: `tests/php/test-fields-write.php`

**Interfaces:**
- Consumes: everything from Task 2.
- Produces:
  - `afristream_with_lock( callable $fn ): mixed` — serialises claims; returns the callback's value, or `WP_Error('afristream_locked')` if the lock could not be taken
  - `afristream_rebuild_user_mirror( int $user_id ): void`
  - `afristream_assign_license( int $license_id, int $user_id, string $context = '' ): true|WP_Error`
  - `afristream_unassign_license( int $license_id, string $context = '' ): bool`
  - `afristream_user_mirror_ids( int $user_id ): int[]` — reads the mirror, for consistency checks only
  - `AFRISTREAM_LOCK_KEY`, `AFRISTREAM_LOCK_TTL`

Note for later tasks: `afristream_assign_license()` and `afristream_unassign_license()` call `afristream_license_log_add()`, which does not exist until Task 4. Guard those calls with `function_exists()` so this task's tests pass standalone; Task 4 removes the guard.

- [ ] **Step 1: Write the failing tests**

Create `tests/php/test-fields-write.php`:

```php
<?php
require_once __DIR__ . '/../../includes/fields.php';

af_test( 'assigning writes the licence and rebuilds the mirror as strings', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );

	af_assert_same( true, afristream_assign_license( 10, 7, 'test' ), 'assign succeeds' );
	af_assert_same( 7, afristream_license_owner( 10 ), 'owner is on the licence' );

	// ACF stored the relationship as an array of *strings*. The Connected User
	// column matches on LIKE '"10"', which only hits a serialized string.
	af_assert_same( array( '10' ), get_user_meta( 7, AFRISTREAM_USER_LICENSE_META, true ), 'mirror holds string ids' );
} );

af_test( 'a second licence for the same user does not disturb the first', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	af_seed_post( 11, 'beta' );

	afristream_assign_license( 10, 7, 'test' );
	afristream_assign_license( 11, 7, 'test' );

	af_assert_same( array( 10, 11 ), afristream_user_license_ids( 7 ), 'both held' );
	af_assert_same( array( '10', '11' ), get_user_meta( 7, AFRISTREAM_USER_LICENSE_META, true ), 'mirror has both' );
} );

af_test( 'a licence already held by someone else is refused', function () {
	af_seed_user( 7 );
	af_seed_user( 8 );
	af_seed_post( 10, 'alpha' );

	afristream_assign_license( 10, 7, 'test' );
	$result = afristream_assign_license( 10, 8, 'test' );

	af_assert( is_wp_error( $result ), 'second assignment is a WP_Error' );
	af_assert_same( 'afristream_license_taken', $result->get_error_code(), 'with a specific code' );
	af_assert_same( 7, afristream_license_owner( 10 ), 'the first holder keeps it' );
	af_assert_same( array(), afristream_user_license_ids( 8 ), 'the second user gets nothing' );
} );

af_test( 'reassigning to the same user is a no-op, not an error', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );

	afristream_assign_license( 10, 7, 'test' );
	af_assert_same( true, afristream_assign_license( 10, 7, 'test' ), 'idempotent' );
	af_assert_same( array( 10 ), afristream_user_license_ids( 7 ), 'still exactly one' );
} );

af_test( 'an expired or draft licence cannot be assigned', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'expired' );
	update_post_meta( 10, 'expiry_date', '20250101' );
	af_seed_post( 11, 'draft', 'draft' );

	af_assert( is_wp_error( afristream_assign_license( 10, 7, 'test' ) ), 'expired refused' );
	af_assert( is_wp_error( afristream_assign_license( 11, 7, 'test' ) ), 'draft refused' );
} );

af_test( 'unassigning frees the licence and updates the mirror', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	af_seed_post( 11, 'beta' );
	afristream_assign_license( 10, 7, 'test' );
	afristream_assign_license( 11, 7, 'test' );

	af_assert_same( true, afristream_unassign_license( 10, 'test' ), 'unassign succeeds' );
	af_assert_same( 0, afristream_license_owner( 10 ), 'licence is free' );
	af_assert( afristream_license_is_available( 10 ), 'and available again' );
	af_assert_same( array( 11 ), afristream_user_license_ids( 7 ), 'the other licence is untouched' );
	af_assert_same( array( '11' ), get_user_meta( 7, AFRISTREAM_USER_LICENSE_META, true ), 'mirror follows' );
} );

af_test( 'unassigning a free licence is harmless', function () {
	af_seed_post( 10, 'alpha' );
	af_assert_same( false, afristream_unassign_license( 10, 'test' ), 'reports nothing to do' );
} );

af_test( 'a user with no licences has an empty mirror, not a stale one', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	afristream_assign_license( 10, 7, 'test' );
	afristream_unassign_license( 10, 'test' );

	af_assert_same( array(), get_user_meta( 7, AFRISTREAM_USER_LICENSE_META, true ), 'mirror emptied' );
} );

af_test( 'the lock serialises and reports refusal rather than proceeding', function () {
	$ran = 0;
	$out = afristream_with_lock( function () use ( &$ran ) {
		$ran++;
		// Re-entering while held must be refused, not deadlock or double-run.
		$inner = afristream_with_lock( function () use ( &$ran ) {
			$ran++;
			return 'inner';
		} );
		af_assert( is_wp_error( $inner ), 'a nested claim is refused' );
		return 'outer';
	} );

	af_assert_same( 'outer', $out, 'the outer body ran and returned' );
	af_assert_same( 1, $ran, 'the inner body never ran' );

	// The lock is released afterwards, so the next claim succeeds.
	af_assert_same( 'after', afristream_with_lock( function () { return 'after'; } ), 'lock released' );
} );

af_test( 'the mirror can be rebuilt from the licences alone', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	update_post_meta( 10, AFRISTREAM_LICENSE_OWNER_META, 7 );
	update_user_meta( 7, AFRISTREAM_USER_LICENSE_META, array( '99' ) ); // stale nonsense

	afristream_rebuild_user_mirror( 7 );
	af_assert_same( array( '10' ), get_user_meta( 7, AFRISTREAM_USER_LICENSE_META, true ), 'rebuilt from truth' );
} );
```

- [ ] **Step 2: Run the tests and watch them fail**

Run: `php tests/php/run.php`
Expected: FAIL — `Call to undefined function afristream_assign_license()`

- [ ] **Step 3: Append the implementation to `includes/fields.php`**

```php
/** Transient key guarding a licence claim. */
define( 'AFRISTREAM_LOCK_KEY', 'afristream_assign_lock' );

/** How long a claim may hold the lock before it is assumed abandoned. */
define( 'AFRISTREAM_LOCK_TTL', 10 );

/**
 * Run a callback with the assignment lock held.
 *
 * Claims are short — read an owner, write an owner — so a single global lock
 * costs nothing and removes a whole class of interleaving. A caller that cannot
 * take the lock is told so rather than proceeding without it, because
 * proceeding is exactly how two checkouts end up claiming the same licence.
 *
 * The TTL means a request that dies mid-claim releases the lock on its own
 * within ten seconds instead of wedging assignment until someone notices.
 *
 * @param callable $fn Body to run while holding the lock.
 * @return mixed The callback's return value, or WP_Error when the lock is held.
 */
function afristream_with_lock( $fn ) {
	if ( get_transient( AFRISTREAM_LOCK_KEY ) ) {
		return new WP_Error(
			'afristream_locked',
			__( 'Another licence assignment is in progress. Please try again.', 'bluegroup-project-afristream' )
		);
	}

	set_transient( AFRISTREAM_LOCK_KEY, 1, AFRISTREAM_LOCK_TTL );

	try {
		return call_user_func( $fn );
	} finally {
		delete_transient( AFRISTREAM_LOCK_KEY );
	}
}

/**
 * Rewrite a user's usermeta mirror from the licences that point at them.
 *
 * The values are written as strings, not integers, because that is what ACF
 * wrote and what the Connected User column's LIKE '"10"' query matches: a
 * serialized integer is i:10; and would never be found.
 *
 * @param int $user_id User ID.
 * @return void
 */
function afristream_rebuild_user_mirror( $user_id ) {
	$user_id = (int) $user_id;
	if ( ! $user_id ) {
		return;
	}

	$ids = array_map( 'strval', afristream_user_license_ids( $user_id ) );
	update_user_meta( $user_id, AFRISTREAM_USER_LICENSE_META, $ids );
}

/**
 * The licence IDs recorded in a user's mirror.
 *
 * Only for checking the mirror against the truth — never for deciding what
 * someone holds. afristream_user_license_ids() is the answer to that.
 *
 * @param int $user_id User ID.
 * @return int[]
 */
function afristream_user_mirror_ids( $user_id ) {
	$raw = get_user_meta( (int) $user_id, AFRISTREAM_USER_LICENSE_META, true );
	if ( empty( $raw ) ) {
		return array();
	}
	if ( ! is_array( $raw ) ) {
		$raw = array( $raw );
	}

	$ids = array();
	foreach ( $raw as $entry ) {
		$id = is_object( $entry ) ? (int) $entry->ID : (int) $entry;
		if ( $id ) {
			$ids[] = $id;
		}
	}

	sort( $ids );
	return $ids;
}

/**
 * Give a licence to a user.
 *
 * The availability check happens inside the lock, immediately before the write,
 * so a licence that was free when the caller looked but taken by the time it
 * acted is refused rather than quietly stolen.
 *
 * @param int    $license_id Licence post ID.
 * @param int    $user_id    User to give it to.
 * @param string $context    Why, for the log: 'auto-assign', 'profile', 'backfill'.
 * @return true|WP_Error
 */
function afristream_assign_license( $license_id, $user_id, $context = '' ) {
	$license_id = (int) $license_id;
	$user_id    = (int) $user_id;

	if ( ! $license_id || ! $user_id ) {
		return new WP_Error( 'afristream_bad_args', __( 'A licence and a user are both required.', 'bluegroup-project-afristream' ) );
	}

	return afristream_with_lock(
		function () use ( $license_id, $user_id, $context ) {
			$owner = afristream_license_owner( $license_id );

			// Already theirs. Saying so is more useful than an error, because it
			// makes a repeated webhook harmless.
			if ( $owner === $user_id ) {
				return true;
			}

			if ( $owner ) {
				return new WP_Error(
					'afristream_license_taken',
					__( 'That licence is already assigned to another user.', 'bluegroup-project-afristream' )
				);
			}

			if ( ! afristream_license_is_available( $license_id ) ) {
				return new WP_Error(
					'afristream_license_unavailable',
					__( 'That licence is expired or not published.', 'bluegroup-project-afristream' )
				);
			}

			update_post_meta( $license_id, AFRISTREAM_LICENSE_OWNER_META, $user_id );
			afristream_rebuild_user_mirror( $user_id );

			if ( function_exists( 'afristream_license_log_add' ) ) {
				afristream_license_log_add( $license_id, 'assigned', $user_id, $context );
			}

			return true;
		}
	);
}

/**
 * Take a licence back.
 *
 * @param int    $license_id Licence post ID.
 * @param string $context    Why, for the log.
 * @return bool True if a holder was removed, false if it was already free.
 */
function afristream_unassign_license( $license_id, $context = '' ) {
	$license_id = (int) $license_id;
	if ( ! $license_id ) {
		return false;
	}

	$result = afristream_with_lock(
		function () use ( $license_id, $context ) {
			$owner = afristream_license_owner( $license_id );
			if ( ! $owner ) {
				return false;
			}

			delete_post_meta( $license_id, AFRISTREAM_LICENSE_OWNER_META );
			afristream_rebuild_user_mirror( $owner );

			if ( function_exists( 'afristream_license_log_add' ) ) {
				afristream_license_log_add( $license_id, 'unassigned', $owner, $context );
			}

			return true;
		}
	);

	return true === $result;
}
```

- [ ] **Step 4: Run the tests and confirm they pass**

Run: `php tests/php/run.php`
Expected: `PHP tests: 20 tests, 52 assertions, all passing`

- [ ] **Step 5: Commit**

```bash
git add includes/fields.php tests/php/test-fields-write.php
git commit -m "Make double-assignment impossible rather than merely validated

Two functions are now the only things allowed to change who holds a licence,
and both check availability inside a lock immediately before writing. A licence
that was free when the caller looked but taken by the time it acted is refused
instead of quietly stolen.

The lock refuses rather than waits. Proceeding without it is exactly how two
simultaneous checkouts end up claiming the same licence, and a ten second TTL
means a request that dies mid-claim releases it on its own rather than wedging
assignment until someone notices.

Assigning a licence a user already holds returns true rather than an error, so
a replayed webhook is harmless.

The usermeta mirror is rewritten wholesale from the licences pointing at the
user, never edited in place, which is what stops a second licence disturbing
the first. Its values are written as strings because that is what ACF wrote and
what the Connected User column's LIKE query matches — a serialized integer is
i:10; and would never be found.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: The per-licence event log

**Files:**
- Create: `includes/license-log.php`
- Create: `tests/php/test-license-log.php`
- Modify: `includes/fields.php` (drop the two `function_exists( 'afristream_license_log_add' )` guards)
- Modify: `bluegroup-project-afristream.php` (require `license-log.php` directly after `fields.php`)

**Interfaces:**
- Consumes: `AFRISTREAM_LICENSE_OWNER_META`, `afristream_all_license_ids()`.
- Produces:
  - `AFRISTREAM_LICENSE_LOG_META` = `'afristream_license_log'`, `AFRISTREAM_LOG_CAP` = `100`
  - `afristream_current_actor(): string` — the logged-in admin's display name, or `'system'`
  - `afristream_license_log_add( int $license_id, string $event, int $user_id = 0, string $context = '', string $detail = '' ): void`
  - `afristream_license_log_get( int $license_id ): array` — newest first
  - `afristream_license_log_recent( int $limit = 20 ): array` — merged across licences, newest first, each entry gains `'license' => int`
  - `afristream_license_last_assignment( int $license_id ): array|null` — the most recent `assigned` entry

Entry shape, relied on by Tasks 5, 7 and 12:

```php
array(
	'time'    => 1785024000, // unix, UTC
	'event'   => 'assigned', // assigned|unassigned|created|updated|conflict
	'user'    => 7,          // licence holder involved, 0 if none
	'actor'   => 'Luke',     // who did it, or 'system'
	'context' => 'auto-assign',
	'detail'  => 'expiry_date 20261231 → 20271231',
)
```

- [ ] **Step 1: Write the failing tests**

Create `tests/php/test-license-log.php`:

```php
<?php
require_once __DIR__ . '/../../includes/fields.php';
require_once __DIR__ . '/../../includes/license-log.php';

af_test( 'an entry records what happened, to whom, and who did it', function () {
	af_seed_post( 10, 'alpha' );
	afristream_license_log_add( 10, 'assigned', 7, 'auto-assign' );

	$log = afristream_license_log_get( 10 );
	af_assert_same( 1, count( $log ), 'one entry' );
	af_assert_same( 'assigned', $log[0]['event'], 'event' );
	af_assert_same( 7, $log[0]['user'], 'user' );
	af_assert_same( 'auto-assign', $log[0]['context'], 'context' );
	af_assert_same( 'system', $log[0]['actor'], 'actor falls back to system with no logged-in user' );
	af_assert_same( 1785024000, $log[0]['time'], 'stamped from the frozen clock' );
} );

af_test( 'the log reads newest first', function () {
	af_seed_post( 10, 'alpha' );
	afristream_license_log_add( 10, 'created', 0, 'admin' );
	af_set_now( 1785024000 + 60 );
	afristream_license_log_add( 10, 'assigned', 7, 'profile' );

	$log = afristream_license_log_get( 10 );
	af_assert_same( 'assigned', $log[0]['event'], 'most recent first' );
	af_assert_same( 'created', $log[1]['event'], 'oldest last' );
} );

af_test( 'the log is capped and drops the oldest, not the newest', function () {
	af_seed_post( 10, 'alpha' );
	for ( $i = 0; $i < AFRISTREAM_LOG_CAP + 15; $i++ ) {
		afristream_license_log_add( 10, 'updated', 0, 'admin', 'change ' . $i );
	}

	$log = afristream_license_log_get( 10 );
	af_assert_same( AFRISTREAM_LOG_CAP, count( $log ), 'capped' );
	af_assert_same( 'change ' . ( AFRISTREAM_LOG_CAP + 14 ), $log[0]['detail'], 'newest kept' );
	af_assert_same( 'change 15', $log[ AFRISTREAM_LOG_CAP - 1 ]['detail'], 'oldest dropped' );
} );

af_test( 'recent events merge across licences, newest first', function () {
	af_seed_post( 10, 'alpha' );
	af_seed_post( 11, 'beta' );

	afristream_license_log_add( 10, 'assigned', 7, 'profile' );
	af_set_now( 1785024000 + 60 );
	afristream_license_log_add( 11, 'assigned', 8, 'auto-assign' );

	$recent = afristream_license_log_recent( 10 );
	af_assert_same( 2, count( $recent ), 'both licences appear' );
	af_assert_same( 11, $recent[0]['license'], 'newest first, tagged with its licence' );
	af_assert_same( 10, $recent[1]['license'], 'then the older one' );
	af_assert_same( 1, count( afristream_license_log_recent( 1 ) ), 'the limit applies' );
} );

af_test( 'the last assignment is findable for a manual override', function () {
	af_seed_post( 10, 'alpha' );
	afristream_license_log_add( 10, 'assigned', 7, 'auto-assign' );
	af_set_now( 1785024000 + 60 );
	afristream_license_log_add( 10, 'unassigned', 7, 'admin' );

	$last = afristream_license_last_assignment( 10 );
	af_assert_same( 7, $last['user'], 'the last person it went to, even after being freed' );
	af_assert_same( 'auto-assign', $last['context'], 'and how it got there' );

	af_seed_post( 11, 'never-assigned' );
	af_assert_same( null, afristream_license_last_assignment( 11 ), 'null when it never happened' );
} );

af_test( 'assigning and unassigning write their own log entries', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );

	afristream_assign_license( 10, 7, 'auto-assign' );
	afristream_unassign_license( 10, 'admin' );

	$log = afristream_license_log_get( 10 );
	af_assert_same( 2, count( $log ), 'both events recorded' );
	af_assert_same( 'unassigned', $log[0]['event'], 'newest is the unassign' );
	af_assert_same( 'assigned', $log[1]['event'], 'then the assign' );
	af_assert_same( 7, $log[1]['user'], 'naming who it went to' );
} );
```

- [ ] **Step 2: Run the tests and watch them fail**

Run: `php tests/php/run.php`
Expected: FAIL — `Failed opening required '.../includes/license-log.php'`

- [ ] **Step 3: Write the implementation**

Create `includes/license-log.php`:

```php
<?php
/**
 * A history for each licence: who held it, when, and who made it so.
 *
 * The point is manual override. When a licence needs reassigning by hand, the
 * question is always "who had this last and how did they get it" — and before
 * this there was no way to answer it, because an assignment left no trace
 * beyond its current state.
 *
 * Stored as postmeta rather than a custom table. There are tens of licences,
 * not thousands; the log travels with the post through export and import; and
 * there is no schema to create, version or migrate.
 *
 * @package bluegroup-project-afristream
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Where a licence's history lives. */
define( 'AFRISTREAM_LICENSE_LOG_META', 'afristream_license_log' );

/**
 * Entries kept per licence.
 *
 * Enough to cover the life of a licence several times over, small enough that
 * the meta row stays a sensible size. The oldest go first — recent history is
 * what an override decision needs.
 */
define( 'AFRISTREAM_LOG_CAP', 100 );

/**
 * Who is making this change.
 *
 * A person's display name when someone is logged in, 'system' when the change
 * came from a webhook or cron, where there is no user to name.
 *
 * @return string
 */
function afristream_current_actor() {
	$user = wp_get_current_user();
	if ( $user && ! empty( $user->ID ) && ! empty( $user->display_name ) ) {
		return (string) $user->display_name;
	}
	return 'system';
}

/**
 * Append an entry to a licence's history.
 *
 * Stored oldest-first so appending is a push and capping is a slice; the
 * reversal for display happens on read, which is the rarer operation.
 *
 * @param int    $license_id Licence post ID.
 * @param string $event      assigned|unassigned|created|updated|conflict.
 * @param int    $user_id    Licence holder involved, 0 if none.
 * @param string $context    Where the change came from: auto-assign, profile, backfill, admin.
 * @param string $detail     Free text, e.g. a field's before and after.
 * @return void
 */
function afristream_license_log_add( $license_id, $event, $user_id = 0, $context = '', $detail = '' ) {
	$license_id = (int) $license_id;
	if ( ! $license_id ) {
		return;
	}

	$log = get_post_meta( $license_id, AFRISTREAM_LICENSE_LOG_META, true );
	if ( ! is_array( $log ) ) {
		$log = array();
	}

	$log[] = array(
		'time'    => (int) current_time( 'timestamp' ),
		'event'   => (string) $event,
		'user'    => (int) $user_id,
		'actor'   => afristream_current_actor(),
		'context' => (string) $context,
		'detail'  => (string) $detail,
	);

	if ( count( $log ) > AFRISTREAM_LOG_CAP ) {
		$log = array_slice( $log, -AFRISTREAM_LOG_CAP );
	}

	update_post_meta( $license_id, AFRISTREAM_LICENSE_LOG_META, $log );
}

/**
 * A licence's history, newest first.
 *
 * @param int $license_id Licence post ID.
 * @return array<int,array>
 */
function afristream_license_log_get( $license_id ) {
	$log = get_post_meta( (int) $license_id, AFRISTREAM_LICENSE_LOG_META, true );
	if ( ! is_array( $log ) || empty( $log ) ) {
		return array();
	}

	return array_reverse( $log );
}

/**
 * The most recent time this licence was given to someone.
 *
 * Survives the licence being freed again, which is the case that matters: the
 * reason to look is usually that it is free now and should not be.
 *
 * @param int $license_id Licence post ID.
 * @return array|null
 */
function afristream_license_last_assignment( $license_id ) {
	foreach ( afristream_license_log_get( $license_id ) as $entry ) {
		if ( 'assigned' === $entry['event'] ) {
			return $entry;
		}
	}
	return null;
}

/**
 * Recent events across every licence, newest first.
 *
 * Each entry carries the licence it belongs to, since the merged view loses
 * that context otherwise.
 *
 * @param int $limit Most entries to return.
 * @return array<int,array>
 */
function afristream_license_log_recent( $limit = 20 ) {
	$all = array();

	foreach ( afristream_all_license_ids() as $license_id ) {
		foreach ( afristream_license_log_get( $license_id ) as $entry ) {
			$entry['license'] = $license_id;
			$all[]            = $entry;
		}
	}

	usort(
		$all,
		function ( $a, $b ) {
			if ( $a['time'] !== $b['time'] ) {
				return $b['time'] <=> $a['time'];
			}
			return $b['license'] <=> $a['license'];
		}
	);

	$limit = (int) $limit;
	return $limit > 0 ? array_slice( $all, 0, $limit ) : $all;
}
```

- [ ] **Step 4: Remove the temporary guards in `includes/fields.php`**

The log now always exists. In `afristream_assign_license()` replace:

```php
			if ( function_exists( 'afristream_license_log_add' ) ) {
				afristream_license_log_add( $license_id, 'assigned', $user_id, $context );
			}
```

with:

```php
			afristream_license_log_add( $license_id, 'assigned', $user_id, $context );
```

and in `afristream_unassign_license()` replace:

```php
			if ( function_exists( 'afristream_license_log_add' ) ) {
				afristream_license_log_add( $license_id, 'unassigned', $owner, $context );
			}
```

with:

```php
			afristream_license_log_add( $license_id, 'unassigned', $owner, $context );
```

- [ ] **Step 5: Require the file from the plugin**

Modify `bluegroup-project-afristream.php` so the require block reads:

```php
require_once plugin_dir_path( __FILE__ ) . 'includes/fields.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/license-log.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/licenses.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/shortcodes.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/affiliates.php';
```

- [ ] **Step 6: Run the tests and confirm they pass**

Run: `php tests/php/run.php`
Expected: `PHP tests: 26 tests, 76 assertions, all passing`

- [ ] **Step 7: Commit**

```bash
git add includes/license-log.php includes/fields.php tests/php/test-license-log.php bluegroup-project-afristream.php
git commit -m "Give every licence a history so an override has something to go on

When a licence needs reassigning by hand the question is always who held it
last and how they got it, and until now an assignment left no trace beyond its
current state. Each licence keeps its own capped log of assigned, unassigned,
created, updated and conflict events, each stamped with the holder, the actor
and where the change came from.

Postmeta rather than a custom table: there are tens of licences, not thousands,
the log travels with the post through export and import, and there is no schema
to create, version or migrate.

Entries are stored oldest-first so appending is a push and capping is a slice,
and reversed on read, which is the rarer operation. afristream_license_last_
assignment() deliberately survives the licence being freed again, because the
reason to go looking is usually that it is free now and should not be.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: The licence editor — field and history meta boxes

Replaces the ACF field group on the licence edit screen. No unit tests: this is form rendering and `$_POST` handling, which the harness cannot exercise meaningfully. It is verified by hand against the live site in Step 4.

**Files:**
- Create: `includes/license-admin.php`
- Modify: `bluegroup-project-afristream.php` (require after `license-log.php`)

**Interfaces:**
- Consumes: `afristream_license_fields()`, `afristream_license_meta()`, `afristream_license_expiry_ymd()`, `afristream_license_log_get()`, `afristream_license_log_add()`, `afristream_license_owner()`.
- Produces: `afristream_license_sanitize_field( string $key, mixed $raw ): string` — used again by Task 9's backfill.

- [ ] **Step 1: Write the file**

Create `includes/license-admin.php`:

```php
<?php
/**
 * The licence edit screen and the licence field on a user's profile.
 *
 * Between them these replace both ACF field groups. The markup deliberately
 * uses core's own form-table classes rather than anything bespoke, so the
 * screens look like the rest of wp-admin and not like a plugin's idea of it.
 *
 * @package bluegroup-project-afristream
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coerce a submitted field value to something safe and expected.
 *
 * A date arrives from <input type="date"> as Y-m-d and is stored as Ymd, which
 * is what ACF stored and what sorting and expiry comparisons rely on. A choice
 * field that arrives as anything outside its own list becomes empty rather than
 * being stored: a value the UI can never produce came from somewhere that
 * should not be trusted.
 *
 * @param string $key Field name.
 * @param mixed  $raw Submitted value.
 * @return string
 */
function afristream_license_sanitize_field( $key, $raw ) {
	$fields = afristream_license_fields();
	if ( ! isset( $fields[ $key ] ) ) {
		return '';
	}

	$value = sanitize_text_field( wp_unslash( (string) $raw ) );
	$type  = $fields[ $key ]['type'];

	if ( 'date' === $type ) {
		if ( '' === $value ) {
			return '';
		}
		$date = DateTime::createFromFormat( 'Y-m-d', $value );
		return ( $date && $date->format( 'Y-m-d' ) === $value ) ? $date->format( 'Ymd' ) : '';
	}

	if ( in_array( $type, array( 'select', 'radio' ), true ) ) {
		return in_array( $value, $fields[ $key ]['choices'], true ) ? $value : '';
	}

	return $value;
}

/**
 * Register both meta boxes on the licence editor.
 */
function afristream_license_meta_boxes() {
	add_meta_box(
		'afristream-license-fields',
		__( 'Licence Details', 'bluegroup-project-afristream' ),
		'afristream_render_license_fields_box',
		'license',
		'normal',
		'high'
	);

	add_meta_box(
		'afristream-license-history',
		__( 'History', 'bluegroup-project-afristream' ),
		'afristream_render_license_history_box',
		'license',
		'normal',
		'default'
	);
}
add_action( 'add_meta_boxes_license', 'afristream_license_meta_boxes' );

/**
 * The four licence fields.
 *
 * @param WP_Post $post Licence being edited.
 */
function afristream_render_license_fields_box( $post ) {
	wp_nonce_field( 'afristream_save_license', 'afristream_license_nonce' );

	$owner = afristream_license_owner( $post->ID );
	echo '<table class="form-table" role="presentation"><tbody>';

	foreach ( afristream_license_fields() as $key => $field ) {
		$id = 'afristream-field-' . $key;
		echo '<tr>';
		echo '<th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . '</label></th>';
		echo '<td>';

		if ( 'date' === $field['type'] ) {
			$ymd   = afristream_license_expiry_ymd( $post->ID );
			$value = '' === $ymd ? '' : substr( $ymd, 0, 4 ) . '-' . substr( $ymd, 4, 2 ) . '-' . substr( $ymd, 6, 2 );
			echo '<input type="date" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" class="regular-text" />';
		} elseif ( 'select' === $field['type'] ) {
			$value = afristream_license_meta( $post->ID, $key );
			echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '">';
			echo '<option value="">' . esc_html__( '— Select —', 'bluegroup-project-afristream' ) . '</option>';
			foreach ( $field['choices'] as $choice ) {
				echo '<option value="' . esc_attr( $choice ) . '" ' . selected( $value, $choice, false ) . '>' . esc_html( $choice ) . '</option>';
			}
			echo '</select>';
		} elseif ( 'radio' === $field['type'] ) {
			$value = afristream_license_meta( $post->ID, $key );
			echo '<fieldset><legend class="screen-reader-text">' . esc_html( $field['label'] ) . '</legend>';
			foreach ( $field['choices'] as $choice ) {
				echo '<label style="margin-right:16px;"><input type="radio" name="' . esc_attr( $key ) . '" value="' . esc_attr( $choice ) . '" ' . checked( $value, $choice, false ) . ' /> ' . esc_html( $choice ) . '</label>';
			}
			echo '<label><input type="radio" name="' . esc_attr( $key ) . '" value="" ' . checked( $value, '', false ) . ' /> ' . esc_html__( 'Not set', 'bluegroup-project-afristream' ) . '</label>';
			echo '</fieldset>';
		} else {
			$value = afristream_license_meta( $post->ID, $key );
			echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" class="regular-text" />';
		}

		echo '</td></tr>';
	}

	echo '</tbody></table>';

	echo '<p class="description">';
	if ( $owner ) {
		$user = get_userdata( $owner );
		printf(
			/* translators: %s: linked user name. */
			esc_html__( 'Assigned to %s. Change the assignment from that user\'s profile.', 'bluegroup-project-afristream' ),
			'<a href="' . esc_url( get_edit_user_link( $owner ) ) . '">' . esc_html( $user ? $user->display_name : '#' . $owner ) . '</a>'
		);
	} else {
		esc_html_e( 'Not assigned to anyone — available for auto-assignment.', 'bluegroup-project-afristream' );
	}
	echo '</p>';
}

/**
 * The licence's history, newest first.
 *
 * @param WP_Post $post Licence being edited.
 */
function afristream_render_license_history_box( $post ) {
	$log = afristream_license_log_get( $post->ID );

	if ( empty( $log ) ) {
		echo '<p>' . esc_html__( 'Nothing has happened to this licence yet.', 'bluegroup-project-afristream' ) . '</p>';
		return;
	}

	echo '<table class="widefat striped"><thead><tr>';
	echo '<th>' . esc_html__( 'When', 'bluegroup-project-afristream' ) . '</th>';
	echo '<th>' . esc_html__( 'Event', 'bluegroup-project-afristream' ) . '</th>';
	echo '<th>' . esc_html__( 'User', 'bluegroup-project-afristream' ) . '</th>';
	echo '<th>' . esc_html__( 'By', 'bluegroup-project-afristream' ) . '</th>';
	echo '<th>' . esc_html__( 'Detail', 'bluegroup-project-afristream' ) . '</th>';
	echo '</tr></thead><tbody>';

	foreach ( $log as $entry ) {
		$user      = $entry['user'] ? get_userdata( $entry['user'] ) : false;
		$user_cell = '—';
		if ( $user ) {
			$user_cell = '<a href="' . esc_url( get_edit_user_link( $entry['user'] ) ) . '">' . esc_html( $user->display_name ) . '</a>';
		} elseif ( $entry['user'] ) {
			$user_cell = esc_html__( 'deleted user', 'bluegroup-project-afristream' ) . ' #' . (int) $entry['user'];
		}

		$by = $entry['actor'];
		if ( ! empty( $entry['context'] ) ) {
			$by .= ' (' . $entry['context'] . ')';
		}

		echo '<tr>';
		echo '<td>' . esc_html( wp_date( 'j M Y, H:i', $entry['time'] ) ) . '</td>';
		echo '<td>' . esc_html( $entry['event'] ) . '</td>';
		echo '<td>' . wp_kses_post( $user_cell ) . '</td>';
		echo '<td>' . esc_html( $by ) . '</td>';
		echo '<td>' . esc_html( $entry['detail'] ) . '</td>';
		echo '</tr>';
	}

	echo '</tbody></table>';
}

/**
 * Save the licence fields, recording any change in the licence's history.
 *
 * The before-and-after is written to the log rather than just the new value:
 * "app_password changed" is not much use six weeks later, and the old value is
 * exactly what someone reverting a mistake needs.
 *
 * @param int $post_id Licence post ID.
 */
function afristream_save_license_fields( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! isset( $_POST['afristream_license_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['afristream_license_nonce'] ) ), 'afristream_save_license' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$changes = array();

	foreach ( array_keys( afristream_license_fields() ) as $key ) {
		$before = (string) get_post_meta( $post_id, $key, true );
		$after  = isset( $_POST[ $key ] ) ? afristream_license_sanitize_field( $key, $_POST[ $key ] ) : '';

		if ( $before === $after ) {
			continue;
		}

		if ( '' === $after ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, $after );
		}

		$changes[] = sprintf(
			'%s %s → %s',
			$key,
			'' === $before ? '(empty)' : $before,
			'' === $after ? '(empty)' : $after
		);
	}

	if ( ! empty( $changes ) ) {
		afristream_license_log_add( $post_id, 'updated', afristream_license_owner( $post_id ), 'admin', implode( ', ', $changes ) );
	}
}
add_action( 'save_post_license', 'afristream_save_license_fields' );

/**
 * Record a licence being created, so its history starts at the beginning.
 *
 * @param int $post_id Licence post ID.
 */
function afristream_log_license_created( $post_id ) {
	afristream_license_log_add( $post_id, 'created', 0, 'admin' );
}
add_action( 'publish_license', 'afristream_log_license_created', 10, 1 );
```

- [ ] **Step 2: Require it from the plugin**

Modify `bluegroup-project-afristream.php` to add, after the `license-log.php` line:

```php
require_once plugin_dir_path( __FILE__ ) . 'includes/license-admin.php';
```

- [ ] **Step 3: Confirm it parses and the suite still passes**

Run: `npm run lint`
Expected: `PHP lint: clean`

Run: `php tests/php/run.php`
Expected: `PHP tests: 26 tests, 76 assertions, all passing` — unchanged, this task adds no units.

- [ ] **Step 4: Verify by hand on the live site**

ACF is still active, so the licence editor will show **two** sets of fields — ACF's and this plugin's. That is expected until Task 14 deletes ACF's copies, and it is the moment to confirm both read the same stored values.

1. Open **Licenses → All Licenses**, edit any licence.
2. Confirm the new **Licence Details** box shows the same App Password, Expiry Date, License Provider and Mobile Active as ACF's box above it.
3. Change App Password in the new box, click **Update**.
4. Confirm the value persists, and that ACF's box now shows the same new value — proving both are reading one set of meta rows, not two.
5. Confirm the **History** box now lists an `updated` entry naming the before and after.
6. Set the password back to what it was.

- [ ] **Step 5: Commit**

```bash
git add includes/license-admin.php bluegroup-project-afristream.php
git commit -m "Put the licence fields on the licence editor without ACF

Four fields and a history table, using core's own form-table markup so the
screen looks like the rest of wp-admin rather than like a plugin's idea of it.

A submitted choice outside its own list is stored as empty rather than kept: a
value the UI cannot produce came from somewhere that should not be trusted.
Dates arrive from the browser as Y-m-d and are stored as Ymd, which is what ACF
stored and what sorting and expiry comparison rely on.

Field changes are logged with their before and after rather than just the fact
of a change, because 'app_password changed' is no use six weeks later and the
old value is exactly what someone reverting a mistake needs.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: The licence field on a user's profile

Replaces the ACF relationship field, and is where multiple licences per user become visible and editable.

**Files:**
- Modify: `includes/license-admin.php` (append)
- Create: `tests/php/test-profile-diff.php`

**Interfaces:**
- Consumes: `afristream_user_license_ids()`, `afristream_available_licenses()`, `afristream_assign_license()`, `afristream_unassign_license()`.
- Produces: `afristream_license_selection_diff( int[] $current, int[] $submitted ): array{add:int[],remove:int[]}` — pure, tested; the rendering and `$_POST` handling around it are verified by hand.

- [ ] **Step 1: Write the failing test for the diff**

Create `tests/php/test-profile-diff.php`:

```php
<?php
require_once __DIR__ . '/../../includes/fields.php';
require_once __DIR__ . '/../../includes/license-log.php';
require_once __DIR__ . '/../../includes/license-admin.php';

af_test( 'the profile diff only touches what actually changed', function () {
	$diff = afristream_license_selection_diff( array( 10, 11 ), array( 11, 12 ) );
	af_assert_same( array( 12 ), $diff['add'], 'newly selected' );
	af_assert_same( array( 10 ), $diff['remove'], 'deselected' );
} );

af_test( 'an unchanged selection produces no writes at all', function () {
	$diff = afristream_license_selection_diff( array( 10, 11 ), array( 11, 10 ) );
	af_assert_same( array(), $diff['add'], 'nothing to add regardless of order' );
	af_assert_same( array(), $diff['remove'], 'nothing to remove' );
} );

af_test( 'clearing the selection removes every licence', function () {
	$diff = afristream_license_selection_diff( array( 10, 11 ), array() );
	af_assert_same( array(), $diff['add'], 'nothing added' );
	af_assert_same( array( 10, 11 ), $diff['remove'], 'both removed' );
} );

af_test( 'duplicates and junk in the submission are ignored', function () {
	$diff = afristream_license_selection_diff( array(), array( 12, 12, 0, -3 ) );
	af_assert_same( array( 12 ), $diff['add'], 'deduped, and non-ids dropped' );
} );
```

- [ ] **Step 2: Run the tests and watch them fail**

Run: `php tests/php/run.php`
Expected: FAIL — `Call to undefined function afristream_license_selection_diff()`

- [ ] **Step 3: Append the implementation to `includes/license-admin.php`**

```php
/**
 * What changed between the licences a user holds and the ones just submitted.
 *
 * Separated out and returned rather than acted on so it can be tested, and so
 * the save path touches only what actually changed. Re-saving a profile without
 * altering the selection must write nothing — otherwise every profile save
 * would churn the licence log with events that did not happen.
 *
 * @param int[] $current   Licences the user holds now.
 * @param int[] $submitted Licences chosen in the form.
 * @return array{add:int[],remove:int[]}
 */
function afristream_license_selection_diff( $current, $submitted ) {
	$current = array_values( array_unique( array_map( 'intval', (array) $current ) ) );

	$clean = array();
	foreach ( (array) $submitted as $id ) {
		$id = (int) $id;
		if ( $id > 0 && ! in_array( $id, $clean, true ) ) {
			$clean[] = $id;
		}
	}

	$add    = array_values( array_diff( $clean, $current ) );
	$remove = array_values( array_diff( $current, $clean ) );

	sort( $add );
	sort( $remove );

	return array(
		'add'    => $add,
		'remove' => $remove,
	);
}

/**
 * The Active License field on a user's profile.
 *
 * A multi-select of the licences this user holds plus every free one. A licence
 * held by somebody else is simply not in the list — which is what ACF's
 * relationship query filter did, except that now it is a property of how the
 * options are built rather than a filter that has to be remembered.
 *
 * @param WP_User $user User being edited.
 */
function afristream_render_user_license_field( $user ) {
	if ( ! current_user_can( 'edit_users' ) && get_current_user_id() !== $user->ID ) {
		return;
	}

	$held      = afristream_user_license_ids( $user->ID );
	$available = afristream_available_licenses();
	$options   = array_values( array_unique( array_merge( $held, $available ) ) );
	sort( $options );

	wp_nonce_field( 'afristream_save_user_licenses', 'afristream_user_license_nonce' );
	?>
	<h2><?php esc_html_e( 'AfriStream Licence', 'bluegroup-project-afristream' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="afristream-active-license"><?php esc_html_e( 'Active License', 'bluegroup-project-afristream' ); ?></label></th>
			<td>
				<?php if ( empty( $options ) ) : ?>
					<p><?php esc_html_e( 'No licences are available and this user holds none.', 'bluegroup-project-afristream' ); ?></p>
				<?php else : ?>
					<select id="afristream-active-license" name="afristream_active_license[]" multiple size="<?php echo esc_attr( min( 10, max( 3, count( $options ) ) ) ); ?>" style="min-width:340px;">
						<?php foreach ( $options as $license_id ) : ?>
							<?php
							$expiry = afristream_license_meta( $license_id, 'expiry_date' );
							$label  = get_the_title( $license_id );
							if ( '' !== $expiry ) {
								$label .= ' — expires ' . $expiry;
							}
							?>
							<option value="<?php echo esc_attr( $license_id ); ?>" <?php selected( in_array( $license_id, $held, true ) ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php esc_html_e( 'Hold Ctrl (Cmd on a Mac) to select more than one. Licences held by another user are not listed. Each one the user holds becomes a profile in the portal.', 'bluegroup-project-afristream' ); ?>
					</p>
				<?php endif; ?>
			</td>
		</tr>
	</table>
	<?php
}
add_action( 'show_user_profile', 'afristream_render_user_license_field' );
add_action( 'edit_user_profile', 'afristream_render_user_license_field' );

/**
 * Save the licence selection.
 *
 * Every claim goes through afristream_assign_license(), which re-checks
 * availability inside the lock. A licence that was free when the form rendered
 * but taken by the time it was submitted is refused here rather than stolen —
 * this is the job ACF's validate_value filter did, moved onto the write path so
 * it also covers saves that do not come from this form.
 *
 * @param int $user_id User being saved.
 */
function afristream_save_user_license_field( $user_id ) {
	if ( ! isset( $_POST['afristream_user_license_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['afristream_user_license_nonce'] ) ), 'afristream_save_user_licenses' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_user', $user_id ) ) {
		return;
	}

	$submitted = isset( $_POST['afristream_active_license'] )
		? array_map( 'intval', (array) wp_unslash( $_POST['afristream_active_license'] ) )
		: array();

	$diff = afristream_license_selection_diff( afristream_user_license_ids( $user_id ), $submitted );

	foreach ( $diff['remove'] as $license_id ) {
		afristream_unassign_license( $license_id, 'profile' );
	}

	$refused = array();
	foreach ( $diff['add'] as $license_id ) {
		$result = afristream_assign_license( $license_id, $user_id, 'profile' );
		if ( is_wp_error( $result ) ) {
			$refused[] = get_the_title( $license_id );
		}
	}

	if ( ! empty( $refused ) ) {
		set_transient( 'afristream_license_refused_' . get_current_user_id(), $refused, 60 );
	}
}
add_action( 'personal_options_update', 'afristream_save_user_license_field' );
add_action( 'edit_user_profile_update', 'afristream_save_user_license_field' );

/**
 * Tell the admin when a licence they picked was claimed underneath them.
 *
 * Silently dropping it would look like the save worked, which is worse than the
 * refusal itself.
 */
function afristream_license_refused_notice() {
	$key     = 'afristream_license_refused_' . get_current_user_id();
	$refused = get_transient( $key );
	if ( empty( $refused ) ) {
		return;
	}
	delete_transient( $key );

	echo '<div class="notice notice-error is-dismissible"><p>';
	printf(
		/* translators: %s: comma-separated licence names. */
		esc_html__( 'These licences were not assigned because someone else claimed them first: %s', 'bluegroup-project-afristream' ),
		esc_html( implode( ', ', (array) $refused ) )
	);
	echo '</p></div>';
}
add_action( 'admin_notices', 'afristream_license_refused_notice' );
```

- [ ] **Step 4: Run the tests and confirm they pass**

Run: `php tests/php/run.php`
Expected: `PHP tests: 30 tests, 83 assertions, all passing`

- [ ] **Step 5: Verify by hand on the live site**

1. Edit a user who holds a licence. Confirm the new **AfriStream Licence** section shows it selected, and that the ACF relationship field above shows the same one.
2. Confirm a licence already held by a *different* user does not appear in the list.
3. Assign a second licence to a user who already has one. Save. Confirm both stay selected and the first was not dropped.
4. Open that user's licences and confirm each **History** box shows the `assigned` entry.
5. Deselect one, save, and confirm it returns to the available pool.

- [ ] **Step 6: Commit**

```bash
git add includes/license-admin.php tests/php/test-profile-diff.php
git commit -m "Let a user hold more than one licence, from their own profile

A multi-select replaces ACF's relationship field, which was capped at one. The
list is built from the licences this user holds plus every free one, so a
licence belonging to someone else is not offered — the same outcome as ACF's
relationship query filter, but as a property of how the options are built
rather than a filter someone has to remember exists.

The save path computes a diff first and acts only on what changed, so
re-saving a profile without touching the selection writes nothing and does not
churn the licence history with events that did not happen.

Every claim still goes through afristream_assign_license() and its lock, so a
licence that was free when the form rendered but taken by the time it was
submitted is refused rather than stolen, and the admin is told which ones and
why. Silently dropping them would look like the save worked, which is worse
than the refusal.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 7: Rewrite `includes/licenses.php` onto the new accessors

Removes the last `get_field()` call and both `acf/*` filters, and adds the **Last assigned** column.

**Files:**
- Modify: `includes/licenses.php` (substantial rewrite)
- Modify: `includes/shortcodes.php:1-16` (doc comment only)
- Modify: `bluegroup-project-afristream.php:959-970` (`afristream_portal_credentials_data()`)
- Create: `tests/php/test-credentials.php`

**Interfaces:**
- Consumes: everything from Tasks 2–4.
- Produces: `afristream_portal_user_credentials(): array<int,array{label:string,user:string,pass:string}>` — same signature as today, so the shortcode and REST route are unchanged.

Two behaviours in the current file are being deliberately dropped, and both are safe:

- `afristream_portal_current_acf_user_id()` and `afristream_portal_assigned_license_ids()` existed only to feed the two ACF filters. Task 6 replaced their purpose. They go.
- The Connected User column's `get_users()` + `LIKE '"47"'` query is replaced by `afristream_license_owner()`, one meta read instead of a scan of every user. The mirror still stores strings (Task 3), so any *other* consumer of that query shape keeps working.

- [ ] **Step 1: Write the failing test for credentials**

Create `tests/php/test-credentials.php`:

```php
<?php
require_once __DIR__ . '/../../includes/fields.php';
require_once __DIR__ . '/../../includes/license-log.php';
require_once __DIR__ . '/../../includes/licenses.php';

af_test( 'credentials list one profile per licence, numbered in order', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'BabyBlue123' );
	af_seed_post( 11, 'BabyBlue-TV' );
	update_post_meta( 10, 'app_password', 'pass-one' );
	update_post_meta( 11, 'app_password', 'pass-two' );
	update_post_meta( 10, AFRISTREAM_LICENSE_OWNER_META, 7 );
	update_post_meta( 11, AFRISTREAM_LICENSE_OWNER_META, 7 );

	$profiles = afristream_portal_credentials_for_user( 7 );

	af_assert_same( 2, count( $profiles ), 'one entry per licence' );
	af_assert_same( 'Profile 1', $profiles[0]['label'], 'first is Profile 1' );
	af_assert_same( 'BabyBlue123', $profiles[0]['user'], 'username is the licence title' );
	af_assert_same( 'pass-one', $profiles[0]['pass'], 'password is the licence field' );
	af_assert_same( 'Profile 2', $profiles[1]['label'], 'second is Profile 2' );
	af_assert_same( 'BabyBlue-TV', $profiles[1]['user'], 'and its own title' );
} );

af_test( 'a user with no licences gets an empty list, not a placeholder', function () {
	af_seed_user( 7 );
	af_assert_same( array(), afristream_portal_credentials_for_user( 7 ), 'empty' );
} );

af_test( 'a licence with neither title nor password is skipped, not shown blank', function () {
	af_seed_user( 7 );
	af_seed_post( 10, '' );
	af_seed_post( 11, 'BabyBlue-TV' );
	update_post_meta( 11, 'app_password', 'pass-two' );
	update_post_meta( 10, AFRISTREAM_LICENSE_OWNER_META, 7 );
	update_post_meta( 11, AFRISTREAM_LICENSE_OWNER_META, 7 );

	$profiles = afristream_portal_credentials_for_user( 7 );
	af_assert_same( 1, count( $profiles ), 'the empty licence is skipped' );
	af_assert_same( 'Profile 1', $profiles[0]['label'], 'and numbering closes up rather than skipping to 2' );
} );
```

- [ ] **Step 2: Run the tests and watch them fail**

Run: `php tests/php/run.php`
Expected: FAIL — `Call to undefined function afristream_portal_credentials_for_user()`

- [ ] **Step 3: Rewrite `includes/licenses.php`**

Replace the file's contents entirely:

```php
<?php
/**
 * Licence admin screens and the credentials the portal shows a customer.
 *
 * Everything here reads through includes/fields.php, which owns the data model.
 * There is no ACF dependency left and therefore no function_exists() guards:
 * the fields are the plugin's own now, so there is nothing optional to degrade
 * against.
 *
 * @package bluegroup-project-afristream
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A user's streaming credentials, one entry per licence they hold.
 *
 * Split from afristream_portal_user_credentials() so it can be tested without a
 * logged-in session.
 *
 * @param int $user_id User ID.
 * @return array<int,array{label:string,user:string,pass:string}>
 */
function afristream_portal_credentials_for_user( $user_id ) {
	$profiles = array();
	$n        = 1;

	foreach ( afristream_user_license_ids( $user_id ) as $license_id ) {
		$username = (string) get_the_title( $license_id );
		$password = (string) afristream_license_meta( $license_id, 'app_password' );

		// A licence with neither is a half-created record, not a profile. Showing
		// it as an empty card reads as the portal being broken.
		if ( '' === $username && '' === $password ) {
			continue;
		}

		$profiles[] = array(
			'label' => 'Profile ' . $n,
			'user'  => $username,
			'pass'  => $password,
		);
		$n++;
	}

	return $profiles;
}

/**
 * The logged-in user's credentials. Shared by the REST endpoint that feeds the
 * portal's Profile tab and by the [user_acf_fields] shortcode.
 *
 * @return array<int,array{label:string,user:string,pass:string}>
 */
function afristream_portal_user_credentials() {
	if ( ! is_user_logged_in() ) {
		return array();
	}
	return afristream_portal_credentials_for_user( get_current_user_id() );
}

/**
 * Free a user's licences when the user is deleted.
 *
 * Going through afristream_unassign_license() rather than deleting the meta
 * means the licences return to the available pool and each one records who it
 * was taken from — which is the whole point of the log when a deletion turns
 * out to have been a mistake.
 *
 * @param int $user_id User being deleted.
 */
function afristream_portal_unassign_licenses_on_user_delete( $user_id ) {
	foreach ( afristream_user_license_ids( $user_id ) as $license_id ) {
		afristream_unassign_license( $license_id, 'user-deleted' );
	}
	delete_user_meta( $user_id, AFRISTREAM_USER_LICENSE_META );
}
add_action( 'delete_user', 'afristream_portal_unassign_licenses_on_user_delete' );
add_action( 'wpmu_delete_user', 'afristream_portal_unassign_licenses_on_user_delete' );

/**
 * Add the Active Licenses column to the Users table.
 *
 * @param array $columns Existing columns.
 * @return array
 */
function afristream_portal_active_licenses_user_column( $columns ) {
	$columns['active_licenses'] = __( 'Active Licenses', 'bluegroup-project-afristream' );
	return $columns;
}
add_filter( 'manage_users_columns', 'afristream_portal_active_licenses_user_column' );

/**
 * Render the Active Licenses column.
 *
 * @param string $value       Existing value.
 * @param string $column_name Column being rendered.
 * @param int    $user_id     User for this row.
 * @return string
 */
function afristream_portal_render_active_licenses_user_column( $value, $column_name, $user_id ) {
	if ( 'active_licenses' !== $column_name ) {
		return $value;
	}

	$titles = array();
	foreach ( afristream_user_license_ids( $user_id ) as $license_id ) {
		$titles[] = get_the_title( $license_id );
	}

	return ! empty( $titles ) ? esc_html( implode( ', ', $titles ) ) : '—';
}
add_filter( 'manage_users_custom_column', 'afristream_portal_render_active_licenses_user_column', 10, 3 );

/**
 * Add Expiry Date, Mobile Active, Connected User and Last Assigned columns to
 * the Licenses table.
 *
 * @param array $columns Existing columns.
 * @return array
 */
function afristream_portal_license_columns( $columns ) {
	$new_columns = array();
	foreach ( $columns as $key => $label ) {
		$new_columns[ $key ] = $label;
		if ( 'title' === $key ) {
			$new_columns['expiry_date']    = __( 'Expiry Date', 'bluegroup-project-afristream' );
			$new_columns['mobile_active']  = __( 'Mobile Active', 'bluegroup-project-afristream' );
			$new_columns['active_license'] = __( 'Connected User', 'bluegroup-project-afristream' );
			$new_columns['last_assigned']  = __( 'Last Assigned', 'bluegroup-project-afristream' );
		}
	}
	return $new_columns;
}
add_filter( 'manage_license_posts_columns', 'afristream_portal_license_columns' );

/**
 * Convert an expiry date string to a timestamp, tolerant of several formats.
 *
 * Kept tolerant even though the field is now written only as Ymd, because dates
 * entered before this plugin owned the field may be in any of these shapes.
 *
 * @param string $expiry_date Date in one of the accepted formats.
 * @return int|false
 */
function afristream_portal_license_expiry_timestamp( $expiry_date ) {
	if ( empty( $expiry_date ) ) {
		return false;
	}
	$formats = array( 'd/m/Y', 'Y-m-d', 'Ymd', 'm/d/Y' );
	foreach ( $formats as $format ) {
		$date = DateTime::createFromFormat( $format, $expiry_date );
		if ( $date && $date->format( $format ) === $expiry_date ) {
			$date->setTime( 0, 0, 0 );
			return $date->getTimestamp();
		}
	}
	$fallback = strtotime( $expiry_date );
	return $fallback ? $fallback : false;
}

/**
 * Render the licence table's custom columns.
 *
 * @param string $column  Column being rendered.
 * @param int    $post_id Licence for this row.
 */
function afristream_portal_render_license_columns( $column, $post_id ) {
	if ( 'expiry_date' === $column ) {
		$expiry_date = afristream_license_meta( $post_id, 'expiry_date' );
		if ( '' === $expiry_date ) {
			echo '<span style="color:#6b7280;">—</span>';
			return;
		}

		$timestamp   = afristream_portal_license_expiry_timestamp( $expiry_date );
		$today_start = strtotime( gmdate( 'Y-m-d', current_time( 'timestamp' ) ) );

		if ( ! $timestamp ) {
			echo '<span>' . esc_html( $expiry_date ) . '</span>';
			return;
		}

		$is_expired = $timestamp < $today_start;
		$label      = $is_expired ? __( 'Expired', 'bluegroup-project-afristream' ) : __( 'Active', 'bluegroup-project-afristream' );
		$bg_color   = $is_expired ? '#fee2e2' : '#dcfce7';
		$text_color = $is_expired ? '#b91c1c' : '#166534';

		echo '<div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">';
		echo '<span>' . esc_html( $expiry_date ) . '</span>';
		echo '<span style="display:inline-block; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:600; line-height:1; background:' . esc_attr( $bg_color ) . '; color:' . esc_attr( $text_color ) . ';">' . esc_html( $label ) . '</span>';
		echo '</div>';
		return;
	}

	if ( 'mobile_active' === $column ) {
		$is_active  = filter_var( afristream_license_meta( $post_id, 'mobile_active' ), FILTER_VALIDATE_BOOLEAN );
		$label      = $is_active ? __( 'Yes', 'bluegroup-project-afristream' ) : __( 'No', 'bluegroup-project-afristream' );
		$bg_color   = $is_active ? '#dcfce7' : '#f3f4f6';
		$text_color = $is_active ? '#166534' : '#374151';
		echo '<span style="display:inline-block; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:600; line-height:1; background:' . esc_attr( $bg_color ) . '; color:' . esc_attr( $text_color ) . ';">' . esc_html( $label ) . '</span>';
		return;
	}

	// Who holds it — one meta read now that the licence carries its own owner,
	// where this used to scan every user for a serialized ID.
	if ( 'active_license' === $column ) {
		$owner = afristream_license_owner( $post_id );
		if ( ! $owner ) {
			echo '<span style="color:#6b7280;">—</span>';
			return;
		}
		$user = get_userdata( $owner );
		if ( ! $user ) {
			echo '<span style="color:#b91c1c;">' . esc_html__( 'deleted user', 'bluegroup-project-afristream' ) . '</span>';
			return;
		}
		echo '<a href="' . esc_url( get_edit_user_link( $owner ) ) . '">' . esc_html( $user->display_name ) . '</a>';
		return;
	}

	// The last person it went to, even if it is free now — which is exactly the
	// case where the question gets asked.
	if ( 'last_assigned' === $column ) {
		$last = afristream_license_last_assignment( $post_id );
		if ( ! $last ) {
			echo '<span style="color:#6b7280;">—</span>';
			return;
		}
		$user = $last['user'] ? get_userdata( $last['user'] ) : false;
		$name = $user ? $user->display_name : __( 'deleted user', 'bluegroup-project-afristream' );
		echo '<span>' . esc_html( $name ) . '</span><br>';
		echo '<span style="color:#6b7280; font-size:12px;">' . esc_html( wp_date( 'j M Y', $last['time'] ) );
		if ( ! empty( $last['context'] ) ) {
			echo ' · ' . esc_html( $last['context'] );
		}
		echo '</span>';
	}
}
add_action( 'manage_license_posts_custom_column', 'afristream_portal_render_license_columns', 10, 2 );

/**
 * Make Expiry Date and Mobile Active sortable.
 *
 * @param array $columns Sortable columns.
 * @return array
 */
function afristream_portal_license_sortable_columns( $columns ) {
	$columns['expiry_date']   = 'expiry_date';
	$columns['mobile_active'] = 'mobile_active';
	return $columns;
}
add_filter( 'manage_edit-license_sortable_columns', 'afristream_portal_license_sortable_columns' );

/**
 * Sort licences by expiry date or mobile flag.
 *
 * @param WP_Query $query The query being run.
 */
function afristream_portal_sort_license_columns( $query ) {
	if ( ! is_admin() || ! $query->is_main_query() ) {
		return;
	}
	if ( 'license' !== $query->get( 'post_type' ) ) {
		return;
	}

	$orderby = $query->get( 'orderby' );
	if ( in_array( $orderby, array( 'expiry_date', 'mobile_active' ), true ) ) {
		$query->set( 'meta_key', $orderby ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		$query->set( 'orderby', 'meta_value' );
	}
}
add_action( 'pre_get_posts', 'afristream_portal_sort_license_columns' );
```

- [ ] **Step 4: Update the REST payload's source label**

`afristream_portal_credentials_data()` reports `source => 'acf'`, which is no longer true. The front-end only ever tests for `'fallback'` (`assets/portal.js:2417` and `:2455`), so renaming the positive case is safe.

Modify `bluegroup-project-afristream.php` — replace the function and its doc comment:

```php
/**
 * The current user's app credentials, one entry per assigned licence, for the
 * portal's Profile tab. Returns source:"fallback" with an empty list when no
 * licence is assigned, so the front-end can show a clear "no profile assigned"
 * state instead of stale placeholders.
 */
function afristream_portal_credentials_data() {
	$profiles = afristream_portal_user_credentials();
	return rest_ensure_response(
		array(
			'source'   => $profiles ? 'assigned' : 'fallback',
			'profiles' => $profiles,
		)
	);
}
```

- [ ] **Step 5: Update the shortcodes file's doc comment**

The tag name `[user_acf_fields]` stays — pages reference it and renaming it would break them — but the header should stop claiming an ACF dependency. Modify `includes/shortcodes.php` lines 1–16, replacing the opening comment block:

```php
<?php
/**
 * Legacy shortcodes ported from the "afristream code snippets" plugin so it can
 * be retired without breaking pages that still reference these tags:
 *
 *   [user_acf_fields]      — the logged-in user's app credentials
 *   [troubleshooting_guide] — the detailed troubleshooting accordion
 *
 * The acf in [user_acf_fields] is now only a name. The fields it shows belong
 * to this plugin (see includes/fields.php); the tag keeps its old spelling
 * because pages in the wild reference it and renaming it would break them.
 *
 * Both are restyled in the AfriStream brand colours. The portal's own Profile
 * tab is the primary surface for this data — these exist for pages built before
 * it and for anyone embedding the details on their own page.
 *
 * @package bluegroup-project-afristream
 */
```

- [ ] **Step 6: Run the tests and confirm they pass**

Run: `php tests/php/run.php`
Expected: `PHP tests: 33 tests, 92 assertions, all passing`

- [ ] **Step 7: Confirm no ACF calls remain in the plugin**

Run: `grep -rn "get_field\|the_field\|update_field\|delete_field\|acf/" --include="*.php" includes/ bluegroup-project-afristream.php`
Expected: no output. (`dist/` will still contain the old build; that is regenerated in the final task.)

- [ ] **Step 8: Verify by hand on the live site**

1. **Licenses → All Licenses**: Expiry Date, Mobile Active and Connected User read exactly as before, and a new **Last Assigned** column appears.
2. Sort by Expiry Date and by Mobile Active — both still work.
3. **Users**: the Active Licenses column still lists names, and a user with two licences shows both.
4. Open the portal page as a customer holding two licences and confirm the Profile tab shows **Profile 1** and **Profile 2** with the right credentials.
5. Load `/wp-json/afristream/v1/credentials` while logged in as that customer and confirm `source` is `assigned` with two entries.

- [ ] **Step 9: Commit**

```bash
git add includes/licenses.php includes/shortcodes.php bluegroup-project-afristream.php tests/php/test-credentials.php
git commit -m "Remove the last of the ACF dependency

Every get_field() call and both acf/* filters are gone. The filters are not
replaced: hiding taken licences and rejecting an already-assigned one are now
properties of how the profile field builds its options and of the assignment
lock, rather than hooks that only fire on an admin form and never on a
programmatic write.

The Connected User column stops scanning every user for a serialized licence ID
and reads the owner off the licence, which is one meta read instead of a table
scan with a LIKE in it.

A new Last Assigned column names whoever held a licence most recently and how
it got to them, which is the question that actually gets asked — usually about
a licence that is free now and should not be.

The credentials endpoint reported source:"acf", which is no longer true; it now
says "assigned". The front-end only ever tested for "fallback", so nothing
downstream changes. [user_acf_fields] keeps its name because pages in the wild
reference it.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 8: Backfill existing assignments onto the licences

Until this runs, `afristream_license_owner()` returns 0 for every licence on the live site: the eleven real assignments exist only in the old usermeta arrays. Everything built so far is correct but operating on empty data.

**Files:**
- Modify: `includes/fields.php` (append)
- Create: `tests/php/test-backfill.php`

**Interfaces:**
- Consumes: `afristream_user_mirror_ids()`, `afristream_license_owner()`, `afristream_rebuild_user_mirror()`, `afristream_license_log_add()`.
- Produces:
  - `AFRISTREAM_SCHEMA_OPTION` = `'afristream_schema_version'`, `AFRISTREAM_SCHEMA_VERSION` = `2`
  - `afristream_backfill_ownership(): array{claimed:int,conflicts:array<int,array{license:int,kept:int,rejected:int[]}>}`
  - `afristream_maybe_upgrade(): void` — runs the backfill once, on `admin_init`
  - `afristream_ownership_conflicts(): array` — the stored conflict report, for the Configurations page

- [ ] **Step 1: Write the failing tests**

Create `tests/php/test-backfill.php`:

```php
<?php
require_once __DIR__ . '/../../includes/fields.php';
require_once __DIR__ . '/../../includes/license-log.php';

af_test( 'the backfill moves usermeta assignments onto the licences', function () {
	af_seed_user( 7, 'alice', '2026-01-01 00:00:00' );
	af_seed_post( 10, 'alpha' );
	af_seed_post( 11, 'beta' );
	update_user_meta( 7, AFRISTREAM_USER_LICENSE_META, array( '10', '11' ) );

	$report = afristream_backfill_ownership();

	af_assert_same( 2, $report['claimed'], 'both claimed' );
	af_assert_same( 7, afristream_license_owner( 10 ), 'first licence points at the user' );
	af_assert_same( 7, afristream_license_owner( 11 ), 'so does the second' );
	af_assert_same( array(), $report['conflicts'], 'no conflicts' );
} );

af_test( 'a licence held by two users goes to the earlier registration and is reported', function () {
	af_seed_user( 7, 'alice', '2026-01-01 00:00:00' );
	af_seed_user( 8, 'bob', '2026-03-01 00:00:00' );
	af_seed_post( 10, 'alpha' );
	update_user_meta( 7, AFRISTREAM_USER_LICENSE_META, array( '10' ) );
	update_user_meta( 8, AFRISTREAM_USER_LICENSE_META, array( '10' ) );

	$report = afristream_backfill_ownership();

	af_assert_same( 7, afristream_license_owner( 10 ), 'the earlier-registered user keeps it' );
	af_assert_same( 1, count( $report['conflicts'] ), 'the clash is reported' );
	af_assert_same( 10, $report['conflicts'][0]['license'], 'naming the licence' );
	af_assert_same( 7, $report['conflicts'][0]['kept'], 'and who kept it' );
	af_assert_same( array( 8 ), $report['conflicts'][0]['rejected'], 'and who lost it' );

	$log = afristream_license_log_get( 10 );
	af_assert_same( 'conflict', $log[0]['event'], 'and it is on the licence history, not just a report' );
} );

af_test( 'the loser of a conflict does not silently keep it in their mirror', function () {
	af_seed_user( 7, 'alice', '2026-01-01 00:00:00' );
	af_seed_user( 8, 'bob', '2026-03-01 00:00:00' );
	af_seed_post( 10, 'alpha' );
	update_user_meta( 7, AFRISTREAM_USER_LICENSE_META, array( '10' ) );
	update_user_meta( 8, AFRISTREAM_USER_LICENSE_META, array( '10' ) );

	afristream_backfill_ownership();

	af_assert_same( array( '10' ), get_user_meta( 7, AFRISTREAM_USER_LICENSE_META, true ), 'winner keeps it' );
	af_assert_same( array(), get_user_meta( 8, AFRISTREAM_USER_LICENSE_META, true ), 'loser mirror is corrected' );
} );

af_test( 'a mirror pointing at a licence that no longer exists is dropped', function () {
	af_seed_user( 7, 'alice' );
	update_user_meta( 7, AFRISTREAM_USER_LICENSE_META, array( '999' ) );

	$report = afristream_backfill_ownership();
	af_assert_same( 0, $report['claimed'], 'nothing claimed' );
	af_assert_same( array(), get_user_meta( 7, AFRISTREAM_USER_LICENSE_META, true ), 'the dangling reference is cleared' );
} );

af_test( 'running the backfill twice changes nothing the second time', function () {
	af_seed_user( 7, 'alice' );
	af_seed_post( 10, 'alpha' );
	update_user_meta( 7, AFRISTREAM_USER_LICENSE_META, array( '10' ) );

	afristream_backfill_ownership();
	$second = afristream_backfill_ownership();

	af_assert_same( 0, $second['claimed'], 'nothing left to claim' );
	af_assert_same( 7, afristream_license_owner( 10 ), 'ownership intact' );
	af_assert_same( 1, count( afristream_license_log_get( 10 ) ), 'and no duplicate log entry' );
} );

af_test( 'the upgrade runs once and then stands down', function () {
	af_seed_user( 7, 'alice' );
	af_seed_post( 10, 'alpha' );
	update_user_meta( 7, AFRISTREAM_USER_LICENSE_META, array( '10' ) );

	afristream_maybe_upgrade();
	af_assert_same( AFRISTREAM_SCHEMA_VERSION, (int) get_option( AFRISTREAM_SCHEMA_OPTION ), 'version recorded' );
	af_assert_same( 7, afristream_license_owner( 10 ), 'backfilled' );

	// A second call must not re-run: unassign and confirm nothing puts it back.
	afristream_unassign_license( 10, 'test' );
	afristream_maybe_upgrade();
	af_assert_same( 0, afristream_license_owner( 10 ), 'the upgrade did not run again' );
} );
```

- [ ] **Step 2: Run the tests and watch them fail**

Run: `php tests/php/run.php`
Expected: FAIL — `Call to undefined function afristream_backfill_ownership()`

- [ ] **Step 3: Append the implementation to `includes/fields.php`**

```php
/** Where the data-shape version is recorded. */
define( 'AFRISTREAM_SCHEMA_OPTION', 'afristream_schema_version' );

/**
 * Current data shape.
 *
 * 1 — ACF's: assignments live only in the usermeta array.
 * 2 — this plugin's: the licence carries its owner, usermeta is a mirror.
 */
define( 'AFRISTREAM_SCHEMA_VERSION', 2 );

/** Where the backfill leaves anything it could not resolve cleanly. */
define( 'AFRISTREAM_CONFLICTS_OPTION', 'afristream_ownership_conflicts' );

/**
 * Point every licence at whoever the old usermeta arrays say holds it.
 *
 * Safe to run repeatedly: a licence that already has an owner is left alone, so
 * a second pass claims nothing and writes no duplicate history.
 *
 * Where two users' arrays both claim the same licence — which the old shape had
 * no way to prevent — the earlier-registered user keeps it. That is a guess, so
 * it is recorded on the licence's own history and in a report the Configurations
 * page surfaces, rather than resolved quietly. Someone has to look at those.
 *
 * @return array{claimed:int,conflicts:array<int,array{license:int,kept:int,rejected:int[]}>}
 */
function afristream_backfill_ownership() {
	$claims = array();

	// Oldest registration first, so the first claim on a licence is the one that
	// wins and the ordering of the report is deterministic.
	$users = get_users(
		array(
			'fields'  => array( 'ID', 'user_registered' ),
			'orderby' => 'registered',
			'order'   => 'ASC',
			'number'  => -1,
		)
	);

	usort(
		$users,
		function ( $a, $b ) {
			$ta = isset( $a->user_registered ) ? strtotime( $a->user_registered ) : 0;
			$tb = isset( $b->user_registered ) ? strtotime( $b->user_registered ) : 0;
			if ( $ta !== $tb ) {
				return $ta <=> $tb;
			}
			return (int) $a->ID <=> (int) $b->ID;
		}
	);

	foreach ( $users as $user ) {
		foreach ( afristream_user_mirror_ids( $user->ID ) as $license_id ) {
			$claims[ $license_id ][] = (int) $user->ID;
		}
	}

	$claimed   = 0;
	$conflicts = array();
	$touched   = array();

	foreach ( $claims as $license_id => $claimants ) {
		$claimants = array_values( array_unique( $claimants ) );

		// A mirror pointing at a licence that has since been deleted. Nothing to
		// claim; the stale reference is cleaned up when the mirror is rebuilt.
		if ( ! get_post_status( $license_id ) ) {
			foreach ( $claimants as $user_id ) {
				$touched[ $user_id ] = true;
			}
			continue;
		}

		$keeper = $claimants[0];

		if ( count( $claimants ) > 1 ) {
			$rejected    = array_values( array_slice( $claimants, 1 ) );
			$conflicts[] = array(
				'license'  => (int) $license_id,
				'kept'     => $keeper,
				'rejected' => $rejected,
			);
			afristream_license_log_add(
				$license_id,
				'conflict',
				$keeper,
				'backfill',
				'also claimed by user ' . implode( ', ', $rejected )
			);
		}

		foreach ( $claimants as $user_id ) {
			$touched[ $user_id ] = true;
		}

		if ( afristream_license_owner( $license_id ) ) {
			continue;
		}

		update_post_meta( $license_id, AFRISTREAM_LICENSE_OWNER_META, $keeper );
		afristream_license_log_add( $license_id, 'assigned', $keeper, 'backfill' );
		$claimed++;
	}

	// Rebuild every affected mirror from the licences, which both corrects the
	// losers of a conflict and drops references to licences that no longer exist.
	foreach ( array_keys( $touched ) as $user_id ) {
		afristream_rebuild_user_mirror( $user_id );
	}

	update_option( AFRISTREAM_CONFLICTS_OPTION, $conflicts );

	return array(
		'claimed'   => $claimed,
		'conflicts' => $conflicts,
	);
}

/**
 * Anything the backfill could not resolve without guessing.
 *
 * @return array<int,array{license:int,kept:int,rejected:int[]}>
 */
function afristream_ownership_conflicts() {
	$stored = get_option( AFRISTREAM_CONFLICTS_OPTION, array() );
	return is_array( $stored ) ? $stored : array();
}

/**
 * Run the backfill once, the first time an admin loads a page after upgrading.
 *
 * On admin_init rather than plugin activation: the plugin is already active on
 * the live site, so an activation hook would never fire.
 */
function afristream_maybe_upgrade() {
	if ( (int) get_option( AFRISTREAM_SCHEMA_OPTION, 1 ) >= AFRISTREAM_SCHEMA_VERSION ) {
		return;
	}

	afristream_backfill_ownership();
	update_option( AFRISTREAM_SCHEMA_OPTION, AFRISTREAM_SCHEMA_VERSION );
}
add_action( 'admin_init', 'afristream_maybe_upgrade' );
```

- [ ] **Step 4: Run the tests and confirm they pass**

Run: `php tests/php/run.php`
Expected: `PHP tests: 39 tests, 111 assertions, all passing`

- [ ] **Step 5: Verify on the live site — the real migration**

This is the step where production data moves. ACF is still active, so both readings can be compared directly.

1. Before deploying, note from **Users** which users currently show which licences in the Active Licenses column.
2. Deploy, then load any wp-admin page as an administrator to trigger `afristream_maybe_upgrade()`.
3. Reload **Users**. The Active Licenses column must show exactly the same names as in step 1.
4. Open **Licenses → All Licenses**. The **Connected User** column must name the same people, and **Last Assigned** must show them with context `backfill`.
5. Open a licence and confirm its **History** box has an `assigned` entry from `backfill`.
6. Confirm the site still reports no conflicts — the Configurations page in Task 11 surfaces them, but for now check directly that `afristream_ownership_conflicts()` returned empty by confirming no licence history shows a `conflict` entry.

If any assignment differs from step 1, stop and report before continuing.

- [ ] **Step 6: Commit**

```bash
git add includes/fields.php tests/php/test-backfill.php
git commit -m "Move the eleven live assignments onto the licences themselves

Everything built so far reads ownership off the licence, and on the live site
no licence has one yet: the real assignments exist only in the old usermeta
arrays. This is the migration that makes the rest of it operate on real data.

Idempotent by design. A licence that already has an owner is skipped, so a
second pass claims nothing and writes no duplicate history, and the whole thing
is gated behind a stored schema version so it runs once.

Where two users' arrays both claim the same licence — which the old shape had
no way to prevent — the earlier-registered user keeps it. That is a guess, so
it goes on the licence's own history and into a report rather than being
resolved quietly, and the loser's mirror is corrected instead of being left
pointing at a licence they no longer hold.

Runs on admin_init rather than activation, because the plugin is already active
on the live site and an activation hook would never fire.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 9: Entitlement and top-up

**Files:**
- Create: `includes/auto-assign.php`
- Create: `tests/php/test-auto-assign.php`
- Modify: `bluegroup-project-afristream.php` (require after `license-admin.php`)

**Interfaces:**
- Consumes: `afristream_user_license_ids()`, `afristream_available_licenses()`, `afristream_assign_license()`.
- Produces:
  - `afristream_entitlement( int $user_id ): int` — how many licences this user should hold
  - `afristream_subscription_count( int $user_id ): int` — SureCart lookup, 0 when SureCart is absent or errors
  - `afristream_topup_user( int $user_id, string $context = 'auto-assign' ): array{assigned:int[],short:int,entitled:int,held:int}`

The `afristream_entitlement` filter is the seam that makes this testable: the harness cannot instantiate SureCart models, so the tests override entitlement through the filter and exercise the top-up arithmetic — which is the part that can actually get allocation wrong.

- [ ] **Step 1: Write the failing tests**

Create `tests/php/test-auto-assign.php`:

```php
<?php
require_once __DIR__ . '/../../includes/fields.php';
require_once __DIR__ . '/../../includes/license-log.php';
require_once __DIR__ . '/../../includes/auto-assign.php';

/** Pin entitlement without SureCart, which the harness cannot instantiate. */
function af_set_entitlement( $map ) {
	add_filter(
		'afristream_entitlement',
		function ( $value, $user_id ) use ( $map ) {
			return isset( $map[ $user_id ] ) ? $map[ $user_id ] : $value;
		},
		10,
		2
	);
}

af_test( 'a user with one subscription gets one licence', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	af_seed_post( 11, 'beta' );
	af_set_entitlement( array( 7 => 1 ) );

	$result = afristream_topup_user( 7 );

	af_assert_same( 1, count( $result['assigned'] ), 'one licence handed out' );
	af_assert_same( 0, $result['short'], 'nothing outstanding' );
	af_assert_same( array( 10 ), afristream_user_license_ids( 7 ), 'the first available one' );
} );

af_test( 'two subscriptions get two licences', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	af_seed_post( 11, 'beta' );
	af_seed_post( 12, 'gamma' );
	af_set_entitlement( array( 7 => 2 ) );

	afristream_topup_user( 7 );
	af_assert_same( array( 10, 11 ), afristream_user_license_ids( 7 ), 'exactly two' );
} );

af_test( 'running the top-up twice assigns nothing the second time', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	af_seed_post( 11, 'beta' );
	af_set_entitlement( array( 7 => 1 ) );

	afristream_topup_user( 7 );
	$second = afristream_topup_user( 7 );

	af_assert_same( array(), $second['assigned'], 'a replayed webhook hands out nothing' );
	af_assert_same( array( 10 ), afristream_user_license_ids( 7 ), 'still exactly one' );
} );

af_test( 'a user already holding their entitlement is left alone', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	update_post_meta( 10, AFRISTREAM_LICENSE_OWNER_META, 7 );
	af_seed_post( 11, 'beta' );
	af_set_entitlement( array( 7 => 1 ) );

	$result = afristream_topup_user( 7 );
	af_assert_same( array(), $result['assigned'], 'nothing assigned' );
	af_assert_same( 0, afristream_license_owner( 11 ), 'the spare stays free' );
} );

af_test( 'a partial top-up assigns what it can and reports the shortfall', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	af_set_entitlement( array( 7 => 3 ) );

	$result = afristream_topup_user( 7 );

	af_assert_same( 1, count( $result['assigned'] ), 'the one available licence is used' );
	af_assert_same( 2, $result['short'], 'and the gap is reported, not swallowed' );
	af_assert_same( 3, $result['entitled'], 'entitlement reported' );
	af_assert_same( 1, $result['held'], 'and what they ended up with' );
} );

af_test( 'no stock at all assigns nothing and reports the whole entitlement short', function () {
	af_seed_user( 7 );
	af_set_entitlement( array( 7 => 2 ) );

	$result = afristream_topup_user( 7 );
	af_assert_same( array(), $result['assigned'], 'nothing to give' );
	af_assert_same( 2, $result['short'], 'both outstanding' );
} );

af_test( 'expired stock is not counted as available', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'expired' );
	update_post_meta( 10, 'expiry_date', '20250101' );
	af_set_entitlement( array( 7 => 1 ) );

	$result = afristream_topup_user( 7 );
	af_assert_same( array(), $result['assigned'], 'an expired licence is not stock' );
	af_assert_same( 1, $result['short'], 'reported short instead' );
} );

af_test( 'someone who has bought nothing is entitled to nothing', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	af_set_entitlement( array( 7 => 0 ) );

	$result = afristream_topup_user( 7 );
	af_assert_same( array(), $result['assigned'], 'no subscription, no licence' );
	af_assert_same( 0, $result['short'], 'and not recorded as short' );
	af_assert_same( 0, afristream_license_owner( 10 ), 'stock untouched' );
} );

af_test( 'the top-up logs how the licence was given out', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	af_set_entitlement( array( 7 => 1 ) );

	afristream_topup_user( 7 );
	$log = afristream_license_log_get( 10 );
	af_assert_same( 'assigned', $log[0]['event'], 'logged' );
	af_assert_same( 'auto-assign', $log[0]['context'], 'and attributed to the automation' );
} );
```

- [ ] **Step 2: Run the tests and watch them fail**

Run: `php tests/php/run.php`
Expected: FAIL — `Failed opening required '.../includes/auto-assign.php'`

- [ ] **Step 3: Write the implementation**

Create `includes/auto-assign.php`:

```php
<?php
/**
 * Giving licences to customers who have paid for them.
 *
 * A user is entitled to one licence per active SureCart subscription. Not per
 * purchase and not by amount: prices change, and an entitlement derived from
 * money would quietly change with them.
 *
 * Everything here tops up rather than grants. On each event it asks how many
 * the user should have, counts how many they do have, and closes the gap — so
 * running it twice, or a webhook arriving twice, hands out nothing the second
 * time. That property is what makes it safe to hook to more than one event.
 *
 * @package bluegroup-project-afristream
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How many active subscriptions this user has.
 *
 * SureCart joins to WordPress through the customer record, so this is two
 * lookups: the customer for the user, then their subscriptions. Guarded with
 * class_exists() in the same style as includes/affiliates.php, so the feature
 * degrades to "assigns nothing" rather than fatally when SureCart is absent.
 *
 * @param int $user_id User ID.
 * @return int
 */
function afristream_subscription_count( $user_id ) {
	if ( ! class_exists( '\SureCart\Models\Subscription' ) || ! class_exists( '\SureCart\Models\Customer' ) ) {
		return 0;
	}

	$user = get_userdata( (int) $user_id );
	if ( ! $user || empty( $user->user_email ) ) {
		return 0;
	}

	$customers = \SureCart\Models\Customer::where( array( 'user_ids' => array( (int) $user_id ) ) )->get();
	if ( is_wp_error( $customers ) || empty( $customers ) ) {
		return 0;
	}

	$customer_ids = array();
	foreach ( (array) $customers as $customer ) {
		$id = afristream_affiliate_prop( $customer, 'id', '' );
		if ( $id ) {
			$customer_ids[] = $id;
		}
	}

	if ( empty( $customer_ids ) ) {
		return 0;
	}

	$subscriptions = \SureCart\Models\Subscription::where(
		array(
			'customer_ids' => $customer_ids,
			'status'       => array( 'active', 'trialing' ),
		)
	)->get();

	if ( is_wp_error( $subscriptions ) || empty( $subscriptions ) ) {
		return 0;
	}

	// Confirm the status rather than trusting the API's filter — the same
	// caution afristream_affiliate_lookup() applies to its own query.
	$count = 0;
	foreach ( (array) $subscriptions as $subscription ) {
		$status = (string) afristream_affiliate_prop( $subscription, 'status', '' );
		if ( in_array( $status, array( 'active', 'trialing' ), true ) ) {
			$count++;
		}
	}

	return $count;
}

/**
 * How many licences a user should hold.
 *
 * Filterable so the count can be tested without SureCart, and so a future
 * arrangement — a staff account, a bundled plan — can be expressed without
 * changing the allocation logic.
 *
 * @param int $user_id User ID.
 * @return int
 */
function afristream_entitlement( $user_id ) {
	$count = afristream_subscription_count( $user_id );
	return max( 0, (int) apply_filters( 'afristream_entitlement', $count, (int) $user_id ) );
}

/**
 * Bring a user up to their entitlement, as far as stock allows.
 *
 * @param int    $user_id User ID.
 * @param string $context Where the call came from, for the log.
 * @return array{assigned:int[],short:int,entitled:int,held:int}
 */
function afristream_topup_user( $user_id, $context = 'auto-assign' ) {
	$user_id  = (int) $user_id;
	$entitled = afristream_entitlement( $user_id );
	$held     = count( afristream_user_license_ids( $user_id ) );
	$assigned = array();

	$wanted = $entitled - $held;
	if ( $wanted <= 0 ) {
		return array(
			'assigned' => array(),
			'short'    => 0,
			'entitled' => $entitled,
			'held'     => $held,
		);
	}

	// Ask for more candidates than needed: another request may claim one between
	// this list being built and the assignment being attempted, and a refusal
	// should cost a retry rather than the whole top-up.
	foreach ( afristream_available_licenses( $wanted + 3 ) as $license_id ) {
		if ( count( $assigned ) >= $wanted ) {
			break;
		}
		if ( true === afristream_assign_license( $license_id, $user_id, $context ) ) {
			$assigned[] = $license_id;
		}
	}

	$held += count( $assigned );

	return array(
		'assigned' => $assigned,
		'short'    => max( 0, $entitled - $held ),
		'entitled' => $entitled,
		'held'     => $held,
	);
}
```

- [ ] **Step 4: Require it from the plugin**

Modify `bluegroup-project-afristream.php` — the require block becomes:

```php
require_once plugin_dir_path( __FILE__ ) . 'includes/fields.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/license-log.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/license-admin.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/licenses.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/shortcodes.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/affiliates.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/auto-assign.php';
```

`auto-assign.php` loads after `affiliates.php` because it calls `afristream_affiliate_prop()`.

- [ ] **Step 5: Run the tests and confirm they pass**

Run: `php tests/php/run.php`
Expected: `PHP tests: 48 tests, 133 assertions, all passing`

- [ ] **Step 6: Verify the SureCart lookup against the live site**

The model calls above follow the idiom already working in `includes/affiliates.php`, but the Customer and Subscription queries are new and must be confirmed rather than assumed.

1. In wp-admin, open **SureCart → Customers** and note a customer who has an active subscription and a linked WordPress user.
2. Add this temporary snippet to the top of `afristream_portal_render_settings_page()` in `bluegroup-project-afristream.php`:

```php
	if ( isset( $_GET['afristream_debug_entitlement'] ) ) {
		$uid = (int) $_GET['afristream_debug_entitlement'];
		echo '<pre>subscriptions: ' . (int) afristream_subscription_count( $uid ) . '</pre>';
	}
```

3. Visit `/wp-admin/options-general.php?page=bluegroup-project-afristream&afristream_debug_entitlement=<user id>` and confirm the number matches what SureCart shows for that customer.
4. Check a user with no subscription returns `0`.
5. **Remove the snippet.** It must not be committed.

If the count is wrong, the fix belongs in `afristream_subscription_count()` only — `afristream_topup_user()` and its tests do not change.

- [ ] **Step 7: Commit**

```bash
git add includes/auto-assign.php tests/php/test-auto-assign.php bluegroup-project-afristream.php
git commit -m "Give paying customers a licence each, one per active subscription

Entitlement is the count of active subscriptions, not purchase quantity and not
amount. Prices change, and an entitlement derived from money would quietly
change with them.

The routine tops up rather than grants: it asks how many the user should have,
counts how many they do have, and closes the gap. Running it twice hands out
nothing the second time, which is what makes it safe to hook to more than one
SureCart event and what stops a replayed webhook draining stock.

A shortfall is returned rather than swallowed, so the queue in the next commit
has something to act on. It asks for a few more candidate licences than it
needs, because another request may claim one between the list being built and
the assignment being attempted, and that should cost a retry rather than the
whole top-up.

Entitlement goes through a filter so the arithmetic can be tested without
SureCart, which the test harness cannot instantiate.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 10: SureCart triggers, the pending queue, and over-allocation

**Files:**
- Modify: `includes/auto-assign.php` (append)
- Create: `tests/php/test-pending.php`

**Interfaces:**
- Consumes: `afristream_topup_user()`, `afristream_entitlement()`, `afristream_user_license_ids()`, `afristream_available_licenses()`.
- Produces:
  - `AFRISTREAM_PENDING_OPTION` = `'afristream_pending_licenses'`
  - `afristream_pending_all(): array<int,array{user:int,short:int,since:int}>` — oldest first
  - `afristream_pending_set( int $user_id, int $short ): void` — records, updates or clears
  - `afristream_pending_drain(): int` — returns how many licences it managed to hand out
  - `afristream_over_allocated(): array<int,array{user:int,entitled:int,held:int,surplus:int[]}>`
  - `afristream_mirror_mismatches(): int[]` — users whose mirror disagrees with the licences
  - `afristream_autoassign_status(): array{state:string,label:string}` — for the Configurations page
  - `afristream_user_id_from_surecart( mixed $object ): int`

- [ ] **Step 1: Write the failing tests**

Create `tests/php/test-pending.php`:

```php
<?php
require_once __DIR__ . '/../../includes/fields.php';
require_once __DIR__ . '/../../includes/license-log.php';
require_once __DIR__ . '/../../includes/auto-assign.php';

af_test( 'a shortfall is queued with who and how many', function () {
	af_seed_user( 7 );
	afristream_pending_set( 7, 2 );

	$pending = afristream_pending_all();
	af_assert_same( 1, count( $pending ), 'one entry' );
	af_assert_same( 7, $pending[0]['user'], 'the user' );
	af_assert_same( 2, $pending[0]['short'], 'and the shortfall' );
	af_assert_same( 1785024000, $pending[0]['since'], 'stamped when it happened' );
} );

af_test( 'queueing the same user again updates rather than duplicates', function () {
	afristream_pending_set( 7, 2 );
	af_set_now( 1785024000 + 600 );
	afristream_pending_set( 7, 1 );

	$pending = afristream_pending_all();
	af_assert_same( 1, count( $pending ), 'still one entry' );
	af_assert_same( 1, $pending[0]['short'], 'shortfall updated' );
	af_assert_same( 1785024000, $pending[0]['since'], 'but the original wait is preserved' );
} );

af_test( 'a shortfall of zero clears the entry', function () {
	afristream_pending_set( 7, 2 );
	afristream_pending_set( 7, 0 );
	af_assert_same( array(), afristream_pending_all(), 'cleared' );
} );

af_test( 'the queue comes out oldest first', function () {
	afristream_pending_set( 7, 1 );
	af_set_now( 1785024000 + 600 );
	afristream_pending_set( 8, 1 );

	$pending = afristream_pending_all();
	af_assert_same( 7, $pending[0]['user'], 'the one who has waited longest' );
	af_assert_same( 8, $pending[1]['user'], 'then the newer one' );
} );

af_test( 'draining serves the longest wait first when stock is short', function () {
	af_seed_user( 7 );
	af_seed_user( 8 );
	af_set_entitlement( array( 7 => 1, 8 => 1 ) );

	afristream_pending_set( 7, 1 );
	af_set_now( 1785024000 + 600 );
	afristream_pending_set( 8, 1 );

	af_seed_post( 10, 'the only licence' );

	af_assert_same( 1, afristream_pending_drain(), 'one licence handed out' );
	af_assert_same( array( 10 ), afristream_user_license_ids( 7 ), 'to whoever waited longest' );
	af_assert_same( array(), afristream_user_license_ids( 8 ), 'the newer wait keeps waiting' );

	$pending = afristream_pending_all();
	af_assert_same( 1, count( $pending ), 'and stays queued' );
	af_assert_same( 8, $pending[0]['user'], 'as the only one left' );
} );

af_test( 'draining with no stock leaves the queue exactly as it was', function () {
	af_seed_user( 7 );
	af_set_entitlement( array( 7 => 1 ) );
	afristream_pending_set( 7, 1 );

	af_assert_same( 0, afristream_pending_drain(), 'nothing handed out' );
	af_assert_same( 1, count( afristream_pending_all() ), 'still queued' );
} );

af_test( 'a queued user whose subscription lapsed is dropped, not given a licence', function () {
	af_seed_user( 7 );
	af_set_entitlement( array( 7 => 0 ) );
	afristream_pending_set( 7, 1 );
	af_seed_post( 10, 'alpha' );

	af_assert_same( 0, afristream_pending_drain(), 'nothing handed out' );
	af_assert_same( array(), afristream_pending_all(), 'and they leave the queue' );
	af_assert_same( 0, afristream_license_owner( 10 ), 'stock untouched' );
} );

af_test( 'holding more than the entitlement is reported and never revoked', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	af_seed_post( 11, 'beta' );
	update_post_meta( 10, AFRISTREAM_LICENSE_OWNER_META, 7 );
	update_post_meta( 11, AFRISTREAM_LICENSE_OWNER_META, 7 );
	af_set_entitlement( array( 7 => 1 ) );

	$over = afristream_over_allocated();
	af_assert_same( 1, count( $over ), 'one user flagged' );
	af_assert_same( 7, $over[0]['user'], 'named' );
	af_assert_same( 1, $over[0]['entitled'], 'entitlement shown' );
	af_assert_same( 2, $over[0]['held'], 'against what they hold' );
	af_assert_same( array( 11 ), $over[0]['surplus'], 'and the surplus is the most recently acquired' );

	af_assert_same( 7, afristream_license_owner( 11 ), 'and nothing was taken away' );
} );

af_test( 'a mirror that disagrees with the licences is reported', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	update_post_meta( 10, AFRISTREAM_LICENSE_OWNER_META, 7 );
	update_user_meta( 7, AFRISTREAM_USER_LICENSE_META, array( '10', '99' ) );

	af_assert_same( array( 7 ), afristream_mirror_mismatches(), 'the disagreement surfaces' );

	afristream_rebuild_user_mirror( 7 );
	af_assert_same( array(), afristream_mirror_mismatches(), 'and clears once rebuilt' );
} );

af_test( 'a SureCart object yields a user id however it names it', function () {
	af_assert_same( 7, afristream_user_id_from_surecart( (object) array( 'user_id' => 7 ) ), 'direct user_id' );
	af_assert_same( 7, afristream_user_id_from_surecart( array( 'user_id' => '7' ) ), 'array form, string value' );
	af_assert_same( 7, afristream_user_id_from_surecart( (object) array( 'customer' => (object) array( 'user_id' => 7 ) ) ), 'nested on the customer' );
	af_assert_same( 0, afristream_user_id_from_surecart( (object) array( 'nothing' => 1 ) ), 'nothing usable gives 0' );
	af_assert_same( 0, afristream_user_id_from_surecart( null ), 'null is safe' );
} );
```

Note: `af_set_entitlement()` is defined in `tests/php/test-auto-assign.php`, which the runner loads first because `glob()` returns alphabetically and `test-auto-assign.php` sorts before `test-pending.php`. Do not redefine it.

- [ ] **Step 2: Run the tests and watch them fail**

Run: `php tests/php/run.php`
Expected: FAIL — `Call to undefined function afristream_pending_set()`

- [ ] **Step 3: Append the implementation to `includes/auto-assign.php`**

```php
/** Users owed a licence that stock could not cover. */
define( 'AFRISTREAM_PENDING_OPTION', 'afristream_pending_licenses' );

/**
 * Find the WordPress user behind a SureCart object.
 *
 * SureCart hands different shapes to different events — sometimes the purchase
 * carries user_id directly, sometimes only its customer does — so this looks in
 * both places rather than assuming one.
 *
 * @param mixed $object Purchase, subscription, model, array or null.
 * @return int User ID, or 0 when there is nothing usable.
 */
function afristream_user_id_from_surecart( $object ) {
	if ( empty( $object ) ) {
		return 0;
	}

	$direct = (int) afristream_affiliate_prop( $object, 'user_id', 0 );
	if ( $direct ) {
		return $direct;
	}

	$customer = afristream_affiliate_prop( $object, 'customer', null );
	if ( ! empty( $customer ) ) {
		return (int) afristream_affiliate_prop( $customer, 'user_id', 0 );
	}

	return 0;
}

/**
 * Everyone waiting for a licence, longest wait first.
 *
 * @return array<int,array{user:int,short:int,since:int}>
 */
function afristream_pending_all() {
	$stored = get_option( AFRISTREAM_PENDING_OPTION, array() );
	if ( ! is_array( $stored ) || empty( $stored ) ) {
		return array();
	}

	$pending = array();
	foreach ( $stored as $entry ) {
		if ( empty( $entry['user'] ) || empty( $entry['short'] ) ) {
			continue;
		}
		$pending[] = array(
			'user'  => (int) $entry['user'],
			'short' => (int) $entry['short'],
			'since' => (int) ( isset( $entry['since'] ) ? $entry['since'] : 0 ),
		);
	}

	usort(
		$pending,
		function ( $a, $b ) {
			if ( $a['since'] !== $b['since'] ) {
				return $a['since'] <=> $b['since'];
			}
			return $a['user'] <=> $b['user'];
		}
	);

	return $pending;
}

/**
 * Record, update or clear what a user is owed.
 *
 * An existing entry keeps its original timestamp when the shortfall changes, so
 * partially filling someone's order does not send them to the back of the queue.
 *
 * @param int $user_id User ID.
 * @param int $short   Licences still owed; 0 removes them from the queue.
 * @return void
 */
function afristream_pending_set( $user_id, $short ) {
	$user_id = (int) $user_id;
	$short   = max( 0, (int) $short );
	if ( ! $user_id ) {
		return;
	}

	$pending = afristream_pending_all();
	$out     = array();
	$since   = (int) current_time( 'timestamp' );

	foreach ( $pending as $entry ) {
		if ( $entry['user'] === $user_id ) {
			$since = $entry['since'] ? $entry['since'] : $since;
			continue;
		}
		$out[] = $entry;
	}

	if ( $short > 0 ) {
		$out[] = array(
			'user'  => $user_id,
			'short' => $short,
			'since' => $since,
		);
	}

	update_option( AFRISTREAM_PENDING_OPTION, $out );
}

/**
 * Hand out whatever stock exists to whoever has waited longest.
 *
 * Each user's entitlement is re-checked rather than trusting the queued number:
 * a subscription may have lapsed while they waited, and giving a licence to
 * someone who has since cancelled would be worse than the original shortage.
 *
 * @return int Licences assigned.
 */
function afristream_pending_drain() {
	$assigned = 0;

	foreach ( afristream_pending_all() as $entry ) {
		if ( empty( afristream_available_licenses( 1 ) ) ) {
			break;
		}

		$result = afristream_topup_user( $entry['user'], 'auto-assign-queued' );
		$assigned += count( $result['assigned'] );
		afristream_pending_set( $entry['user'], $result['short'] );
	}

	return $assigned;
}

/**
 * Bring a user up to date, queueing whatever stock could not cover.
 *
 * @param int    $user_id User ID.
 * @param string $context For the log.
 * @return void
 */
function afristream_autoassign_for_user( $user_id, $context = 'auto-assign' ) {
	$user_id = (int) $user_id;
	if ( ! $user_id ) {
		return;
	}

	$result = afristream_topup_user( $user_id, $context );
	afristream_pending_set( $user_id, $result['short'] );
}

/**
 * React to a SureCart purchase or subscription.
 *
 * Both events funnel here because either can be the first moment a customer is
 * genuinely paid-up, and the top-up is idempotent so being told twice costs
 * nothing.
 *
 * @param mixed $object The SureCart model the event carried.
 */
function afristream_autoassign_from_surecart( $object ) {
	$user_id = afristream_user_id_from_surecart( $object );
	if ( $user_id ) {
		afristream_autoassign_for_user( $user_id );
	}
}
add_action( 'surecart/purchase_created', 'afristream_autoassign_from_surecart' );
add_action( 'surecart/subscription_created', 'afristream_autoassign_from_surecart' );

/**
 * Serve the queue whenever stock appears.
 *
 * @param int $post_id Licence that was published or updated.
 */
function afristream_drain_on_license_change( $post_id ) {
	if ( 'license' !== get_post_type( $post_id ) ) {
		return;
	}
	afristream_pending_drain();
}
add_action( 'save_post_license', 'afristream_drain_on_license_change', 20 );
add_action( 'delete_user', 'afristream_pending_drain', 20 );

/**
 * Users holding more licences than they are entitled to.
 *
 * Reported, never acted on. A lapsed subscription is often a failed card that
 * recovers within days, and taking a paying-then-briefly-lapsed customer's
 * access away automatically is a decision that belongs to a person.
 *
 * The surplus named is the most recently acquired, since that is the one most
 * likely to be the mistake.
 *
 * @return array<int,array{user:int,entitled:int,held:int,surplus:int[]}>
 */
function afristream_over_allocated() {
	$over = array();

	$holders = array();
	foreach ( afristream_all_license_ids() as $license_id ) {
		$owner = afristream_license_owner( $license_id );
		if ( $owner ) {
			$holders[ $owner ][] = $license_id;
		}
	}

	foreach ( $holders as $user_id => $license_ids ) {
		$entitled = afristream_entitlement( $user_id );
		$held     = count( $license_ids );

		if ( $held <= $entitled ) {
			continue;
		}

		sort( $license_ids );
		$over[] = array(
			'user'     => (int) $user_id,
			'entitled' => $entitled,
			'held'     => $held,
			'surplus'  => array_values( array_slice( $license_ids, $entitled ) ),
		);
	}

	usort(
		$over,
		function ( $a, $b ) {
			return $a['user'] <=> $b['user'];
		}
	);

	return $over;
}

/**
 * Users whose usermeta mirror disagrees with the licences pointing at them.
 *
 * Should always be empty. When it is not, something wrote the mirror directly
 * instead of going through the assignment functions — worth knowing about
 * rather than discovering through a customer seeing the wrong credentials.
 *
 * @return int[] User IDs.
 */
function afristream_mirror_mismatches() {
	$mismatched = array();

	foreach ( get_users( array( 'fields' => array( 'ID' ), 'number' => -1 ) ) as $user ) {
		$user_id = (int) $user->ID;
		$truth   = afristream_user_license_ids( $user_id );
		$mirror  = afristream_user_mirror_ids( $user_id );

		if ( empty( $truth ) && empty( $mirror ) ) {
			continue;
		}
		if ( $truth !== $mirror ) {
			$mismatched[] = $user_id;
		}
	}

	sort( $mismatched );
	return $mismatched;
}

/**
 * A one-line health summary for the Configurations page.
 *
 * @return array{state:string,label:string}
 */
function afristream_autoassign_status() {
	if ( ! class_exists( '\SureCart\Models\Subscription' ) ) {
		return array(
			'state' => 'off',
			'label' => __( 'SureCart inactive — nothing is assigned automatically', 'bluegroup-project-afristream' ),
		);
	}

	$pending   = afristream_pending_all();
	$available = count( afristream_available_licenses() );

	if ( ! empty( $pending ) ) {
		return array(
			'state' => 'warn',
			/* translators: %d: number of users waiting. */
			'label' => sprintf( _n( '%d user awaiting a licence', '%d users awaiting a licence', count( $pending ), 'bluegroup-project-afristream' ), count( $pending ) ),
		);
	}

	return array(
		'state' => 'ok',
		/* translators: %d: number of free licences. */
		'label' => sprintf( _n( 'Active — %d licence available', 'Active — %d licences available', $available, 'bluegroup-project-afristream' ), $available ),
	);
}
```

- [ ] **Step 4: Run the tests and confirm they pass**

Run: `php tests/php/run.php`
Expected: `PHP tests: 58 tests, 164 assertions, all passing`

- [ ] **Step 5: Verify the trigger names against SureCart**

The two action names must be real, or auto-assign silently never fires — the worst possible failure here, because nothing looks broken.

1. Add this temporary line to `includes/auto-assign.php`:

```php
add_action( 'all', function ( $tag ) { if ( 0 === strpos( (string) $tag, 'surecart/' ) ) { error_log( 'SURECART HOOK: ' . $tag ); } } );
```

2. Make a test purchase on the live site (SureCart test mode).
3. Check the PHP error log for `SURECART HOOK:` lines and confirm `surecart/purchase_created` and `surecart/subscription_created` appear. If they are named differently, correct the two `add_action()` calls to the real names.
4. **Remove the temporary line.**

- [ ] **Step 6: Verify assignment end to end**

1. Ensure at least one free, unexpired licence exists.
2. Make a test purchase as a user holding no licence.
3. Confirm the user now holds one, its **History** shows `assigned` with context `auto-assign`, and the portal's Profile tab shows the credentials.
4. Set every licence to expired or assigned, make another test purchase, and confirm the user is queued rather than given anything.
5. Publish a new licence and confirm the queued user is served automatically.

- [ ] **Step 7: Commit**

```bash
git add includes/auto-assign.php tests/php/test-pending.php
git commit -m "Queue customers when stock runs out, and never revoke automatically

A purchase that lands with no licence free is recorded rather than lost, and the
queue is served oldest-first the moment stock appears — when a licence is
published, updated, or freed by a user being deleted.

Draining re-checks entitlement rather than trusting the queued number. A
subscription may have lapsed while someone waited, and handing a licence to
somebody who has since cancelled would be worse than the original shortage.
Partially filling an order keeps the original timestamp, so it does not send
that customer to the back of the queue.

Over-allocation is reported and never acted on. A lapsed subscription is often
a failed card that recovers within days, and taking a briefly-lapsed customer's
access away automatically is a decision that belongs to a person.

Also reports any user whose usermeta mirror disagrees with the licences
pointing at them. That should never happen; if it does, something wrote the
mirror directly instead of going through the assignment functions, and it is
better to know than to find out through a customer seeing the wrong credentials.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 11: The Configurations page

**Files:**
- Create: `includes/configurations.php`
- Create: `tests/php/test-registry.php`
- Modify: `bluegroup-project-afristream.php` (require last, so every other file has registered its entries)

**Interfaces:**
- Consumes: `afristream_available_licenses()`, `afristream_all_license_ids()`, `afristream_pending_all()`, `afristream_over_allocated()`, `afristream_mirror_mismatches()`, `afristream_ownership_conflicts()`, `afristream_license_log_recent()`, `afristream_autoassign_status()`.
- Produces:
  - `afristream_registry(): array` — grouped, sorted registry entries
  - `afristream_registry_groups(): string[]` — display order
  - `afristream_acf_audit(): array{elementor:array,plugins:array,acf_posts:array,safe:bool}`
  - Filter `afristream_registry` — every feature file adds its entries here (Task 12)

Entry shape:

```php
array(
	'group'  => 'Licences',        // one of afristream_registry_groups()
	'name'   => 'Auto-assign on purchase',
	'type'   => 'hook',            // shortcode|rest|column|field|hook|integration|setting|page
	'handle' => 'surecart/purchase_created',
	'file'   => 'includes/auto-assign.php',
	'status' => array( 'state' => 'ok', 'label' => 'Active — 3 licences available' ), // optional
)
```

- [ ] **Step 1: Write the failing tests**

Create `tests/php/test-registry.php`:

```php
<?php
require_once __DIR__ . '/../../includes/fields.php';
require_once __DIR__ . '/../../includes/license-log.php';
require_once __DIR__ . '/../../includes/auto-assign.php';
require_once __DIR__ . '/../../includes/configurations.php';

af_test( 'the registry is built from the filter, not a hand-written list', function () {
	add_filter(
		'afristream_registry',
		function ( $items ) {
			$items[] = array(
				'group'  => 'Licences',
				'name'   => 'A thing',
				'type'   => 'hook',
				'handle' => 'some/hook',
				'file'   => 'includes/x.php',
			);
			return $items;
		}
	);

	$registry = afristream_registry();
	af_assert_same( 1, count( $registry ), 'the contributed entry is there' );
	af_assert_same( 'A thing', $registry[0]['name'], 'intact' );
} );

af_test( 'entries are grouped in display order, not registration order', function () {
	add_filter(
		'afristream_registry',
		function ( $items ) {
			$items[] = array( 'group' => 'Admin UI', 'name' => 'Zed', 'type' => 'column', 'handle' => 'z', 'file' => 'f.php' );
			$items[] = array( 'group' => 'Portal', 'name' => 'Alpha', 'type' => 'shortcode', 'handle' => 'a', 'file' => 'f.php' );
			$items[] = array( 'group' => 'Portal', 'name' => 'Beta', 'type' => 'rest', 'handle' => 'b', 'file' => 'f.php' );
			return $items;
		}
	);

	$registry = afristream_registry();
	af_assert_same( 'Portal', $registry[0]['group'], 'Portal leads regardless of when it registered' );
	af_assert_same( 'Alpha', $registry[0]['name'], 'and sorts by name within its group' );
	af_assert_same( 'Beta', $registry[1]['name'], 'second' );
	af_assert_same( 'Admin UI', $registry[2]['group'], 'Admin UI comes later' );
} );

af_test( 'an entry missing its required parts is dropped rather than rendered broken', function () {
	add_filter(
		'afristream_registry',
		function ( $items ) {
			$items[] = array( 'group' => 'Portal', 'name' => 'Good', 'type' => 'rest', 'handle' => 'g', 'file' => 'f.php' );
			$items[] = array( 'group' => 'Portal', 'type' => 'rest' ); // no name
			$items[] = array( 'name' => 'No group', 'type' => 'rest' );
			return $items;
		}
	);

	$registry = afristream_registry();
	af_assert_same( 1, count( $registry ), 'only the complete entry survives' );
	af_assert_same( 'Good', $registry[0]['name'], 'the right one' );
} );

af_test( 'an unknown group is kept and sorted last rather than discarded', function () {
	add_filter(
		'afristream_registry',
		function ( $items ) {
			$items[] = array( 'group' => 'Something New', 'name' => 'X', 'type' => 'hook', 'handle' => 'x', 'file' => 'f.php' );
			$items[] = array( 'group' => 'Portal', 'name' => 'Y', 'type' => 'hook', 'handle' => 'y', 'file' => 'f.php' );
			return $items;
		}
	);

	$registry = afristream_registry();
	af_assert_same( 2, count( $registry ), 'nothing is lost' );
	af_assert_same( 'Portal', $registry[0]['group'], 'known groups first' );
	af_assert_same( 'Something New', $registry[1]['group'], 'unknown group still shown' );
} );

af_test( 'licence stock is counted for the page header', function () {
	af_seed_post( 10, 'free' );
	af_seed_post( 11, 'taken' );
	update_post_meta( 11, AFRISTREAM_LICENSE_OWNER_META, 7 );
	af_seed_post( 12, 'expired' );
	update_post_meta( 12, 'expiry_date', '20250101' );

	$stock = afristream_license_stock();
	af_assert_same( 3, $stock['total'], 'every published licence' );
	af_assert_same( 1, $stock['available'], 'one free and unexpired' );
	af_assert_same( 1, $stock['assigned'], 'one held' );
	af_assert_same( 1, $stock['expired'], 'one expired' );
} );
```

- [ ] **Step 2: Run the tests and watch them fail**

Run: `php tests/php/run.php`
Expected: FAIL — `Failed opening required '.../includes/configurations.php'`

- [ ] **Step 3: Write the implementation**

Create `includes/configurations.php`:

```php
<?php
/**
 * One page that says what this plugin actually does to the site.
 *
 * Read-only on purpose. The value is in being able to trust it, and a page with
 * switches on it is a page that can turn something off by accident.
 *
 * The list is not written here. Each feature file contributes its own entries
 * through the afristream_registry filter, so the page cannot drift from the
 * code the way a hand-maintained list does the first time someone adds a
 * feature and forgets. Status is computed on each load rather than stored, for
 * the same reason.
 *
 * @package bluegroup-project-afristream
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The groups, in the order they are shown.
 *
 * @return string[]
 */
function afristream_registry_groups() {
	return array( 'Portal', 'Licences', 'Affiliates', 'Content sources', 'Admin UI' );
}

/**
 * Everything the plugin registers, grouped and sorted for display.
 *
 * @return array<int,array>
 */
function afristream_registry() {
	$items  = apply_filters( 'afristream_registry', array() );
	$groups = afristream_registry_groups();
	$clean  = array();

	foreach ( (array) $items as $item ) {
		// An entry without a group, a name and a type cannot be rendered
		// usefully. Dropping it is better than a row of blanks that reads as a
		// broken feature.
		if ( empty( $item['group'] ) || empty( $item['name'] ) || empty( $item['type'] ) ) {
			continue;
		}

		$clean[] = array(
			'group'  => (string) $item['group'],
			'name'   => (string) $item['name'],
			'type'   => (string) $item['type'],
			'handle' => isset( $item['handle'] ) ? (string) $item['handle'] : '',
			'file'   => isset( $item['file'] ) ? (string) $item['file'] : '',
			'status' => isset( $item['status'] ) && is_array( $item['status'] ) ? $item['status'] : null,
		);
	}

	usort(
		$clean,
		function ( $a, $b ) use ( $groups ) {
			$ia = array_search( $a['group'], $groups, true );
			$ib = array_search( $b['group'], $groups, true );

			// A group nobody declared is still shown — just last. Silently
			// dropping it would make the page lie about coverage.
			$ia = ( false === $ia ) ? count( $groups ) : $ia;
			$ib = ( false === $ib ) ? count( $groups ) : $ib;

			if ( $ia !== $ib ) {
				return $ia <=> $ib;
			}
			if ( $a['group'] !== $b['group'] ) {
				return strcmp( $a['group'], $b['group'] );
			}
			return strcmp( $a['name'], $b['name'] );
		}
	);

	return $clean;
}

/**
 * How the licence stock stands right now.
 *
 * @return array{total:int,available:int,assigned:int,expired:int}
 */
function afristream_license_stock() {
	$total     = 0;
	$available = 0;
	$assigned  = 0;
	$expired   = 0;
	$today     = current_time( 'Ymd' );

	foreach ( afristream_all_license_ids() as $license_id ) {
		$total++;

		if ( afristream_license_owner( $license_id ) ) {
			$assigned++;
			continue;
		}

		$expiry = afristream_license_expiry_ymd( $license_id );
		if ( '' !== $expiry && $expiry < $today ) {
			$expired++;
			continue;
		}

		$available++;
	}

	return array(
		'total'     => $total,
		'available' => $available,
		'assigned'  => $assigned,
		'expired'   => $expired,
	);
}

/**
 * What still depends on ACF, so it can be retired without guessing.
 *
 * Deactivating ACF blind would break any Elementor page using an ACF dynamic
 * tag, and there is no way to tell from this repo alone. This looks.
 *
 * @return array{elementor:array<int,array{id:int,title:string,type:string}>,plugins:string[],acf_posts:array<int,array{id:int,title:string,type:string}>,safe:bool}
 */
function afristream_acf_audit() {
	global $wpdb;

	$elementor = array();
	$acf_posts = array();
	$plugins   = array();

	// Elementor stores its tree as JSON in _elementor_data; an ACF dynamic tag
	// appears in it as an "acf-" prefixed name.
	$rows = $wpdb->get_results(
		"SELECT p.ID, p.post_title, p.post_type
		 FROM {$wpdb->postmeta} m
		 INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
		 WHERE m.meta_key = '_elementor_data'
		   AND m.meta_value LIKE '%acf-%'
		   AND p.post_status != 'trash'"
	);
	foreach ( (array) $rows as $row ) {
		$elementor[] = array(
			'id'    => (int) $row->ID,
			'title' => (string) $row->post_title,
			'type'  => (string) $row->post_type,
		);
	}

	// ACF's own field groups, post types and taxonomies. These must be deleted
	// before ACF is deactivated, or ACF and this plugin both register the
	// licence post type and the editor shows two of every field.
	$acf_rows = $wpdb->get_results(
		"SELECT ID, post_title, post_type
		 FROM {$wpdb->posts}
		 WHERE post_type IN ( 'acf-field-group', 'acf-post-type', 'acf-taxonomy' )
		   AND post_status != 'trash'"
	);
	foreach ( (array) $acf_rows as $row ) {
		$acf_posts[] = array(
			'id'    => (int) $row->ID,
			'title' => (string) $row->post_title,
			'type'  => (string) $row->post_type,
		);
	}

	// Other active plugins calling ACF's API. The theme is checked too.
	$paths = array();
	foreach ( (array) get_option( 'active_plugins', array() ) as $plugin ) {
		if ( 0 === strpos( $plugin, 'advanced-custom-fields' ) || 0 === strpos( $plugin, 'bluegroup-project-afristream' ) ) {
			continue;
		}
		$paths[ $plugin ] = WP_PLUGIN_DIR . '/' . dirname( $plugin );
	}
	$paths['(theme) ' . get_stylesheet()] = get_stylesheet_directory();

	foreach ( $paths as $label => $dir ) {
		if ( ! is_dir( $dir ) || afristream_dir_uses_acf( $dir ) ) {
			if ( is_dir( $dir ) ) {
				$plugins[] = (string) $label;
			}
		}
	}

	return array(
		'elementor' => $elementor,
		'plugins'   => $plugins,
		'acf_posts' => $acf_posts,
		'safe'      => empty( $elementor ) && empty( $plugins ),
	);
}

/**
 * Whether any PHP file under a directory calls ACF's API.
 *
 * Depth-limited and short-circuiting: this runs on an admin page load, not a
 * build step, and reading every line of every plugin would be felt.
 *
 * @param string $dir Directory to scan.
 * @return bool
 */
function afristream_dir_uses_acf( $dir ) {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::LEAVES_ONLY
	);

	$checked = 0;
	foreach ( $iterator as $file ) {
		if ( 'php' !== strtolower( $file->getExtension() ) ) {
			continue;
		}
		if ( ++$checked > 800 ) {
			break;
		}
		if ( $file->getSize() > 2 * MB_IN_BYTES ) {
			continue;
		}

		$contents = file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false !== $contents && preg_match( '/\b(get_field|the_field|have_rows|get_sub_field|acf_add_local_field_group)\s*\(/', $contents ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Add the Configurations menu.
 */
function afristream_add_configurations_page() {
	add_menu_page(
		__( 'Configurations', 'bluegroup-project-afristream' ),
		__( 'Configurations', 'bluegroup-project-afristream' ),
		'manage_options',
		'afristream-configurations',
		'afristream_render_configurations_page',
		'dashicons-list-view',
		58
	);
}
add_action( 'admin_menu', 'afristream_add_configurations_page' );

/**
 * A coloured status pill, or an em dash when a feature reports no status.
 *
 * @param array|null $status Status array.
 * @return string
 */
function afristream_status_pill( $status ) {
	if ( empty( $status['label'] ) ) {
		return '<span style="color:#6b7280;">—</span>';
	}

	$colours = array(
		'ok'   => array( '#dcfce7', '#166534' ),
		'warn' => array( '#fef9c3', '#854d0e' ),
		'off'  => array( '#f3f4f6', '#374151' ),
		'bad'  => array( '#fee2e2', '#b91c1c' ),
	);
	$state = isset( $status['state'] ) && isset( $colours[ $status['state'] ] ) ? $status['state'] : 'off';
	list( $bg, $fg ) = $colours[ $state ];

	return '<span style="display:inline-block; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:600; line-height:1; background:' . esc_attr( $bg ) . '; color:' . esc_attr( $fg ) . ';">' . esc_html( $status['label'] ) . '</span>';
}

/**
 * Render the page.
 */
function afristream_render_configurations_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$stock     = afristream_license_stock();
	$pending   = afristream_pending_all();
	$over      = afristream_over_allocated();
	$mismatch  = afristream_mirror_mismatches();
	$conflicts = afristream_ownership_conflicts();
	$audit     = afristream_acf_audit();
	$registry  = afristream_registry();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Configurations', 'bluegroup-project-afristream' ); ?></h1>
		<p class="description">
			<?php
			printf(
				/* translators: 1: plugin version, 2: settings page link. */
				esc_html__( 'Everything the AfriStream Portal plugin adds to this site. Version %1$s. Settings live on the %2$s page.', 'bluegroup-project-afristream' ),
				esc_html( AFRISTREAM_PORTAL_VERSION ),
				'<a href="' . esc_url( admin_url( 'options-general.php?page=bluegroup-project-afristream' ) ) . '">' . esc_html__( 'AfriStream Portal settings', 'bluegroup-project-afristream' ) . '</a>'
			);
			?>
		</p>

		<h2><?php esc_html_e( 'Licences', 'bluegroup-project-afristream' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: 1: total, 2: available, 3: assigned, 4: expired. */
				esc_html__( '%1$d licences — %2$d available, %3$d assigned, %4$d expired.', 'bluegroup-project-afristream' ),
				(int) $stock['total'],
				(int) $stock['available'],
				(int) $stock['assigned'],
				(int) $stock['expired']
			);
			?>
		</p>

		<?php if ( ! empty( $pending ) ) : ?>
			<div class="notice notice-warning inline"><p>
				<strong><?php esc_html_e( 'Waiting for a licence:', 'bluegroup-project-afristream' ); ?></strong><br>
				<?php foreach ( $pending as $entry ) : ?>
					<?php $user = get_userdata( $entry['user'] ); ?>
					<?php echo esc_html( $user ? $user->display_name : '#' . $entry['user'] ); ?>
					— <?php echo esc_html( sprintf( _n( '%d licence', '%d licences', $entry['short'], 'bluegroup-project-afristream' ), $entry['short'] ) ); ?>
					<?php if ( $entry['since'] ) : ?>
						(<?php echo esc_html( human_time_diff( $entry['since'], current_time( 'timestamp' ) ) ); ?>)
					<?php endif; ?>
					<br>
				<?php endforeach; ?>
			</p></div>
		<?php endif; ?>

		<?php if ( ! empty( $over ) ) : ?>
			<div class="notice notice-warning inline"><p>
				<strong><?php esc_html_e( 'Holding more licences than they are paying for:', 'bluegroup-project-afristream' ); ?></strong><br>
				<?php esc_html_e( 'Nothing has been taken away — decide for each one.', 'bluegroup-project-afristream' ); ?><br>
				<?php foreach ( $over as $entry ) : ?>
					<?php $user = get_userdata( $entry['user'] ); ?>
					<a href="<?php echo esc_url( get_edit_user_link( $entry['user'] ) ); ?>"><?php echo esc_html( $user ? $user->display_name : '#' . $entry['user'] ); ?></a>
					— <?php echo esc_html( sprintf( __( 'holds %1$d, entitled to %2$d. Surplus:', 'bluegroup-project-afristream' ), $entry['held'], $entry['entitled'] ) ); ?>
					<?php
					$names = array();
					foreach ( $entry['surplus'] as $license_id ) {
						$names[] = get_the_title( $license_id );
					}
					echo esc_html( implode( ', ', $names ) );
					?>
					<br>
				<?php endforeach; ?>
			</p></div>
		<?php endif; ?>

		<?php if ( ! empty( $conflicts ) || ! empty( $mismatch ) ) : ?>
			<div class="notice notice-error inline"><p>
				<?php if ( ! empty( $conflicts ) ) : ?>
					<strong><?php esc_html_e( 'Licences that were claimed by two users before the migration:', 'bluegroup-project-afristream' ); ?></strong><br>
					<?php foreach ( $conflicts as $conflict ) : ?>
						<?php echo esc_html( get_the_title( $conflict['license'] ) ); ?>
						— <?php echo esc_html( sprintf( __( 'kept by user %d', 'bluegroup-project-afristream' ), $conflict['kept'] ) ); ?><br>
					<?php endforeach; ?>
				<?php endif; ?>
				<?php if ( ! empty( $mismatch ) ) : ?>
					<strong><?php esc_html_e( 'Users whose stored licence list disagrees with their licences:', 'bluegroup-project-afristream' ); ?></strong>
					<?php echo esc_html( implode( ', ', array_map( 'strval', $mismatch ) ) ); ?><br>
					<?php esc_html_e( 'Re-saving each profile rebuilds it.', 'bluegroup-project-afristream' ); ?>
				<?php endif; ?>
			</p></div>
		<?php endif; ?>

		<h2><?php esc_html_e( 'ACF readiness', 'bluegroup-project-afristream' ); ?></h2>
		<?php if ( $audit['safe'] ) : ?>
			<p><?php esc_html_e( 'Nothing outside this plugin uses ACF. It is safe to delete ACF\'s field groups and post types and deactivate it.', 'bluegroup-project-afristream' ); ?></p>
		<?php else : ?>
			<div class="notice notice-warning inline"><p>
				<strong><?php esc_html_e( 'Something still uses ACF — do not deactivate it yet.', 'bluegroup-project-afristream' ); ?></strong><br>
				<?php if ( ! empty( $audit['elementor'] ) ) : ?>
					<?php esc_html_e( 'Elementor content with ACF dynamic tags:', 'bluegroup-project-afristream' ); ?>
					<?php
					$titles = array();
					foreach ( $audit['elementor'] as $item ) {
						$titles[] = $item['title'] . ' (' . $item['type'] . ')';
					}
					echo esc_html( implode( ', ', $titles ) );
					?><br>
				<?php endif; ?>
				<?php if ( ! empty( $audit['plugins'] ) ) : ?>
					<?php esc_html_e( 'Plugins or themes calling ACF:', 'bluegroup-project-afristream' ); ?>
					<?php echo esc_html( implode( ', ', $audit['plugins'] ) ); ?>
				<?php endif; ?>
			</p></div>
		<?php endif; ?>
		<?php if ( ! empty( $audit['acf_posts'] ) ) : ?>
			<p class="description">
				<?php esc_html_e( 'ACF still defines these, and they must be deleted before it is deactivated or the licence editor will show every field twice:', 'bluegroup-project-afristream' ); ?>
				<?php
				$titles = array();
				foreach ( $audit['acf_posts'] as $item ) {
					$titles[] = $item['title'] . ' (' . $item['type'] . ')';
				}
				echo esc_html( implode( ', ', $titles ) );
				?>
			</p>
		<?php endif; ?>

		<h2><?php esc_html_e( 'What this plugin registers', 'bluegroup-project-afristream' ); ?></h2>
		<?php
		$current_group = '';
		foreach ( $registry as $item ) {
			if ( $item['group'] !== $current_group ) {
				if ( '' !== $current_group ) {
					echo '</tbody></table>';
				}
				$current_group = $item['group'];
				echo '<h3>' . esc_html( $current_group ) . '</h3>';
				echo '<table class="widefat striped"><thead><tr>';
				echo '<th style="width:24%;">' . esc_html__( 'Feature', 'bluegroup-project-afristream' ) . '</th>';
				echo '<th style="width:10%;">' . esc_html__( 'Type', 'bluegroup-project-afristream' ) . '</th>';
				echo '<th style="width:24%;">' . esc_html__( 'Handle', 'bluegroup-project-afristream' ) . '</th>';
				echo '<th style="width:20%;">' . esc_html__( 'Source', 'bluegroup-project-afristream' ) . '</th>';
				echo '<th>' . esc_html__( 'Status', 'bluegroup-project-afristream' ) . '</th>';
				echo '</tr></thead><tbody>';
			}

			echo '<tr>';
			echo '<td><strong>' . esc_html( $item['name'] ) . '</strong></td>';
			echo '<td>' . esc_html( $item['type'] ) . '</td>';
			echo '<td><code>' . esc_html( $item['handle'] ) . '</code></td>';
			echo '<td><code>' . esc_html( $item['file'] ) . '</code></td>';
			echo '<td>' . wp_kses_post( afristream_status_pill( $item['status'] ) ) . '</td>';
			echo '</tr>';
		}
		if ( '' !== $current_group ) {
			echo '</tbody></table>';
		}
		?>

		<h2><?php esc_html_e( 'Recent licence events', 'bluegroup-project-afristream' ); ?></h2>
		<?php $recent = afristream_license_log_recent( 20 ); ?>
		<?php if ( empty( $recent ) ) : ?>
			<p><?php esc_html_e( 'Nothing has happened to a licence yet.', 'bluegroup-project-afristream' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'When', 'bluegroup-project-afristream' ); ?></th>
					<th><?php esc_html_e( 'Licence', 'bluegroup-project-afristream' ); ?></th>
					<th><?php esc_html_e( 'Event', 'bluegroup-project-afristream' ); ?></th>
					<th><?php esc_html_e( 'User', 'bluegroup-project-afristream' ); ?></th>
					<th><?php esc_html_e( 'By', 'bluegroup-project-afristream' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $recent as $entry ) : ?>
					<?php $user = $entry['user'] ? get_userdata( $entry['user'] ) : false; ?>
					<tr>
						<td><?php echo esc_html( wp_date( 'j M Y, H:i', $entry['time'] ) ); ?></td>
						<td><a href="<?php echo esc_url( get_edit_post_link( $entry['license'] ) ); ?>"><?php echo esc_html( get_the_title( $entry['license'] ) ); ?></a></td>
						<td><?php echo esc_html( $entry['event'] ); ?></td>
						<td><?php echo esc_html( $user ? $user->display_name : '—' ); ?></td>
						<td><?php echo esc_html( $entry['actor'] . ( $entry['context'] ? ' (' . $entry['context'] . ')' : '' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Warn on every admin screen when someone is waiting for a licence.
 *
 * Buried on one page it would be found a week late, and the whole point of
 * queueing rather than dropping the request is that somebody acts on it.
 */
function afristream_pending_admin_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$pending = afristream_pending_all();
	if ( empty( $pending ) ) {
		return;
	}

	echo '<div class="notice notice-warning"><p>';
	printf(
		/* translators: 1: number of users, 2: link to the Configurations page. */
		esc_html( _n( '%1$d AfriStream customer is waiting for a licence — none was available when they paid. %2$s', '%1$d AfriStream customers are waiting for a licence — none was available when they paid. %2$s', count( $pending ), 'bluegroup-project-afristream' ) ),
		count( $pending ),
		'<a href="' . esc_url( admin_url( 'admin.php?page=afristream-configurations' ) ) . '">' . esc_html__( 'View', 'bluegroup-project-afristream' ) . '</a>'
	);
	echo '</p></div>';
}
add_action( 'admin_notices', 'afristream_pending_admin_notice' );
```

- [ ] **Step 4: Require it last**

Modify `bluegroup-project-afristream.php` — append after the other requires so every file has registered before the page reads the filter:

```php
require_once plugin_dir_path( __FILE__ ) . 'includes/configurations.php';
```

- [ ] **Step 5: Run the tests and confirm they pass**

The audit and render functions need `$wpdb` and admin functions the harness does not stub, but neither is called by the tests.

Run: `php tests/php/run.php`
Expected: `PHP tests: 63 tests, 180 assertions, all passing`

- [ ] **Step 6: Verify by hand**

1. A **Configurations** menu appears in wp-admin.
2. The licence line matches reality — cross-check against **Licenses → All Licenses**.
3. **ACF readiness** lists the two ACF field groups and the licence post type, and reports whether anything else uses ACF.
4. **Recent licence events** shows the backfill entries from Task 8.
5. The registry section is empty for now — Task 12 fills it.

- [ ] **Step 7: Commit**

```bash
git add includes/configurations.php tests/php/test-registry.php bluegroup-project-afristream.php
git commit -m "Add a Configurations page that cannot drift from the code

Read-only on purpose: the value is in being able to trust it, and a page with
switches on it is a page that can turn something off by accident.

The list is not written on the page. Each feature file contributes its entries
through a filter, so adding a feature and forgetting to document it is not
possible in the way a hand-maintained list makes inevitable. Status is computed
on load rather than stored, for the same reason. An entry from a group nobody
declared is still shown, just last — dropping it would make the page lie about
its own coverage.

It also answers the question this work needs answered before ACF can go: what
else on this site uses it. Elementor keeps its tree as JSON in postmeta, so an
ACF dynamic tag is findable there, and the other active plugins and the theme
are scanned for ACF API calls. Deactivating ACF blind would break an Elementor
page silently, and nothing in this repo can tell you that.

Waiting customers are surfaced as a notice on every admin screen, not just
here. Buried on one page it would be found a week late, and the point of
queueing rather than dropping the request is that somebody acts on it.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 12: Declare every feature to the registry

The page from Task 11 renders nothing until the files describe themselves. Each file declares its own entries, next to the code they describe, so the two move together.

**Files:**
- Modify: `includes/fields.php`, `includes/licenses.php`, `includes/license-admin.php`, `includes/auto-assign.php`, `includes/shortcodes.php`, `includes/affiliates.php`, `bluegroup-project-afristream.php` — one registry block appended to each
- Create: `tests/php/test-registry-coverage.php`

**Interfaces:**
- Consumes: the `afristream_registry` filter from Task 11.
- Produces: nothing new.

- [ ] **Step 1: Write the failing coverage test**

This is the test that stops the page quietly under-reporting. Create `tests/php/test-registry-coverage.php`:

```php
<?php
require_once __DIR__ . '/../../includes/fields.php';
require_once __DIR__ . '/../../includes/license-log.php';
require_once __DIR__ . '/../../includes/licenses.php';
require_once __DIR__ . '/../../includes/license-admin.php';
require_once __DIR__ . '/../../includes/auto-assign.php';
require_once __DIR__ . '/../../includes/configurations.php';

af_test( 'every shortcode, REST route and licence feature is declared', function () {
	$registry = afristream_registry();

	$handles = array();
	foreach ( $registry as $item ) {
		$handles[] = $item['handle'];
	}

	$required = array(
		'afristream_portal',
		'user_acf_fields',
		'troubleshooting_guide',
		'afristream/v1/watch',
		'afristream/v1/editor-picks',
		'afristream/v1/detail',
		'afristream/v1/credentials',
		'afristream/v1/affiliate',
		'license',
		'surecart/purchase_created',
		'surecart/subscription_created',
	);

	foreach ( $required as $handle ) {
		af_assert( in_array( $handle, $handles, true ), 'the registry declares ' . $handle );
	}
} );

af_test( 'every declared entry names a real source file', function () {
	foreach ( afristream_registry() as $item ) {
		af_assert(
			'' !== $item['file'] && file_exists( dirname( __DIR__, 2 ) . '/' . $item['file'] ),
			$item['name'] . ' points at a file that exists: ' . $item['file']
		);
	}
} );

af_test( 'every declared entry uses a known group', function () {
	$groups = afristream_registry_groups();
	foreach ( afristream_registry() as $item ) {
		af_assert( in_array( $item['group'], $groups, true ), $item['name'] . ' is in a declared group, not "' . $item['group'] . '"' );
	}
} );
```

- [ ] **Step 2: Run the tests and watch them fail**

Run: `php tests/php/run.php`
Expected: FAIL — `the registry declares afristream_portal`, and one failure per required handle.

- [ ] **Step 3: Declare the licence data model — append to `includes/fields.php`**

```php
/**
 * Declare the data model on the Configurations page.
 *
 * Kept in the file it describes so the two are edited together. A description
 * that lives somewhere else goes stale the first time someone is in a hurry.
 *
 * @param array $items Registry entries so far.
 * @return array
 */
function afristream_register_field_registry( $items ) {
	$stock = function_exists( 'afristream_license_stock' ) ? afristream_license_stock() : array( 'total' => 0, 'available' => 0 );

	$items[] = array(
		'group'  => 'Licences',
		'name'   => 'Licence post type',
		'type'   => 'field',
		'handle' => 'license',
		'file'   => 'includes/fields.php',
		'status' => array(
			'state' => 'ok',
			/* translators: 1: total licences, 2: available licences. */
			'label' => sprintf( __( '%1$d licences — %2$d available', 'bluegroup-project-afristream' ), $stock['total'], $stock['available'] ),
		),
	);

	foreach ( afristream_license_fields() as $key => $field ) {
		$items[] = array(
			'group'  => 'Licences',
			'name'   => $field['label'],
			'type'   => 'field',
			'handle' => $key,
			'file'   => 'includes/fields.php',
		);
	}

	$items[] = array(
		'group'  => 'Licences',
		'name'   => 'Licence assignment',
		'type'   => 'field',
		'handle' => AFRISTREAM_LICENSE_OWNER_META,
		'file'   => 'includes/fields.php',
		'status' => array(
			'state' => 'ok',
			'label' => __( 'Owner stored on the licence, user meta mirrored', 'bluegroup-project-afristream' ),
		),
	);

	return $items;
}
add_filter( 'afristream_registry', 'afristream_register_field_registry' );
```

- [ ] **Step 4: Declare the licence history — append to `includes/license-log.php`**

```php
/**
 * Declare the licence history on the Configurations page.
 *
 * @param array $items Registry entries so far.
 * @return array
 */
function afristream_register_log_registry( $items ) {
	$items[] = array(
		'group'  => 'Licences',
		'name'   => 'Licence history',
		'type'   => 'field',
		'handle' => AFRISTREAM_LICENSE_LOG_META,
		'file'   => 'includes/license-log.php',
		'status' => array(
			'state' => 'ok',
			/* translators: %d: entries kept per licence. */
			'label' => sprintf( __( 'Last %d events per licence', 'bluegroup-project-afristream' ), AFRISTREAM_LOG_CAP ),
		),
	);

	return $items;
}
add_filter( 'afristream_registry', 'afristream_register_log_registry' );
```

- [ ] **Step 5: Declare auto-assign — append to `includes/auto-assign.php`**

```php
/**
 * Declare auto-assignment on the Configurations page.
 *
 * @param array $items Registry entries so far.
 * @return array
 */
function afristream_register_autoassign_registry( $items ) {
	$status = afristream_autoassign_status();

	$items[] = array(
		'group'  => 'Licences',
		'name'   => 'Auto-assign on purchase',
		'type'   => 'hook',
		'handle' => 'surecart/purchase_created',
		'file'   => 'includes/auto-assign.php',
		'status' => $status,
	);

	$items[] = array(
		'group'  => 'Licences',
		'name'   => 'Auto-assign on new subscription',
		'type'   => 'hook',
		'handle' => 'surecart/subscription_created',
		'file'   => 'includes/auto-assign.php',
		'status' => $status,
	);

	$over = afristream_over_allocated();
	$items[] = array(
		'group'  => 'Licences',
		'name'   => 'Entitlement check',
		'type'   => 'integration',
		'handle' => 'afristream_entitlement',
		'file'   => 'includes/auto-assign.php',
		'status' => empty( $over )
			? array( 'state' => 'ok', 'label' => __( 'One licence per active subscription', 'bluegroup-project-afristream' ) )
			: array(
				'state' => 'warn',
				/* translators: %d: number of users. */
				'label' => sprintf( _n( '%d user over-allocated', '%d users over-allocated', count( $over ), 'bluegroup-project-afristream' ), count( $over ) ),
			),
	);

	$items[] = array(
		'group'  => 'Licences',
		'name'   => 'Waiting list',
		'type'   => 'hook',
		'handle' => 'save_post_license',
		'file'   => 'includes/auto-assign.php',
		'status' => array(
			'state' => 'ok',
			'label' => __( 'Served automatically when a licence frees up', 'bluegroup-project-afristream' ),
		),
	);

	return $items;
}
add_filter( 'afristream_registry', 'afristream_register_autoassign_registry' );
```

- [ ] **Step 6: Declare the admin screens — append to `includes/licenses.php`**

```php
/**
 * Declare the licence admin columns on the Configurations page.
 *
 * @param array $items Registry entries so far.
 * @return array
 */
function afristream_register_columns_registry( $items ) {
	$columns = array(
		'active_licenses' => array( 'Users: Active Licenses column', 'manage_users_columns' ),
		'expiry_date'     => array( 'Licences: Expiry Date column', 'manage_license_posts_columns' ),
		'mobile_active'   => array( 'Licences: Mobile Active column', 'manage_license_posts_columns' ),
		'active_license'  => array( 'Licences: Connected User column', 'manage_license_posts_columns' ),
		'last_assigned'   => array( 'Licences: Last Assigned column', 'manage_license_posts_columns' ),
	);

	foreach ( $columns as $handle => $column ) {
		$items[] = array(
			'group'  => 'Admin UI',
			'name'   => $column[0],
			'type'   => 'column',
			'handle' => $handle,
			'file'   => 'includes/licenses.php',
		);
	}

	return $items;
}
add_filter( 'afristream_registry', 'afristream_register_columns_registry' );
```

- [ ] **Step 7: Declare the editors — append to `includes/license-admin.php`**

```php
/**
 * Declare the licence and profile editors on the Configurations page.
 *
 * @param array $items Registry entries so far.
 * @return array
 */
function afristream_register_admin_registry( $items ) {
	$items[] = array(
		'group'  => 'Admin UI',
		'name'   => 'Licence editor fields',
		'type'   => 'page',
		'handle' => 'afristream-license-fields',
		'file'   => 'includes/license-admin.php',
	);

	$items[] = array(
		'group'  => 'Admin UI',
		'name'   => 'Licence editor history',
		'type'   => 'page',
		'handle' => 'afristream-license-history',
		'file'   => 'includes/license-admin.php',
	);

	$items[] = array(
		'group'  => 'Admin UI',
		'name'   => 'Licence field on user profiles',
		'type'   => 'field',
		'handle' => 'afristream_active_license',
		'file'   => 'includes/license-admin.php',
	);

	return $items;
}
add_filter( 'afristream_registry', 'afristream_register_admin_registry' );
```

- [ ] **Step 8: Declare the legacy shortcodes — append to `includes/shortcodes.php`**

```php
/**
 * Declare the legacy shortcodes on the Configurations page.
 *
 * @param array $items Registry entries so far.
 * @return array
 */
function afristream_register_shortcode_registry( $items ) {
	$items[] = array(
		'group'  => 'Portal',
		'name'   => 'App credentials shortcode',
		'type'   => 'shortcode',
		'handle' => 'user_acf_fields',
		'file'   => 'includes/shortcodes.php',
		'status' => array(
			'state' => 'ok',
			'label' => __( 'Legacy tag name, no ACF dependency', 'bluegroup-project-afristream' ),
		),
	);

	$items[] = array(
		'group'  => 'Portal',
		'name'   => 'Troubleshooting guide shortcode',
		'type'   => 'shortcode',
		'handle' => 'troubleshooting_guide',
		'file'   => 'includes/shortcodes.php',
	);

	return $items;
}
add_filter( 'afristream_registry', 'afristream_register_shortcode_registry' );
```

- [ ] **Step 9: Declare the affiliate integration — append to `includes/affiliates.php`**

```php
/**
 * Declare the affiliate integration on the Configurations page.
 *
 * @param array $items Registry entries so far.
 * @return array
 */
function afristream_register_affiliate_registry( $items ) {
	$items[] = array(
		'group'  => 'Affiliates',
		'name'   => 'SureCart affiliation lookup',
		'type'   => 'integration',
		'handle' => '\SureCart\Models\Affiliation',
		'file'   => 'includes/affiliates.php',
		'status' => class_exists( '\SureCart\Models\Affiliation' )
			? array( 'state' => 'ok', 'label' => __( 'SureCart active', 'bluegroup-project-afristream' ) )
			: array( 'state' => 'off', 'label' => __( 'SureCart inactive — the affiliate tab is hidden', 'bluegroup-project-afristream' ) ),
	);

	$items[] = array(
		'group'  => 'Affiliates',
		'name'   => 'Default commission rate',
		'type'   => 'setting',
		'handle' => 'afristream_affiliate_default_rate',
		'file'   => 'includes/affiliates.php',
	);

	return $items;
}
add_filter( 'afristream_registry', 'afristream_register_affiliate_registry' );
```

Note: confirm the option name on the rate setting matches what `includes/affiliates.php` actually registers — read the `register_setting()` call in that file and use its exact name rather than the one written here.

- [ ] **Step 10: Declare the portal itself — append to `bluegroup-project-afristream.php`**

```php
/**
 * Declare the portal's own surface on the Configurations page.
 *
 * @param array $items Registry entries so far.
 * @return array
 */
function afristream_register_portal_registry( $items ) {
	$items[] = array(
		'group'  => 'Portal',
		'name'   => 'Portal shortcode',
		'type'   => 'shortcode',
		'handle' => 'afristream_portal',
		'file'   => 'bluegroup-project-afristream.php',
		'status' => array(
			'state' => 'ok',
			'label' => __( 'Attributes: default_tab, show_sport', 'bluegroup-project-afristream' ),
		),
	);

	$routes = array(
		'afristream/v1/watch'        => 'What to Watch data',
		'afristream/v1/editor-picks' => 'Editor Picks data',
		'afristream/v1/detail'       => 'Title detail lookup',
		'afristream/v1/credentials'  => 'Customer app credentials',
		'afristream/v1/affiliate'    => 'Affiliate status and rates',
	);
	foreach ( $routes as $handle => $name ) {
		$items[] = array(
			'group'  => 'Portal',
			'name'   => $name,
			'type'   => 'rest',
			'handle' => $handle,
			'file'   => 'bluegroup-project-afristream.php',
		);
	}

	$has_key = '' !== (string) afristream_portal_tmdb_key();
	$items[] = array(
		'group'  => 'Content sources',
		'name'   => 'TMDB catalogue',
		'type'   => 'setting',
		'handle' => 'afristream_tmdb_api_key',
		'file'   => 'bluegroup-project-afristream.php',
		'status' => $has_key
			? array( 'state' => 'ok', 'label' => __( 'Connected — trending titles are live', 'bluegroup-project-afristream' ) )
			: array( 'state' => 'warn', 'label' => __( 'No key — the built-in lists are showing', 'bluegroup-project-afristream' ) ),
	);

	$picks = count( afristream_portal_editor_ids() );
	$items[] = array(
		'group'  => 'Content sources',
		'name'   => 'Editor Picks list',
		'type'   => 'setting',
		'handle' => 'afristream_editor_picks_ids',
		'file'   => 'bluegroup-project-afristream.php',
		'status' => array(
			'state' => $picks ? 'ok' : 'warn',
			/* translators: %d: number of curated titles. */
			'label' => sprintf( _n( '%d curated title', '%d curated titles', $picks, 'bluegroup-project-afristream' ), $picks ),
		),
	);

	$items[] = array(
		'group'  => 'Content sources',
		'name'   => 'Sport fixtures and broadcasters',
		'type'   => 'integration',
		'handle' => 'ESPN + TheSportsDB + baked listings',
		'file'   => 'bluegroup-project-afristream.php',
		'status' => array(
			'state' => 'ok',
			'label' => __( 'Keyless public feeds — no configuration', 'bluegroup-project-afristream' ),
		),
	);

	$items[] = array(
		'group'  => 'Admin UI',
		'name'   => 'Settings page',
		'type'   => 'page',
		'handle' => 'options-general.php?page=bluegroup-project-afristream',
		'file'   => 'bluegroup-project-afristream.php',
	);

	$items[] = array(
		'group'  => 'Admin UI',
		'name'   => 'Configurations page',
		'type'   => 'page',
		'handle' => 'admin.php?page=afristream-configurations',
		'file'   => 'includes/configurations.php',
	);

	return $items;
}
add_filter( 'afristream_registry', 'afristream_register_portal_registry' );
```

- [ ] **Step 11: Run the tests and confirm they pass**

`afristream_portal_editor_ids()` reads an option and `afristream_portal_tmdb_key()` reads an option and a constant — both work under the harness. If the coverage test cannot load the main plugin file, add `require_once` for it at the top of `tests/php/test-registry-coverage.php` guarded so the plugin's `ABSPATH` check does not exit; the constant is already defined by `bootstrap.php`.

Run: `php tests/php/run.php`
Expected: `PHP tests: 66 tests, 210 assertions, all passing`

- [ ] **Step 12: Verify by hand**

1. Open **Configurations**. Every group renders with rows.
2. Cross-check a handful against reality: the TMDB row matches the settings page, the SureCart row matches whether SureCart is active, the licence count matches the Licenses screen.
3. Click through to a source file path and confirm it is right.

- [ ] **Step 13: Commit**

```bash
git add includes/ bluegroup-project-afristream.php tests/php/test-registry-coverage.php
git commit -m "Have every file declare what it registers

Each declaration sits in the file it describes, so the two get edited together.
A description kept somewhere else goes stale the first time somebody is in a
hurry, which is exactly when the page needs to be right.

The coverage test is the part that matters: it asserts every shortcode, REST
route and licence hook appears in the registry, and that each entry names a
source file that exists. Without it the page would under-report silently, which
is worse than not having it — an incomplete list still reads as complete.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 13: Portal coverage, ACF retirement, version bump, release

**Files:**
- Modify: `tests/portal.spec.js` (append)
- Modify: `preview/index.html` or the preview harness fixture, if a second profile is not already present
- Modify: `bluegroup-project-afristream.php` (header version + `AFRISTREAM_PORTAL_VERSION`)
- Modify: `package.json` (version)
- Modify: `CHANGELOG.md`
- Modify: `README.md` if it documents the ACF dependency

- [ ] **Step 1: Check what the preview fixture already covers**

Run: `grep -n "BabyBlue123\|BabyBlue-TV\|Profile 2" preview/index.html tests/portal.spec.js`
Expected: the existing test at `tests/portal.spec.js:13` already switches to **Profile 2**, so the harness has two profiles. If it does not, add a second profile to the fixture before continuing.

- [ ] **Step 2: Add the multi-licence assertion**

Append to `tests/portal.spec.js`:

```javascript
test('a customer holding two licences gets a profile tab for each', async ({ page }) => {
  // The Profile tab renders one card per assigned licence. Two licences is the
  // case the old ACF field could not express — it was capped at one — so it is
  // the one worth pinning down.
  const tabs = page.locator('.as-profile-tab, [data-profile-tab]');
  await expect(page.getByRole('button', { name: 'Profile 1' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Profile 2' })).toBeVisible();

  await page.getByRole('button', { name: 'Profile 1' }).click();
  await expect(page.getByText('BabyBlue123')).toBeVisible();

  await page.getByRole('button', { name: 'Profile 2' }).click();
  await expect(page.getByText('BabyBlue-TV')).toBeVisible();
  await expect(page.getByText('BabyBlue123')).toBeHidden();
});
```

If the profile switcher uses a different selector, read `assets/portal.js` around the profile rendering and match the real roles rather than forcing these.

- [ ] **Step 3: Run the full suite**

Run: `npm test`
Expected: the PHP suite passes, then Playwright reports all tests passing.

- [ ] **Step 4: Retire ACF on the live site**

Do this only once **Configurations → ACF readiness** reports that nothing else uses ACF. If it names an Elementor page or another plugin, stop and report to Luke rather than proceeding.

1. **ACF → Field Groups**: delete **License Field Groups** and **User Field Groups**. Trash then Delete Permanently.
2. **ACF → Post Types**: delete **Licenses**.
3. Reload **Licenses → All Licenses** and confirm the list still renders with every column intact — this proves the plugin's own post type is doing the work.
4. Edit a licence and confirm exactly **one** set of fields is shown, with the right values.
5. Edit a user and confirm one licence field, with the right selection.
6. Open the portal as a customer and confirm the Profile tab is unchanged.
7. **Plugins**: deactivate **Advanced Custom Fields**.
8. Re-check steps 3–6 with ACF deactivated. If anything breaks, reactivate ACF and report.
9. Leave ACF deactivated but installed for one week before deleting it, so there is a way back.

- [ ] **Step 5: Bump the version**

`bluegroup-project-afristream.php` — header line 6 and the constant:

```php
 * Version:     0.20.0
```

```php
define( 'AFRISTREAM_PORTAL_VERSION', '0.20.0' );
```

`package.json`:

```json
  "version": "0.20.0",
```

- [ ] **Step 6: Write the changelog entry**

Prepend to `CHANGELOG.md`, directly under the format paragraph:

```markdown
## [0.20.0] - 2026-07-26

### Added

- **A Configurations page.** A new top-level menu listing everything the plugin adds to the site — every shortcode, REST route, admin column, licence field, hook and integration — with a live status against each: how many licences are free, whether TMDB is connected, whether anyone is waiting for a licence. It is read-only, and the list is built by each file declaring its own entries rather than being written on the page, so it cannot drift from the code the way a hand-maintained list does the first time a feature is added in a hurry.

- **Licences are assigned automatically when someone pays.** A customer gets one licence per active subscription, taken from the stock closest to expiring. If nothing is free they are queued rather than quietly missed, flagged on every admin screen, and served the moment a licence is published or freed. The routine tops up to the entitlement instead of granting per event, so a repeated or replayed payment webhook cannot hand out a second licence.

- **A history on every licence.** Assigned, unassigned, created, updated — each with the date, the customer, and who or what did it. Shown on the licence editor, as a Last Assigned column on the licence list, and as a recent-events feed on the Configurations page. It exists for the moment a licence needs reassigning by hand and the question is who had it last.

- **Customers can hold more than one licence.** The old field was capped at one; each licence a customer holds now becomes its own profile in the portal.

### Changed

- **Advanced Custom Fields is no longer required.** The licence post type, its four fields and the customer's licence assignment all belong to the plugin now. Nothing moved in the database — the same meta keys hold the same values in the same formats — so the change is invisible to anyone using the site.

- **A licence records who holds it, rather than each customer recording which licences they hold.** The old arrangement kept assignments as a list on the customer, which two simultaneous changes could silently overwrite and which allowed the same licence to appear against two people. A licence now names its own holder, so one licence can only ever have one owner, and the customer-side list is rebuilt from it for anything that still reads the old shape.

- The Connected User column reads the holder off the licence instead of searching every user on the site for it.

### Fixed

- Two customers checking out at the same moment can no longer be handed the same licence.
```

- [ ] **Step 7: Run lint once and report**

Run: `npm run lint`

Report every finding to Luke. **Do not fix them** — per the project's linting rule, that is Luke's call.

- [ ] **Step 8: Build the deployment zip**

Per the WordPress deployment rule, `Compress-Archive` is banned — it writes backslash paths that make WordPress report "Plugin file does not exist."

```bash
npm install
npm run build
rm -f ../bluegroup-project-afristream.zip
/c/Windows/System32/tar.exe -a -c -f ../bluegroup-project-afristream.zip -C dist bluegroup-project-afristream
unzip -l ../bluegroup-project-afristream.zip | head -20
```

Expected: every entry reads `bluegroup-project-afristream/...` with forward slashes, nested one level. Confirm `bluegroup-project-afristream/bluegroup-project-afristream.php` is present. If any backslash appears, the zip is broken — rebuild with bsdtar.

- [ ] **Step 9: Commit and open the pull request**

```bash
git add -A
git commit -m "Release 0.20.0

Version bump, changelog, and the Playwright case for a customer holding two
licences — which is the case the old ACF field could not express at all, since
it was capped at one.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
git push -u origin HEAD
gh pr create --title "Retire ACF, add a Configurations page, and auto-assign licences" --body "$(cat <<'BODY'
Replaces Advanced Custom Fields with the plugin's own code, adds a Configurations page listing everything the plugin registers, and assigns licences automatically when a customer pays.

Nothing moves in the database. The same meta keys hold the same values in the same formats, so the swap is invisible to anyone using the site.

The load-bearing change is that a licence now records its own holder instead of each customer recording a list of licences. The old shape was a read-modify-write on a serialized array, which two overlapping changes could silently overwrite and which allowed one licence to appear against two people — both of which get worse now that a customer can hold more than one.

Auto-assign gives one licence per active subscription, tops up rather than grants so a replayed webhook cannot over-assign, and queues the customer when stock runs out rather than losing the request. Nothing is ever revoked automatically; over-allocation is reported for a person to decide.

## Testing

- New dependency-free PHP unit suite (`npm run test:php`) covering availability, ordering, assignment, the lock, the mirror, the backfill, entitlement arithmetic, the queue and the registry.
- `php -l` added to lint, which previously checked only JavaScript.
- Playwright case for a customer holding two licences.
- Manual verification against the live site at each step, including the migration itself.

## Before merging

ACF's own field groups and post type must be deleted before ACF is deactivated, or both it and this plugin register the licence post type and the editor shows every field twice. Configurations → ACF readiness reports whether anything else on the site still needs ACF.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
BODY
)"
```

- [ ] **Step 10: Report to Luke**

State plainly: what was verified against the live site and what was not, any lint findings, whether ACF was deactivated or is still pending the audit, and where the zip is.

---

## Self-Review

**Spec coverage.** Every section of the design maps to a task: ownership inversion → 3, post type and fields → 2, meta box → 5, profile field → 6, `get_field()` removal → 7, event log → 4, auto-assign → 9 and 10, queue and over-allocation → 10, Configurations page → 11 and 12, ACF audit and retirement → 11 and 13, backfill → 8, testing → 1 throughout and 13, version → 13.

**Type consistency.** `afristream_topup_user()` returns `assigned/short/entitled/held` and is consumed with those keys in Task 10. `afristream_pending_all()` returns `user/short/since`, used with those keys in the drain and the page. Log entries carry `time/event/user/actor/context/detail`, plus `license` from `afristream_license_log_recent()` only — the Configurations feed is the only consumer of that extra key. Registry entries are `group/name/type/handle/file/status`, and `status` is `state/label` everywhere including `afristream_autoassign_status()`.

**Known gaps, deliberately left to implementation.**

- The two SureCart action names are verified in Task 10 Step 5 against a real test purchase rather than assumed. If they differ, only the two `add_action()` calls change.
- `afristream_subscription_count()` is verified in Task 9 Step 6 the same way. If SureCart's Customer query differs, the fix is contained to that one function and no test changes.
- The affiliate rate option name in Task 12 Step 9 must be read from `includes/affiliates.php` rather than trusted.
- Assertion counts in the "Expected" lines are indicative. A mismatch of one or two means an assertion was counted differently, not that something failed — the pass/fail line is what matters.
