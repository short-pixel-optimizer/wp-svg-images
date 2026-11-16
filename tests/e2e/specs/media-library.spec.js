// Journeys 2 to 4 in the Media Library: who can upload an SVG, what is
// stored, and what the library shows afterwards. Uploads are real HTTP
// uploads through the library's own uploader.
const { test, expect } = require( '@playwright/test' );
const { resetSite, siteState, logIn } = require( '../support/site' );
const { CLEAN_SVG, DIRTY_SVG, BROKEN_SVG, svgFile, uploadInMediaLibrary, uploadWithPlainForm, gridTiles, expectLoadedSvg } = require( '../support/media' );

/** What the plugin makes of DIRTY_SVG. */
const SANITIZED = '<svg xmlns="http://www.w3.org/2000/svg" width="30" height="20" viewBox="0 0 30 20"><rect width="30" height="20" fill="#0a7"></rect></svg>';

const uploadError = ( page ) => page.locator( '.upload-errors .upload-error-message' );

test.describe( 'clean SVG', () => {
	for ( const login of [ 'author', 'editor', 'admin' ] ) {
		test( `${ login } uploads an SVG and sees it in the grid`, async ( { page, request } ) => {
			await resetSite( request, { settings: { administrator: 1, editor: 1, author: 1 } } );
			await logIn( page, login );

			const answer = await uploadInMediaLibrary( page, svgFile( 'logo.svg', CLEAN_SVG ) );

			expect( answer.requested ).toBe( true );
			expect( JSON.parse( answer.body ).success ).toBe( true );
			await expect( gridTiles( page ) ).toHaveCount( 1 );
			await expect( gridTiles( page ).first().locator( '.attachment-preview' ) ).toHaveClass( /subtype-svg\+xml/ );
			await expectLoadedSvg( gridTiles( page ).first().locator( 'img' ) );
			await expect( uploadError( page ) ).toHaveCount( 0 );

			const [ attachment ] = ( await siteState( request ) ).attachments;
			expect( attachment.mime ).toBe( 'image/svg+xml' );
			expect( attachment.file ).toMatch( /\/logo\.svg$/ );
			expect( attachment.author ).toBe( login );
			expect( attachment.metadata ).toMatchObject( { width: 30, height: 20 } );
		} );
	}

	test( 'attachment details show the preview, type and dimensions', async ( { page, request } ) => {
		await resetSite( request, { settings: { author: 1 } } );
		await logIn( page, 'author' );
		await uploadInMediaLibrary( page, svgFile( 'logo.svg', CLEAN_SVG ) );

		await gridTiles( page ).first().click();

		const details = page.locator( '.media-modal .attachment-details' );
		await expectLoadedSvg( details.locator( 'img.details-image' ) );
		await expect( details.locator( '.filename' ) ).toContainText( 'logo.svg' );
		await expect( details.locator( '.file-type' ) ).toContainText( 'image/svg+xml' );
		await expect( details.locator( '.dimensions' ) ).toContainText( '30 by 20 pixels' );
	} );

	test( 'list view shows the SVG as thumbnail with its dimensions', async ( { page, request } ) => {
		await resetSite( request, { settings: { author: 1 } } );
		await logIn( page, 'author' );
		await uploadInMediaLibrary( page, svgFile( 'logo.svg', CLEAN_SVG ) );

		await page.goto( '/wp-admin/upload.php?mode=list' );

		const thumbnail = page.locator( '#the-list tr .media-icon img' );
		await expectLoadedSvg( thumbnail );
		await expect( thumbnail ).toHaveAttribute( 'width', '30' );
		await expect( thumbnail ).toHaveAttribute( 'height', '20' );
	} );

	test( 'the stored file is served as an SVG image', async ( { page, request } ) => {
		await resetSite( request, { settings: { author: 1 } } );
		await logIn( page, 'author' );
		await uploadInMediaLibrary( page, svgFile( 'logo.svg', CLEAN_SVG ) );
		const [ attachment ] = ( await siteState( request ) ).attachments;

		const response = await request.get( attachment.url );

		expect( response.status() ).toBe( 200 );
		expect( response.headers()[ 'content-type' ] ).toContain( 'image/svg+xml' );
	} );
} );

test.describe( 'malicious and broken SVG', () => {
	test( 'a sanitized role gets the script and the handler removed', async ( { page, request } ) => {
		await resetSite( request, { settings: { author: 1 } } );
		await logIn( page, 'author' );

		await uploadInMediaLibrary( page, svgFile( 'logo.svg', DIRTY_SVG ) );

		await expect( gridTiles( page ) ).toHaveCount( 1 );
		await expectLoadedSvg( gridTiles( page ).first().locator( 'img' ) );
		const [ attachment ] = ( await siteState( request ) ).attachments;
		expect( attachment.content ).toBe( SANITIZED );
	} );

	test( 'the sanitized file runs no script when opened directly', async ( { page, request } ) => {
		await resetSite( request, { settings: { author: 1 } } );
		await logIn( page, 'author' );
		await uploadInMediaLibrary( page, svgFile( 'logo.svg', DIRTY_SVG ) );
		const [ attachment ] = ( await siteState( request ) ).attachments;

		await page.goto( attachment.url );

		await expect( page.locator( 'svg rect' ) ).toHaveCount( 1 );
		expect( await page.evaluate( () => window.wpsvgRan === true ) ).toBe( false );
	} );

	test( 'an unrestricted role stores the file as sent, and its script runs when opened', async ( { page, request } ) => {
		await resetSite( request, { settings: { administrator: 2 } } );
		await logIn( page, 'admin' );

		await uploadInMediaLibrary( page, svgFile( 'logo.svg', DIRTY_SVG ) );

		await expect( gridTiles( page ) ).toHaveCount( 1 );
		const [ attachment ] = ( await siteState( request ) ).attachments;
		expect( attachment.content ).toBe( DIRTY_SVG );
		await page.goto( attachment.url );
		await expect.poll( () => page.evaluate( () => window.wpsvgRan === true ) ).toBe( true );
	} );

	test( 'an SVG that cannot be parsed is refused with the plugin\'s message', async ( { page, request } ) => {
		await resetSite( request, { settings: { author: 1 } } );
		await logIn( page, 'author' );

		const answer = await uploadInMediaLibrary( page, svgFile( 'broken.svg', BROKEN_SVG ) );

		expect( answer.requested, 'The server refuses it, not the browser' ).toBe( true );

		await expect( uploadError( page ) ).toHaveText( 'This SVG can not be sanitized!' );
		await expect( gridTiles( page ) ).toHaveCount( 0 );
		expect( ( await siteState( request ) ).attachments ).toEqual( [] );
	} );
} );

