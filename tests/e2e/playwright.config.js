// Configuration of the `e2e` suite. Run through bin/test.sh, which starts
// the site and passes the selection options; see TESTING.md.
const { defineConfig, devices } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: './specs',
	// One site and one database are shared by all tests.
	workers: 1,
	fullyParallel: false,
	// A flaky test is a red test.
	retries: 0,
	forbidOnly: true,
	timeout: 60000,
	expect: { timeout: 15000 },
	reporter: [
		[ 'list' ],
		[ 'junit', { outputFile: '/results/e2e.junit.xml' } ],
	],
	outputDir: '/results/e2e-artifacts',
	use: {
		baseURL: process.env.WPSVG_E2E_BASE_URL,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	projects: [
		{ name: 'chromium', use: { ...devices[ 'Desktop Chrome' ] } },
	],
} );
