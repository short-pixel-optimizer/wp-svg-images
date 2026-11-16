// Journey 6: the ShortPixel Image Optimizer box on the settings page moves
// from "Install now" to "Activate" to "activated".
//
// The site is offline, so the two requests the box sends to WordPress's
// plugin installer are answered here, in the browser.
const { test, expect } = require( '@playwright/test' );
const { SETTINGS_PAGE, resetSite, logIn } = require( '../support/site' );

const box = ( page ) => page.locator( '.shortpixel-offer' );
const installLink = ( page ) => box( page ).locator( 'a.upsell-installer' );
const activateLink = ( page ) => box( page ).locator( 'a.upsell-activate' );
const done = ( page ) => box( page ).locator( '.upsell-activate-done' );

/**
 * Answers the installer and activation requests of the box.
 *
 * @param {import('@playwright/test').Page} page
 * @param {{install?: Object, activate?: Object}} answers Options for route.fulfill().
 * @return {Promise<string[]>} Addresses requested, filled as requests arrive.
 */
async function stubInstaller( page, answers = {} ) {
	const requested = [];
	await page.route( /\/wp-admin\/(network\/)?update\.php\?action=install-plugin/, async ( route ) => {
		requested.push( route.request().url() );
		await route.fulfill( { status: 200, contentType: 'text/html', body: '<p>Plugin installed successfully.</p>', ...( answers.install || {} ) } );
	} );
	await page.route( /\/wp-admin\/plugins\.php\?action=activate/, async ( route ) => {
		requested.push( route.request().url() );
		await route.fulfill( { status: 200, contentType: 'text/html', body: '<p>Plugin activated.</p>', ...( answers.activate || {} ) } );
	} );
	return requested;
}

test.beforeEach( async ( { page, request } ) => {
	await resetSite( request, { settings: { administrator: 1 } } );
	await logIn( page, 'admin' );
} );

test( 'the box offers the installation while the plugin is not installed', async ( { page } ) => {
	await page.goto( SETTINGS_PAGE );

	await expect( box( page ) ).toBeVisible();
	await expect( box( page ) ).toContainText( 'ShortPixel Image Optimizer' );
	await expect( installLink( page ) ).toBeVisible();
	await expect( installLink( page ) ).toHaveText( 'Install now' );
	await expect( activateLink( page ) ).toBeHidden();
	await expect( done( page ) ).toBeHidden();
	const logo = box( page ).locator( 'img' );
	await expect.poll( () => logo.evaluate( ( image ) => image.complete && image.naturalWidth > 0 ), { message: 'The logo loaded' } ).toBe( true );
} );

test( 'the box styles and script are loaded from the plugin', async ( { page } ) => {
	await page.goto( SETTINGS_PAGE );

	await expect( page.locator( 'link[href*="/wp-svg-images/assets/css/spio-upsell.css"]' ) ).toHaveCount( 1 );
	await expect( page.locator( 'script[src*="/wp-svg-images/assets/js/spio-upsell.js"]' ) ).toHaveCount( 1 );
} );

test( 'install, then activate, then done', async ( { page } ) => {
	const requested = await stubInstaller( page );
	await page.goto( SETTINGS_PAGE );

	await installLink( page ).click();
	await expect( activateLink( page ) ).toBeVisible();
	await expect( installLink( page ) ).toBeHidden();
	await expect( done( page ) ).toBeHidden();

	await activateLink( page ).click();
	await expect( done( page ) ).toBeVisible();
	await expect( done( page ) ).toHaveText( 'Shortpixel activated!' );
	await expect( activateLink( page ) ).toBeHidden();

	expect( requested ).toHaveLength( 2 );
	expect( requested[ 0 ] ).toMatch( /update\.php\?action=install-plugin&plugin=shortpixel-image-optimiser&_wpnonce=[0-9a-f]+$/ );
	expect( decodeURIComponent( requested[ 1 ] ) ).toMatch( /plugins\.php\?action=activate&plugin=shortpixel-image-optimiser\/wp-shortpixel\.php&.*_wpnonce=[0-9a-f]+$/ );
	await expect( page ).toHaveURL( /options-general\.php\?page=wp-svg-images\.php/ );
} );

test( 'the install link shows that it is working while the request runs', async ( { page } ) => {
	let release;
	const held = new Promise( ( resolve ) => {
		release = resolve;
	} );
	await page.route( /update\.php\?action=install-plugin/, async ( route ) => {
		await held;
		await route.fulfill( { status: 200, contentType: 'text/html', body: 'ok' } );
	} );
	await page.goto( SETTINGS_PAGE );

	await installLink( page ).click();

	await expect( installLink( page ) ).toHaveText( 'Installing ...' );
	await expect( activateLink( page ) ).toBeHidden();
	release();
	await expect( activateLink( page ) ).toBeVisible();
} );

// Pins the defect documented in upsell.php: the box moves on after any
// successful HTTP response, and WordPress's installer page answers 200 even
// when the installation failed. When fixed, a failed installation does not
// offer "Activate".
test( 'a failed installation still offers the activation, pinned for deferred fix @pinned', async ( { page } ) => {
	await stubInstaller( page, { install: { body: '<div class="wrap"><p>Installation failed: Download failed.</p></div>' } } );
	await page.goto( SETTINGS_PAGE );

	await installLink( page ).click();

	await expect( activateLink( page ) ).toBeVisible();
	await expect( installLink( page ) ).toBeHidden();
} );

// Pins the second half of the same defect: when the installer request fails
// outright, nothing happens and the link keeps saying "Installing ..." for
// good. When fixed, the box reports the failure or offers the link again.
test( 'a failing installer request leaves the link on "Installing ...", pinned for deferred fix @pinned', async ( { page } ) => {
	await stubInstaller( page, { install: { status: 500, body: 'Internal Server Error' } } );
	await page.goto( SETTINGS_PAGE );

	const answered = page.waitForResponse( /update\.php\?action=install-plugin/ );
	await installLink( page ).click();
	expect( ( await answered ).status() ).toBe( 500 );

	await expect( installLink( page ) ).toHaveText( 'Installing ...' );
	await expect( installLink( page ) ).toBeVisible();
	await expect( activateLink( page ) ).toBeHidden();
} );
