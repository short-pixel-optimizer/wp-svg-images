<?php
/**
 * Drops and recreates the database of one suite so every suite starts empty.
 *
 * Usage: php reset-db.php <suite>
 * Connection details come from the WP_TESTS_DB_* environment variables.
 */

$suite = isset( $argv[1] ) ? $argv[1] : '';
if ( ! preg_match( '/^[a-z0-9]+$/', $suite ) ) {
	fwrite( STDERR, "reset-db.php: invalid suite name\n" );
	exit( 1 );
}

mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );
$db   = new mysqli( getenv( 'WP_TESTS_DB_HOST' ), getenv( 'WP_TESTS_DB_USER' ), getenv( 'WP_TESTS_DB_PASSWORD' ) );
$name = 'wpsvg_' . $suite;
$db->query( "DROP DATABASE IF EXISTS `$name`" );
$db->query( "CREATE DATABASE `$name` CHARACTER SET utf8mb4" );
