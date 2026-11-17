// Journey 7: a published post shows its SVG image block with width and
// height on the front end.
const { test, expect } = require( '@playwright/test' );
const { resetSite, logIn } = require( '../support/site' );
const { CLEAN_SVG, svgFile, expectLoadedSvg } = require( '../support/media' );
const { openNewPost, uploadInImageBlock, publishPost, updateFirstBlock } = require( '../support/editor' );

const NS = 'xmlns="http://www.w3.org/2000/svg"';

/** An SVG that declares neither a size nor a viewBox. */
const UNSIZED_SVG = `<svg ${ NS }><rect width="30" height="20" fill="#0a7"/></svg>`;

const image = ( page ) => page.locator( 'figure.wp-block-image img' );

/**
 * Uploads an SVG into an image block of a new post and publishes the post.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} svg        File content.
 * @param {Object} attributes Block attributes to set before publishing.
 * @return {Promise<string>} Address of the published post.
 */
async function publishPostWithSvg( page, svg, attributes = {} ) {
	await openNewPost( page );
	const answer = await uploadInImageBlock( page, svgFile( 'logo.svg', svg ) );
	expect( answer.status ).toBe( 201 );
	if ( Object.keys( attributes ).length ) {
		await updateFirstBlock( page, attributes );
	}
	return publishPost( page, 'A post with an SVG' );
}

test.beforeEach( async ( { page, request } ) => {
	await resetSite( request, { settings: { administrator: 1, author: 1 } } );
	await logIn( page, 'author' );
} );

test( 'the image block shows the SVG with its width and height', async ( { page } ) => {
	const address = await publishPostWithSvg( page, CLEAN_SVG );

	await page.goto( address );

	await expect( page.locator( 'h1, h2' ).filter( { hasText: 'A post with an SVG' } ).first() ).toBeVisible();
	await expect( image( page ) ).toHaveCount( 1 );
	await expectLoadedSvg( image( page ) );
	await expect( image( page ) ).toHaveAttribute( 'width', '30' );
	await expect( image( page ) ).toHaveAttribute( 'height', '20' );
} );

test( 'a visitor who is not logged in gets the same markup', async ( { page, browser } ) => {
	const address = await publishPostWithSvg( page, CLEAN_SVG );
	const context = await browser.newContext( { baseURL: process.env.WPSVG_E2E_BASE_URL } );
	const visitor = await context.newPage();

	await visitor.goto( address );

	await expectLoadedSvg( image( visitor ) );
	await expect( image( visitor ) ).toHaveAttribute( 'width', '30' );
	await expect( image( visitor ) ).toHaveAttribute( 'height', '20' );
	await context.close();
} );

test( 'a caption does not get in the way of the dimensions', async ( { page } ) => {
	const address = await publishPostWithSvg( page, CLEAN_SVG, { caption: 'Our logo' } );

	await page.goto( address );

	await expect( page.locator( 'figure.wp-block-image figcaption' ) ).toHaveText( 'Our logo' );
	await expect( image( page ) ).toHaveAttribute( 'width', '30' );
	await expect( image( page ) ).toHaveAttribute( 'height', '20' );
} );

// Pins the defect in WPSVG::fix_missing_width_height_on_image_block(): the
// text "height=" in the caption counts as an existing dimension, so the
// image gets none. When fixed, the image has width 30 and height 20.
test( 'a caption that mentions "height=" leaves the image without dimensions, pinned for deferred fix @pinned', async ( { page } ) => {
	const address = await publishPostWithSvg( page, CLEAN_SVG, { caption: 'Max height=20' } );

	await page.goto( address );

	await expect( page.locator( 'figure.wp-block-image figcaption' ) ).toHaveText( 'Max height=20' );
	await expectLoadedSvg( image( page ) );
	expect( await image( page ).getAttribute( 'width' ) ).toBeNull();
	expect( await image( page ).getAttribute( 'height' ) ).toBeNull();
} );

// Pins the second defect of the same method: an SVG without size and
// viewBox gets width="0" height="0", and the visitor sees nothing. When
// fixed, the image has no zero dimensions and is visible.
test( 'an SVG without declared size is hidden by zero dimensions, pinned for deferred fix @pinned', async ( { page } ) => {
	const address = await publishPostWithSvg( page, UNSIZED_SVG );

	await page.goto( address );

	await expect( image( page ) ).toHaveCount( 1 );
	await expect( image( page ) ).toHaveAttribute( 'width', '0' );
	await expect( image( page ) ).toHaveAttribute( 'height', '0' );
	await expect( image( page ) ).toBeHidden();
} );
