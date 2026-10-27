import { test, expect, Page, Frame } from '@playwright/test';
import { e2e, tours, path, chooseRide, fillDetails } from './helpers';

/*
 * Real Stripe test mode and PayPal sandbox. Runs only when the e2e site was prepared with sandbox keys:
 *
 *   DGB_SANDBOX_STRIPE_PK=pk_test_… DGB_SANDBOX_STRIPE_SK=sk_test_… \
 *   DGB_SANDBOX_PAYPAL_CLIENT=… DGB_SANDBOX_PAYPAL_SECRET=… \
 *   DGB_SANDBOX_PAYPAL_BUYER=buyer@example.com DGB_SANDBOX_PAYPAL_BUYER_PASSWORD=… \
 *   DGB_WP_DIR=… DGB_SQLITE_DIR=… npm run test:e2e -- tests/e2e/sandbox.spec.ts
 *
 * Stripe and PayPal cannot send webhooks to a local site, so these tests also prove the booking is confirmed
 * from the browser's return alone. Webhook handling is covered by tests/Integration (scenario F).
 */

test.skip( ! e2e.sandbox, 'Set the DGB_SANDBOX_* keys and prepare the site again to run against the real sandboxes.' );
test.skip( ( { isMobile } ) => isMobile, 'desktop' );
test.setTimeout( 120_000 );

async function book( page: Page, method: RegExp ) {
	await page.goto( path( tours.buggy.url ) );
	const w = page.locator( '[data-dgb-widget]' );
	await chooseRide( w );
	await fillDetails( w );
	await w.getByRole( 'radio', { name: method } ).check();
	await w.locator( '[data-dgb-submit]' ).click();
	await expect( page.locator( '[data-dgb-pay]' ) ).toBeVisible();
}

async function card( page: Page, number: string ) {
	const frame = page.frameLocator( '[data-dgb-pay-element] iframe' ).first();
	await frame.getByPlaceholder( '1234 1234 1234 1234' ).fill( number );
	await frame.getByPlaceholder( 'MM / YY' ).fill( '12 / 34' );
	await frame.getByPlaceholder( 'CVC' ).fill( '123' );
	const zip = frame.getByPlaceholder( /ZIP|Postal/ );
	if ( await zip.count() ) {
		await zip.fill( '12345' );
	}
	await page.locator( '[data-dgb-pay-button]' ).click();
}

/** Stripe's 3-D Secure test page lives in nested frames; find its button wherever it is. */
async function threeDs( page: Page, label: 'Complete' | 'Fail' ) {
	for ( let i = 0; i < 60; i++ ) {
		for ( const frame of page.frames() as Frame[] ) {
			const button = frame.getByRole( 'button', { name: label, exact: true } );
			if ( await button.count().catch( () => 0 ) ) {
				await button.click();
				return;
			}
		}
		await page.waitForTimeout( 500 );
	}
	throw new Error( '3-D Secure challenge not found' );
}

test.describe( 'Stripe test mode', () => {
	test.skip( ! process.env.DGB_SANDBOX_STRIPE_SK, 'Stripe keys not set' );

	test( 'success', async ( { page } ) => {
		await book( page, /^Card, Apple Pay or Google Pay/ );
		await card( page, '4242424242424242' );
		await expect( page.locator( '[data-dgb-confirmation]' ) ).toContainText( 'Booking confirmed', { timeout: 30_000 } );
	} );

	test( 'declined card, then success', async ( { page } ) => {
		await book( page, /^Card, Apple Pay or Google Pay/ );
		await card( page, '4000000000000002' );
		await expect( page.locator( '[data-dgb-pay-message]' ) ).toContainText( 'declined', { timeout: 30_000 } );
		await card( page, '4242424242424242' );
		await expect( page.locator( '[data-dgb-confirmation]' ) ).toContainText( 'Booking confirmed', { timeout: 30_000 } );
	} );

	test( '3-D Secure: failed, then completed', async ( { page } ) => {
		await book( page, /^Card, Apple Pay or Google Pay/ );
		await card( page, '4000002760003184' );
		await threeDs( page, 'Fail' );
		await expect( page.locator( '[data-dgb-pay-message]' ) ).not.toBeEmpty( { timeout: 30_000 } );
		await card( page, '4000002760003184' );
		await threeDs( page, 'Complete' );
		await expect( page.locator( '[data-dgb-confirmation]' ) ).toContainText( 'Booking confirmed', { timeout: 30_000 } );
	} );

	test( 'refund from the Payments screen', async ( { page, browser } ) => {
		await book( page, /^Card, Apple Pay or Google Pay/ );
		await card( page, '4242424242424242' );
		const confirmation = page.locator( '[data-dgb-confirmation]' );
		await expect( confirmation ).toContainText( 'Booking confirmed', { timeout: 30_000 } );
		const ref = ( ( await confirmation.locator( '.dgb-ref strong' ).textContent() ) || '' ).trim();

		const context = await browser.newContext();
		const staff = await context.newPage();
		await staff.goto( '/wp-login.php' );
		await staff.locator( '#user_login' ).fill( 'admin' );
		await staff.locator( '#user_pass' ).fill( 'password' );
		await staff.locator( '#wp-submit' ).click();
		await staff.goto( '/wp-admin/admin.php?page=dgb-payments&booking=' + ref );
		staff.on( 'dialog', ( dialog ) => dialog.accept() );
		await staff.getByLabel( 'Amount to refund' ).fill( '100' );
		await staff.getByRole( 'button', { name: 'Refund', exact: true } ).click();
		await expect( staff.locator( '.notice-success' ) ).toContainText( /Refund (recorded|sent)/ );
		await context.close();
	} );
} );

test.describe( 'PayPal sandbox', () => {
	test.skip( ! process.env.DGB_SANDBOX_PAYPAL_SECRET || ! process.env.DGB_SANDBOX_PAYPAL_BUYER, 'PayPal keys or sandbox buyer not set' );

	test( 'pay with a sandbox buyer account', async ( { page } ) => {
		await book( page, /^PayPal/ );
		const buttons = page.frameLocator( '[data-dgb-pay-element] iframe' ).first();
		const [ popup ] = await Promise.all( [ page.waitForEvent( 'popup' ), buttons.locator( '[data-funding-source="paypal"]' ).first().click() ] );
		await popup.waitForLoadState();
		await popup.locator( '#email' ).fill( process.env.DGB_SANDBOX_PAYPAL_BUYER as string );
		await popup.locator( '#btnNext' ).click();
		await popup.locator( '#password' ).fill( process.env.DGB_SANDBOX_PAYPAL_BUYER_PASSWORD as string );
		await popup.locator( '#btnLogin' ).click();
		await popup.locator( '#payment-submit-btn' ).click();
		await expect( page.locator( '[data-dgb-confirmation]' ) ).toContainText( 'Booking confirmed', { timeout: 60_000 } );
	} );
} );
