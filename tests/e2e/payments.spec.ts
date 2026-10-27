import { test, expect, Page, Locator, Browser } from '@playwright/test';
import { e2e, tours, path, events, axe, pickTomorrow, chooseRide, fillDetails } from './helpers';

test.skip( !! e2e.sandbox, 'Fake gateways are not installed in sandbox mode (see sandbox.spec.ts).' );

/*
 * Payments in the browser. Stripe.js and the PayPal SDK are replaced by the fakes in tests/e2e/fakes (this
 * sandbox cannot reach Stripe or PayPal); they talk to the fake APIs of tests/Integration/fake-gateways.php
 * through the same calls the real SDKs make. Real sandbox runs: tests/e2e/sandbox.spec.ts.
 */

const PNG = Buffer.from( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64' );

async function fakeSdks( page: Page ) {
	await page.route( 'https://js.stripe.com/v3/', ( route ) => route.fulfill( { path: 'tests/e2e/fakes/stripe.js', contentType: 'text/javascript' } ) );
	await page.route( /^https:\/\/www\.paypal\.com\/sdk\/js/, ( route ) => route.fulfill( { path: 'tests/e2e/fakes/paypal.js', contentType: 'text/javascript' } ) );
}

async function book( page: Page, method: RegExp ): Promise< Locator > {
	await page.goto( path( tours.buggy.url ) );
	const w = page.locator( '[data-dgb-widget]' );
	await chooseRide( w );
	await fillDetails( w );
	await w.getByRole( 'radio', { name: method } ).check();
	await w.locator( '[data-dgb-submit]' ).click();
	return w.locator( '[data-dgb-confirmation]' );
}

async function reference( confirmation: Locator ) {
	return ( ( await confirmation.locator( '.dgb-ref strong' ).textContent() ) || '' ).trim();
}

async function payByCard( page: Page, card: string ) {
	const box = page.locator( '[data-dgb-pay]' );
	await box.locator( '[data-fake-card]' ).fill( card );
	await box.getByRole( 'button', { name: /^Pay AED/ } ).click();
}

async function admin( browser: Browser ) {
	const context = await browser.newContext();
	const page = await context.newPage();
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( 'admin' );
	await page.locator( '#user_pass' ).fill( 'password' );
	await page.locator( '#wp-submit' ).click();
	await page.waitForURL( /wp-admin/ );
	return page;
}

test.describe( 'payments', () => {
	test.skip( ( { isMobile } ) => isMobile, 'desktop layout; the phone flow is covered below' );
	test.beforeEach( async ( { page } ) => fakeSdks( page ) );

	test( 'card: a declined card, then a good one confirms the booking', async ( { page } ) => {
		await page.goto( path( tours.buggy.url ) );
		const w = page.locator( '[data-dgb-widget]' );
		await chooseRide( w );
		await fillDetails( w );
		await w.getByRole( 'radio', { name: /^Card, Apple Pay or Google Pay/ } ).check();
		await expect( w.locator( '[data-dgb-submit]' ) ).toHaveText( 'Continue to payment · AED 899.00' );
		await w.locator( '[data-dgb-submit]' ).click();

		const confirmation = w.locator( '[data-dgb-confirmation]' );
		await expect( confirmation ).toContainText( 'Complete your payment' );
		const box = page.locator( '[data-dgb-pay]' );
		await expect( box.getByRole( 'button', { name: 'Pay AED 899.00' } ) ).toBeEnabled();
		expect( await events( page ) ).not.toContain( 'purchase' );
		await axe( page );

		await payByCard( page, '4000 0000 0000 0002' );
		await expect( box.locator( '[data-dgb-pay-message]' ) ).toHaveText( 'Your card was declined.' );
		await expect( box.getByRole( 'button', { name: 'Pay AED 899.00' } ) ).toBeEnabled();

		await payByCard( page, '4242 4242 4242 4242' );
		await expect( page.locator( '[data-dgb-confirmation]' ) ).toContainText( 'Booking confirmed' );
		await expect( page.locator( '[data-dgb-pay]' ) ).toHaveCount( 0 );
		const ref = await reference( page.locator( '[data-dgb-confirmation]' ) );
		const purchase = await page.evaluate( () => window.dataLayer.find( ( e ) => 'purchase' === e.event ) );
		expect( purchase?.ecommerce.transaction_id ).toBe( ref );
		expect( purchase?.ecommerce.value ).toBe( 899 );
	} );

	test( 'card with 3-D Secure: failed, then completed authentication', async ( { page } ) => {
		await book( page, /^Card, Apple Pay or Google Pay/ );
		const box = page.locator( '[data-dgb-pay]' );
		await payByCard( page, '4000 0025 0000 3155' );
		await page.getByRole( 'dialog', { name: '3D Secure test authentication' } ).getByRole( 'button', { name: 'Fail authentication' } ).click();
		await expect( box.locator( '[data-dgb-pay-message]' ) ).toContainText( 'unable to authenticate' );

		await payByCard( page, '4000 0025 0000 3155' );
		await page.getByRole( 'dialog', { name: '3D Secure test authentication' } ).getByRole( 'button', { name: 'Complete authentication' } ).click();
		await expect( page.locator( '[data-dgb-confirmation]' ) ).toContainText( 'Booking confirmed' );
	} );

	test( 'card deposit: 20% now, the rest on arrival', async ( { page } ) => {
		await page.goto( path( tours.buggy.url ) );
		const w = page.locator( '[data-dgb-widget]' );
		await chooseRide( w );
		await fillDetails( w );
		await w.getByRole( 'radio', { name: /^Card, Apple Pay or Google Pay/ } ).check();
		await w.getByRole( 'radio', { name: /20% deposit/ } ).check();
		await expect( w.locator( '[data-dgb-submit]' ) ).toHaveText( 'Continue to payment · AED 179.80' );
		await w.locator( '[data-dgb-submit]' ).click();
		await expect( page.locator( '[data-dgb-pay] h3' ) ).toHaveText( 'Pay the deposit: AED 179.80' );
		await payByCard( page, '4242424242424242' );
		const confirmation = page.locator( '[data-dgb-confirmation]' );
		await expect( confirmation ).toContainText( 'Booking confirmed' );
		await expect( confirmation ).toContainText( 'AED 719.20' );
	} );

	test( 'PayPal: closed window, declined card, then paid (converted to USD)', async ( { page } ) => {
		const confirmation = await book( page, /^PayPal/ );
		const box = page.locator( '[data-dgb-pay]' );
		await expect( confirmation ).toContainText( 'PayPal charges USD 244.79 (AED 899.00)' );

		await box.getByRole( 'button', { name: 'Close PayPal window' } ).click();
		await expect( box.locator( '[data-dgb-pay-message]' ) ).toContainText( 'window was closed' );
		await box.getByRole( 'button', { name: 'Declined card' } ).click();
		await expect( box.locator( '[data-dgb-pay-message]' ) ).toContainText( 'declined this payment method' );
		await box.getByRole( 'button', { name: 'Pay with PayPal' } ).click();
		await expect( page.locator( '[data-dgb-confirmation]' ) ).toContainText( 'Booking confirmed' );
		await axe( page );
	} );

	test( 'bank transfer: screenshot upload, staff check it and mark as paid', async ( { page, browser } ) => {
		const confirmation = await book( page, /^Bank transfer/ );
		await expect( confirmation ).toContainText( 'waiting for payment' );
		const ref = await reference( confirmation );
		const form = page.locator( '[data-dgb-proof]' );

		await form.getByLabel( 'Upload the transfer screenshot' ).setInputFiles( { name: 'big.jpg', mimeType: 'image/jpeg', buffer: Buffer.alloc( 6 * 1024 * 1024, 1 ) } );
		await form.getByRole( 'button', { name: 'Upload', exact: true } ).click();
		await expect( form.locator( '[data-dgb-proof-message]' ) ).toContainText( 'larger than 5 MB' );

		await form.getByLabel( 'Upload the transfer screenshot' ).setInputFiles( { name: 'transfer.png', mimeType: 'image/png', buffer: PNG } );
		await form.getByRole( 'button', { name: 'Upload', exact: true } ).click();
		const fresh = page.locator( '[data-dgb-proof]' );
		await expect( fresh.locator( '[data-dgb-proof-message]' ) ).toContainText( 'We received your screenshot' );
		await expect( fresh ).toContainText( 'transfer.png' );
		await axe( page );

		const staff = await admin( browser );
		await staff.goto( '/wp-admin/admin.php?page=digitum-bookings' );
		const row = staff.locator( 'tr', { hasText: ref } );
		await expect( row ).toContainText( 'Screenshot 1' );
		const shot = await row.getByRole( 'link', { name: 'Screenshot 1' } ).getAttribute( 'href' );
		const image = await staff.request.get( shot as string );
		expect( image.status() ).toBe( 200 );
		expect( image.headers()[ 'content-type' ] ).toBe( 'image/png' );
		expect( image.headers()[ 'x-content-type-options' ] ).toBe( 'nosniff' );
		const stranger = await page.request.get( shot as string );
		expect( stranger.headers()[ 'content-type' ] || '' ).not.toContain( 'image/png' );

		await row.getByRole( 'button', { name: 'Mark as paid' } ).click();
		await expect( staff.locator( '.notice-success' ) ).toContainText( 'Marked as paid' );
		await expect( staff.locator( '.dgb-status' ).first() ).toHaveText( 'Confirmed' );
		await staff.context().close();

		await page.reload();
		await expect( page.locator( '[data-dgb-confirmation]' ) ).toContainText( 'Booking confirmed' );
	} );

	test( 'staff refund part of a card payment', async ( { page, browser } ) => {
		await book( page, /^Card, Apple Pay or Google Pay/ );
		await payByCard( page, '4242424242424242' );
		const confirmation = page.locator( '[data-dgb-confirmation]' );
		await expect( confirmation ).toContainText( 'Booking confirmed' );
		const ref = await reference( confirmation );

		const staff = await admin( browser );
		await staff.goto( '/wp-admin/admin.php?page=dgb-payments&booking=' + ref );
		await expect( staff.getByRole( 'heading', { name: 'Booking ' + ref } ) ).toBeVisible();
		staff.on( 'dialog', ( dialog ) => dialog.accept() );
		await staff.getByLabel( 'Amount to refund' ).fill( '100' );
		await staff.getByLabel( 'Reason' ).fill( 'Shorter ride' );
		await staff.getByRole( 'button', { name: 'Refund', exact: true } ).click();
		await expect( staff.locator( '.notice-success' ) ).toContainText( 'Refund recorded' );
		await expect( staff.locator( '.dgb-panel-card', { hasText: 'Paid (after refunds)' } ) ).toContainText( 'Partly refunded' );
		await expect( staff.locator( '.dgb-panel-card', { hasText: 'Paid (after refunds)' } ) ).toContainText( 'AED 799.00' );
		await staff.context().close();
	} );
} );

test.describe( 'without JavaScript', () => {
	test.skip( ( { isMobile } ) => isMobile, 'desktop' );

	test( 'the screenshot upload still works', async ( { page, browser } ) => {
		const confirmation = await book( page, /^Bank transfer/ );
		await expect( confirmation ).toContainText( 'waiting for payment' );
		const url = page.url();

		const context = await browser.newContext( { javaScriptEnabled: false } );
		const plain = await context.newPage();
		await plain.goto( url );
		await plain.getByLabel( 'Upload the transfer screenshot' ).setInputFiles( { name: 'receipt.png', mimeType: 'image/png', buffer: PNG } );
		await plain.getByRole( 'button', { name: 'Upload', exact: true } ).click();
		await expect( plain.locator( '[data-dgb-proof-message]' ) ).toContainText( 'We received your screenshot' );
		await expect( plain.locator( '[data-dgb-proof]' ) ).toContainText( 'receipt.png' );
		await context.close();
	} );
} );

test.describe( 'phone', () => {
	test.skip( ( { isMobile } ) => ! isMobile, 'phone layout' );
	test.beforeEach( async ( { page } ) => fakeSdks( page ) );

	test( 'card payment from the booking sheet', async ( { page } ) => {
		await page.goto( path( tours.buggy.url ) );
		await page.locator( '[data-dgb-sticky] [data-dgb-open]' ).click();
		const w = page.locator( '[data-dgb-widget]' );
		const next = w.locator( '[data-dgb-next]' );
		await w.getByRole( 'radio', { name: /^1 hour/ } ).check();
		await pickTomorrow( w );
		await w.getByRole( 'radio', { name: /^Evening/ } ).check();
		await next.click();
		await w.getByRole( 'button', { name: 'More: Adults' } ).click();
		await next.click();
		await next.click();
		await next.click();
		await fillDetails( w );
		await next.click();
		await w.getByRole( 'radio', { name: /^Card, Apple Pay or Google Pay/ } ).check();
		await w.locator( '[data-dgb-submit]' ).click();
		await payByCard( page, '4242424242424242' );
		await expect( page.locator( '[data-dgb-confirmation]' ) ).toContainText( 'Booking confirmed' );
	} );
} );
