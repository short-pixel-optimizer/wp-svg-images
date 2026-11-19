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

Names are exact and case-sensitive. An unknown name or a wrong case (`Unit`)
exits with code 2 instead of running nothing.

`all`, and a run without suite names, select the six PHPUnit suites. The
browser suite `e2e` needs the web stack and runs only when named;
`bin/test.sh all e2e` is the full release gate.

| Suite | Status | What it covers | Configuration / bootstrap |
|---|---|---|---|
| `unit` | implemented | Sanitizer library and `WPSVG` helpers, no WordPress | `tests/phpunit/unit.xml`, `tests/bootstrap/unit.php` |
| `integration` | implemented | Real WordPress, database and uploads directory; the plugin is active through `active_plugins` | `tests/phpunit/integration.xml`, `tests/bootstrap/integration.php` |
| `compat` | implemented | The plugin next to real third-party plugins and libraries, one run per scenario | `tests/phpunit/compat.xml`, `tests/bootstrap/compat.php`, `tests/compat/scenarios.php` |
| `multisite` | implemented | WordPress installed as a network, the plugin network-activated | `tests/phpunit/multisite.xml`, `tests/bootstrap/multisite.php` |
| `cli` | implemented | WP-CLI commands against a freshly installed site: activation, imports with and without `--user`, removal | `tests/phpunit/cli.xml`, `tests/bootstrap/cli.php`, `tests/bootstrap/wp-cli-config.php` |
| `l10n` | implemented | Text domain loading with a generated translation; `languages/wp-svg-images.pot` against the sources | `tests/phpunit/l10n.xml`, bootstrap of `integration` |
| `e2e` | implemented, opt-in | Chromium against a running site: real logins, clicks and HTTP uploads | `tests/e2e/playwright.config.js`, `tests/e2e/site/` |

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
- **Second versions.** `previous_plugins` in the manifest pins an older
  release of a third party where the part this plugin relies on differs. A
  scenario with `versions` => `previous` runs the same test folder against
  those releases, from a plugins directory of their own; the tests ask
  `uses_previous_versions()` where the two releases behave differently, and
  `pinned_version()` for the version to expect. A scenario can name the newest
  WordPress branch an old release is run on (`wordpress_up_to`).
- **Downloads** happen in the `setup` container only, into
  `tests/.cache/compat/`. The test run itself stays offline.
- **Own plugins directory.** The suite sets `WP_PLUGIN_DIR` to a folder that
  holds the pinned third parties and this plugin, so the other suites never
  see them. It also uploads to `wp-content/uploads-compat`, because some
  third parties create folders in the uploads directory.
- **Activation routines.** Activating through the `active_plugins` option
  runs no activation hook. A scenario lists under `activate` the plugins that
  need theirs (WP All Import creates its tables there); the bootstrap fires it
  at the start of `init`.
- **Unsupported versions.** When a third-party plugin's header asks for a
  newer WordPress or PHP than the cell runs, the bootstrap does not load it
  and every test of that scenario is skipped with that reason. The skips are
  listed after the `Executed:` line.
- **New test classes** extend `CompatTestCase`, which adds the scenario
  helpers to the integration test case.

