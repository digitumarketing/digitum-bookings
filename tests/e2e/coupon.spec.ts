import { test, expect } from '@playwright/test';
import { tours, path, axe, chooseRide, fillDetails } from './helpers';

/*
 * Coupons (M7): the guest applies a code in the payment step; a wrong code is explained and ignored.
 */

test( 'coupon code in the payment step', async ( { page, isMobile } ) => {
	test.skip( isMobile, 'same form on phones' );
	await page.goto( path( tours.rzr.url ) );
	const w = page.locator( '[data-dgb-widget]' );
	await chooseRide( w );
	await fillDetails( w );
	await w.getByRole( 'radio', { name: /^Pay on arrival/ } ).check();
	await expect( w.locator( '[data-dgb-breakdown]' ) ).toContainText( '899.00' );

	const code = w.getByLabel( 'Coupon code (optional)' );
	await code.fill( 'nope' );
	await code.press( 'Enter' );
	await expect( w.locator( '[data-dgb-coupon-status]' ) ).toHaveText( 'This coupon code cannot be used for this booking.' );
	await expect( w.locator( '[data-dgb-breakdown]' ) ).not.toContainText( 'NOPE' );

	await code.fill( 'welcome10' );
	await w.getByRole( 'button', { name: 'Apply' } ).click();
	await expect( w.locator( '[data-dgb-coupon-status]' ) ).toHaveText( 'Coupon WELCOME10 applied.' );
	await expect( w.locator( '[data-dgb-breakdown]' ) ).toContainText( 'WELCOME10' );
	await expect( w.locator( '[data-dgb-breakdown] .is-total' ) ).toContainText( '809.10' );
	await axe( page );

	await w.locator( '[data-dgb-submit]' ).click();
	const confirmation = w.locator( '[data-dgb-confirmation]' );
	await expect( confirmation ).toContainText( 'Booking confirmed' );
	await expect( confirmation ).toContainText( '809.10' );
} );
