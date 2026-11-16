// Helpers for the block editor: a new post with an image block, and uploads
// through that block, which go to the REST API as a multipart form.
const { expect } = require( '@playwright/test' );

/**
 * Opens the editor for a new post and waits until its data stores are ready.
 *
 * @param {import('@playwright/test').Page} page
 */
async function openNewPost( page ) {
	await page.goto( '/wp-admin/post-new.php' );
	await page.waitForFunction( () => window.wp && window.wp.data && window.wp.blocks && window.wp.blocks.getBlockType( 'core/image' ) && window.wp.data.select( 'core/editor' ).getCurrentPostId() );
}

/**
 * The editor canvas: an iframe in current WordPress, the page itself in
 * older versions.
 *
 * @param {import('@playwright/test').Page} page
 * @return {Promise<import('@playwright/test').FrameLocator|import('@playwright/test').Page>}
 */
async function editorCanvas( page ) {
	await expect( page.locator( 'iframe[name="editor-canvas"], .editor-styles-wrapper' ).first() ).toBeVisible();
	const framed = await page.locator( 'iframe[name="editor-canvas"]' ).count();
	return framed ? page.frameLocator( 'iframe[name="editor-canvas"]' ) : page;
}

/**
 * Waits for an error in the editor's notice list and returns its text.
 *
 * The list is read from the editor's data store, where each notice carries a
 * status. In the page, upload errors look the same as the "Uploading" and
 * "Upload complete" messages current WordPress shows.
 *
 * @param {import('@playwright/test').Page} page
 * @return {Promise<string>} Text of the first error notice.
 */
async function editorError( page ) {
	const handle = await page.waitForFunction( () => {
		const error = window.wp.data.select( 'core/notices' ).getNotices().find( ( notice ) => notice.status === 'error' );
		return error ? String( error.content ) : false;
	} );
	return handle.jsonValue();
}

/**
 * Inserts an empty image block and hands a file to its upload button.
 *
 * The editor knows the file types WordPress allows for the user and refuses
 * other files in the browser, without a request. Returns once either the
 * REST API has answered the upload or the editor shows an error.
 *
 * @param {import('@playwright/test').Page} page
 * @param {{name: string, mimeType: string, buffer: Buffer}} file
 * @return {Promise<{requested: boolean, status: number|null, body: Object|null}>}
 *         Whether a request was sent, and the REST API's answer to it.
 */
async function uploadInImageBlock( page, file ) {
	await page.evaluate( () => {
		window.wp.data.dispatch( 'core/block-editor' ).insertBlocks( window.wp.blocks.createBlock( 'core/image' ) );
	} );
	const canvas = await editorCanvas( page );
	const block = canvas.locator( '.wp-block-image' ).first();
	await expect( block ).toBeVisible();

	const answered = page
		.waitForResponse( ( response ) => response.request().method() === 'POST' && decodeURIComponent( response.url() ).includes( '/wp/v2/media' ) )
		.catch( () => null );
	await block.locator( 'input[type="file"]' ).first().setInputFiles( file );
	const response = await Promise.race( [ answered, editorError( page ).then( () => null ) ] );

	if ( ! response ) {
		return { requested: false, status: null, body: null };
	}
	return { requested: true, status: response.status(), body: await response.json() };
}

/**
 * @param {import('@playwright/test').Page} page
 * @return {Promise<Object[]>} Name and attributes of the blocks in the post.
 */
function blocks( page ) {
	return page.evaluate( () => window.wp.data.select( 'core/block-editor' ).getBlocks().map( ( block ) => ( { name: block.name, attributes: block.attributes } ) ) );
}

module.exports = { openNewPost, editorCanvas, editorError, uploadInImageBlock, blocks };