| Scenario | Active | Tests |
|---|---|---|
| `baseline` | this plugin only; every third party installed but inactive (includes ShortPixel Image Optimizer inactive and the Git Updater headers) | `tests/compat/Baseline/` |
| `safe-svg-first` | Safe SVG, then this plugin (WordPress's default order) | `tests/compat/SafeSvg/` |
| `safe-svg-last` | this plugin, then Safe SVG | `tests/compat/SafeSvg/` |
| `htmlpurifier` | this plugin, HTMLPurifier loaded (another plugin may bring it; CDATA output must not change) | `tests/compat/HtmlPurifier/` |
| `wp-all-import` | WP All Import (activation routine run), then this plugin | `tests/compat/WpAllImport/` |
| `beaver-builder` | Beaver Builder Lite, then this plugin | `tests/compat/BeaverBuilder/` |
| `shortpixel` | ShortPixel Image Optimizer (activation routine run), then this plugin | `tests/compat/ShortPixel/` |
| `gutenberg` | Gutenberg plugin, then this plugin | `tests/compat/Gutenberg/` |
| `safe-svg-previous` | older Safe SVG (sanitizes in the prefilter only, allows the SVG type on every request, ships HTMLPurifier), then this plugin | `tests/compat/SafeSvg/` |
| `wp-all-import-previous` | older WP All Import (does not sanitize SVG, no HTMLPurifier), then this plugin | `tests/compat/WpAllImport/` |
| `beaver-builder-previous` | older Beaver Builder Lite (shorter photo pattern, no audio module), then this plugin; up to WordPress 6.4 | `tests/compat/BeaverBuilder/` |
| `gutenberg-previous` | older Gutenberg, the newest that supports WordPress 6.4, then this plugin | `tests/compat/Gutenberg/` |

### The `cli` suite

PHPUnit loads no WordPress here. Every test gets a freshly installed site and
starts each `wp` command as its own process through `CliTestCase::wp()`;
assertions look at exit code, output, and the files and rows the command left.

- **WP-CLI is pinned** by version and checksum in
  `tests/docker/php.Dockerfile`.
- **The site's `wp-config.php`** is `tests/bootstrap/wp-cli-config.php`, handed
  to WP-CLI through `WP_CONFIG_PATH`, so the cached WordPress copy stays
  without one. The site uses the database `wpsvg_cli` and uploads to
  `wp-content/uploads-cli`.
- **Imports** run `tests/cli/scripts/import-media.php` with `wp eval-file`
  instead of `wp media import`, which refuses to start without GD or Imagick.
  The script does what that command does with a local file.
- **WP-CLI's own deprecation notices are ignored.** The pinned release prints
  them on the newest PHP version; `CliTestCase::wp()` drops the lines that
  name WP-CLI's phar from both output streams and keeps everything else.
- **Slow by nature:** each test installs WordPress and starts several PHP
  processes; the suite takes about half a minute.

### The `l10n` suite

- **`TemplateTest`** compares the translatable strings in the PHP sources with
  `languages/wp-svg-images.pot`. `tests/l10n/TranslatableStrings.php` extracts both
  sides with PHP's tokenizer; add new source files to `TemplateTest::SOURCES`.
  The bundled promo banner (`build/shortpixel/`) is one of the sources: after
  a module rebuild, regenerate the template.
- **`TextDomainTest`** switches the site to the locale `xx_XX` and answers the
  translation file WordPress asks for with one generated during the test. The
  language pack test writes a real file to `WP_LANG_DIR/plugins` and clears
  WordPress's cached list of that folder. Between tests the domain is unloaded
  without the "reloadable" flag: since WordPress 6.5 a reloadable unload keeps
  the loaded files, and they would answer in the next test.

### The `e2e` suite

Playwright drives Chromium against a real site. `bin/test.sh e2e` empties the
database and the uploads folder, installs WordPress, starts the site, runs the
specs and applies the same zero-test guard as for the other suites. The
`assertions` count is always 0: Playwright's report has none.

- **Site:** the `web` service serves the cached WordPress copy with PHP's
  built-in web server from the image the other suites use, as
  `http://web:8080` on the internal network. Its `wp-config.php` and a
  must-use helper plugin live in `tests/e2e/site/`; the database is
  `wpsvg_e2e`, uploads go to `wp-content/uploads-e2e`.
- **Browser:** the official Playwright image. Its version in
  `tests/docker/compose.yml` and the `@playwright/test` version in
  `tests/e2e/package.json` have to be equal; bump both and refresh
  `package-lock.json` together.
- **Known state:** `resetSite()` in `tests/e2e/support/site.js` calls the
  helper plugin, which deletes all content and plugin data, seeds one user per
  role (`admin`, `editor`, `author`, `contributor`, `subscriber`, password
  `password`) and sets the plugin's state and settings. `siteState()` reads
  options, stored files and metadata. Call `resetSite()` before each test.
- **Promo banner:** the helper plugin keeps the campaign window closed unless
  the test resets the site with `campaign: true`, so no spec depends on the
  date. The banner's script does not read the answer of its dismiss request;
  wait for the response status and check `banner_dismissals` in
  `siteState()`, not the response body, which never finishes loading for
  Playwright.
- **No network:** Node packages are installed by the `e2e-setup` service
  before the run. The helper plugin answers WordPress's own outbound requests
  with an empty success, so update checks print no warnings.
- **Selection:** `--class NAME` selects spec files whose path contains NAME,
  `--filter PATTERN` is Playwright's `--grep` on test titles, and
  `--group pinned` selects tests whose title carries the tag `@pinned`.
- **Rules:** one worker, no retries, no `waitForTimeout`. Wait on what
  WordPress shows or answers.
- **After a failure** the screenshot, trace and page snapshot are in
  `tests/.results/e2e-artifacts/`.
- **Uploads:** `tests/e2e/support/media.js` uploads through the Media Library
  grid or the plain upload form, `tests/e2e/support/editor.js` through an
  image block. Both uploaders know the file types WordPress allows for the
  user and refuse other files in the browser, without a request; the helpers
  report whether a request was sent. To test the server's refusal, use
  `uploadWithPlainForm()` or a REST request.
- **Editor errors come from the notice store.** In the page an upload error
  looks like the "Uploading" and "Upload complete" messages of current
  WordPress; `editorError()` reads `core/notices` and takes notices with the
  status `error` only.
- **Previews** are checked with `expectLoadedSvg()`: the image's address ends
  in `.svg` and the browser decoded it. There are no pixel snapshots.

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
| 2 | Usage error: unknown option or unknown suite |
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
5. Unknown suite names (`Unit`, `browser`) are rejected.
6. The e2e canary runs alone: exactly 1 test executed and passed, with a
   browser reaching a site on the requested PHP and WordPress versions.

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

- All workflows run on every push to `updates`, on every pull request into
  `updates` or `main`, and by hand. A pull request from `updates` into `main`
  is skipped (and reported as passing) because the push to `updates` already
  tested that commit, so nothing runs twice. Pushes to `main` do not run CI.
- `.github/workflows/tests.yml` — the release gate. Runs `bin/test.sh --verify`
  once, then `bin/test.sh all` on every matrix cell. The advisory cell may fail
  without failing the workflow. Each job writes the executed counts to the job
  summary and uploads `tests/.results/`. No retries.
- `.github/workflows/e2e.yml` — the browser part of the release gate. Runs
  `bin/test.sh e2e` on the oldest and the newest cell (PHP 7.4 / WP 6.4 and
  PHP 8.5 / latest), both blocking, and uploads the report with screenshots
  and traces of failed tests.
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
- **PHP warnings can depend on earlier tests.** The bundled sanitizer
  restores libxml's internal error handling when it is done, but an older
  copy loaded by another plugin (a `compat` scenario) switches it on and
  never off, which silences later `simplexml_load_file()` warnings; the base
  test cases reset it after each test. Wrap calls that may warn in
  `with_warnings_captured()`.
- **`active_plugins` is fixed by the test bootstrap** through a
  `pre_option_active_plugins` filter, so `update_option()` and
  `deactivate_plugins()` do not change what WordPress reads. Add a later
  filter, or remove the filters for one test; see `UpsellTest` and
  `UninstallTest`.
- **The promo banner shows only inside its campaign window**, so the
  `integration`, `l10n`, `multisite` and `compat` bootstraps close the window
  for every test (`wpsvg/promo_banner/in_window` returns false at priority
  10). A test about the banner adds `__return_true` at a later priority
  (`PromoBannerTest::open_window()`) or removes the bootstrap filter
  (`PromoBannerModuleTest`, which drives the window through `start`/`end`).
- **The banner exists only after `admin_init`.** `WPSVG::$promo_banner` is
  null until `WPSVG::promo_banner()` ran; tests call that method directly
  and set the property back to null in `tear_down()`. The screen ID of the
  settings page differs between the test environment and a real admin
  request; read it from `WPSVG::$plugin_admin_page` after `admin_menu`.
- **WordPress includes `uninstall.php` once per PHP process.** A second
  `uninstall_plugin()` call in the same process does not run it again; the
  test that needs the deletion runs in its own process (`UninstallTest`).
- **`build/shortpixel/` is generated.** `PromoBannerBuildTest` (`unit`)
  checks the namespace, hook prefix and text domain of the copy; when it
  fails, rebuild (see `CLAUDE.md`), do not edit the copy.
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
- **Another plugin may bring HTMLPurifier.** WP All Import and older Safe
  SVG releases ship it. The bundled sanitizer no longer uses it, so CDATA
  output is asserted to be the same with and without it.
- **On a network, settings follow the request, not the current site.** The
  plugin reads its option on `init` only. A `multisite` test that moves to
  another site calls `plugin_init()` after `switch_to_blog()` to act like a
  request to that site, unless the missing reload is what it tests.
- **Network activation is answered by a filter** on
  `pre_site_option_active_sitewide_plugins` in the `multisite` bootstrap, like
  `active_plugins` in the other suites.
- **The test image has no `mysql` client and no GD.** `wp db ...` commands
  and `wp media import` do not work in it; see the `cli` suite above.
- **`wp plugin list` checks for updates** and prints a warning offline. Use
  `wp plugin get <name> --field=...`.
- **WordPress remembers where a translation was loaded from.** A test that
  loads a translation file from a temporary folder replaces
  `$wp_textdomain_registry` afterwards, or later tests look in that folder.
- **A generated translation file needs the `.mo` extension**; WordPress picks
  the reader by it.
- **Plugin PHP files use CRLF line endings.** Keep them when editing.
- **`bin/test.sh` targets bash 3.2** (macOS default). Avoid bash 4 features.
