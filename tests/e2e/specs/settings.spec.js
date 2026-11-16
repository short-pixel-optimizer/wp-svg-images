// Journey 1: an administrator activates the plugin, lands on its settings
// page, chooses a mode per role and saves.
const { test, expect } = require( '@playwright/test' );
const { SETTINGS_PAGE, resetSite, siteState, logIn } = require( '../support/site' );

const PLUGIN_ROW = 'tr[data-plugin="wp-svg-images/wp-svg-images.php"]';

test.describe( 'activation', () => {
	test.beforeEach( async ( { request } ) => {
		await resetSite( request, { plugin: 'inactive' } );
	} );

	test( 'activating the plugin leads to its settings page, once', async ( { page, request } ) => {
		await logIn( page, 'admin' );
		await page.goto( '/wp-admin/plugins.php' );
		await expect( page.locator( PLUGIN_ROW ) ).toHaveClass( /\binactive\b/ );

		await page.locator( `${ PLUGIN_ROW } .activate a` ).click();

		await expect( page ).toHaveURL( /options-general\.php\?page=wp-svg-images\.php/ );
		await expect( page.getByRole( 'heading', { name: 'SVG images', level: 2 } ) ).toBeVisible();
		const state = await siteState( request );
		expect( state.plugin_active ).toBe( true );
		expect( state.activation_flag, 'The redirect flag is used up' ).toBeNull();

		await page.goto( '/wp-admin/plugins.php' );
		await expect( page ).toHaveURL( /plugins\.php$/ );
		await expect( page.locator( PLUGIN_ROW ) ).toHaveClass( /\bactive\b/ );
	} );

	test( 'an active plugin offers a Settings link on the Plugins screen', async ( { page, request } ) => {
		await resetSite( request, {} );
		await logIn( page, 'admin' );
		await page.goto( '/wp-admin/plugins.php' );

		await page.locator( PLUGIN_ROW ).getByRole( 'link', { name: 'Settings', exact: true } ).click();

		await expect( page ).toHaveURL( /options-general\.php\?page=wp-svg-images\.php/ );
		await expect( page.getByRole( 'heading', { name: 'SVG images', level: 2 } ) ).toBeVisible();
	} );
} );

test.describe( 'settings page', () => {
	test.beforeEach( async ( { request } ) => {
		await resetSite( request, {} );
	} );

	test( 'is reachable from the Settings menu and lists the roles that can upload', async ( { page } ) => {
		await logIn( page, 'admin' );
		await page.goto( '/wp-admin/options-general.php' );

		await page.locator( '#menu-settings' ).getByRole( 'link', { name: 'SVG images', exact: true } ).click();

		await expect( page ).toHaveURL( /options-general\.php\?page=wp-svg-images\.php/ );
		await expect( page.getByRole( 'heading', { name: 'Security notice' } ) ).toBeVisible();
		await expect( page.locator( 'form table.form-table th label' ) ).toHaveText( [ 'Administrator', 'Editor', 'Author' ] );
		await expect( page.locator( '#role_contributor, #role_subscriber' ) ).toHaveCount( 0 );
	} );

	test( 'shows every role as sanitized before anything is saved', async ( { page, request } ) => {
		await logIn( page, 'admin' );
		await page.goto( SETTINGS_PAGE );

		for ( const role of [ 'administrator', 'editor', 'author' ] ) {
			await expect( page.locator( `#role_${ role }` ) ).toHaveValue( '1' );
			await expect( page.locator( `#role_${ role } option:checked` ) ).toHaveText( /enable sanitized SVG upload/ );
		}
		expect( ( await siteState( request ) ).settings, 'Opening the page saves nothing' ).toBeNull();
	} );

	test( 'saves the chosen mode per role and shows it again', async ( { page, request } ) => {
		await logIn( page, 'admin' );
		await page.goto( SETTINGS_PAGE );

		await page.locator( '#role_administrator' ).selectOption( { label: 'enable unrestricted SVG upload' } );
		await page.locator( '#role_editor' ).selectOption( { label: 'disable SVG upload' } );
		await page.getByRole( 'button', { name: 'Save', exact: true } ).click();

		await expect( page.locator( '.updated' ) ).toHaveText( 'Settings saved.' );
		await expect( page.locator( '#role_administrator' ) ).toHaveValue( '2' );
		await expect( page.locator( '#role_editor' ) ).toHaveValue( '0' );
		await expect( page.locator( '#role_author' ) ).toHaveValue( '1' );
		expect( ( await siteState( request ) ).settings ).toEqual( {
			role_administrator: 2,
			role_editor: 0,
			role_author: 1,
		} );

		await page.goto( SETTINGS_PAGE );
		await expect( page.locator( '.updated' ) ).toHaveCount( 0 );
		await expect( page.locator( '#role_administrator' ) ).toHaveValue( '2' );
		await expect( page.locator( '#role_editor' ) ).toHaveValue( '0' );
		await expect( page.locator( '#role_author' ) ).toHaveValue( '1' );
	} );

	test( 'explains the modes and links to the documentation', async ( { page } ) => {
		await logIn( page, 'admin' );
		await page.goto( SETTINGS_PAGE );

		await expect( page.locator( '.notice-warning' ) ).toContainText( 'enable unrestricted SVG upload' );
		await expect( page.getByRole( 'link', { name: 'Read more' } ).first() ).toHaveAttribute(
			'href',
			'https://shortpixel.com/knowledge-base/article/524-about-sanitizing-svgs'
		);
	} );

	test( 'is closed to a user who cannot manage options', async ( { page } ) => {
		await logIn( page, 'editor' );

		await page.goto( SETTINGS_PAGE );

		await expect( page.locator( 'body' ) ).toContainText( 'Sorry, you are not allowed to access this page.' );
		await expect( page.locator( '#role_editor' ) ).toHaveCount( 0 );
		await page.goto( '/wp-admin/' );
		await expect( page.locator( '#adminmenu' ).getByRole( 'link', { name: 'SVG images', exact: true } ) ).toHaveCount( 0 );
	} );
} );
