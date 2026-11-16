# WP SVG Images — Documentation and Testing Plan

Status: **approved.** Each phase below ends with a hand-off and an approval
checkpoint. The decisions from §7 are recorded there.

---

## 1. Code inspection summary

### 1.1 Shape of the project

| Item | Finding |
|---|---|
| Language | PHP (plugin + bundled library), one small jQuery script, one minified CSS file |
| Framework | WordPress plugin API (actions and filters); no Composer, no autoloader, no build step |
| Declared runtime | `readme.txt`: *Requires at least* WP 3.0.1, *Requires PHP* 5.6.40, *Tested up to* WP 7.0 |
| Actual runtime floor | PHP 7.1+ (nullable type hints in `svg-sanitizer/Exceptions/NestingException.php`); WP 5.0+ for the `render_block` integration |
| Entry point | `wp-svg-images.php` — plugin header, `require_once` of the bundled sanitizer, three classes, `new WPSVG()` at load time |
| Other PHP | `upsell.php` — template partial included from the settings page with `$this` bound to `WPSVG` |
| Bundled library | `svg-sanitizer/` — a vendored copy of `enshrined/svg-sanitize` (namespace `enshrined\svgSanitize`), version not recorded anywhere in the tree |
| Assets | `assets/js/spio-upsell.js`, `assets/css/spio-upsell.css`, `assets/images/shortpixel-image-optimiser.svg` |
| i18n | Text domain `wpsvg`, `languages/wpsvg.pot` |
| Dependency tooling | None. `.gitignore` already lists `vendor`, `composer.lock`, `/wordpress/`, `/travis_output/`, `.env.testing` (leftovers of an earlier test setup that is not in the tree) |
| Distribution | WordPress.org SVN (`readme.txt` format) and Git Updater (`GitHub Plugin URI`, `Primary Branch` headers) |
| Existing tests | None |
| Existing CI | None (no `.github/`) |
| Existing docs | `readme.txt` (user-facing, includes the two developer filters); no developer or agent docs |

### 1.2 Size

| Area | Files | Lines | Classes / interfaces | Functions / methods |
|---|---:|---:|---:|---:|
| Plugin (`wp-svg-images.php`, `upsell.php`) | 2 | 488 | 3 | 26 (incl. one global function declared inside a method) |
| Sanitizer core (`Sanitizer.php`, `Helper.php`) | 2 | 726 | 2 | 32 |
| Sanitizer element references (`ElementReference/`) | 3 | 368 | 3 | 22 |
| Sanitizer data (`data/`) | 5 | 555 | 3 classes + 2 interfaces | 7 |
| Sanitizer exceptions | 1 | 36 | 1 | 2 |
| JS / CSS | 2 | ~25 | — | 1 click handler |
| **Total PHP** | **13** | **2 173** | **14** | **89** |

### 1.3 Architecture and patterns

- **Single god-class with self-instantiation.** `WPSVG` registers every hook in
  its constructor and is instantiated at file load (`new WPSVG()`), guarded by
  `class_exists('WPSVG')`. There is no global handle to the instance, so tests
  reach it through `$wp_filter` or by constructing a fresh instance.
- **Hook-driven pipeline.** All behaviour is in callbacks:
  - Upload gate: `upload_mimes` → `wp_check_filetype_and_ext` (priority 100) →
    `wp_handle_upload_prefilter` (MIME sniff with `mime_content_type`, then
    sanitize in place, gzip-aware).
  - Metadata: `wp_generate_attachment_metadata`, `wp_prepare_attachment_for_js`,
    `render_block` (`core/image` width/height), all via `svg_dimensions()`
    (SimpleXML, `width`/`height` then `viewBox`).
  - Admin UI: settings page under *Settings → SVG images* (role → 0/1/2 map,
    stored in option `wpsvg_settings`), admin notice with AJAX dismissal (user
    meta `wpsvg_notice_dismissed`), activation flag option `WPSVGActivated`
    that triggers a redirect on the next `admin_init`.
  - Media modal templates: `admin_init` opens an output buffer for the whole
    admin request and string-replaces two Backbone template fragments
    (`final_output` filter).
- **Settings model.** Per-role integer: `0` disabled, `1` sanitized, `2`
  unrestricted. Read once on `init` into `$this->settings`.