test.describe( 'roles without permission', () => {
	const cases = [
		{ title: 'a disabled role', settings: { author: 0, editor: 1 }, login: 'author' },
		{ title: 'a role missing from the settings', settings: { editor: 1 }, login: 'author' },
	];
	for ( const { title, settings, login } of cases ) {
		// The grid's uploader knows the allowed file types and refuses the
		// file in the browser; its wording differs between WordPress versions.
		test( `${ title } is stopped by the Media Library's uploader`, async ( { page, request } ) => {
			await resetSite( request, { settings } );
			await logIn( page, login );

			const answer = await uploadInMediaLibrary( page, svgFile( 'logo.svg', CLEAN_SVG ) );

			expect( answer.requested, 'No upload request is sent' ).toBe( false );
			await expect( page.locator( '.upload-errors .upload-error-filename' ) ).toHaveText( 'logo.svg' );
			await expect( uploadError( page ) ).not.toBeEmpty();
			await expect( gridTiles( page ) ).toHaveCount( 0 );
			expect( ( await siteState( request ) ).attachments ).toEqual( [] );
		} );

		test( `${ title } gets WordPress's file type error from the server`, async ( { page, request } ) => {
			await resetSite( request, { settings } );
			await logIn( page, login );

			await uploadWithPlainForm( page, svgFile( 'logo.svg', CLEAN_SVG ) );

			await expect( page.locator( 'body' ) ).toContainText( 'Sorry, you are not allowed to upload this file type.' );
			expect( ( await siteState( request ) ).attachments ).toEqual( [] );
		} );
	}

	test( 'a contributor has no Media Library at all', async ( { page, request } ) => {
		await resetSite( request, { settings: { author: 1 } } );
		await logIn( page, 'contributor' );

		await page.goto( '/wp-admin/upload.php' );

		await expect( page.locator( 'body' ) ).toContainText( 'Sorry, you are not allowed to access this page.' );
	} );

	test( 'other file types are still uploaded as before', async ( { page, request } ) => {
		await resetSite( request, { settings: { author: 0 } } );
		await logIn( page, 'author' );
		const png = Buffer.from( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', 'base64' );

		await uploadInMediaLibrary( page, { name: 'pixel.png', mimeType: 'image/png', buffer: png } );

		await expect( uploadError( page ) ).toHaveCount( 0 );
		await expect( gridTiles( page ) ).toHaveCount( 1 );
		const [ attachment ] = ( await siteState( request ) ).attachments;
		expect( attachment.mime ).toBe( 'image/png' );
	} );
} );

test.describe( 'known defects', () => {
	// Pins the defect in WPSVG::admin_options_page(): the settings page shows
	// every role as "sanitized" on a fresh install, but uploads are blocked
	// until the form is saved once. When fixed, the upload is stored.
	test( 'a fresh install shows sanitized but refuses the upload, pinned for deferred fix @pinned', async ( { page, request } ) => {
		await resetSite( request, {} );
		await logIn( page, 'admin' );
		await page.goto( '/wp-admin/options-general.php?page=wp-svg-images.php' );
		await expect( page.locator( '#role_administrator' ) ).toHaveValue( '1' );

		await uploadWithPlainForm( page, svgFile( 'logo.svg', CLEAN_SVG ) );

		await expect( page.locator( 'body' ) ).toContainText( 'Sorry, you are not allowed to upload this file type.' );
		expect( ( await siteState( request ) ).attachments ).toEqual( [] );
	} );

	// Pins the defect in WPSVG::wp_handle_upload_prefilter(): content that is
	// not detected as SVG is stored unsanitized. A line break before the
	// markup is enough; the script then runs for whoever opens the file.
	// When fixed, the stored file has no script.
	test( 'an SVG with a leading line break keeps its script, pinned for deferred fix @pinned', async ( { page, request } ) => {
		await resetSite( request, { settings: { author: 1 } } );
		await logIn( page, 'author' );

		await uploadInMediaLibrary( page, svgFile( 'logo.svg', '\n' + DIRTY_SVG ) );

		await expect( gridTiles( page ) ).toHaveCount( 1 );
		const [ attachment ] = ( await siteState( request ) ).attachments;
		expect( attachment.mime ).toBe( 'image/svg+xml' );
		expect( attachment.content ).toContain( '<script>' );
		await page.goto( attachment.url );
		await expect.poll( () => page.evaluate( () => window.wpsvgRan === true ) ).toBe( true );
	} );
} );
