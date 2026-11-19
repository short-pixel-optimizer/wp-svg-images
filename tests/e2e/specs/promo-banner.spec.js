// The campaign banner on the settings page: where it shows, what it offers
// and that closing it lasts. The helper plugin decides whether the campaign
// runs, so the tests do not depend on the date.
const { test, expect } = require( '@playwright/test' );
const { SETTINGS_PAGE, resetSite, siteState, logIn } = require( '../support/site' );

const banner = ( page ) => page.getByRole( 'region', { name: 'ShortPixel Black Friday offer' } );

test.describe( 'while the campaign runs', () => {
	test.beforeEach( async ( { request } ) => {
		await resetSite( request, { campaign: true } );
	} );

	test( 'the settings page shows the banner between its heading and the settings', async ( { page } ) => {
		await logIn( page, 'admin' );
		await page.goto( SETTINGS_PAGE );

		await expect( banner( page ) ).toBeVisible();
		await expect( banner( page ) ).toContainText( '25% OFF any yearly plan for life' );
		await expect( banner( page ) ).toContainText( '2x One-Time Credits' );
		await expect( banner( page ) ).toContainText( 'Offer ends November 30, 2026' );
		await expect( banner( page ).getByRole( 'link', { name: 'Claim Deal' } ) ).toHaveAttribute(
			'href',
			'https://shortpixel.com/pricing?utm_source=plugin&utm_medium=wpsvg&utm_campaign=top_banner_bf2026'
		);
		await expect( banner( page ).getByRole( 'link', { name: 'Claim Deal' } ) ).toHaveAttribute( 'target', '_blank' );

		const heading = await page.getByRole( 'heading', { name: 'SVG images', level: 2 } ).boundingBox();
		const box = await banner( page ).boundingBox();
		const notice = await page.getByRole( 'heading', { name: 'Security notice' } ).boundingBox();
		expect( heading.y ).toBeLessThan( box.y );
		expect( box.y + box.height ).toBeLessThanOrEqual( notice.y );
	} );

	test( 'the banner is styled by its stylesheet and its images load', async ( { page } ) => {
		await logIn( page, 'admin' );
		await page.goto( SETTINGS_PAGE );

		await expect( page.locator( 'link#wpsvg_promo_banner-css' ) ).toHaveAttribute( 'href', /\/wp-svg-images\/build\/shortpixel\/promo-banner\/assets\/promo-banner\.css\?ver=1\.0$/ );
		expect( ( await banner( page ).boundingBox() ).height ).toBeLessThanOrEqual( 120 );
		await expect( banner( page ).locator( 'img' ) ).toHaveCount( 3 );
		const loaded = await banner( page ).locator( 'img' ).evaluateAll( ( images ) => images.map( ( image ) => image.complete && image.naturalWidth > 0 ) );
		expect( loaded ).toEqual( [ true, true, true ] );
	} );

	test( 'the banner fits a narrow window without widening the page', async ( { page } ) => {
		await page.setViewportSize( { width: 390, height: 844 } );
		await logIn( page, 'admin' );
		await page.goto( SETTINGS_PAGE );

		await expect( banner( page ) ).toBeVisible();
		await expect( banner( page ).getByRole( 'link', { name: 'Claim Deal' } ) ).toBeVisible();
		const box = await banner( page ).boundingBox();
		expect( box.x ).toBeGreaterThanOrEqual( 0 );
		expect( box.x + box.width ).toBeLessThanOrEqual( 390 );
	} );

	test( 'other admin screens show no banner and load none of its files', async ( { page } ) => {
		await logIn( page, 'admin' );

		for ( const screen of [ '/wp-admin/', '/wp-admin/plugins.php', '/wp-admin/upload.php', '/wp-admin/options-general.php' ] ) {
			await page.goto( screen );
			await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
			await expect( page.locator( '.shortpixel-bf' ), screen ).toHaveCount( 0 );
			await expect( page.locator( 'link#wpsvg_promo_banner-css, script#wpsvg_promo_banner-js' ), screen ).toHaveCount( 0 );
		}
	} );

	test( 'closing the banner hides it for good', async ( { page, request } ) => {
		await logIn( page, 'admin' );
		await page.goto( SETTINGS_PAGE );
		const stored = page.waitForResponse( ( response ) => response.url().includes( '/wp-admin/admin-ajax.php' ) && response.request().method() === 'POST' );

		await banner( page ).getByRole( 'button', { name: 'Dismiss' } ).click();

		await expect( banner( page ) ).toBeHidden();
		// The banner's script does not read the answer, so its body never
		// finishes loading for Playwright; the stored dismissal is the result.
		expect( ( await stored ).status() ).toBe( 200 );
		expect( ( await siteState( request ) ).banner_dismissals ).toEqual( [ 'black-friday-2026' ] );

		await page.goto( SETTINGS_PAGE );
		await expect( page.getByRole( 'heading', { name: 'SVG images', level: 2 } ) ).toBeVisible();
		await expect( page.locator( '.shortpixel-bf' ) ).toHaveCount( 0 );
	} );

	test( 'the settings still save with the banner on the page', async ( { page, request } ) => {
		await logIn( page, 'admin' );
		await page.goto( SETTINGS_PAGE );

		await page.locator( '#role_editor' ).selectOption( { label: 'disable SVG upload' } );
		await page.getByRole( 'button', { name: 'Save', exact: true } ).click();

		await expect( page.locator( '.updated' ) ).toHaveText( 'Settings saved.' );
		await expect( banner( page ) ).toBeVisible();
		expect( ( await siteState( request ) ).settings.role_editor ).toBe( 0 );
	} );
} );

test( 'outside the campaign the settings page shows no banner', async ( { page, request } ) => {
	await resetSite( request, {} );
	await logIn( page, 'admin' );

	await page.goto( SETTINGS_PAGE );

	await expect( page.getByRole( 'heading', { name: 'SVG images', level: 2 } ) ).toBeVisible();
	await expect( page.locator( '.shortpixel-bf' ) ).toHaveCount( 0 );
	await expect( page.locator( 'link#wpsvg_promo_banner-css, script#wpsvg_promo_banner-js' ) ).toHaveCount( 0 );
} );
