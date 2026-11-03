import { test, expect } from '@playwright/test';
import { e2e, tours, path, events, axe, pickTomorrow, chooseRide, fillDetails, goTo } from './helpers';

test.describe( 'desktop', () => {
	test.skip( ( { isMobile } ) => isMobile, 'desktop layout' );

	test( 'per-vehicle booking with hotel pickup, paid on arrival', async ( { page } ) => {
		await page.goto( path( tours.buggy.url ) );
		const w = page.locator( '[data-dgb-widget]' );
		await expect( w.locator( '.dgb-from' ) ).toContainText( '699' );

		await chooseRide( w );
		await expect( w.locator( '[data-dgb-progress]' ) ).toHaveText( 'Step 1 of 3: Your trip' );
		await expect( w.locator( '[data-dgb-live]' ) ).toContainText( '899.00' );
		await goTo( w, 'details' );
		await expect( w.locator( '[data-dgb-progress]' ) ).toHaveText( 'Step 2 of 3: Pickup and details' );
		await expect( w.locator( '[data-step="details"] .dgb-legend' ) ).toBeFocused();
		await w.getByRole( 'radio', { name: /^Hotel pickup/ } ).check();
		await w.getByLabel( 'Pickup area' ).selectOption( { label: 'North Zone' } );
		await w.getByLabel( 'Hotel or address' ).fill( 'Test Hotel, Marina' );
		await expect( w.locator( '[data-dgb-window]' ) ).toContainText( 'Pickup' );
		await axe( page );

		await fillDetails( w );
		await expect( w.locator( '[data-dgb-progress]' ) ).toHaveText( 'Step 3 of 3: Review and confirm' );
		await expect( w.locator( '[data-dgb-breakdown]' ) ).toContainText( '1,199.00' );
		await expect( w.locator( '[data-dgb-review]' ) ).toContainText( 'Hotel pickup, North Zone, Test Hotel, Marina' );
		await w.getByRole( 'radio', { name: /^Pay on arrival/ } ).check();
		await expect( w.locator( '[data-dgb-method-note="arrival"]' ) ).toHaveText( 'Cash or card when we pick you up.' );
		await expect( w.locator( '[data-dgb-breakdown]' ) ).toContainText( 'Pay on the day: AED 1,199.00' );
		await expect( w.locator( '[data-dgb-breakdown]' ) ).toContainText( 'Prices include 5% VAT' );
		await expect( w.locator( '[data-dgb-breakdown]' ) ).not.toContainText( 'Pay now' );
		await expect( w.locator( '[data-dgb-submit]' ) ).toHaveText( 'Confirm booking' );
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
		await page.goto( path( tours.buggy.url ) );
		const w = page.locator( '[data-dgb-widget]' );
		await chooseRide( w );
		await goTo( w, 'details' );
		await w.getByRole( 'radio', { name: /^Hotel pickup/ } ).check();
		await w.getByLabel( 'Pickup area' ).selectOption( { label: 'North Zone' } );
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

	test( 'Far Zone pickup is refused with the meeting point instead', async ( { page } ) => {
		await page.goto( path( tours.buggy.url ) );
		const w = page.locator( '[data-dgb-widget]' );
		await chooseRide( w );
		await goTo( w, 'details' );
		await w.getByRole( 'radio', { name: /^Hotel pickup/ } ).check();
		await w.getByLabel( 'Pickup area' ).selectOption( { label: 'Far Zone' } );
		await expect( w.locator( '[data-dgb-zone-note]' ) ).toContainText( 'not available from Far Zone' );
		await expect( w.getByLabel( 'Hotel or address' ) ).toBeHidden();
		await w.locator( '[data-dgb-next]' ).click();
		await expect( w.locator( '[data-dgb-error="zone"]' ) ).toContainText( 'not available from Far Zone' );
		await expect( w.locator( '[data-step="details"]' ) ).toHaveClass( /is-current/ );
		await expect( w.locator( '[data-dgb-breakdown] .dgb-price-errors, [data-dgb-breakdown] li' ) ).toHaveCount( 0 );
	} );

	test( 'errors are shown, announced and focused', async ( { page } ) => {
		await page.goto( path( tours.buggy.url ) );
		const w = page.locator( '[data-dgb-widget]' );
		await expect( w.locator( '.dgb-day' ).first() ).toBeVisible();
		const next = w.locator( '[data-dgb-next]' );
		await next.click();
		// Only this step's fields: option, date. Nothing about pickup or details yet, and nothing in the price box.
		await expect( w.locator( '[data-dgb-error="variant"]' ) ).toHaveText( 'Please choose an option.' );
		await expect( w.locator( '[data-dgb-error="date"]' ) ).toBeVisible();
		await expect( w.locator( '[data-dgb-error="zone"], [data-dgb-error="name"], [data-dgb-error="terms"]' ) ).toHaveCount( 3 );
		await expect( w.locator( '[data-dgb-error="zone"]:not([hidden]), [data-dgb-error="name"]:not([hidden]), [data-dgb-error="terms"]:not([hidden])' ) ).toHaveCount( 0 );
		await expect( w.getByRole( 'radio', { name: /^30 min/ } ) ).toBeFocused();
		await w.getByRole( 'radio', { name: /^1 hour/ } ).check();
		await expect( w.locator( '[data-dgb-error="variant"]' ) ).toBeHidden();

		await chooseRide( w );
		await next.click();
		await next.click();
		await expect( w.getByLabel( 'Full name' ) ).toHaveAttribute( 'aria-invalid', 'true' );
		await expect( w.getByLabel( 'Full name' ) ).toBeFocused();
		await expect( w.locator( '[data-dgb-error="phone"]' ) ).toBeVisible();
		await expect( w.locator( '[data-dgb-error="terms"]' ) ).toBeHidden();
		await w.getByLabel( 'Full name' ).fill( 'A Guest' );
		await expect( w.getByLabel( 'Full name' ) ).not.toHaveAttribute( 'aria-invalid', 'true' );
		await w.getByLabel( 'Phone', { exact: true } ).fill( '50a12b' );
		await expect( w.getByLabel( 'Phone', { exact: true } ) ).toHaveValue( '5012' );
		await expect( w.getByLabel( 'Country code' ) ).toHaveValue( '1' );
	} );

	test( 'calendar works with the keyboard', async ( { page } ) => {
		await page.goto( path( tours.buggy.url ) );
		const w = page.locator( '[data-dgb-widget]' );
		const first = w.locator( '.dgb-day[tabindex="0"]' );
		await first.focus();
		const start = await first.getAttribute( 'data-date' );
		await page.keyboard.press( 'ArrowRight' );
		const next = await page.evaluate( () => document.activeElement?.getAttribute( 'data-date' ) );
		expect( next && start && next > start ).toBeTruthy();
		await page.keyboard.press( 'Enter' );
		const chosen = w.locator( `.dgb-day[data-date="${ next }"]` );
		await expect( chosen ).toHaveAttribute( 'aria-pressed', 'true' );
		await expect( chosen ).toHaveClass( /is-selected/ );
		await expect( chosen ).toHaveAttribute( 'aria-label', /\d{4}/ );
		await expect( w.locator( '[data-dgb-selected]' ) ).toHaveText( /^Selected: \w{3} \d{1,2} \w{3} \d{4}$/ );
		const colours = await w.locator( '.dgb-day:not(:disabled)' ).evaluateAll( ( days ) => days.map( ( d ) => getComputedStyle( d ).backgroundColor ) );
		expect( colours.filter( ( c ) => c !== 'rgba(0, 0, 0, 0)' ) ).toHaveLength( 1 );
		await expect( w.getByRole( 'radio', { name: /^Morning|^Evening/ } ).first() ).toBeVisible();
	} );

	test( 'price-on-request tour ends in a quote request', async ( { page } ) => {
		await page.goto( path( tours.newyear.url ) );
		const w = page.locator( '[data-dgb-widget]' );
		await expect( w.locator( '.dgb-from' ) ).toContainText( 'Price on request' );
		await expect( w.locator( 'input[name="payment_method"]' ) ).toHaveCount( 0 );
		await pickTomorrow( w );
		await w.getByRole( 'radio', { name: /^Evening/ } ).check();
		await goTo( w, 'details' );
		await w.getByLabel( 'Pickup area' ).selectOption( { label: 'North Zone' } );
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
		await page.goto( path( tours.buggy.url ) );
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
		await page.goto( path( tours.buggy.url ) );
		const root = page.locator( '[data-dgb-widget]' );
		const w = page.locator( '[data-dgb-widget], .dgb-portal' );
		await expect( root.locator( '[data-dgb-panel]' ) ).toBeHidden();
		const sticky = page.locator( '[data-dgb-sticky] [data-dgb-open]' );
		await expect( sticky ).toBeVisible();
		await page.evaluate( () => window.scrollTo( 0, 300 ) );
		const scrolled = await page.evaluate( () => window.scrollY );

		await sticky.click();
		const sheet = page.getByRole( 'dialog', { name: 'Book this tour' } );
		await expect( sheet ).toBeVisible();
		await expect( w.locator( '[data-dgb-progress]' ) ).toHaveText( 'Step 1 of 3: Your trip' );
		await expect( w.locator( '[data-step="details"]' ) ).toBeHidden();
		// On top of everything, page held in place.
		expect( await page.evaluate( () => getComputedStyle( document.querySelector( '.dgb-panel' ) as Element ).zIndex ) ).toBe( '100000' );
		expect( await page.evaluate( () => ( document.querySelector( '.dgb-panel' ) as Element ).parentElement?.parentElement === document.body ) ).toBe( true );

		// Escape closes the sheet, returns focus to the button that opened it, and the page has not moved.
		await page.keyboard.press( 'Escape' );
		await expect( sheet ).toBeHidden();
		await expect( sticky ).toBeFocused();
		expect( await page.evaluate( () => window.scrollY ) ).toBe( scrolled );
		await sticky.click();

		const next = w.locator( '[data-dgb-next]' );
		await next.click();
		await expect( w.locator( '[data-dgb-error="variant"]' ) ).toBeVisible();

		await w.getByRole( 'radio', { name: /^1 hour/ } ).check();
		await pickTomorrow( w );
		await w.getByRole( 'radio', { name: /^Evening/ } ).check();
		await w.getByRole( 'button', { name: 'More: Adults' } ).click();
		await axe( page, '.dgb-portal' );
		await next.click();
		await expect( w.locator( '[data-dgb-progress]' ) ).toHaveText( 'Step 2 of 3: Pickup and details' );
		await expect( w.locator( '[data-step="details"] .dgb-legend' ) ).toBeFocused();

		// Closing and reopening keeps what was chosen.
		await page.keyboard.press( 'Escape' );
		await sticky.click();
		await expect( w.locator( '[data-dgb-progress]' ) ).toHaveText( 'Step 2 of 3: Pickup and details' );
		await w.getByRole( 'button', { name: 'Back' } ).click();
		await expect( w.getByRole( 'radio', { name: /^1 hour/ } ) ).toBeChecked();
		await next.click();

		await fillDetails( w );
		await expect( w.locator( '[data-dgb-progress]' ) ).toHaveText( 'Step 3 of 3: Review and confirm' );
		// The footer never covers the last field: the terms checkbox can be seen and used.
		const terms = w.getByRole( 'checkbox', { name: /I accept the booking terms/ } );
		const box = await terms.boundingBox();
		const foot = await w.locator( '.dgb-foot' ).boundingBox();
		expect( box && foot && box.y + box.height <= foot.y ).toBeTruthy();
		await w.getByRole( 'radio', { name: /^Pay on arrival/ } ).check();
		await expect( w.locator( '[data-dgb-breakdown]' ) ).toContainText( '899.00' );
		await axe( page, '.dgb-portal' );
		await w.locator( '[data-dgb-submit]' ).click();
		await expect( root.locator( '[data-dgb-confirmation]' ) ).toContainText( 'Booking confirmed' );
		await expect( page.locator( '[data-dgb-sticky]' ) ).toBeHidden();
		await expect( page.locator( '.dgb-portal' ) ).toHaveCount( 0 );
		expect( await page.evaluate( () => document.documentElement.classList.contains( 'dgb-lock' ) ) ).toBe( false );
	} );
} );
