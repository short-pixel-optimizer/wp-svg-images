# CLAUDE.md — WP SVG Images

Canonical guide for coding agents working in this repository. `AGENTS.md`,
`.github/copilot-instructions.md` and `.cursor/rules/project.mdc` point here;
change rules in this file only.

## Overview

WordPress plugin that lets chosen user roles upload SVG and SVGZ files to the
Media Library, sanitizes them on upload, records their dimensions and shows
SVG previews in the Media Library and the image block.

- Plugin version: `Version:` header in `wp-svg-images.php` and `Stable tag:` in
  `readme.txt` (keep them equal).
- Declared WordPress / PHP requirements: `readme.txt` header.
- Tested version matrix: `docs/TESTING-PLAN.md` §3.3.
- Text domain: `wp-svg-images` (the wordpress.org slug, so language packs
  apply); template: `languages/wp-svg-images.pot`, regenerated with
  `wp i18n make-pot` (it includes the strings of the bundled promo banner in
  `build/shortpixel/`; exclude `tests`, `svg-sanitizer`, `assets`, `bin`,
  `docs` and `vendor`).

## Build and dependencies

- The plugin's own code has no build step. The sanitizer is vendored in
  `svg-sanitizer/` and loaded with `require_once`.
- The shared ShortPixel modules (the modules repository, cloned next to this
  one as `../modules`) are bundled the way the other ShortPixel plugins bundle
  them. The root `composer.json` requires them through a path repository and
  runs `ShortPixel\Build\Build::BuildIt`, which copies each module into
  `build/shortpixel/<module>/` and rewrites it for this plugin (`extra`
  block): namespace `ShortPixel\` to `ShortPixel\SVG\`, hook prefix
  `shortpixel/` to `wpsvg/`, text domain to `wp-svg-images`. It also writes
  `build/shortpixel/autoload.php`, `PackageLoader.php` and `composer.json`
  (the autoload map); the plugin file requires `autoload.php`. Only
  `promo-banner` is bundled.
- `build/shortpixel/` is generated and **tracked**: never edit it by hand. A
  fix goes into `../modules`, then the build is run again. `vendor/`
  (symlinks into `../modules`) and `composer.lock` are local and ignored; the
  root `composer.json` is build tooling only and is not shipped
  (`.distignore`, `.gitattributes`). Add no runtime dependency to it.
- Rebuild in a container that has Composer, with the parent directory
  mounted for the path repository:
  ```sh
  docker run --rm -v "$(dirname "$PWD")":/git -w "/git/$(basename "$PWD")" \
    -e COMPOSER_ALLOW_SUPERUSER=1 spio-tests:php8.3 composer update
  ```
  Then regenerate the translation template, which holds the banner's strings.
- The rewrite matters because other ShortPixel plugins bundle the same
  modules under their own names and run on the same site: this plugin's copy
  must never keep the source names.
- Runtime PHP extensions used: `fileinfo`, `dom`, `libxml`, `simplexml`,
  `zlib`; `mbstring` optional.
- Test tooling lives under `tests/` with its own `tests/composer.json`; keep
  test dependencies out of the root manifest.
- Development tools run in Docker; do not assume PHP, Composer or Node on the
  host.

## Tests

See `TESTING.md` for the full instructions. Suite names (exact, lowercase):
`unit`, `integration`, `compat`, `multisite`, `cli`, `l10n`, `e2e`; `--list`
shows which are implemented.

```sh
bin/test.sh                                   # the six PHPUnit suites
bin/test.sh e2e                               # browser suite, only when named
bin/test.sh all e2e                           # full release gate
bin/test.sh unit                              # one suite
bin/test.sh unit --class SanitizerTest        # one class
bin/test.sh unit --filter 'SanitizerTest::test_name'
bin/test.sh all --group pinned                # pinned defects
bin/test.sh all --php 7.4 --wp 6.4            # another matrix cell
bin/test.sh --verify                          # prove the environment works
```

- Always report the `Executed: N tests` count, never just "green".
- Zero executed tests is a failure (exit 1); unknown suite names exit 2.
- Only one run at a time; a second run exits 3.
- Test dependencies: `tests/composer.json` (lock resolved for PHP 7.4).

## Architecture

| Path | Role |
|---|---|
| `wp-svg-images.php` | Plugin header, loads the sanitizer, `WPSVG_allowedTags`, `WPSVG_allowedAttrs`, `WPSVG` (all behaviour), activation hook, `new WPSVG()` |
| `upsell.php` | Settings-page partial for the ShortPixel Image Optimizer offer; runs with `$this` = `WPSVG` |
| `uninstall.php` | Deletes the promo banner's dismissals when the plugin is deleted |
| `build/shortpixel/` | Generated copy of the shared modules (`promo-banner`) and their autoloader; do not edit, rebuild |
| `composer.json` | The module build: path repository `../modules`, namespace / hook / text-domain rewrite |
| `svg-sanitizer/` | Vendored `enshrined/svg-sanitize` 1.1.1, its `src/` folder without `svg-scanner.php` (namespace `enshrined\svgSanitize`), loaded only when no other copy is already loaded |
| `assets/` | Upsell JS/CSS and logo |
| `languages/` | Translation template |
| `docs/TESTING-PLAN.md` | Approved documentation and testing plan |

Upload flow for a user whose role allows SVG:

1. `upload_mimes` → `add_svg_mime()` adds `svg`/`svgz` (the permission gate).
2. `wp_check_filetype_and_ext` (priority 100) → forces `image/svg+xml` for
   `.svg`/`.svgz` file names.
3. `wp_handle_upload_prefilter` (and `wp_handle_sideload_prefilter` for
   sideloads, REST raw-body uploads and WP-CLI imports) → a file named
   `.svg`/`.svgz` or sniffed as SVG is sanitized in place, then cleaned of
   unsafe animations, unless the role is unrestricted.
4. `wp_generate_attachment_metadata` → stores width/height from
   `svg_dimensions()`.
5. Display: `wp_prepare_attachment_for_js`, `render_block` (`core/image`), and
   an admin-wide output buffer that patches the Media Library templates.

## Key patterns

- **Hooks only.** Every behaviour is a `WPSVG` method registered in the
  constructor; the instance is not stored anywhere. Tests reach it through
  the registered callbacks or by constructing a new instance.
- **Settings.** Option `wpsvg_settings`: `role_<slug>` => `0` disabled,
  `1` sanitized, `2` unrestricted. Read into `$this->settings` on `init` and
  resolved per role by `role_permission()`: once saved, a missing key means
  disabled; before the first save, roles with `upload_files` get `1`.
- **Other state.** Option `WPSVGActivated` (one-shot activation redirect),
  user meta `wpsvg_notice_dismissed`, option `wpsvg-promo-banner` (promo
  banner dismissals per site: campaign id => time).
- **Promo banner.** `WPSVG::promo_banner()` (on `admin_init`) configures the
  campaign of the bundled `promo-banner` module; the settings page prints it
  under its heading. It shows there only, inside the campaign window, to
  users with `manage_options`, until dismissed for the site. Its hooks carry
  the `wpsvg/` prefix (`wpsvg/promo_banner/show`, `wpsvg/settings/no_banner`,
  ...); the `SHORTPIXEL_NO_BANNER` constant silences it too. A new campaign
  is a new `id`, window and link in that method.
- **Public extension points.** Filters `WPSVG_setAllowedTags`,
  `WPSVG_setAllowedAttrs` (documented in `readme.txt`).
- **Third parties.** Beaver Builder (`fl_module_upload_regex`), WP All Import
  (`wp_all_import_image_mime_type`), ShortPixel Image Optimizer (upsell
  detection), other plugins bundling the same sanitizer (`class_exists`
  guard).
- **File format.** `wp-svg-images.php`, `upsell.php` and the JS asset use CRLF
  line endings and ASCII only; keep both. Indent with tabs in plugin code and
  four spaces in `svg-sanitizer/`.
- **Vendored code.** `svg-sanitizer/` stays as upstream released it, so an
  update is a plain copy of the upstream `src/` folder: do not reformat it
  and do not document it further. The only additions are `@todo` comments
  for open defects; carry them over on an update while the defect is still
  there. What the plugin relies on is described by the tests in
  `tests/unit/Sanitizer/`. `build/shortpixel/` is generated: no changes at
  all.

## Non-negotiable rules

1. **Plan first.** No file changes before the plan for the current phase or
   task is approved. Each phase starts with a short plan and ends with a
   hand-off; wait for approval between phases.
2. **Git.** NEVER COMMIT to this repository without human approval. A human
   reviews every change and makes or explicitly approves each commit; approval
   given for one commit does not cover the next. Likewise never branch, create
   a worktree, push, rebase, merge or open a pull request unless explicitly
   asked. Leave work uncommitted in the current working tree and end every
   hand-off with a ready-to-paste `git commit -m "..."` suggestion.
3. **No speculative production fixes.** When a test reveals a defect, do not
   change production code: pin it with a test and report it. Fix only when
   asked.
4. **No internal references in the repository.** No ticket numbers, commit
   hashes used as history, dates or people's names in code, tests or docs.
5. **A green run that ran nothing is a failure.** Every test command checks
   that tests executed and fails otherwise. Always report the test count.
6. **No retries, sleeps or skips as a fix.** A flaky test is a red test; find
   the race or missing wait. Skip only with a reason in the skip message and
   a line in the hand-off.
7. **Never run two test stacks at once** when they share a database, port or
   filesystem fixture. Run suites one after another.
8. **Document before testing.** A class, function or endpoint gets reviewed or
   written documentation before its tests; the documentation is the spec.
9. **Report faithfully.** Failing tests are reported with their output;
   skipped or partial work is stated as such.
10. **Existing tests are read-only by default.** Never change, loosen or
    delete a test to make a run green. Name the cause of every failing test;
    only a flipped pin or a pure rename may be changed without asking. See
    "Changing existing tests" below.

## Documenting defects

- An open defect is a `@todo` in the nearest docblock, in present tense:
  what is wrong, where, and the suggested fix.
- A fixed defect gets no mention: delete its `@todo` together with the fix.
- A defect covered by a test is pinned as
  `test_<description>_pinned_for_deferred_fix` in `@group pinned`; the
  docblock explains the defect and how the fix will be recognised, and the
  test must fail once the defect is fixed. Then turn it into a regular
  regression test.
- Defect lists for hand-offs stay in the conversation, never in the
  repository.
- Describe behaviour as it is. Where code looks wrong, add a `@todo`; do not
  change the code.

## Changing existing tests

Existing tests are read-only by default. A red test is evidence, not an
obstacle: it is first assumed to have found a defect. Never change a test just
to make a run green.

1. **Find the cause first.** A failing test has one of three causes: the code
   is wrong, the intended behaviour changed, or the test is wrong. The
   hand-off names the cause for every failing test before any assertion
   changes.
2. **Valid reasons to change an existing test** (the list is closed):
   - the maintainer changed the intended behaviour, or approved the change;
   - a pinned defect was fixed, so its pin flips to a regular test;
   - the code under test was renamed or moved and the asserted behaviour stays
     the same;
   - the test was wrong, or depended on something outside its subject (the
     date, the run order, the environment), and the hand-off shows the proof.
3. **Never acceptable as a fix:** loosening an assertion (`assertSame` to
   `assertEquals`, an exact value to "contains", a count to "at least"),
   deleting an assertion or a test, widening an allow-list of known errors,
   raising a timeout or a tolerance, adding a skip, refreshing a snapshot
   without an intended change.
4. **Ask before, not after.** A flipped pin and a pure rename need no
   question. For every other reason, stop, report the failure and its cause,
   and wait for the maintainer's decision. A newly found defect is pinned,
   never fixed and never hidden.
5. **Removal needs replacement.** A test is deleted only when the thing it
   tested no longer exists. The hand-off names the test that covers the
   behaviour now, or says that none does.
6. **Report test changes on their own.** Every hand-off lists each changed,
   removed or loosened test with its reason. Changes to existing tests go into
   their own commit, apart from new tests and from code, and the suggested
   commit message carries a `Tests-Changed: <reason>` line.
7. **Prove a changed test still bites.** After changing a test, show that it
   fails against the old or the broken behaviour, and say how in the hand-off.
8. **Snapshots and baselines:** a stored snapshot, baseline or golden file is
   refreshed only for an intended change, one test at a time, with the before
   and after named in the hand-off.

`bin/check-test-changes.sh` reports the uncommitted changes that fall under
these rules; run it before every hand-off that touches `tests/`. CI runs the
same script (`.github/workflows/test-guard.yml`): a pushed commit that changes
existing tests fails without a `Tests-Changed: <reason>` line in its message,
and a pull request fails until a maintainer applies the
`test-changes-approved` label.