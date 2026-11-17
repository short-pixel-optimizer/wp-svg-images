// Journeys 2 to 4 in the block editor: uploads through the image block go to
// the REST API as a multipart form, a real HTTP upload.
const { test, expect } = require( '@playwright/test' );
const { resetSite, siteState, logIn } = require( '../support/site' );
const { CLEAN_SVG, DIRTY_SVG, BROKEN_SVG, svgFile, expectLoadedSvg } = require( '../support/media' );
const { openNewPost, editorCanvas, editorError, uploadInImageBlock, blocks } = require( '../support/editor' );

/** What the plugin makes of DIRTY_SVG. */
const SANITIZED = '<svg xmlns="http://www.w3.org/2000/svg" width="30" height="20" viewBox="0 0 30 20"><rect width="30" height="20" fill="#0a7"></rect></svg>';

/**
 * Sends an upload to the REST API with the logged-in user's cookies and
 * nonce, without the editor's own checks in between.
 *
 * @param {import('@playwright/test').Page} page An open editor page.
 * @param {Object} options `multipart` or `data` plus `headers` for page.request.post().
 * @return {Promise<import('@playwright/test').APIResponse>}
 */
async function restUpload( page, options ) {
	const nonce = await page.evaluate( () => window.wpApiSettings.nonce );
	return page.request.post( '/?rest_route=/wp/v2/media', {
		...options,
		headers: { 'X-WP-Nonce': nonce, ...( options.headers || {} ) },
	} );
}

test.describe( 'image block', () => {
	for ( const login of [ 'author', 'editor', 'admin' ] ) {
		test( `${ login } uploads an SVG into an image block and sees it`, async ( { page, request } ) => {
			await resetSite( request, { settings: { administrator: 1, editor: 1, author: 1 } } );
			await logIn( page, login );
			await openNewPost( page );

			const answer = await uploadInImageBlock( page, svgFile( 'logo.svg', CLEAN_SVG ) );

			expect( answer.status ).toBe( 201 );
			expect( answer.body.mime_type ).toBe( 'image/svg+xml' );
			expect( answer.body.media_details ).toMatchObject( { width: 30, height: 20 } );
			const canvas = await editorCanvas( page );
			await expectLoadedSvg( canvas.locator( '.wp-block-image img' ) );
			const [ block ] = await blocks( page );
			expect( block.attributes.id ).toBe( answer.body.id );
			expect( block.attributes.url ).toMatch( /\/logo\.svg$/ );
		} );
	}

	test( 'a sanitized role gets the script and the handler removed', async ( { page, request } ) => {
		await resetSite( request, { settings: { author: 1 } } );
		await logIn( page, 'author' );
		await openNewPost( page );

		const answer = await uploadInImageBlock( page, svgFile( 'logo.svg', DIRTY_SVG ) );

		expect( answer.status ).toBe( 201 );
		await expectLoadedSvg( ( await editorCanvas( page ) ).locator( '.wp-block-image img' ) );
		const [ attachment ] = ( await siteState( request ) ).attachments;
		expect( attachment.content ).toBe( SANITIZED );
	} );

	test( 'an unrestricted role stores the file as sent', async ( { page, request } ) => {
		await resetSite( request, { settings: { administrator: 2 } } );
		await logIn( page, 'admin' );
		await openNewPost( page );

		await uploadInImageBlock( page, svgFile( 'logo.svg', DIRTY_SVG ) );

		await expectLoadedSvg( ( await editorCanvas( page ) ).locator( '.wp-block-image img' ) );
		const [ attachment ] = ( await siteState( request ) ).attachments;
		expect( attachment.content ).toBe( DIRTY_SVG );
	} );

	test( 'an SVG that cannot be parsed shows the plugin\'s message in the editor', async ( { page, request } ) => {
		await resetSite( request, { settings: { author: 1 } } );
		await logIn( page, 'author' );
		await openNewPost( page );

		const answer = await uploadInImageBlock( page, svgFile( 'broken.svg', BROKEN_SVG ) );

		expect( answer.requested, 'The server refuses it, not the browser' ).toBe( true );
		expect( answer.status ).toBe( 500 );
		expect( await editorError( page ) ).toContain( 'This SVG cannot be sanitized!' );
		expect( ( await blocks( page ) )[ 0 ].attributes.id ).toBeUndefined();
		expect( ( await siteState( request ) ).attachments ).toEqual( [] );
	} );
} );

test.describe( 'roles without permission', () => {
	test( 'a disabled role is stopped by the editor with WordPress\'s file type error', async ( { page, request } ) => {
		await resetSite( request, { settings: { editor: 0, author: 1 } } );
		await logIn( page, 'editor' );
		await openNewPost( page );

		const answer = await uploadInImageBlock( page, svgFile( 'logo.svg', CLEAN_SVG ) );

		expect( answer.requested, 'No upload request is sent' ).toBe( false );
		expect( await editorError( page ) ).toContain( 'Sorry, you are not allowed to upload this file type.' );
		expect( ( await siteState( request ) ).attachments ).toEqual( [] );
	} );

	test( 'the REST API refuses the same file when it is sent anyway', async ( { page, request } ) => {
		await resetSite( request, { settings: { editor: 0, author: 1 } } );
		await logIn( page, 'editor' );
		await openNewPost( page );

		const response = await restUpload( page, { multipart: { file: svgFile( 'logo.svg', CLEAN_SVG ) } } );

		expect( response.status() ).toBe( 500 );
		expect( ( await response.json() ).message ).toBe( 'Sorry, you are not allowed to upload this file type.' );
		expect( ( await siteState( request ) ).attachments ).toEqual( [] );
	} );
} );

test.describe( 'REST API uploads', () => {
	// A REST upload sent as raw request body is a sideload; it goes through
	// the same prefilter as the multipart upload.
	test( 'a REST upload sent as raw body is sanitized like a multipart upload', async ( { page, request } ) => {
		await resetSite( request, { settings: { author: 1 } } );
		await logIn( page, 'author' );
		await openNewPost( page );

		const multipart = await restUpload( page, { multipart: { file: svgFile( 'form.svg', DIRTY_SVG ) } } );
		const raw = await restUpload( page, {
			data: Buffer.from( DIRTY_SVG, 'utf8' ),
			headers: { 'Content-Type': 'image/svg+xml', 'Content-Disposition': 'attachment; filename=raw.svg' },
		} );

		expect( multipart.status() ).toBe( 201 );
		expect( raw.status() ).toBe( 201 );
		const stored = Object.fromEntries( ( await siteState( request ) ).attachments.map( ( attachment ) => [ attachment.file.split( '/' ).pop(), attachment.content ] ) );
		expect( stored[ 'form.svg' ] ).toBe( SANITIZED );
		expect( stored[ 'raw.svg' ] ).toBe( SANITIZED );
	} );
} );
