<?php
/**
 * Prints one line per scenario of the `compat` suite: `<name> <tests folder>`.
 * Used by run-suite.sh to run the scenarios one after another.
 */

foreach ( require dirname( __DIR__ ) . '/compat/scenarios.php' as $name => $scenario ) {
	echo $name, ' ', $scenario['tests'], "\n";
}
