import { test, expect } from '@playwright/test';
import { e2e, tours, path, events, axe, pickTomorrow, chooseRide, fillDetails } from './helpers';

test.describe( 'desktop', () => {
	test.skip( ( { isMobile } ) => isMobile, 'desktop layout' );

	test( 'per-vehicle booking with hotel pickup, paid on arrival', async ( { page } ) => {
		await page.goto( path( tours.rzr.url ) );
		const w = page.locator( '[data-dgb-widget]' );
		await expect( w.locator( '.dgb-from' ) ).toContainText( '699' );

		await chooseRide( w );
		await w.getByRole( 'radio', { name: /^Hotel pickup/ } ).check();
		await w.getByLabel( 'Pickup area' ).selectOption( { label: 'Dubai' } );
		await w.getByLabel( 'Hotel or address' ).fill( 'Test Hotel, Marina' );
		await expect( w.locator( '[data-dgb-window]' ) ).toContainText( 'Pickup' );
		await expect( w.locator( '[data-dgb-breakdown]' ) ).toContainText( '1,199.00' );
		await axe( page );

		await fillDetails( w );
		await w.getByRole( 'radio', { name: /^Pay on arrival/ } ).check();
		await expect( w.locator( '[data-dgb-submit]' ) ).toHaveText( 'Confirm booking · pay on arrival' );
		await w.locator( '[data-dgb-submit]' ).click();

		const confirmation = w.locator( '[data-dgb-confirmation]' );
		await expect( confirmation ).toContainText( 'Booking confirmed' );
		await expect( confirmation ).toBeFocused();
		const reference = ( ( await confirmation.locator( '.dgb-ref strong' ).textContent() ) || '' ).trim();
		expect( reference ).toMatch( /^DGB-\d{6}-[A-Z2-9]{4}$/ );
		await expect( confirmation ).toContainText( 'Test Hotel, Marina' );
		await expect( page ).toHaveURL( /dgb_booking=/ );

		expect( await events( page ) ).toEqual( expect.arrayContaining( [ 'view_item', 'select_item', 'add_to_cart', 'begin_checkout', 'add_payment_info', 'purchase' ] ) );
		const purchase = await page.evaluate( () => window.dataLayer.find( ( e ) => 'purchase' === e.event ) );
		expect( purchase?.ecommerce.transaction_id ).toBe( reference );
		expect( purchase?.ecommerce.value ).toBe( 1199 );
		expect( purchase?.user_data.sha256_email_address ).toHaveLength( 64 );
		await axe( page );

		// The confirmation link works after a reload and does not count the purchase twice.
		await page.reload();
		await expect( page.locator( '[data-dgb-confirmation]' ) ).toContainText( reference );
		expect( ( await events( page ) ).filter( ( e ) => 'purchase' === e ) ).toHaveLength( 0 );

		const ics = await page.request.get( await page.locator( 'a', { hasText: 'Add to calendar' } ).getAttribute( 'href' ) as string );
		expect( ics.status() ).toBe( 200 );
		expect( ics.headers()[ 'content-type' ] ).toContain( 'text/calendar' );
		expect( await ics.text() ).toContain( 'BEGIN:VEVENT' );
	} );

	test( 'bank transfer with a 20% deposit', async ( { page } ) => {
		await page.goto( path( tours.rzr.url ) );
		const w = page.locator( '[data-dgb-widget]' );
		await chooseRide( w );
		await w.getByRole( 'radio', { name: /^Hotel pickup/ } ).check();
		await w.getByLabel( 'Pickup area' ).selectOption( { label: 'Dubai' } );
		await w.getByLabel( 'Hotel or address' ).fill( 'Test Hotel' );
		await fillDetails( w );
		await w.getByRole( 'radio', { name: /^Bank transfer/ } ).check();
		const deposit = w.getByRole( 'radio', { name: /20% deposit/ } );
		await expect( deposit ).toBeVisible();
		await expect( w.getByRole( 'radio', { name: /^Full amount/ } ) ).toBeChecked();
		await deposit.check();
		await expect( w.locator( '[data-dgb-submit]' ) ).toContainText( '239.80' );
		await expect( w.locator( '[data-dgb-breakdown]' ) ).toContainText( '959.20' );
		await w.locator( '[data-dgb-submit]' ).click();
		const confirmation = w.locator( '[data-dgb-confirmation]' );
		await expect( confirmation ).toContainText( 'waiting for payment' );
		await expect( confirmation ).toContainText( 'AE070331234567890123456' );
		await expect( confirmation ).toContainText( '239.80' );
	} );

	test( 'Abu Dhabi pickup is refused with the meeting point instead', async ( { page } ) => {
		await page.goto( path( tours.rzr.url ) );
		const w = page.locator( '[data-dgb-widget]' );
		await chooseRide( w );
		await w.getByRole( 'radio', { name: /^Hotel pickup/ } ).check();
		await w.getByLabel( 'Pickup area' ).selectOption( { label: 'Abu Dhabi' } );
		await expect( w.locator( '[data-dgb-zone-note]' ) ).toContainText( 'not available from Abu Dhabi' );
		await expect( w.getByLabel( 'Hotel or address' ) ).toBeHidden();
		await expect( w.locator( '[data-dgb-breakdown]' ) ).toContainText( 'Pickup is not available from Abu Dhabi' );
		await expect( w.locator( '[data-dgb-breakdown]' ) ).toContainText( '899.00' );
	} );

	test( 'errors are shown, announced and focused', async ( { page } ) => {
		await page.goto( path( tours.rzr.url ) );
		const w = page.locator( '[data-dgb-widget]' );
		await expect( w.locator( '.dgb-day' ).first() ).toBeVisible();
		await w.locator( '[data-dgb-submit]' ).click();
		const summary = w.locator( '[data-dgb-errors]' );
		await expect( summary ).toBeVisible();
		await expect( summary ).toHaveAttribute( 'role', 'alert' );
		await expect( summary ).toContainText( 'Please choose an option.' );
		await expect( w.locator( '[data-dgb-error="variant"]' ) ).toBeVisible();

		await chooseRide( w );
		await w.locator( '[data-dgb-submit]' ).click();
		await expect( w.getByLabel( 'Full name' ) ).toHaveAttribute( 'aria-invalid', 'true' );
		await expect( w.getByLabel( 'Full name' ) ).toBeFocused();
	} );

	test( 'calendar works with the keyboard', async ( { page } ) => {
		await page.goto( path( tours.rzr.url ) );
		const w = page.locator( '[data-dgb-widget]' );
		const first = w.locator( '.dgb-day[tabindex="0"]' );
		await first.focus();
		const start = await first.getAttribute( 'data-date' );
		await page.keyboard.press( 'ArrowRight' );
		const next = await page.evaluate( () => document.activeElement?.getAttribute( 'data-date' ) );
		expect( next && start && next > start ).toBeTruthy();
		await page.keyboard.press( 'Enter' );
		await expect( w.locator( `.dgb-day[data-date="${ next }"]` ) ).toHaveAttribute( 'aria-pressed', 'true' );
		await expect( w.getByRole( 'radio', { name: /^Morning|^Evening/ } ).first() ).toBeVisible();
	} );

	test( 'price-on-request tour ends in a quote request', async ( { page } ) => {
		await page.goto( path( tours.newyear.url ) );
		const w = page.locator( '[data-dgb-widget]' );
		await expect( w.locator( '.dgb-from' ) ).toContainText( 'Price on request' );
		await expect( w.locator( '[data-step="pay"]' ) ).toHaveCount( 0 );
		await pickTomorrow( w );
		await w.getByRole( 'radio', { name: /^Evening/ } ).check();
		await w.getByLabel( 'Pickup area' ).selectOption( { label: 'Dubai' } );
		await w.getByLabel( 'Hotel or address' ).fill( 'Test Hotel' );
		await fillDetails( w );
		await expect( w.locator( '[data-dgb-submit]' ) ).toHaveText( 'Request a quote' );
		await axe( page );
		await w.locator( '[data-dgb-submit]' ).click();
		await expect( w.locator( '[data-dgb-confirmation]' ) ).toContainText( 'Quote request received' );
		expect( await events( page ) ).toContain( 'generate_lead' );
	} );
} );

