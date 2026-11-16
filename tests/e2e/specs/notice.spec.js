// Journey 5: the "new settings" notice appears on four screens for an
// administrator and stays away once dismissed.
const { test, expect } = require( '@playwright/test' );
const { SETTINGS_PAGE, resetSite, siteState, logIn } = require( '../support/site' );
const { CLEAN_SVG, svgFile, uploadInMediaLibrary } = require( '../support/media' );

const notice = ( page ) => page.locator( '.wpsvg-notice' );

test.beforeEach( async ( { request } ) => {
	await resetSite( request, { settings: { administrator: 1 } } );
} );

test( 'an administrator sees the notice on the plugin and media screens', async ( { page, request } ) => {
	await logIn( page, 'admin' );
	await uploadInMediaLibrary( page, svgFile( 'logo.svg', CLEAN_SVG ) );
	const [ attachment ] = ( await siteState( request ) ).attachments;

	const screens = [
		'/wp-admin/plugins.php',
		'/wp-admin/plugin-install.php',
		'/wp-admin/upload.php?mode=list',
		`/wp-admin/post.php?post=${ attachment.id }&action=edit`,
	];
	for ( const screen of screens ) {
		await page.goto( screen );
		await expect( notice( page ), screen ).toBeVisible();
		await expect( notice( page ), screen ).toContainText( 'WP SVG images has some new settings!' );
	}
} );

test( 'the notice is not shown on other screens', async ( { page } ) => {
	await logIn( page, 'admin' );

	for ( const screen of [ '/wp-admin/index.php', '/wp-admin/edit.php', '/wp-admin/options-general.php', '/wp-admin/media-new.php' ] ) {
		await page.goto( screen );
		await expect( page.locator( '#wpbody-content' ) ).toBeVisible();
		await expect( notice( page ), screen ).toHaveCount( 0 );
	}
} );

test( 'a user who cannot manage options does not see the notice', async ( { page } ) => {
	await logIn( page, 'editor' );

	await page.goto( '/wp-admin/upload.php?mode=list' );

	await expect( page.locator( '#wpbody-content' ) ).toBeVisible();
	await expect( notice( page ) ).toHaveCount( 0 );
} );

test( 'the notice button leads to the settings page', async ( { page } ) => {
	await logIn( page, 'admin' );
	await page.goto( '/wp-admin/plugins.php' );

	await notice( page ).getByRole( 'link', { name: 'Go to Settings' } ).click();

	await expect( page ).toHaveURL( /options-general\.php\?page=wp-svg-images\.php/ );
	await expect( page.getByRole( 'heading', { name: 'SVG images', level: 2 } ) ).toBeVisible();
} );

test( 'dismissing the notice hides it for good', async ( { page } ) => {
	await logIn( page, 'admin' );
	await page.goto( '/wp-admin/plugins.php' );
	await expect( notice( page ) ).toBeVisible();

	const saved = page.waitForResponse( ( response ) => response.url().includes( '/wp-admin/admin-ajax.php' ) && ( response.request().postData() || '' ).includes( 'action=wpsvg_notice_dismissed' ) );
	await notice( page ).locator( '.notice-dismiss' ).click();
	const response = await saved;

	expect( response.status() ).toBe( 200 );
	await expect( notice( page ) ).toHaveCount( 0 );
	await page.reload();
	await expect( page.locator( '#the-list' ) ).toBeVisible();
	await expect( notice( page ) ).toHaveCount( 0 );
	await page.goto( '/wp-admin/upload.php?mode=list' );
	await expect( page.locator( '#wpbody-content' ) ).toBeVisible();
	await expect( notice( page ) ).toHaveCount( 0 );
} );

test( 'dismissal is personal: another administrator still sees the notice', async ( { page, browser } ) => {
	await logIn( page, 'admin' );
	await page.goto( '/wp-admin/plugins.php' );
	const saved = page.waitForResponse( ( response ) => response.url().includes( '/wp-admin/admin-ajax.php' ) );
	await notice( page ).locator( '.notice-dismiss' ).click();
	await saved;

	// The seeded editor is promoted through the Users screen by the first administrator.
	await page.goto( '/wp-admin/users.php' );
	await page.locator( '#the-list tr', { hasText: 'editor@example.org' } ).locator( 'input[type="checkbox"]' ).check();
	await page.locator( '#new_role' ).selectOption( 'administrator' );
	await page.locator( '#changeit' ).click();
	await expect( page.locator( '#message' ) ).toContainText( 'Changed roles.' );

	const context = await browser.newContext( { baseURL: process.env.WPSVG_E2E_BASE_URL } );
	const other = await context.newPage();
	await logIn( other, 'editor' );
	await other.goto( '/wp-admin/plugins.php' );
	await expect( notice( other ) ).toBeVisible();
	await context.close();
} );

test( 'opening the settings page dismisses the notice', async ( { page } ) => {
	await logIn( page, 'admin' );

	await page.goto( SETTINGS_PAGE );
	await expect( page.getByRole( 'heading', { name: 'SVG images', level: 2 } ) ).toBeVisible();
	await page.goto( '/wp-admin/plugins.php' );

	await expect( page.locator( '#the-list' ) ).toBeVisible();
	await expect( notice( page ) ).toHaveCount( 0 );
} );
