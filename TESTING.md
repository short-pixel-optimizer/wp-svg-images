# Testing WP SVG Images

Everything runs in Docker through one script, `bin/test.sh`. The plan behind
the suites is `docs/TESTING-PLAN.md`; the rules every contributor follows are
in `CLAUDE.md`.

## Requirements

- Docker with the Compose plugin (`docker compose`). Nothing else on the host:
  no PHP, Composer, Node or MySQL.
- Network access for the first run of each PHP image and WordPress version.
  Test runs themselves are offline (see "How a run works").

## Install

Nothing to install by hand. The first `bin/test.sh` run builds the PHP image,
installs `tests/vendor` from `tests/composer.lock` and downloads WordPress into
`tests/.cache/`. Later runs reuse them.

To start from scratch: `rm -rf tests/.cache tests/vendor`.

## Running tests

```sh
bin/test.sh                      # every implemented suite, PHP 8.3, latest WordPress
bin/test.sh all                  # same
bin/test.sh unit                 # one suite
bin/test.sh unit integration     # several suites, in this order
bin/test.sh --verify             # prove the environment works (see below)
bin/test.sh --list               # suite names
bin/test.sh --help
```

### Suites

Names are exact and case-sensitive. An unknown name, a wrong case (`Unit`) or a
planned suite without tests exits with code 2 instead of running nothing.

| Suite | Status | What it covers | Configuration / bootstrap |
|---|---|---|---|
| `unit` | implemented | Sanitizer library and `WPSVG` helpers, no WordPress | `tests/phpunit/unit.xml`, `tests/bootstrap/unit.php` |
| `integration` | implemented | Real WordPress, database and uploads directory; the plugin is active through `active_plugins` | `tests/phpunit/integration.xml`, `tests/bootstrap/integration.php` |
| `compat` | implemented | The plugin next to real third-party plugins and libraries, one run per scenario | `tests/phpunit/compat.xml`, `tests/bootstrap/compat.php`, `tests/compat/scenarios.php` |
| `multisite` | planned | Network installs | — |
| `cli` | planned | WP-CLI imports and activation | — |
| `l10n` | planned | Text domain loading and translation template | — |
| `e2e` | planned | Real browser against a running site | — |

### The `compat` suite

WordPress loads plugins once per PHP process, so "active" and "inactive"
cannot be switched inside a run. `bin/test.sh compat` therefore runs PHPUnit
once per scenario, each on an empty database, and reports one total.

- **Scenarios** are listed in `tests/compat/scenarios.php`: the active plugins
  in load order, the libraries to load, and the folder below `tests/compat/`
  whose tests run. Two scenarios may share a folder (the two Safe SVG load
  orders do); the tests then ask `scenario()` what to expect.
- **Versions** are pinned in `tests/compat/plugins.json`. Plugins come from
  wordpress.org, libraries from the address in the manifest. Changing a
  version there replaces the cached copy on the next run.
- **Downloads** happen in the `setup` container only, into
  `tests/.cache/compat/`. The test run itself stays offline.
- **Own plugins directory.** The suite sets `WP_PLUGIN_DIR` to a folder that
  holds the pinned third parties and this plugin, so the other suites never
  see them.
- **Activation routines.** Activating through the `active_plugins` option
  runs no activation hook. A scenario lists under `activate` the plugins that
  need theirs (WP All Import creates its tables there); the bootstrap fires it
  once all plugin files are loaded.
- **Unsupported versions.** When a third-party plugin's header asks for a
  newer WordPress or PHP than the cell runs, the bootstrap does not load it
  and every test of that scenario is skipped with that reason. The skips are
  listed after the `Executed:` line.
- **New test classes** extend `CompatTestCase`, which adds the scenario
  helpers to the integration test case.