- **Extension points.** Filters `WPSVG_setAllowedTags`, `WPSVG_setAllowedAttrs`
  (documented in `readme.txt`); internal filter `final_output`.
- **Bundled library guard.** The sanitizer is only `require`d when
  `enshrined\svgSanitize\Sanitizer` is not already loaded, so another plugin's
  copy (any version) can win.
- No DI, no MVC, no queues, no cron, no custom tables, no REST routes, no
  WP-CLI commands.

### 1.4 External services and third-party integrations

| Integration | How it is detected / hooked | What it changes |
|---|---|---|
| Gutenberg `core/image` block | `render_block` filter | Injects `width`/`height` into `<img>` for SVG attachments |
| Beaver Builder | `fl_module_upload_regex` filter | Adds `svgz?` to the photo module's allowed-extension regex |
| WP All Import | `wp_all_import_image_mime_type` filter | Reports `image/svg+xml` for `.svg`/`.svgz` when WPAI found no type |
| ShortPixel Image Optimizer | `get_plugins()` / `is_plugin_active('shortpixel-image-optimiser/wp-shortpixel.php')` | Shows upsell box and enqueues upsell assets when not installed/active |
| WordPress.org plugin installer | Upsell links to `update.php?action=install-plugin` / `plugins.php?action=activate`, fetched by `spio-upsell.js` | Network install of a third-party plugin from the admin |
| Other plugins bundling `enshrined/svg-sanitize` (e.g. Safe SVG) | `class_exists` guard | Their sanitizer version is used instead of the bundled one |
| HTMLPurifier | Referenced by `Sanitizer::cleanUnsafeNodes()` for CDATA sections; **not bundled** | Behaviour depends on whether any other plugin loads HTMLPurifier |
| Git Updater | Plugin header only | Update source; no runtime code |
| External HTTP | `wp_prepare_attachment_for_js` falls back to `simplexml_load_file($response['url'])` when the file is missing locally | Remote fetch of the attachment URL (offloaded media / CDN setups) |
| PHP extensions | `fileinfo` (`mime_content_type`), `libxml`/`dom`/`simplexml`, `zlib` (`gzdecode`/`gzencode`), optional `mbstring` | Hard runtime requirements, none declared |

---

## 2. Documentation gap analysis

Counted as "documented" when a docblock immediately precedes the declaration.

| Area | Documented / total symbols | Notes |
|---|---:|---|
| `wp-svg-images.php` | **0 / 29** | No docblocks at all; file has a plugin header only |
| `upsell.php` | n/a | Template; no header describing the expected `$this` |
| `Sanitizer.php` | 30 / 30 | Several are type-only or one-liners |
| `Helper.php` | 3 / 4 | Class undocumented; method docblocks are type-only |
| `ElementReference/*` | 20 / 25 | Classes and `Resolver::__construct`, `collect` undocumented |
| `data/*` | 10 / 13 | `XPath` class, constructor, `handleDefaultNamespace` undocumented |
| `Exceptions/NestingException.php` | 2 / 3 | Class undocumented |
| **Total** | **65 / 104 (62 %)** | **Plugin code: 0 %. Bundled library: 87 %.** |

Where documentation contradicts the code (to be reconciled in Phase 2 as
present-tense docs plus `@todo` where the code looks wrong — no code changes):

1. `readme.txt` *Requires PHP: 5.6.40* — the bundled library uses PHP 7.1 syntax.
2. `readme.txt` *Requires at least: 3.0.1* — the block integration needs WP 5.0.
3. `readme.txt` "Malicious SVG/XML files are rejected from upload" — malicious
   content is stripped and the file is accepted; rejection happens only when the
   XML cannot be parsed.
4. Settings page preselects *enable sanitized SVG upload* for roles that have no
   saved value, while the upload filters treat a missing value as disabled.
5. `Sanitizer::removeNonPrintableCharacters()` documents `@return bool`, returns
   a string.
6. `Sanitizer` has no mention of its HTMLPurifier dependency; `XPath`'s
   constructor throws `LogicException` without saying so.
7. `languages/wpsvg.pot` is stale: line references no longer match and at
   least one current string (`Temp file is not be set!`) is absent.

---

## 3. Proposed test environment

### 3.1 Runtime

Docker Compose only (Docker is the one tool available on the maintainer's
machine; no local PHP, Composer or Node is assumed).

