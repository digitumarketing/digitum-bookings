import { test, expect, Page, Locator } from '@playwright/test';
import { e2e, tours, tomorrow, path } from './helpers';

/*
 * 0.9.8: the booking card's total is right on load (with and without JavaScript), follows every change at once
 * (an estimate, then the server's price), survives a failed price request, and equals the total of the booking
 * form. Pickup labels, pickup-only tours and dated offers on the card. At 390 and 1440 px.
 */

const site = `http://127.0.0.1:${ e2e.port }`;
const cardCookie = ( page: Page ) => page.context().addCookies( [ { name: 'dgb_test_card', value: '1', url: site } ] );
const aed = ( n: number ) => 'AED ' + n.toLocaleString( 'en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 } );
const dialog = ( page: Page ) => page.locator( '.dgb-portal.is-modal-open .dgb-panel.is-open' );

async function pickCardDate( card: Locator, date: string ) {
	await card.locator( '[data-dgb-card-date]' ).click();
	const pop = card.locator( '[data-dgb-card-pop]' );
	await expect( pop.locator( '.dgb-day' ).first() ).toBeVisible();
	const day = pop.locator( `.dgb-day[data-date="${ date }"]` );
	if ( ! ( await day.count() ) ) {
		await pop.locator( '[data-dgb-card-next]' ).click();
		await expect( day ).toBeVisible();
	}
	await day.click();
	await expect( pop ).toBeHidden();
}

/* The card's total settles (no request pending) on the expected amount. */
async function settled( card: Locator, amount: number ) {
	await expect( card.locator( '[data-dgb-card-total]' ) ).toHaveText( aed( amount ) );
	await expect( card.locator( '[data-dgb-card-total-row]' ) ).toHaveAttribute( 'aria-busy', 'false' );
}

/* Open the booking form from the card and compare its total with the card's. */
async function sameAsForm( page: Page, card: Locator ) {
	const shown = await card.locator( '[data-dgb-card-total]' ).textContent();
	await card.locator( '[data-dgb-card-book]' ).click();
	const total = dialog( page ).locator( '[data-dgb-breakdown] .dgb-line.is-total dd' );
	await expect( total ).toHaveText( shown || '' );
	await page.keyboard.press( 'Escape' );
	await expect( card.locator( '[data-dgb-card-total]' ) ).toHaveText( shown || '' );
}

test.describe( 'booking card total', () => {
	test.skip( ( { isMobile } ) => isMobile, 'viewports are set per test' );

	test( 'the total of the default choices is in the page, also without JavaScript', async ( { browser } ) => {
		const context = await browser.newContext( { javaScriptEnabled: false } );
		await context.addCookies( [ { name: 'dgb_test_card', value: '1', url: site } ] );
		const page = await context.newPage();
		await page.goto( site + path( tours.buggy.url ) );
		await expect( page.locator( '[data-dgb-card-total]' ) ).toHaveText( aed( 699 ) );
		await page.goto( site + path( tours.safari.url ) );
		await expect( page.locator( '[data-dgb-card-total]' ) ).toHaveText( aed( 198 ) );
		await page.goto( site + path( tours.group.url ) );
		await expect( page.locator( '[data-dgb-card-total]' ) ).toHaveText( aed( 500 ) );
		await context.close();
	} );

	for ( const width of [ 390, 1440 ] ) {
		test( `per vehicle: steppers, typing, option and pickup at ${ width } px`, async ( { page } ) => {
			await cardCookie( page );
			await page.setViewportSize( { width, height: 900 } );
			await page.goto( path( tours.buggy.url ) );
			const card = page.locator( '[data-dgb-card]' );
			const more = card.getByRole( 'button', { name: /^More: Buggies/ } );
			const less = card.getByRole( 'button', { name: /^Fewer: Buggies/ } );
			await settled( card, 699 );

			for ( const n of [ 2, 3, 4 ] ) {
				await more.click();
				await settled( card, 699 * n );
			}
			for ( const n of [ 3, 2, 1 ] ) {
				await less.click();
				await settled( card, 699 * n );
			}

			// Three quick clicks: never one step behind.
			await more.click();
			await more.click();
			await more.click();
			await expect( card.locator( 'input[name="units"]' ) ).toHaveValue( '4' );
			await settled( card, 699 * 4 );

			// A typed number counts while typing, not only when the field loses focus.
			await card.locator( 'input[name="units"]' ).fill( '3' );
			await settled( card, 699 * 3 );

			await card.locator( '.dgb-bc-pill' ).nth( 1 ).click();
			await settled( card, 899 * 3 );
			await card.locator( '[data-dgb-card-pickup]' ).selectOption( { index: 1 } );
			await settled( card, 899 * 3 + 300 );

			await pickCardDate( card, tomorrow );
			await settled( card, 899 * 3 + 300 );
			await sameAsForm( page, card );
		} );

		test( `per person and children; the form's total comes back to the card at ${ width } px`, async ( { page } ) => {
			await cardCookie( page );
			await page.setViewportSize( { width, height: 900 } );
			await page.goto( path( tours.safari.url ) );
			const card = page.locator( '[data-dgb-card]' );
			await settled( card, 198 );
			await card.getByRole( 'button', { name: 'More: Persons' } ).click();
			await settled( card, 297 );
			await card.getByRole( 'button', { name: 'More: Children' } ).click();
			await settled( card, 297 + 79 );
			await pickCardDate( card, tomorrow );
			// One session: the form opens on step 2 with the same total.
			await card.locator( '[data-dgb-card-book]' ).click();
			const d = dialog( page );
			await expect( d.locator( '[data-dgb-breakdown] .dgb-line.is-total dd' ) ).toHaveText( aed( 376 ) );
			// A change in the form shows on the card too.
			await d.locator( '[data-dgb-back]' ).click();
			await d.getByRole( 'button', { name: 'More: Persons' } ).click();
			await expect( d.locator( '[data-dgb-breakdown] .dgb-line.is-total dd' ) ).toHaveText( aed( 475 ) );
			await page.keyboard.press( 'Escape' );
			await settled( card, 475 );
			await expect( card.locator( 'input[name="adults"]' ) ).toHaveValue( '4' );
		} );

		test( `a failed price request keeps price × quantity at ${ width } px`, async ( { page } ) => {
			await cardCookie( page );
			await page.setViewportSize( { width, height: 900 } );
			await page.goto( path( tours.buggy.url ) );
			const card = page.locator( '[data-dgb-card]' );
			await settled( card, 699 );
			await page.route( /dgb(\/|%2F)v1(\/|%2F)quote/, ( route ) => route.abort() );
			await card.getByRole( 'button', { name: /^More: Buggies/ } ).click();
			await expect( card.locator( '[data-dgb-card-total]' ) ).toHaveText( aed( 1398 ) );
			await card.locator( '[data-dgb-card-pickup]' ).selectOption( { index: 1 } );
			await expect( card.locator( '[data-dgb-card-total]' ) ).toHaveText( aed( 1698 ) );
			// After one retry it gives up and keeps the estimate.
			await expect( card.locator( '[data-dgb-card-total-row]' ) ).toHaveAttribute( 'aria-busy', 'false', { timeout: 5000 } );
			await expect( card.locator( '[data-dgb-card-total]' ) ).toHaveText( aed( 1698 ) );
		} );

		test( `per group: a dated offer shows on the card without a date at ${ width } px`, async ( { page } ) => {
			await cardCookie( page );
			await page.setViewportSize( { width, height: 900 } );
			await page.goto( path( tours.group.url ) );
			const card = page.locator( '[data-dgb-card]' );
			await settled( card, 500 );
			await expect( card.locator( '[data-dgb-card-hints] .dgb-offer-hint' ) ).toHaveText( 'Add 1 more car to get: 10% off 2 or more cars' );
			await card.getByRole( 'button', { name: /^More: Cars/ } ).click();
			await settled( card, 900 );
			await expect( card.locator( '.dgb-bc-offer' ) ).toContainText( '10% off 2 or more cars' );
			await expect( card.locator( '[data-dgb-card-hints]' ) ).toBeHidden();
			// A date outside the offer's window: no offer.
			await pickCardDate( card, e2e.demo.outside );
			await settled( card, 1000 );
			await expect( card.locator( '.dgb-bc-offer' ) ).toHaveCount( 0 );
		} );

		test( `pickup labels and a pickup-only tour at ${ width } px`, async ( { page } ) => {
			await cardCookie( page );
			await page.setViewportSize( { width, height: 900 } );
			await page.goto( path( tours.noprice.url ) );
			const options = page.locator( '[data-dgb-card] [data-dgb-card-pickup] option' );
			await expect( options ).toHaveText( [ 'Meet at Sample Meeting Point, free', 'Hotel pickup, North Zone, price on request', 'Hotel pickup, East Zone, price on request' ] );

			await page.goto( path( tours.pickonly.url ) );
			const card = page.locator( '[data-dgb-card]' );
			await expect( card.locator( '[data-dgb-card-pickup] option' ) ).toHaveText( [ 'Hotel pickup, North Zone, included', 'Hotel pickup, East Zone, included' ] );
			await settled( card, 160 );
			await pickCardDate( card, tomorrow );
			await card.locator( '[data-dgb-card-book]' ).click();
			const d = dialog( page );
			await expect( d.locator( '[data-dgb-progress]' ) ).toHaveText( /^Step 2 of 3/ );
			await expect( d.getByText( /^Meet us at/ ) ).toHaveCount( 0 );
			await expect( d.locator( 'select[name="zone_id"]' ) ).toHaveValue( String( e2e.demo.zones[ 'North Zone' ] ) );
			// Without a pickup area the form does not go on.
			await d.locator( 'select[name="zone_id"]' ).selectOption( '' );
			await d.getByLabel( 'Full name' ).fill( 'Pickup Guest' );
			await d.locator( '[data-dgb-next]' ).click();
			await expect( d.locator( '[data-dgb-error="zone"]' ) ).toBeVisible();
		} );
	}
} );
