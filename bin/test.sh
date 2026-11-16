#!/usr/bin/env bash
# Single entry point for every test suite. See TESTING.md.
#
#   bin/test.sh [all|<suite>...] [options]
#
# `all`, and no suite name at all, mean every PHPUnit suite. The browser suite
# `e2e` runs only when named: `bin/test.sh all e2e` is the full release gate.
#
# Options:
#   --php X.Y         PHP version (default 8.3)
#   --wp VERSION      latest | nightly | X.Y | X.Y.Z (default latest)
#   --class NAME      run one test class (e2e: spec files whose path contains NAME)
#   --filter PATTERN  PHPUnit --filter (for example 'MyTest::test_name'; e2e:
#                     Playwright --grep on the test title)
#   --group NAME      PHPUnit --group (for example pinned; e2e: tests whose
#                     title carries the tag @NAME)
#   --verify          prove the environment works; ignores suite selection
#   --list            print suite names and exit
#   -h, --help        print this help
#
# Exit codes: 0 all selected suites passed with at least one executed test;
# 1 a suite failed or executed zero tests; 2 usage error (unknown suite or
# option); 3 another run is in progress.
# With --class/--filter/--group and several suites, a suite with no matching
# test is reported as "no match"; the run still fails if nothing matched at all.
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TESTS="$ROOT/tests"
COMPOSE=(docker compose --progress quiet -f "$TESTS/docker/compose.yml")

IMPLEMENTED_SUITES="unit integration compat multisite cli l10n e2e"
# What `all` and a run without suite names select: every PHPUnit suite. The
# browser suite needs the web stack and runs only when named.
DEFAULT_SUITES="unit integration compat multisite cli l10n"
PLANNED_SUITES=""

PHP_VERSION="8.3"
WP_REQUEST="latest"
SUITES=""
PHPUNIT_ARGS=()
# The same selection for the `e2e` suite, in Playwright's terms.
E2E_ARGS=()
VERIFY=0

usage() {
	awk 'NR == 1 { next } /^#/ { sub(/^# ?/, ""); print; next } { exit }' "$0"
}

die_usage() {
	echo "bin/test.sh: $1" >&2
	echo "Run bin/test.sh --help for usage." >&2
	exit 2
}

contains() {
	case " $1 " in *" $2 "*) return 0 ;; esac
	return 1
}

# Prints nothing and returns 0 for an implemented suite; otherwise explains and returns 2.
check_suite() {
	if contains "$IMPLEMENTED_SUITES" "$1"; then
		return 0
	fi
	if contains "$PLANNED_SUITES" "$1"; then
		echo "bin/test.sh: suite '$1' is planned but has no tests yet (see docs/TESTING-PLAN.md)." >&2
	else
		echo "bin/test.sh: unknown suite '$1'. Valid suites: $IMPLEMENTED_SUITES. Names are case-sensitive." >&2
	fi
	return 2
}

while [ $# -gt 0 ]; do
	case "$1" in
		--php) [ $# -ge 2 ] || die_usage "--php needs a value"; PHP_VERSION="$2"; shift 2 ;;
		--wp) [ $# -ge 2 ] || die_usage "--wp needs a value"; WP_REQUEST="$2"; shift 2 ;;
		--class)
			[ $# -ge 2 ] || die_usage "--class needs a value"
			PHPUNIT_ARGS+=(--filter "/(^|\\\\)$2::/"); E2E_ARGS+=("$2"); shift 2 ;;
		--filter) [ $# -ge 2 ] || die_usage "--filter needs a value"; PHPUNIT_ARGS+=(--filter "$2"); E2E_ARGS+=(--grep "$2"); shift 2 ;;
		--group) [ $# -ge 2 ] || die_usage "--group needs a value"; PHPUNIT_ARGS+=(--group "$2"); E2E_ARGS+=(--grep "@$2"); shift 2 ;;
		--verify) VERIFY=1; shift ;;
		--list) echo "Implemented: $IMPLEMENTED_SUITES"; echo "Planned: ${PLANNED_SUITES:-(none)}"; exit 0 ;;
		-h|--help) usage; exit 0 ;;
		-*) die_usage "unknown option '$1'" ;;
		all) SUITES="$SUITES $DEFAULT_SUITES"; shift ;;
		*) check_suite "$1" || exit 2; SUITES="$SUITES $1"; shift ;;
	esac
done

[ -n "$SUITES" ] || SUITES="$DEFAULT_SUITES"

echo "$PHP_VERSION" | grep -Eq '^[0-9]+\.[0-9]+$' || die_usage "--php must look like 8.3"
echo "$WP_REQUEST" | grep -Eq '^(latest|nightly|[0-9]+\.[0-9]+(\.[0-9]+)?)$' || die_usage "--wp must be latest, nightly, X.Y or X.Y.Z"

command -v docker >/dev/null || { echo "bin/test.sh: docker is required." >&2; exit 2; }