| Service | Image | Used by |
|---|---|---|
| `db` | `mariadb:10.11` (tmpfs data dir) | integration, compat, project suites, e2e |
| `php` | Built from `tests/docker/php.Dockerfile`, arg `PHP_VERSION`; adds `pdo_mysql`/`mysqli`, `zip`, Composer, Subversion/Git, WP-CLI | unit, integration, compat, project suites |
| `wp` | `wordpress:php${PHP_VERSION}-apache` with the plugin bind-mounted read-only | e2e only |
| `playwright` | `mcr.microsoft.com/playwright` pinned tag | e2e only |

- WordPress core and the WP PHPUnit library (`tests/phpunit/includes` from
  `wordpress-develop` at the matching tag) are fetched into the git-ignored
  `tests/.cache/wp/<version>/`, which CI can cache as a plain directory.
- Free third-party plugins for the compat suite are downloaded at
  **environment build** time at pinned versions into the same cache volume.
  Test runs themselves run on an internal Docker network with no egress.
- Test dependencies live in `tests/composer.json` (PHPUnit 9.6,
  `yoast/phpunit-polyfills`, Brain Monkey). The plugin itself keeps having no
  Composer manifest.

### 3.2 The single command

```
bin/test.sh [suite|all] [--php X.Y] [--wp X.Y|latest] [--filter NAME] [--class NAME] [--verify]
```

- Default: all PHPUnit suites, sequentially, on the default matrix cell.
  E2E is opt-in by name (`bin/test.sh e2e`) because it needs the web stack.
- Unknown suite name → exit 2 with the list of valid names.
- Every run writes JUnit XML; the script reads the executed count from it,
  prints `Executed: N tests, M assertions, S skipped` and **exits non-zero when
  N = 0** (including when a `--filter` matches nothing).
- Suites never run in parallel; each suite gets a freshly created test
  database.

### 3.3 Version matrix

CI runs a diagonal rather than the full cross product. `latest` is resolved
at run time; 7.0 stays as its own cell because `readme.txt` declares it as
the tested-up-to version.

| PHP | WordPress | Gate |
|---|---|---|
| 7.4 | 6.4 | blocking |
| 8.3 | 7.0 | blocking |
| 8.5 | latest | blocking |
| 8.3 | nightly | advisory |

### 3.4 What "verified" means

`bin/test.sh --verify` passes only if **all** hold:

1. The container reports the requested PHP version and the required
   extensions (`fileinfo`, `dom`, `simplexml`, `libxml`, `zlib`).
2. The unit canary test runs: JUnit shows exactly 1 test executed and passed.
3. The integration canary runs: WordPress loaded at the requested version, the
   plugin is active, a DB write and read-back succeeded, exactly 1 test
   executed and passed.
4. A deliberately failing canary (selected by a reserved filter) makes the
   runner exit non-zero — proving failures are not swallowed.
5. A filter that matches nothing makes the runner exit non-zero — proving the
   zero-test guard works.

---

## 4. Suite ladder

Suite names are exact and lowercase: `unit`, `integration`, `compat`,
`multisite`, `cli`, `l10n`, `e2e`.

### 4.1 `unit` — no WordPress loaded

- **Scope, bottom up:**
  1. `svg-sanitizer/data` — tag/attribute lists, `XPath` namespace handling and
     its root-element rule.
  2. `svg-sanitizer/ElementReference` + `Helper` — `Usage` counting, `Subject`
     loop detection, nesting limit and `NestingException`, `Resolver`
     collection and removal list.
  3. `Sanitizer` — every public method; malicious corpus (scripts, event
     handlers, `javascript:`/`data:` hrefs incl. obfuscated, `foreignObject`,
     `<use>` bombs, XXE/entity expansion, PHP tags, CDATA, `<font>` breakout,
     remote references, aria/data attributes), malformed and empty input,
     minify / XML-tag options, `getXmlIssues()`.
  4. `WPSVG` pure helpers with WordPress functions mocked by Brain Monkey:
     `is_gzipped`, `svg_dimensions` (units, percentages, `viewBox` variants,
     invalid XML, missing file), `final_output`, `fl_module_upload_regex`,
     `wp_all_import_svgs`, `filter_plugin_actions`, `WPSVG_allowedTags/Attrs`
     filter pass-through.
