// Proves the `e2e` suite runs: a browser reaches the site, WordPress and PHP
// are the versions bin/test.sh asked for, and the plugin is active.
//
// Used by `bin/test.sh --verify`, which expects exactly one test to execute
// here.
const { test, expect } = require( '@playwright/test' );
const { resetSite, logIn } = require( '../support/site' );

test( 'e2e canary', async ( { page, request } ) => {
	const state = await resetSite( request, { settings: { administrator: 1 } } );

	const expectedWordPress = process.env.WPSVG_EXPECTED_WP;
	if ( expectedWordPress === 'nightly' ) {
		expect( state.wp_version, 'Nightly builds carry a pre-release suffix' ).toContain( '-' );
	} else {
		expect( state.wp_version ).toBe( expectedWordPress );
	}
	expect( state.php_version ).toBe( process.env.WPSVG_EXPECTED_PHP );
	expect( state.plugin_active ).toBe( true );

	if ( process.env.WPSVG_CANARY_FAIL ) {
		expect( 'canary', 'Deliberate failure requested through WPSVG_CANARY_FAIL.' ).toBe( 'failing' );
	}

	await logIn( page, 'admin' );
	await page.goto( '/wp-admin/plugins.php' );
	await expect( page.locator( 'tr[data-plugin="wp-svg-images/wp-svg-images.php"]' ) ).toHaveClass( /\bactive\b/ );
} );
