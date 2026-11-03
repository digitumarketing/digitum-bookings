import { test, expect, Page } from '@playwright/test';
import { e2e, tours, path, axe, pickTomorrow, chooseRide, fillDetails, goTo } from './helpers';

/*
 * Booking widget 0.8.2: three steps, step-by-step validation, calendar states, no theme CSS inside the widget,
 * the one-column desktop layout, and the QA run-throughs at 375 px and 1440 px.
 */

const PINK = 'rgb(204, 51, 102)';
const cookie = ( page: Page, name: string ) => page.context().addCookies( [ { name, value: '1', url: `http://127.0.0.1:${ e2e.port }` } ] );

test.describe( 'widget', () => {
	test.skip( ( { isMobile } ) => isMobile, 'viewports are set per test' );

	test( 'theme and kit button styles do not reach the widget', async ( { page } ) => {
		await cookie( page, 'dgb_test_theme_css' );
		for ( const width of [ 1440, 375 ] ) {
			await page.setViewportSize( { width, height: 860 } );
			await page.goto( path( tours.buggy.url ) );
			await expect( page.locator( 'body' ) ).toHaveClass( /elementor-kit-6/ );
			const scope = page.locator( '[data-dgb-widget], .dgb-portal' );
			if ( width < 782 ) {
				await page.locator( '[data-dgb-sticky] [data-dgb-open]' ).click();
			}
			await expect( scope.locator( '.dgb-day' ).first() ).toBeVisible();
			await scope.getByRole( 'radio', { name: /^1 hour/ } ).check();
			await pickTomorrow( scope );

			const look = ( selector: string ) => scope.locator( selector ).evaluateAll( ( nodes ) => nodes.map( ( n ) => {
				const c = getComputedStyle( n );
				return { bg: c.backgroundColor, border: c.borderTopColor, color: c.color, shadow: c.boxShadow, transform: c.textTransform, padding: c.paddingTop, radius: c.borderTopLeftRadius };
			} ) );
			const days = await look( '.dgb-day:not(:disabled):not(.is-selected)' );
			expect( days.length ).toBeGreaterThan( 3 );
			for ( const d of days ) {
				expect( d.bg ).toBe( 'rgba(0, 0, 0, 0)' );
				expect( d.border ).not.toBe( PINK );
				expect( d.color ).not.toBe( PINK );
				expect( d.shadow ).toBe( 'none' );
				expect( d.padding ).toBe( '0px' );
			}
			const [ selected ] = await look( '.dgb-day.is-selected' );
			expect( selected.bg ).toBe( 'rgb(180, 83, 9)' );
			expect( selected.color ).toBe( 'rgb(255, 255, 255)' );

			for ( const sel of [ '[data-dgb-prev]', '[data-dgb-next-month]', '[data-dgb-inc="adults"]', '[data-dgb-dec="adults"]', '[data-dgb-next]' ] ) {
				const [ b ] = await look( sel );
				expect( b.border, sel ).not.toBe( PINK );
				expect( b.shadow, sel ).toBe( 'none' );
				expect( b.transform, sel ).toBe( 'none' );
				expect( b.bg, sel ).not.toBe( 'rgb(194, 65, 12)' );
			}
			// Hover and focus keep the plugin's look too.
			const inc = scope.locator( '[data-dgb-inc="adults"]' );
			await inc.hover();
			const [ hovered ] = await look( '[data-dgb-inc="adults"]' );
			expect( hovered.bg ).not.toBe( PINK );
			expect( hovered.bg ).not.toBe( 'rgb(124, 45, 18)' );
			expect( hovered.border ).not.toBe( PINK );
			const day = scope.locator( '.dgb-day:not(:disabled):not(.is-selected)' ).first();
			await day.hover();
			const hoverBg = await day.evaluate( ( n ) => getComputedStyle( n ).backgroundColor );
			expect( [ PINK, 'rgb(124, 45, 18)', 'rgb(194, 65, 12)' ] ).not.toContain( hoverBg );

			if ( width < 782 ) {
				const [ closeBtn ] = await look( '[data-dgb-close]' );
				expect( closeBtn.bg ).toBe( 'rgba(0, 0, 0, 0)' );
				expect( closeBtn.border ).not.toBe( PINK );
				expect( closeBtn.padding ).toBe( '0px' );
				await page.keyboard.press( 'Escape' );
			}
			for ( const sel of [ 'input[name="variant_id"]', 'input[name="phone_code"]' ] ) {
				const [ input ] = await look( sel );
				expect( input.border, sel ).not.toBe( PINK );
				expect( input.bg, sel ).not.toBe( 'rgb(253, 230, 138)' );
			}
			const name = scope.locator( 'input[name="name"]' );
			expect( await name.evaluate( ( n ) => getComputedStyle( n ).borderTopColor ) ).not.toBe( PINK );
		}
		await page.context().clearCookies( { name: 'dgb_test_theme_css' } );
	} );

	test( 'one-column desktop layout shows the same three sections', async ( { page } ) => {
		await cookie( page, 'dgb_test_flat' );
		await page.setViewportSize( { width: 1440, height: 900 } );
		await page.goto( path( tours.buggy.url ) );
		const w = page.locator( '[data-dgb-widget]' );
		await expect( w.locator( '.dgb-step:visible' ) ).toHaveCount( 3 );
		await expect( w.locator( '[data-dgb-next]' ) ).toBeHidden();
		await expect( w.locator( '[data-dgb-progress]' ) ).toHaveText( '' );
		await w.locator( '[data-dgb-submit]' ).click();
		// Errors of the first incomplete step only, under their fields.
		await expect( w.locator( '[data-dgb-error="variant"]' ) ).toBeVisible();
		await expect( w.locator( '[data-dgb-error="name"]' ) ).toBeHidden();
		await chooseRide( w );
		await fillDetails( w );
		await w.getByRole( 'radio', { name: /^Pay on arrival/ } ).check();
		await axe( page );
		await w.locator( '[data-dgb-submit]' ).click();
		await expect( w.locator( '[data-dgb-confirmation]' ) ).toContainText( 'Booking confirmed' );
		await page.context().clearCookies( { name: 'dgb_test_flat' } );
	} );

	for ( const width of [ 375, 1440 ] ) {
		test( `QA run-through at ${ width } px`, async ( { page } ) => {
			await page.setViewportSize( { width, height: 860 } );
			const phone = width < 782;
			const open = async () => {
				if ( phone ) {
					await page.locator( '[data-dgb-sticky] [data-dgb-open]' ).click();
				}
			};
			const w = page.locator( '[data-dgb-widget], .dgb-portal' );

			// Single-option tour with pickup included: the option is chosen, pickup is the default.
			await page.goto( path( tours.safari.url ) );
			await open();
			await expect( w.getByRole( 'radio', { name: /^Standard/ } ) ).toBeChecked();
			await pickTomorrow( w );
			await w.getByRole( 'radio', { name: /^Evening/ } ).check();
			await expect( w.locator( '[data-dgb-live]' ) ).toContainText( '99.00' );
			await goTo( w, 'details' );
			await expect( w.getByRole( 'radio', { name: /^Hotel pickup/ } ) ).toBeChecked();
			// Hotel pickup with no zone: zone and hotel are required.
			await w.locator( '[data-dgb-next]' ).click();
			await expect( w.locator( '[data-dgb-error="zone"]' ) ).toHaveText( 'Please choose your pickup area.' );
			await expect( w.locator( '[data-dgb-error="hotel"]' ) ).toBeVisible();
			await expect( w.locator( '[data-dgb-error="name"]' ) ).toBeVisible();
			await w.getByLabel( 'Pickup area' ).selectOption( { label: 'North Zone' } );
			await expect( w.locator( '[data-dgb-error="zone"]' ) ).toBeHidden();
			await w.getByLabel( 'Hotel or address' ).fill( 'Sample Hotel' );
			await expect( w.locator( '[data-dgb-error="hotel"]' ) ).toBeHidden();
			// Empty details, then filled.
			await w.getByLabel( 'Full name' ).fill( 'QA Guest' );
			await w.getByLabel( 'Phone', { exact: true } ).fill( '501234567' );
			await w.getByLabel( 'Email', { exact: true } ).fill( 'not-an-email' );
			await w.locator( '[data-dgb-next]' ).click();
			await expect( w.locator( '[data-dgb-error="email"]' ) ).toHaveText( 'Please enter a valid email address.' );
			await expect( w.getByLabel( 'Email', { exact: true } ) ).toBeFocused();
			await w.getByLabel( 'Email', { exact: true } ).fill( 'qa@example.com' );
			await w.locator( '[data-dgb-next]' ).click();
			await expect( w.locator( '[data-dgb-review]' ) ).toContainText( 'Hotel pickup, North Zone, Sample Hotel' );
			// Back and forward keep everything.
			await w.getByRole( 'button', { name: 'Back' } ).click();
			await expect( w.getByLabel( 'Hotel or address' ) ).toHaveValue( 'Sample Hotel' );
			await w.getByRole( 'button', { name: 'Back' } ).click();
			await expect( w.locator( '.dgb-day.is-selected' ) ).toHaveCount( 1 );
			await goTo( w, 'review' );
			await expect( w.getByLabel( 'Full name' ) ).toHaveValue( 'QA Guest' );
			await w.locator( '[data-dgb-submit]' ).click();
			await expect( w.locator( '[data-dgb-error="terms"]' ) ).toBeVisible();
			await expect( w.locator( '[data-dgb-breakdown] li' ) ).toHaveCount( 0 );
			await axe( page, phone ? '.dgb-portal' : '[data-dgb-widget]' );

			// Multi-option tour, meeting point by default, pickup as a paid add-on.
			await page.goto( path( tours.buggy.url ) );
			await open();
			await chooseRide( w );
			await goTo( w, 'details' );
			await expect( w.getByRole( 'radio', { name: /^Meet us at/ } ) ).toBeChecked();
			await expect( w.locator( '[data-dgb-meet]' ) ).toBeVisible();
			await fillDetails( w );
			await expect( w.locator( '[data-dgb-review]' ) ).toContainText( 'Meet us at Sample Meeting Point' );
			await w.getByRole( 'radio', { name: /^Pay on arrival/ } ).check();
			await expect( w.locator( '[data-dgb-method-note="arrival"]' ) ).toHaveText( 'Cash or card at the meeting point.' );
			await expect( w.locator( '[data-dgb-breakdown]' ) ).toContainText( 'Pay on the day: AED 899.00' );
			if ( phone ) {
				// Close and reopen keep the step and the answers.
				await page.keyboard.press( 'Escape' );
				await open();
				await expect( w.locator( '[data-step="review"]' ) ).toHaveClass( /is-current/ );
				await expect( w.getByRole( 'checkbox', { name: /I accept the booking terms/ } ) ).toBeChecked();
			}
			await w.locator( '[data-dgb-submit]' ).click();
			await expect( page.locator( '[data-dgb-confirmation]' ) ).toContainText( 'Booking confirmed' );
		} );
	}
} );
