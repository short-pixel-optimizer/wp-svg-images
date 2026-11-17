#!/usr/bin/env bash
# Runs inside the `php` container. Usage: run-suite.sh <suite> [phpunit args...]
#
# Resets the suite's database when the suite needs WordPress, runs PHPUnit with
# a JUnit report, then applies the zero-test guard. The `compat` suite runs
# once per scenario of compat/scenarios.php and is counted as one suite. Exits
# non-zero when PHPUnit fails, when its report is missing, or when zero tests
# executed.
set -uo pipefail

suite="$1"
shift

report="/results/$suite.junit.xml"
rm -f /results/"$suite".junit.xml /results/"$suite".*.junit.xml "/results/$suite.summary"

if [ "$suite" = compat ]; then
	# One PHPUnit run per scenario, each on an empty database. A selector that
	# matches nothing in a scenario writes no report; the guard below counts
	# what all scenarios executed together.
	report="/results/$suite.*.junit.xml"
	phpunit_rc=0
	while read -r scenario folder; do
		echo "--> scenario $scenario"
		php bin/reset-db.php "$suite" || exit 1
		WPSVG_SUITE="$suite" WPSVG_COMPAT_SCENARIO="$scenario" vendor/bin/phpunit -c "phpunit/$suite.xml" \
			--log-junit "/results/$suite.$scenario.junit.xml" "$@" "compat/$folder"
		rc=$?
		[ "$rc" -ne 0 ] && phpunit_rc=$rc
	done < <(php bin/compat-scenarios.php)
else
	[ "$suite" = unit ] || php bin/reset-db.php "$suite" || exit 1
	WPSVG_SUITE="$suite" vendor/bin/phpunit -c "phpunit/$suite.xml" --log-junit "$report" "$@"
	phpunit_rc=$?
fi

php bin/junit-summary.php "$report" "/results/$suite.summary" "$phpunit_rc"
guard_rc=$?

if [ "$phpunit_rc" -ne 0 ]; then
	exit "$phpunit_rc"
fi
exit "$guard_rc"
