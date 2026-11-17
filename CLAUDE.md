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
  `wp i18n make-pot`.

## Build and dependencies

- No build step and no Composer manifest for the plugin. The sanitizer is
  vendored in `svg-sanitizer/` and loaded with `require_once`.
- Runtime PHP extensions used: `fileinfo`, `dom`, `libxml`, `simplexml`,
  `zlib`; `mbstring` optional.
- Test tooling lives under `tests/` with its own `tests/composer.json`; never
  add a Composer manifest at the plugin root.
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
| `svg-sanitizer/` | Vendored `enshrined/svg-sanitize` (namespace `enshrined\svgSanitize`), loaded only when no other copy is already loaded |
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
  `1` sanitized, `2` unrestricted. Read into `$this->settings` on `init`; a
  missing key means disabled in the upload filters.
- **Other state.** Option `WPSVGActivated` (one-shot activation redirect),
  user meta `wpsvg_notice_dismissed`.
- **Public extension points.** Filters `WPSVG_setAllowedTags`,
  `WPSVG_setAllowedAttrs` (documented in `readme.txt`).
- **Third parties.** Beaver Builder (`fl_module_upload_regex`), WP All Import
  (`wp_all_import_image_mime_type`), ShortPixel Image Optimizer (upsell
  detection), other plugins bundling the same sanitizer (`class_exists`
  guard).
- **File format.** `wp-svg-images.php`, `upsell.php` and the JS asset use CRLF
  line endings and ASCII only; keep both. Indent with tabs in plugin code and
  four spaces in `svg-sanitizer/`.
- **Vendored code.** Do not reformat `svg-sanitizer/`; documentation-only
  changes are allowed there.

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
