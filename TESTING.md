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
| `compat` | planned | Third-party plugins active and inactive | — |
| `multisite` | planned | Network installs | — |
| `cli` | planned | WP-CLI imports and activation | — |
| `l10n` | planned | Text domain loading and translation template | — |
| `e2e` | planned | Real browser against a running site | — |

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
bin/test.sh integration --php 8.4 --wp latest
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
| 8.1 | 6.9 | blocking |
| 8.3 | 7.0 | blocking |
| 8.4 | latest | blocking |
| 8.5 | latest | advisory |
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

- `.github/workflows/tests.yml` — the release gate. Runs `bin/test.sh --verify`
  once, then `bin/test.sh all` on every matrix cell. Advisory cells may fail
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
- **`wp_handle_upload()` only accepts real HTTP uploads.** It checks
  `is_uploaded_file()`, which is always false in PHPUnit. Overriding `action`
  avoids the check but renames the prefilter hook to `{action}_prefilter`, so
  the plugin's `wp_handle_upload_prefilter` callback no longer runs. Tests of
  the upload gate apply `wp_handle_upload_prefilter` explicitly, call
  `wp_handle_sideload()` on purpose (it fires `wp_handle_sideload_prefilter`),
  or belong in the `e2e` suite.
- **Plugin PHP files use CRLF line endings.** Keep them when editing.
- **`bin/test.sh` targets bash 3.2** (macOS default). Avoid bash 4 features.
