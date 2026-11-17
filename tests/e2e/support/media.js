// Helpers for uploads: the files the tests upload and the two places a
// person uploads them, the Media Library and the block editor.
const { expect } = require( '@playwright/test' );

const NS = 'xmlns="http://www.w3.org/2000/svg"';

/** A harmless SVG, 30 by 20. */
const CLEAN_SVG = `<svg ${ NS } width="30" height="20" viewBox="0 0 30 20"><rect width="30" height="20" fill="#0a7"/></svg>`;

/** The same picture with a handler and a script. */
const DIRTY_SVG = `<svg ${ NS } width="30" height="20" viewBox="0 0 30 20" onload="window.wpsvgRan = true"><script>window.wpsvgRan = true</script><rect width="30" height="20" fill="#0a7"/></svg>`;

/** Not well-formed XML. */
const BROKEN_SVG = `<svg ${ NS } width="30" height="20"><rect></svg>`;

/**
 * @param {string} name    File name.
 * @param {string} content File content.
 * @return {{name: string, mimeType: string, buffer: Buffer}} A file for setInputFiles().
 */
function svgFile( name, content ) {
	return { name, mimeType: 'image/svg+xml', buffer: Buffer.from( content, 'utf8' ) };
}

/**
 * Opens the Media Library grid and hands one file to its uploader, which
 * posts to async-upload.php like a drag and drop does.
 *
 * The uploader knows the file types WordPress allows for the user and
 * refuses other files in the browser, without a request. Returns once either
 * WordPress has answered or the uploader shows an error.
 *
 * @param {import('@playwright/test').Page} page
 * @param {{name: string, mimeType: string, buffer: Buffer}} file
 * @return {Promise<{requested: boolean, body: string|null}>} Whether a request
 *         was sent, and WordPress's answer to it.
 */
async function uploadInMediaLibrary( page, file ) {
	await page.goto( '/wp-admin/upload.php?mode=grid' );
	await expect( page.locator( '.attachments-browser .media-toolbar' ) ).toBeVisible();

	const refusal = page.locator( '.upload-errors .upload-error' ).first();
	const answered = page
		.waitForResponse( ( response ) => response.url().includes( '/wp-admin/async-upload.php' ) )
		.catch( () => null );
	await page.locator( 'input[type="file"]' ).first().setInputFiles( file );
	const answer = await Promise.race( [ answered, refusal.waitFor().then( () => null ) ] );

	if ( answer ) {
		// The grid shows the new attachment, or the error WordPress answered with.
		await expect( page.locator( '.attachments-browser .attachment[data-id]:not(.uploading)' ).or( refusal ).first() ).toBeVisible();
	}

	return { requested: answer !== null, body: answer ? await answer.text() : null };
}

/**
 * Uploads one file through the plain form of Media > Add Media File
 * ("browser uploader"), which sends whatever file it is given.
 *
 * @param {import('@playwright/test').Page} page
 * @param {{name: string, mimeType: string, buffer: Buffer}} file
 */
async function uploadWithPlainForm( page, file ) {
	await page.goto( '/wp-admin/media-new.php?browser-uploader' );
	await page.locator( '#async-upload' ).setInputFiles( file );
	await page.locator( '#html-upload' ).click();
	await page.waitForLoadState( 'load' );
}

/**
 * @param {import('@playwright/test').Page} page
 * @return {import('@playwright/test').Locator} Tiles of the Media Library grid.
 */
function gridTiles( page ) {
	return page.locator( '.attachments-browser .attachments .attachment' );
}

/**
 * Asserts that an image element shows a loaded SVG file.
 *
 * @param {import('@playwright/test').Locator} image
 */
async function expectLoadedSvg( image ) {
	await expect( image ).toBeVisible();
	await expect( image ).toHaveAttribute( 'src', /\.svg(\?.*)?$/ );
	await expect.poll( () => image.evaluate( ( element ) => element.complete && element.naturalWidth > 0 ), { message: 'The browser loaded and decoded the image' } ).toBe( true );
}

module.exports = { CLEAN_SVG, DIRTY_SVG, BROKEN_SVG, svgFile, uploadInMediaLibrary, uploadWithPlainForm, gridTiles, expectLoadedSvg };
