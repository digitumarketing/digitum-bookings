import { test, expect, Page, Browser } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { e2e, tours } from './helpers';

/*
 * Staff screens (M5): manifest print view, calendar drag and drop, booking page actions, phone check-in.
 */

const { date, refs } = e2e.demo.manifest;

async function login( browser: Browser, user = 'admin', options = {} ): Promise< Page > {
	const context = await browser.newContext( options );
	const page = await context.newPage();
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( user );
	await page.locator( '#user_pass' ).fill( 'password' );
	await page.locator( '#wp-submit' ).click();
	await page.waitForURL( /wp-admin|confirm_admin_email/, { timeout: 30_000 } );
	if ( page.url().includes( 'confirm_admin_email' ) ) {
		await page.getByRole( 'button', { name: /The email is correct/ } ).click();
	}
	return page;
}

async function axe( page: Page, scope: string ) {
	const result = await new AxeBuilder( { page } ).include( scope ).withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ] ).analyze();
	expect( result.violations.map( ( v ) => `${ v.id }: ${ v.nodes.map( ( n ) => n.target.join( ' ' ) ).join( ', ' ) }` ) ).toEqual( [] );
}

test.describe( 'staff', () => {
	test.skip( ( { isMobile } ) => isMobile, 'desktop screens' );

	test( 'manifest of a session with 20 bookings prints', async ( { browser } ) => {
		const page = await login( browser );
		await page.goto( `/wp-admin/admin.php?page=dgb-manifest&date=${ date }&product=${ tours.rzr.id }` );
		await expect( page.locator( 'table.dgb-manifest tbody tr' ) ).toHaveCount( 20 );
		await axe( page, '.dgb-wrap' );

		const [ print ] = await Promise.all( [ page.waitForEvent( 'popup' ), page.getByRole( 'link', { name: 'Print view' } ).click() ] );
		await print.waitForLoadState();
		await expect( print.locator( 'tbody tr' ) ).toHaveCount( 20 );
		await expect( print.locator( 'h2' ) ).toContainText( '40 guests' );
		await print.emulateMedia( { media: 'print' } );
		await expect( print.getByRole( 'button', { name: 'Print' } ) ).toBeHidden();
		const pdf = await print.pdf( { format: 'A4', landscape: true, printBackground: true } );
		const pages = ( pdf.toString( 'latin1' ).match( /\/Type\s*\/Page[^s]/g ) || [] ).length;
		expect( pdf.subarray( 0, 5 ).toString() ).toBe( '%PDF-' );
		expect( pages ).toBeGreaterThanOrEqual( 1 );
		await test.info().attach( 'manifest.pdf', { body: pdf, contentType: 'application/pdf' } );
		await page.context().close();
	} );

	test( 'calendar: drag a booking to the next day', async ( { browser } ) => {
		const page = await login( browser );
		await page.goto( `/wp-admin/admin.php?page=dgb-calendar&view=week&date=${ date }&product=${ tours.rzr.id }` );
		const chip = page.locator( '[data-dgb-booking]', { hasText: refs[ 19 ] } );
		await expect( chip ).toBeVisible();
		const next = new Date( date + 'T12:00:00Z' );
		next.setUTCDate( next.getUTCDate() + 1 );
		const nextDate = next.toISOString().slice( 0, 10 );
		page.on( 'dialog', ( dialog ) => dialog.accept() );
		await chip.dragTo( page.locator( `.dgb-cal-day[data-date="${ nextDate }"]` ) );
		await page.waitForLoadState();
		await expect( page.locator( `.dgb-cal-day[data-date="${ nextDate }"] [data-dgb-booking]`, { hasText: refs[ 19 ] } ) ).toBeVisible();
		await expect( page.locator( `.dgb-cal-day[data-date="${ date }"] [data-dgb-booking]`, { hasText: refs[ 19 ] } ) ).toHaveCount( 0 );
		await page.context().close();
	} );

	test( 'calendar: close a session, then open it again', async ( { browser } ) => {
		const page = await login( browser );
		await page.goto( `/wp-admin/admin.php?page=dgb-calendar&view=day&date=${ date }&product=${ tours.rzr.id }` );
		const morning = page.locator( '.dgb-cal-session', { hasText: 'Morning' } );
		await morning.getByRole( 'button', { name: 'Close' } ).click();
		await expect( page.locator( '.dgb-cal-session', { hasText: 'Morning' } ).locator( '.dgb-status' ) ).toHaveText( 'Closed' );
		const sessions = await page.request.get( `/?rest_route=/dgb/v1/availability/sessions&product=${ tours.rzr.id }&date=${ date }` ).then( ( r ) => r.json() );
		expect( sessions.sessions[ 0 ].status ).toBe( 'closed' );
		await page.locator( '.dgb-cal-session', { hasText: 'Morning' } ).getByRole( 'button', { name: 'Open' } ).click();
		await expect( page.locator( '.dgb-cal-session', { hasText: 'Morning' } ).locator( '.dgb-status' ) ).toHaveCount( 0 );
		await page.context().close();
	} );

	test( 'booking page: find, add a note, move, email again', async ( { browser } ) => {
		const page = await login( browser );
		await page.goto( '/wp-admin/admin.php?page=dgb-bookings' );
		await page.getByLabel( 'Search bookings' ).fill( refs[ 4 ] );
		await page.getByRole( 'button', { name: 'Search bookings' } ).click();
		await page.getByRole( 'link', { name: refs[ 4 ] } ).click();
		await expect( page.getByRole( 'heading', { name: `Booking ${ refs[ 4 ] }` } ) ).toBeVisible();

		await page.getByLabel( 'Internal note' ).fill( 'Guest asked for a child seat.' );
		await page.getByRole( 'button', { name: 'Add note' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Note added' );
		await expect( page.locator( '.dgb-notes' ) ).toContainText( 'child seat' );

		await page.getByText( 'Change date or session' ).click();
		await page.getByLabel( 'Session', { exact: true } ).selectOption( { index: 0 } );
		await page.getByRole( 'button', { name: 'Move and email the guest' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Booking moved' );
		await expect( page.locator( '.dgb-panel-card', { hasText: 'Trip' } ) ).toContainText( 'Morning' );
		await expect( page.locator( '.dgb-email-log' ) ).toContainText( 'Booking changed' );

		await page.getByRole( 'button', { name: 'Email the confirmation again' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Email sent' );
		await axe( page, '.dgb-pay-grid' );
		await page.context().close();
	} );

	test( 'emails screen: test email', async ( { browser } ) => {
		const page = await login( browser );
		await page.goto( '/wp-admin/admin.php?page=dgb-emails' );
		await expect( page.locator( 'details.dgb-email-event' ) ).toHaveCount( 12 );
		await page.getByRole( 'button', { name: 'Send test email' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Test email sent' );
		await page.context().close();
	} );
} );

test.describe( 'guide on a phone', () => {
	test.skip( ( { isMobile } ) => ! isMobile, 'phone' );

	test( 'check guests in without reloading', async ( { browser, playwright } ) => {
		const page = await login( browser, 'guide1', { ...playwright.devices[ 'Pixel 7' ] } );
		await page.goto( `/wp-admin/admin.php?page=dgb-checkin&date=${ date }` );
		const card = page.locator( '.dgb-checkin-card', { hasText: refs[ 0 ] } );
		await expect( card ).toBeVisible();
		expect( await page.locator( '.dgb-checkin-card' ).count() ).toBeGreaterThanOrEqual( 19 );
		await card.getByRole( 'button', { name: `Arrived ${ refs[ 0 ] }` } ).click();
		await expect( card.locator( '[data-dgb-state]' ) ).toHaveText( 'Checked in' );
		await expect( page.locator( '[data-dgb-checkin-status]' ) ).toContainText( refs[ 0 ] );
		await expect( card.getByRole( 'button', { name: `Undo ${ refs[ 0 ] }` } ) ).toBeVisible();
		await axe( page, '.dgb-checkin' );

		await page.reload();
		await expect( page.locator( '.dgb-checkin-card', { hasText: refs[ 0 ] } ).locator( '[data-dgb-state]' ) ).toHaveText( 'Checked in' );
		await page.context().close();
	} );
} );