# One run at a time: suites share the database container and the WordPress cache.
mkdir -p "$TESTS/.cache/none/wordpress" "$TESTS/.cache/none/tests-lib" "$TESTS/.cache/compat/plugins/wp-svg-images" "$TESTS/.cache/compat/libraries" "$TESTS/.results"
LOCK="$TESTS/.cache/run.lock"
if ! mkdir "$LOCK" 2>/dev/null; then
	other="$(cat "$LOCK/pid" 2>/dev/null || true)"
	if [ -n "$other" ] && kill -0 "$other" 2>/dev/null; then
		echo "bin/test.sh: another run (pid $other) is in progress; suites must not run in parallel." >&2
		exit 3
	fi
	rm -rf "$LOCK"
	mkdir "$LOCK" || exit 3
fi
echo $$ > "$LOCK/pid"

# shellcheck disable=SC2329 # invoked through trap
cleanup() {
	"${COMPOSE[@]}" down --volumes --remove-orphans >/dev/null 2>&1
	rm -rf "$LOCK"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

export PHP_VERSION

echo "==> PHP $PHP_VERSION image"
"${COMPOSE[@]}" build --quiet setup php || { echo "bin/test.sh: image build failed." >&2; exit 1; }

needs_wp=0
needs_browser=0
third_parties=""
for s in $SUITES; do
	[ "$s" != unit ] && needs_wp=1
	[ "$s" = compat ] && third_parties=compat
	[ "$s" = e2e ] && needs_browser=1
done
[ "$VERIFY" = 1 ] && needs_wp=1 && needs_browser=1

if [ "$needs_wp" = 1 ]; then
	echo "==> WordPress $WP_REQUEST"
	setup_out="$("${COMPOSE[@]}" run --rm -T setup bash bin/setup.sh "$WP_REQUEST" $third_parties)" || { echo "$setup_out"; echo "bin/test.sh: setup failed." >&2; exit 1; }
else
	setup_out="$("${COMPOSE[@]}" run --rm -T setup bash bin/setup.sh none)" || { echo "$setup_out"; echo "bin/test.sh: setup failed." >&2; exit 1; }
fi
RESOLVED_WP="$(echo "$setup_out" | sed -n 's/^RESOLVED_WP=//p' | tail -1)"

if [ "$RESOLVED_WP" = none ]; then
	mkdir -p "$TESTS/.cache/none/wordpress" "$TESTS/.cache/none/tests-lib"
	export WPSVG_WP_DIR="$TESTS/.cache/none/wordpress" WPSVG_TESTS_LIB_DIR="$TESTS/.cache/none/tests-lib"
else
	echo "    resolved to $RESOLVED_WP"
	export WPSVG_WP_DIR="$TESTS/.cache/wp/$RESOLVED_WP/wordpress" WPSVG_TESTS_LIB_DIR="$TESTS/.cache/wp/$RESOLVED_WP/tests-lib"
fi
export WPSVG_EXPECTED_WP="$RESOLVED_WP"

if [ "$needs_browser" = 1 ]; then
	echo "==> Node packages for the browser tests"
	npm_out="$("${COMPOSE[@]}" run --rm -T e2e-setup npm ci --no-audit --no-fund --loglevel=error 2>&1)" || { echo "$npm_out"; echo "bin/test.sh: npm ci failed." >&2; exit 1; }
fi

# Runs the `e2e` suite: an empty database and uploads folder, a freshly
# installed site served by the `web` service, then Playwright against it.
# Extra arguments go to Playwright. Returns Playwright's exit code, or the
# zero-test guard's when Playwright passed.
run_e2e() {
	local rc guard_rc install_out
	"${COMPOSE[@]}" run --rm -T php bash -c 'rm -rf /opt/wp/wp-content/uploads-e2e /results/e2e.junit.xml /results/e2e.summary /results/e2e-artifacts && php bin/reset-db.php e2e' || return 1
	"${COMPOSE[@]}" up -d --wait web >/dev/null 2>&1 || { echo "bin/test.sh: the site did not start." >&2; return 1; }
	install_out="$("${COMPOSE[@]}" exec -T web wp --allow-root --path=/opt/wp core install \
		--url=http://web:8080 --title="WP SVG Images e2e" --admin_user=admin --admin_password=password \
		--admin_email=admin@example.org --skip-email 2>&1)" || { echo "$install_out"; echo "bin/test.sh: WordPress install failed." >&2; return 1; }
	# Without tests Playwright would fail by itself; the guard below reports it
	# the same way as for the other suites.
	"${COMPOSE[@]}" run --rm -T playwright npx playwright test --pass-with-no-tests "$@"
	rc=$?
	"${COMPOSE[@]}" rm --force --stop web >/dev/null 2>&1
	"${COMPOSE[@]}" run --rm -T --no-deps php php bin/junit-summary.php /results/e2e.junit.xml /results/e2e.summary "$rc"
	guard_rc=$?
	[ "$rc" -ne 0 ] && return "$rc"
	return "$guard_rc"
}

# Runs one suite; extra arguments go to PHPUnit, or to Playwright for `e2e`.
# Returns the in-container exit code.
run_suite() {
	local suite="$1" deps=()
	shift
	if [ "$suite" = e2e ]; then
		run_e2e "$@"
		return
	fi
	# The unit suite never touches the database.
	[ "$suite" = unit ] && deps=(--no-deps)
	"${COMPOSE[@]}" run --rm -T ${deps[@]+"${deps[@]}"} php bash bin/run-suite.sh "$suite" "$@"
}

summary_value() {
	sed -n "s/^$2=//p" "$TESTS/.results/$1.summary" 2>/dev/null | head -1
}

if [ "$VERIFY" = 1 ]; then
	failed=0
	check() {
		if [ "$2" = 0 ]; then echo "  PASS  $1"; else echo "  FAIL  $1"; failed=1; fi
	}

	echo "==> verify 1/6: unit canary runs alone and passes"
	run_suite unit --filter '/::test_unit_canary$/'; rc=$?
	if [ "$rc" = 0 ] && [ "$(summary_value unit tests)" = 1 ]; then ok=0; else ok=1; fi
	check "unit canary: exactly 1 test executed and passed (PHP $PHP_VERSION, extensions)" "$ok"

	echo "==> verify 2/6: integration canary runs alone and passes"
	run_suite integration --filter '/::test_integration_canary$/'; rc=$?
	if [ "$rc" = 0 ] && [ "$(summary_value integration tests)" = 1 ]; then ok=0; else ok=1; fi
	check "integration canary: exactly 1 test executed and passed (WordPress $RESOLVED_WP, plugin active, database)" "$ok"

	echo "==> verify 3/6: a failing test fails the run (failure expected below)"
	WPSVG_CANARY_FAIL=1 run_suite unit --filter '/::test_unit_canary$/'; rc=$?
	if [ "$rc" != 0 ] && [ "$(summary_value unit tests)" = 1 ] && [ "$(summary_value unit failures)" = 1 ]; then ok=0; else ok=1; fi
	check "deliberate failure: runner exited $rc with 1 failure" "$ok"

	echo "==> verify 4/6: a filter that matches nothing fails the run (zero-test error expected below)"
	run_suite unit --filter '/::no_test_has_this_name$/'; rc=$?
	if [ "$rc" != 0 ] && [ "$(summary_value unit tests)" = 0 ]; then ok=0; else ok=1; fi
	check "zero tests executed: runner exited $rc" "$ok"

	echo "==> verify 5/6: unknown suite names are rejected"
	( check_suite Unit ) 2>/dev/null; rc1=$?
	( check_suite browser ) 2>/dev/null; rc2=$?
	[ "$rc1" = 2 ] && [ "$rc2" = 2 ]; check "suite 'Unit' rejected ($rc1), suite 'browser' rejected ($rc2)" $?

	echo "==> verify 6/6: e2e canary runs alone and passes"
	run_suite e2e --grep 'e2e canary'; rc=$?
	if [ "$rc" = 0 ] && [ "$(summary_value e2e tests)" = 1 ]; then ok=0; else ok=1; fi
	check "e2e canary: exactly 1 test executed and passed (browser, site on PHP $PHP_VERSION and WordPress $RESOLVED_WP)" "$ok"

	echo
	if [ "$failed" = 0 ]; then
		echo "VERIFIED: PHP $PHP_VERSION, WordPress $RESOLVED_WP"
		exit 0
	fi
	echo "NOT VERIFIED: PHP $PHP_VERSION, WordPress $RESOLVED_WP" >&2
	exit 1
fi

overall=0
report=""
total=0
for suite in $SUITES; do
	echo "==> suite $suite (PHP $PHP_VERSION, WordPress $RESOLVED_WP)"
	if [ "$suite" = e2e ]; then
		run_suite "$suite" ${E2E_ARGS[@]+"${E2E_ARGS[@]}"}; rc=$?
	else
		run_suite "$suite" ${PHPUNIT_ARGS[@]+"${PHPUNIT_ARGS[@]}"}; rc=$?
	fi
	n="$(summary_value "$suite" tests)"; n="${n:-0}"
	total=$((total + n))
	if [ "$rc" = 0 ]; then
		status=passed
	elif [ "$rc" = 3 ] && [ "$n" = 0 ] && [ ${#PHPUNIT_ARGS[@]} -gt 0 ] && [ "$(echo "$SUITES" | wc -w)" -gt 1 ]; then
		status="no match"
	else
		status="FAILED (exit $rc)"; overall=1
	fi
	report="$report$(printf '  %-12s %5s tests, %5s assertions, %3s skipped  %s' "$suite" "$n" "$(summary_value "$suite" assertions)" "$(summary_value "$suite" skipped)" "$status")
"
done

echo
echo "==> Summary (PHP $PHP_VERSION, WordPress $RESOLVED_WP)"
printf '%s' "$report"
echo "  total        $total tests executed"
not_run=""
for s in $IMPLEMENTED_SUITES $PLANNED_SUITES; do contains "$SUITES" "$s" || not_run="$not_run $s"; done
[ -n "$not_run" ] && echo "  not run:$not_run"
if [ "$total" = 0 ]; then
	echo "bin/test.sh: zero tests executed." >&2
	overall=1
fi
exit "$overall"
