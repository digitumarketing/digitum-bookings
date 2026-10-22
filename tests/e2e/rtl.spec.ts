import { test, expect, Page } from '@playwright/test';
import { e2e, tours, path, axe, pickTomorrow } from './helpers';

/*
 * Arabic, right to left (M7): the widget is translated, mirrored, fits the screen and books.
 */

const arabic = /[؀-ۿ]/;

async function arabicPage( page: Page, url: string ) {
	await page.context().addCookies( [ { name: 'dgb_test_locale', value: 'ar', url: `http://127.0.0.1:${ e2e.port }` } ] );
	await page.goto( url );
}

test.describe( 'Arabic (RTL)', () => {
	test( 'tour page is right to left, translated and fits the screen', async ( { page, isMobile }, info ) => {
		await arabicPage( page, path( tours.rzr.url ) );
		await expect( page.locator( 'html' ) ).toHaveAttribute( 'dir', 'rtl' );
		const w = page.locator( '[data-dgb-widget]' );
		expect( await w.evaluate( ( el ) => getComputedStyle( el ).direction ) ).toBe( 'rtl' );
		await expect( w.locator( '.dgb-from' ) ).toContainText( arabic );
		await expect( w.locator( '[data-dgb-submit]' ) ).toHaveText( arabic );
		await expect( page.locator( '.dgb-credit' ) ).toContainText( arabic );
		await expect( page.locator( '.dgb-credit a' ) ).toHaveText( 'Digitum' );
		expect( await page.evaluate( () => document.documentElement.scrollWidth <= window.innerWidth + 1 ) ).toBe( true );
		if ( isMobile ) {
			await page.screenshot( { path: `test-results/rtl-${ info.project.name }-closed.png`, fullPage: false } );
			await page.locator( '.dgb-sticky [data-dgb-open]' ).click();
			await expect( w.locator( '[data-dgb-next-month]' ) ).toBeVisible();
		}
		expect( await w.locator( '[data-dgb-next-month]' ).evaluate( ( el ) => getComputedStyle( el ).transform ) ).toBe( 'matrix(-1, 0, 0, 1, 0, 0)' );
		expect( await w.locator( '.dgb-card-title' ).first().evaluate( ( el ) => getComputedStyle( el ).unicodeBidi ) ).toBe( 'plaintext' );
		await axe( page );
		await page.screenshot( { path: `test-results/rtl-${ info.project.name }.png`, fullPage: false } );
	} );

	test( 'booking in Arabic, paid on arrival', async ( { page, isMobile } ) => {
		test.skip( isMobile, 'flow covered on desktop; the phone layout is checked above' );
		await arabicPage( page, path( tours.rzr.url ) );
		const w = page.locator( '[data-dgb-widget]' );
		await w.locator( 'input[name="variant_id"]' ).nth( 1 ).check();
		await pickTomorrow( w );
		await w.locator( 'input[name="session_id"]' ).last().check();
		await w.locator( '[data-dgb-inc="adults"]' ).click();
		await w.locator( 'input[name="name"]' ).fill( 'ضيف تجريبي' );
		await w.locator( 'input[name="email"]' ).fill( 'arabic@example.com' );
		await w.locator( 'input[name="phone"]' ).fill( '501234567' );
		await w.locator( 'input[name="terms"]' ).check();
		await w.locator( 'input[name="payment_method"][value="arrival"]' ).check();
		await w.locator( '[data-dgb-submit]' ).click();
		const confirmation = w.locator( '[data-dgb-confirmation]' );
		await expect( confirmation ).toBeVisible();
		await expect( confirmation ).toContainText( arabic );
		await expect( confirmation.locator( '.dgb-ref strong' ) ).toHaveText( /^DGB-\d{6}-[A-Z2-9]{4}$/ );
		expect( await page.evaluate( () => document.documentElement.scrollWidth <= window.innerWidth + 1 ) ).toBe( true );
		await axe( page );
	} );
} );