test.describe( 'phone', () => {
	test.skip( ( { isMobile } ) => ! isMobile, 'phone layout' );

	test( 'sticky bar never covers the end of the page; can replace the theme’s bar', async ( { page } ) => {
		await page.goto( path( tours.rzr.url ) );
		await expect( page.locator( 'body' ) ).toHaveClass( /dgb-has-sticky-bar/ );
		const bar = page.locator( '[data-dgb-sticky]' );
		await expect( bar ).toBeVisible();
		await page.evaluate( () => window.scrollTo( 0, document.documentElement.scrollHeight ) );
		const covered = await page.evaluate( () => {
			const barTop = ( document.querySelector( '[data-dgb-sticky]' ) as HTMLElement ).getBoundingClientRect().top;
			const items = Array.from( document.body.querySelectorAll( 'footer, .wp-site-blocks > *:last-child' ) ) as HTMLElement[];
			return items.some( ( el ) => el.getBoundingClientRect().bottom > barTop + 1 && el.offsetHeight > 0 );
		} );
		expect( covered ).toBe( false );

		await page.context().addCookies( [ { name: 'dgb_test_sticky', value: '1', url: `http://127.0.0.1:${ e2e.port }` } ] );
		await page.reload();
		await expect( page.locator( '.test-theme-bar' ) ).toBeHidden();
		await expect( bar ).toBeVisible();
		await page.context().clearCookies( { name: 'dgb_test_sticky' } );
	} );

	test( 'sticky bar opens a stepped booking sheet', async ( { page } ) => {
		await page.goto( path( tours.rzr.url ) );
		const w = page.locator( '[data-dgb-widget]' );
		await expect( w.locator( '[data-dgb-panel]' ) ).toBeHidden();
		const sticky = page.locator( '[data-dgb-sticky] [data-dgb-open]' );
		await expect( sticky ).toBeVisible();

		await sticky.click();
		const sheet = page.getByRole( 'dialog', { name: 'Book this tour' } );
		await expect( sheet ).toBeVisible();
		await expect( w.locator( '[data-dgb-progress]' ) ).toHaveText( 'Step 1 of 6' );
		await expect( w.locator( '[data-step="guests"]' ) ).toBeHidden();

		// Escape closes the sheet and returns focus to the button that opened it.
		await page.keyboard.press( 'Escape' );
		await expect( sheet ).toBeHidden();
		await expect( sticky ).toBeFocused();
		await sticky.click();

		const next = w.locator( '[data-dgb-next]' );
		await next.click();
		await expect( w.locator( '[data-dgb-error="variant"]' ) ).toBeVisible();

		await w.getByRole( 'radio', { name: /^1 hour/ } ).check();
		await pickTomorrow( w );
		await w.getByRole( 'radio', { name: /^Evening/ } ).check();
		await axe( page );
		await next.click();
		await expect( w.locator( '[data-dgb-progress]' ) ).toHaveText( 'Step 2 of 6' );
		await expect( w.locator( '[data-step="guests"] .dgb-legend' ) ).toBeFocused();
		await w.getByRole( 'button', { name: 'More: Adults' } ).click();
		await next.click();
		await expect( w.locator( '[data-dgb-progress]' ) ).toHaveText( 'Step 3 of 6' );
		await next.click();
		await next.click();
		await expect( w.locator( '[data-dgb-progress]' ) ).toHaveText( 'Step 5 of 6' );
		await fillDetails( w );
		await next.click();
		await expect( w.locator( '[data-dgb-progress]' ) ).toHaveText( 'Step 6 of 6' );
		await w.getByRole( 'radio', { name: /^Pay on arrival/ } ).check();
		await expect( w.locator( '[data-dgb-breakdown]' ) ).toContainText( '899.00' );
		await axe( page );
		await w.locator( '[data-dgb-submit]' ).click();
		await expect( w.locator( '[data-dgb-confirmation]' ) ).toContainText( 'Booking confirmed' );
		await expect( page.locator( '[data-dgb-sticky]' ) ).toBeHidden();
	} );
} );
