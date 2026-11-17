// Helpers shared by the specs: the site's state and logging in.
const { expect } = require( '@playwright/test' );

/** Password of every seeded user; see the helper plugin in ../site/mu-plugins. */
const PASSWORD = 'password';

/** Address of the plugin's settings page, relative to the site. */
const SETTINGS_PAGE = '/wp-admin/options-general.php?page=wp-svg-images.php';

/**
 * Puts the site into a known state through the test-only helper plugin.
 *
 * @param {import('@playwright/test').APIRequestContext} request
 * @param {{plugin?: 'active'|'inactive', settings?: Object<string, number>|null, activated?: boolean, campaign?: boolean}} [state]
 * @return {Promise<Object>} The state after the reset.
 */
async function resetSite( request, state = {} ) {
	const response = await request.post( '/?rest_route=/wpsvg-e2e/v1/reset', { data: state } );
	expect( response.ok(), `reset answered ${ response.status() }` ).toBeTruthy();
	return response.json();
}

/**
 * Reads what the browser cannot show: options, stored files, metadata.
 *
 * @param {import('@playwright/test').APIRequestContext} request
 * @return {Promise<Object>}
 */
async function siteState( request ) {
	const response = await request.get( '/?rest_route=/wpsvg-e2e/v1/state' );
	expect( response.ok(), `state answered ${ response.status() }` ).toBeTruthy();
	return response.json();
}

/**
 * Logs in through the login form. The seeded logins are the role names,
 * except `admin` for the administrator.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} login
 */
async function logIn( page, login ) {
	await page.context().clearCookies();
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( login );
	await page.locator( '#user_pass' ).fill( PASSWORD );
	await page.locator( '#wp-submit' ).click();
	await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
}

module.exports = { PASSWORD, SETTINGS_PAGE, resetSite, siteState, logIn };
