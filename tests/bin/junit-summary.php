<?php
/**
 * Reads a PHPUnit JUnit report and enforces the zero-test guard.
 *
 * Usage: php junit-summary.php <junit.xml> <summary-file> <phpunit-exit-code>
 *
 * <junit.xml> may be a glob pattern; the counts of all matching reports are
 * added up (the `compat` suite writes one report per scenario).
 *
 * Prints the executed test, assertion, failure, error and skip counts, lists
 * every skipped test with its message, and writes the counts as key=value
 * lines to <summary-file> for bin/test.sh.
 *
 * Exit codes: 0 when at least one test executed, 3 when zero tests executed,
 * 4 when the report is missing although PHPUnit failed (it crashed before
 * writing it). PHPUnit 9 writes no report at all when a filter or group
 * selects nothing and then exits 0; that case counts as zero tests.
 */

$report  = isset( $argv[1] ) ? $argv[1] : '';
$summary = isset( $argv[2] ) ? $argv[2] : '';
$phpunit = isset( $argv[3] ) ? (int) $argv[3] : 1;

$counts = array( 'tests' => 0, 'assertions' => 0, 'failures' => 0, 'errors' => 0, 'skipped' => 0 );

$reports = array();
foreach ( (array) glob( $report ) as $path ) {
	$xml = is_readable( $path ) ? simplexml_load_file( $path ) : false;
	if ( false !== $xml && isset( $xml->testsuite ) ) {
		$reports[] = $xml;
	}
}

if ( ! $reports ) {
	file_put_contents( $summary, "tests=0\nreport=missing\n" );
	if ( 0 === $phpunit ) {
		echo "Executed: 0 tests\n";
		fwrite( STDERR, "ERROR: zero tests executed. A run that runs nothing is a failure.\n" );
		exit( 3 );
	}
	fwrite( STDERR, "ERROR: no readable JUnit report at $report; PHPUnit did not finish.\n" );
	exit( 4 );
}

$skipped = array();
foreach ( $reports as $xml ) {
	foreach ( $xml->testsuite as $suite ) {
		foreach ( array_keys( $counts ) as $key ) {
			$counts[ $key ] += (int) $suite[ $key ];
		}
	}
	foreach ( $xml->xpath( '//testcase[skipped]' ) as $case ) {
		$skipped[] = sprintf( "  skipped: %s::%s - %s\n", $case['class'], $case['name'], trim( (string) $case->skipped ) );
	}
}

$lines = '';
foreach ( $counts as $key => $value ) {
	$lines .= "$key=$value\n";
}
file_put_contents( $summary, $lines );

printf(
	"Executed: %d tests, %d assertions, %d failures, %d errors, %d skipped\n",
	$counts['tests'],
	$counts['assertions'],
	$counts['failures'],
	$counts['errors'],
	$counts['skipped']
);

echo implode( '', $skipped );

if ( 0 === $counts['tests'] ) {
	fwrite( STDERR, "ERROR: zero tests executed. A run that runs nothing is a failure.\n" );
	exit( 3 );
}

exit( 0 );
