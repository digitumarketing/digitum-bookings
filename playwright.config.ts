import { defineConfig, devices } from '@playwright/test';
import { readFileSync } from 'fs';

// Written by `php tests/e2e/prepare.php` (run `npm run test:e2e`).
const e2e = JSON.parse( readFileSync( '.wp-test/e2e.json', 'utf8' ) );
const baseURL = `http://127.0.0.1:${ e2e.port }`;

export default defineConfig( {
	testDir: 'tests/e2e',
	fullyParallel: false,
	workers: 1,
	retries: 0,
	timeout: 60_000,
	reporter: [ [ 'list' ] ],
	use: {
		baseURL,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	projects: [
		{ name: 'desktop', use: { ...devices[ 'Desktop Chrome' ], viewport: { width: 1280, height: 900 } } },
		{ name: 'mobile', use: { ...devices[ 'Pixel 7' ] } },
	],
	webServer: {
		command: `php -S 127.0.0.1:${ e2e.port } -t ${ e2e.site } ${ e2e.site }/router.php`,
		url: baseURL + '/wp-login.php',
		reuseExistingServer: true,
		env: { PHP_CLI_SERVER_WORKERS: '4' },
		timeout: 30_000,
	},
} );
