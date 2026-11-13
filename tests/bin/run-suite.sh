#!/usr/bin/env bash
# Runs inside the `php` container. Usage: run-suite.sh <suite> [phpunit args...]
#
# Resets the suite's database when the suite needs WordPress, runs PHPUnit with
# a JUnit report, then applies the zero-test guard. Exits non-zero when PHPUnit
# fails, when its report is missing, or when zero tests executed.
set -uo pipefail

suite="$1"
shift

case "$suite" in
	unit) ;;
	*) php bin/reset-db.php "$suite" || exit 1 ;;
esac

report="/results/$suite.junit.xml"
rm -f "$report" "/results/$suite.summary"

WPSVG_SUITE="$suite" vendor/bin/phpunit -c "phpunit/$suite.xml" --log-junit "$report" "$@"
phpunit_rc=$?

php bin/junit-summary.php "$report" "/results/$suite.summary" "$phpunit_rc"
guard_rc=$?

if [ "$phpunit_rc" -ne 0 ]; then
	exit "$phpunit_rc"
fi
exit "$guard_rc"