| Scenario | Active | Tests |
|---|---|---|
| `baseline` | this plugin only | `tests/compat/Baseline/` |
| `safe-svg-first` | Safe SVG, then this plugin (WordPress's default order) | `tests/compat/SafeSvg/` |
| `safe-svg-last` | this plugin, then Safe SVG | `tests/compat/SafeSvg/` |
| `htmlpurifier` | this plugin, HTMLPurifier loaded | `tests/compat/HtmlPurifier/` |
| `wp-all-import` | WP All Import (activation routine run), then this plugin | `tests/compat/WpAllImport/` |
| `beaver-builder` | Beaver Builder Lite, then this plugin | `tests/compat/BeaverBuilder/` |

### One class, one test, one group

```sh
bin/test.sh unit --class EnvironmentCanaryTest
bin/test.sh unit --filter 'EnvironmentCanaryTest::test_unit_canary'
bin/test.sh all --group pinned
```

- `--class NAME` matches the class name exactly (namespaced or not).
- `--filter` is passed to PHPUnit unchanged; it is a regular expression.
- `--group` is passed to PHPUnit unchanged.
- With a selector and one suite, zero matching tests fails the run. With a
  selector and several suites, a suite with no match is shown as `no match`;
  the run fails only if nothing matched in any suite.

### Choosing PHP and WordPress versions

```sh
bin/test.sh all --php 7.4 --wp 6.4
bin/test.sh integration --php 8.5 --wp latest
bin/test.sh integration --wp 7.0.7
bin/test.sh integration --wp nightly
```

- `--php X.Y`: any version with an official `php:X.Y-cli` image.
- `--wp`: `latest`, `nightly`, `X.Y` (newest `X.Y.Z` release) or an exact
  `X.Y.Z`. The resolved version is printed and checked by the integration
  canary.

Supported matrix (also run by CI):

| PHP | WordPress | Gate |
|---|---|---|
| 7.4 | 6.4 | blocking |
| 8.3 | 7.0 | blocking |
| 8.5 | latest | blocking |
| 8.3 | nightly | advisory |

### Output and exit codes

Each suite prints PHPUnit's output, then
`Executed: N tests, A assertions, F failures, E errors, S skipped` and every
skipped test with its reason. The run ends with a per-suite summary and the
total executed count.

| Exit code | Meaning |
|---|---|
| 0 | Every selected suite passed and executed at least one test |
| 1 | A suite failed, crashed, or executed zero tests |
| 2 | Usage error: unknown option, unknown or planned suite |
| 3 | Another `bin/test.sh` run is in progress |

JUnit reports and the parsed counts are left in `tests/.results/`.

### Verifying the environment

`bin/test.sh --verify` (accepts `--php` and `--wp`) passes only when all of
these hold:

1. The unit canary runs alone: exactly 1 test executed and passed, on the
   requested PHP version, with `fileinfo`, `dom`, `libxml`, `simplexml` and
   `zlib` loaded.
2. The integration canary runs alone: exactly 1 test executed and passed, on
   the resolved WordPress version, with the plugin active and a database write
   read back.
3. The unit canary forced to fail (`WPSVG_CANARY_FAIL=1`) makes the runner
   exit non-zero with 1 failure.
4. A filter that matches nothing makes the runner exit non-zero.
5. An unknown suite name (`Unit`) and a planned suite (`e2e`) are rejected.

Steps 3 and 4 print a failure on purpose; the `PASS`/`FAIL` lines and the
final `VERIFIED` / `NOT VERIFIED` line are what count.

## How a run works

- `tests/docker/compose.yml` defines three services. `setup` (with network
  access) installs Composer dependencies and downloads WordPress core and the
  WordPress PHPUnit library into `tests/.cache/wp/<version>/`. `php` runs the
  suites on an internal network shared only with `db` (MariaDB on tmpfs), so
  tests cannot reach the internet.
- In `php`, WordPress lives at `/opt/wp`, the repository is mounted read-only
  at `/opt/wp/wp-content/plugins/wp-svg-images`, and `tests/.results` is the
  only writable project path.
- Every suite that uses WordPress gets a freshly created database
  `wpsvg_<suite>`.
- A lock in `tests/.cache/run.lock` stops two runs from sharing the stack; the
  stack is removed when the run ends.
- The zero-test guard (`tests/bin/junit-summary.php`) reads the JUnit report.

## Writing tests

Conventions are in `docs/TESTING-PLAN.md` §5. In short:

- One class per file under `tests/<suite>/`, named after its subject:
  `tests/unit/Sanitizer/SanitizerTest.php` → `SanitizerTest`.
- Methods: `test_<behaviour_in_snake_case>`; data providers: `provide_<thing>()`.
- Fixtures: `tests/fixtures/svg/{clean,malicious,malformed,dimensions}/`, one
  behaviour per file; build generated fixtures (gzip, large graphs) in the test.
- Unit tests extend `Yoast\PHPUnitPolyfills\TestCases\TestCase` and use Brain
  Monkey for WordPress functions. Integration tests extend `WP_UnitTestCase`.
- Read the docblock of the code under test first; it is the specification.
- Test dependencies go in `tests/composer.json` only. The lock file is resolved
  for PHP 7.4 (`config.platform`), so it installs on every matrix version.

## Pinned tests

A test that asserts a known defect's current behaviour:

- is named `test_<description>_pinned_for_deferred_fix` and tagged
  `@group pinned`;
- has a docblock explaining the defect, where it is, and how the fix will be
  recognised;
- fails once the defect is fixed, which is the signal to turn it into a
  regular regression test and remove the `@todo` from the production
  docblock.

List them with `bin/test.sh all --group pinned`. Lifecycle rules: `CLAUDE.md`,
"Documenting defects".

## CI

- Both workflows run on every push to `updates`, on every pull request into
  `updates` or `main`, and by hand. A pull request from `updates` into `main`
  is skipped (and reported as passing) because the push to `updates` already
  tested that commit, so nothing runs twice. Pushes to `main` do not run CI.
- `.github/workflows/tests.yml` — the release gate. Runs `bin/test.sh --verify`
  once, then `bin/test.sh all` on every matrix cell. The advisory cell may fail
  without failing the workflow. Each job writes the executed counts to the job
  summary and uploads `tests/.results/`. No retries.
- `.github/workflows/lint.yml` — advisory only: `php -l` on every supported PHP
  version and ShellCheck on the scripts.

## Known traps

- **Messages about skipped groups are noise.** The WordPress test bootstrap
  prints "Not running ajax tests…", "ms-files…", "external-http…". Those
  exclusions live in WordPress core's own PHPUnit config, which this project
  does not use; tests in those groups do run here. Check the executed count,
  not the banner.
- **The plugin is mounted read-only.** Tests cannot write into the plugin
  directory; copy fixtures to a temp or uploads path before modifying them.
- **`WPSVG::$settings` is read on `init`.** After changing the
  `wpsvg_settings` option in a test, call `plugin_init()` on the instance
  again.
- **`admin_init` opens an output buffer** (`WPSVG::add_svg_support()`). Tests
  that fire `admin_init` must close it.
- **`WPSVG::add_svg_support()` can run only once per PHP process**, because it
  declares a global function. A test that calls it needs
  `@runInSeparateProcess` and `@preserveGlobalState disabled`; see
  `tests/integration/BootstrapTest.php`.
- **`wp_handle_upload()` only accepts real HTTP uploads.** It checks
  `is_uploaded_file()`, which is always false in PHPUnit. Overriding `action`
  avoids the check but renames the prefilter hook to `{action}_prefilter`, so
  the plugin's `wp_handle_upload_prefilter` callback no longer runs. The REST
  API does the same in tests (`wp_handle_mock_upload`). Use
  `WPSVGIntegrationTestCase::media_upload()` or
  `forward_mock_uploads_to_the_upload_prefilter()`, which pass the mock action
  on to the real hook; real HTTP uploads belong in the `e2e` suite.
- **Uploads persist between runs.** The WordPress copy is cached on the host,
  so the base test case deletes the files a test added to the uploads folder.
  A test that bypasses it leaves files that rename later uploads
  (`image-1.svg`).
- **PHP warnings depend on earlier tests.** The sanitizer switches libxml's
  internal error handling on and never off, which silences later
  `simplexml_load_file()` warnings; the base test case resets it after each
  test. Wrap calls that may warn in `with_warnings_captured()`.
- **`active_plugins` is fixed by the test bootstrap** through a
  `pre_option_active_plugins` filter, so `update_option()` and
  `deactivate_plugins()` do not change what WordPress reads. Add a later
  filter, or remove the filters for one test; see `UpsellTest` and
  `UninstallTest`.
- **Roles outlive the database rollback.** `add_role()` also changes the
  roles WordPress holds in memory; remove test roles in `tear_down()`.
- **`upsell.php` is loaded with `include_once`.** The settings page prints the
  offer only the first time it is rendered in a PHP process; tests of the
  offer run in separate processes, other settings-page tests mark ShortPixel
  Image Optimizer as active on the plugin instance.
- **The AJAX handler checks the `DOING_AJAX` constant**, which
  `WP_Ajax_UnitTestCase` does not define, and its simulated request fires
  `admin_init`. `NoticeDismissalAjaxTest` therefore runs each test in its own
  process.
- **A sanitizer class may come from another plugin.** In the `compat` suite
  the first-loaded plugin supplies the library, so never assert on bundled
  sanitizer behaviour there without checking `sanitizer_copies()`.
- **HTMLPurifier writes a cache next to its own files** and warns when it
  cannot; the libraries and third-party plugins folders are mounted writable
  for that reason.
- **Another plugin may bring HTMLPurifier.** WP All Import ships it, so in
  that scenario a CDATA section does not end in the Error pinned elsewhere.
- **Plugin PHP files use CRLF line endings.** Keep them when editing.
- **`bin/test.sh` targets bash 3.2** (macOS default). Avoid bash 4 features.