- **Tooling:** PHPUnit 9.6 + polyfills, Brain Monkey; fixtures under
  `tests/fixtures/svg/`.
- **Done when:** every public method has documented-behaviour, boundary and
  error-path tests; suite green on the whole matrix.
- **Size:** ~140 tests.
- **Risks:** the plugin file self-instantiates and calls `add_filter` at load —
  the bootstrap must define stubs before `require`; the sanitizer's behaviour
  depends on the libxml version (differs across PHP images).

### 4.2 `integration` — real WordPress, real DB, real filesystem

- **Scope:** plugin bootstrap and hook registration; activation option and
  redirect; settings page render and save (nonce, capability, arbitrary
  `role_*` keys, value range); role matrix × {svg, svgz, uppercase extension,
  spoofed MIME, non-SVG XML} through the real `wp_handle_upload` and
  `media_handle_upload`; REST `POST /wp/v2/media` (multipart **and** raw body,
  which goes through `wp_handle_sideload`); `media_sideload_image`; attachment
  metadata; `wp_prepare_attachment_for_js`; `render_block`; admin notice
  visibility per screen and capability; AJAX dismissal (`WP_Ajax_UnitTestCase`);
  upsell asset enqueueing with SPIO present/absent; output-buffer template
  rewrite on a real admin request; uninstall leftovers (option, user meta).
- **Tooling:** WP PHPUnit library (`WP_UnitTestCase`), real uploads directory
  under the test install.
- **Done when:** every user-facing action is covered from input to persisted
  result (file on disk, attachment post, metadata, option, user meta).
- **Size:** ~70 tests.
- **Risks:** `is_uploaded_file()` blocks `wp_handle_upload` with fake files —
  use `test_form => false` / `action` override and document it; the output
  buffer opened on `admin_init` must be closed per test; `$this->settings` is
  read on `init`, so tests must re-run `plugin_init()` after changing options.

### 4.3 `compat` — third parties

In order of user impact:

| Third party | Source in tests | Active / inactive cases |
|---|---|---|
| Plugins bundling `enshrined/svg-sanitize` (Safe SVG) | Real plugin from wp.org, pinned | Which sanitizer wins; plugin filters still applied; no fatal on load order either way |
| HTMLPurifier presence | Real library, pinned in the compat manifest (not in `tests/composer.json`, whose autoloader would load it in every suite) vs. absent | CDATA-bearing SVG upload with and without it |
| WP All Import | Real free plugin, pinned | `wp_all_import_image_mime_type` result for svg/svgz/other |
| Beaver Builder (Lite) | Real free plugin, pinned | Photo module regex extended only for svg/svgz |
| ShortPixel Image Optimizer | Real free plugin, pinned | Upsell shown when absent / inactive, hidden when active |
| Gutenberg plugin | Real plugin, pinned, alongside core block editor | `core/image` width/height injection with plugin markup variants |
| Git Updater | Header assertion only (no runtime) | Header keys present and parseable |

- **Done when:** each row has active and inactive tests, plus a second pinned
  version where the third party's public surface changed.
- **Size:** ~30 tests. **Risk:** third-party plugin versions drift — versions
  are pinned in one manifest file and bumped deliberately.

### 4.4 Project-specific suites (global modes)

| Suite | Own bootstrap | Scope | Size |
|---|---|---|---:|
| `multisite` | `WP_TESTS_MULTISITE=1` | Per-site settings isolation; super admin without a role on a subsite; network-activated plugin; `upload_filetypes` site option interplay | ~15 |
| `cli` | WP-CLI inside the php container | `wp media import` of svg/svgz with no current user and with `--user`; activation via CLI (redirect flag left behind) | ~8 |
| `l10n` | Loads a test locale | Text domain loads from `languages/`; every translatable string in PHP exists in `wpsvg.pot` (catches stale templates) | ~5 |

- **Risk:** CLI and multisite each change who "the current user" is, which is
  exactly what the upload gate keys on — high-value, easy to get wrong.

### 4.5 `e2e` — real browser

- **Tool:** Playwright (official image), Chromium only, `retries: 0`,
  hermetic network (`wp` and `playwright` on an internal network; upsell
  install/activate requests answered by `page.route` stubs).
- **Seed:** WP-CLI script creates one user per role and a fixed set of
  attachments before the run; DB reset between spec files.
- **Journeys, in order:**
  1. Admin activates plugin → lands on settings page → sets per-role modes → saves.
  2. Author / Editor / Admin upload a clean SVG in the media modal and in the
     block editor; preview renders in grid, attachment details and image block.
  3. Malicious SVG upload: stored file has the payload stripped; unparseable SVG
     shows the error message.
  4. Disabled role: upload refused with WordPress's file-type error.
  5. Admin notice: shown on the four screens, dismissal persists across reloads.
  6. Upsell box: install → activate state transitions (stubbed responses).
  7. Front end: image block outputs width/height for an SVG.
- **Waits:** on WordPress's own signals (upload progress element removed,
  attachment `li` with `data-id`, REST response promises) — never on time.
- **Visual snapshots:** only for the media-library SVG thumbnail and attachment
  details preview (the `final_output` template rewrite is invisible otherwise).
- **CI:** separate workflow, same no-retry rule. **Size:** ~20 specs.
- **Risk:** media modal markup changes between WP versions — run against the
  oldest and latest WP from the matrix.

---

## 5. Conventions

| Topic | Convention |
|---|---|
| Layout | `tests/{unit,integration,compat,multisite,cli,l10n}/…`, `tests/e2e/specs/…`, `tests/fixtures/svg/{clean,malicious,malformed,dimensions}/`, `tests/bootstrap/<suite>.php`, `tests/phpunit/<suite>.xml` |
| Files and classes | One class per file, mirrors the subject: `tests/unit/Sanitizer/SanitizerTest.php` → `SanitizerTest` |
| Methods | `test_<behaviour_in_snake_case>` (WordPress style), e.g. `test_strips_onload_attribute_from_root_svg` |
| Pinned tests | `test_<description>_pinned_for_deferred_fix`, `@group pinned`, docblock states the defect, where it is, and how to recognise the fix; assertions designed to fail when the defect is gone |
| Data providers | `provide_<thing>()`, keyed with readable case names |
| Fixtures | Static files only, one behaviour per file, named for what they contain (`onload-on-root.svg`); generated fixtures (gzip, large `<use>` graphs) built in the test, not committed |
| Selecting a suite | `bin/test.sh <suite>` |
| Single class / method | `bin/test.sh unit --class SanitizerTest` / `bin/test.sh unit --filter 'SanitizerTest::test_strips_onload_attribute_from_root_svg'` |
| Version | `bin/test.sh integration --php 8.3 --wp 6.9` |
| Pinned list | `bin/test.sh all --group pinned` |

---

## 6. Order and checkpoints

| # | Phase | Deliverable | Checkpoint |
|---|---|---|---|
| 1 | Planning | This file | **Approve plan** |
| 2 | Documentation | Docblocks for all 104 symbols (plugin first, then sanitizer data → references → core), `@todo` for suspect code, `CLAUDE.md`, `AGENTS.md`, `.github/copilot-instructions.md`, `.cursor/rules/project.mdc`, `TESTING.md` skeleton | Approve docs |
| 3 | Test environment | Docker stack, `bin/test.sh`, CI workflow, advisory lint workflow, `TESTING.md` complete, `--verify` output | Approve environment |
| 4 | Unit | `unit` suite, one hand-off per area (data → references → sanitizer → plugin helpers) | Approve per area |
| 5 | Integration | `integration` suite | Approve |
| 6 | Compat | `compat` suite, one hand-off per third party | Approve per integration |
| 7 | Project suites | `multisite`, `cli`, `l10n` | Approve |
| 8 | E2E | `e2e` suite + workflow | Approve |

---

## 7. Decisions

1. **Supported floor.** PHP 7.4 / WP 6.4 is the oldest tested cell.
   (`readme.txt` headers are documented as-is; changing them is a product
   decision.)
2. **Lockfile.** `tests/composer.lock` is committed; `.gitignore` gets one
   negation line for it.
3. **Release packaging.** `tests/`, `bin/`, `docs/`, `.github/`, `.cursor/`,
   `CLAUDE.md`, `AGENTS.md`, `TESTING.md` are excluded from releases through
   `.distignore` and `.gitattributes export-ignore`, added in Phase 3.
4. **Release gate.** All seven suites (`unit`, `integration`, `compat`,
   `multisite`, `cli`, `l10n`, `e2e`) must be green before a release.
