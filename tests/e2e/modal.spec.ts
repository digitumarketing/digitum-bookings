import { test, expect, Page, Locator } from '@playwright/test';
import { e2e, tours, tomorrow, path, axe } from './helpers';

/*
 * 0.9.0: booking card, booking modal and its triggers, tour price, fact chips, gallery and the mobile bar,
 * on a tour page built like the approved design (cookie dgb_test_card, see prepare.php), at 375, 768, 1280
 * and 1440 px.
 */

const site = `http://127.0.0.1:${ e2e.port }`;
const cookies = ( page: Page, names: string[] ) => page.context().addCookies( names.map( ( name ) => ( { name, value: '1', url: site } ) ) );
const DAYS = [ 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' ];
const MONTHS = [ 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec' ];
const shown = ( ymd: string ) => {
	const [ y, m, d ] = ymd.split( '-' ).map( Number );
	return `${ DAYS[ new Date( Date.UTC( y, m - 1, d ) ).getUTCDay() ] } ${ d } ${ MONTHS[ m - 1 ] } ${ y }`;
};

const modal = ( page: Page ) => page.locator( '.dgb-portal.is-modal-open' );
const dialog = ( page: Page ) => modal( page ).locator( '.dgb-panel.is-open' );

async function pickCardDate( card: Locator, date: string ) {
	await card.locator( '[data-dgb-card-date]' ).click();
	const pop = card.locator( '[data-dgb-card-pop]' );
	await expect( pop ).toBeVisible();
	const day = pop.locator( `.dgb-day[data-date="${ date }"]` );
	await expect( pop.locator( '.dgb-day' ).first() ).toBeVisible();
	if ( ! ( await day.count() ) ) {
		await pop.locator( '[data-dgb-card-next]' ).click();
	}
	await day.click();
	await expect( pop ).toBeHidden();
}

test.describe( 'booking card and modal', () => {
	test.skip( ( { isMobile } ) => isMobile, 'viewports are set per test' );

	for ( const width of [ 375, 768, 1280, 1440 ] ) {
		test( `per-vehicle tour: card prefills the modal, triggers, close and keep data at ${ width } px`, async ( { page } ) => {
			await cookies( page, [ 'dgb_test_card' ] );
			await page.setViewportSize( { width, height: 900 } );
			const errors: string[] = [];
			page.on( 'pageerror', ( e ) => errors.push( e.message ) );
			await page.goto( path( tours.buggy.url ) );
			const card = page.locator( '[data-dgb-card]' );

			// Hero price and chips.
			await expect( page.locator( '.dgb-price__label' ) ).toHaveText( /from/i );
			await expect( page.locator( '.dgb-price__amount' ) ).toHaveText( 'AED 699.00' );
			await expect( page.locator( '.dgb-price__basis' ) ).toHaveText( 'per buggy, 30 min · VAT included' );
			await expect( page.locator( '.qa-hero .dgb-chips .dgb-chip' ) ).toHaveText( [ '30 min to 2 h', '2 seats', 'Driver 16+', 'No licence needed', 'Hotel pickup available' ] );

			// Card: title, pills with prices and badge, quantity by vehicle, pickup choices with prices.
			await expect( card.locator( '.dgb-bc-title' ) ).toHaveText( 'Book this buggy' );
			await expect( card.locator( '.dgb-bc-pill' ) ).toHaveCount( 3 );
			await expect( card.locator( '.dgb-bc-pill' ).nth( 1 ) ).toContainText( '1 hour' );
			await expect( card.locator( '.dgb-bc-pill' ).nth( 1 ) ).toContainText( 'AED 899.00' );
			await expect( card.locator( '.dgb-bc-pill-badge' ) ).toHaveText( 'Most picked' );
			await expect( card.getByText( 'Buggies · 2 people each' ) ).toBeVisible();
			await expect( card.locator( '[data-dgb-card-pickup] option' ) ).toHaveText( [ 'Meet at Sample Meeting Point, free', 'Hotel pickup, North Zone, AED 300.00', 'Hotel pickup, East Zone, AED 300.00' ] );
			await expect( card.locator( '[data-dgb-card-date]' ) ).toContainText( 'Choose a date' );
			await expect( card.locator( '.dgb-bc-trust li' ).nth( 0 ) ).toHaveText( 'Free cancellation up to 24 hours before the session.' );
			await expect( card.locator( '.dgb-bc-trust li' ).nth( 1 ) ).toHaveText( 'Confirmed in minutes' );
			await expect( card.locator( '.dgb-bc-trust li' ).nth( 2 ) ).toHaveText( /^Cash or card on arrival/ );

			// Choices update the total (same calculator as the form) and the WhatsApp text.
			await pickCardDate( card, tomorrow );
			await expect( card.locator( '[data-dgb-card-date]' ) ).toContainText( shown( tomorrow ) );
			await card.locator( '.dgb-bc-pill' ).nth( 1 ).click();
			await card.getByRole( 'button', { name: 'More: Buggies · 2 people each' } ).click();
			await expect( card.locator( '[data-dgb-card-total]' ) ).toHaveText( 'AED 1,798.00' );
			await expect( card.locator( '[data-dgb-card-total-label]' ) ).toHaveText( 'Total, VAT included' );
			await card.locator( '[data-dgb-card-pickup]' ).selectOption( { index: 1 } );
			await expect( card.locator( '[data-dgb-card-total]' ) ).toHaveText( 'AED 2,098.00' );
			const wa = decodeURIComponent( ( await card.locator( '[data-dgb-card-wa]' ).getAttribute( 'href' ) ) || '' );
			expect( wa ).toContain( 'https://wa.me/15550100?text=' );
			expect( wa ).toContain( 'Hello! I would like to book 2-Seater Sample Buggy.' );
			expect( wa ).toContain( `Date: ${ shown( tomorrow ) }, Option: 1 hour, Quantity: 2 buggies, Pickup: Hotel pickup, North Zone.` );
			if ( width >= 768 ) {
				await axe( page, '[data-dgb-card]' );
			}

			// Check availability: the modal opens with the card's values. Two sessions that day, so step 1 asks for one.
			const book = card.locator( '[data-dgb-card-book]' );
			await book.click();
			await expect( dialog( page ) ).toBeVisible();
			const d = dialog( page );
			await expect( d.locator( '.dgb-title' ) ).toHaveText( 'Book this buggy' );
			await expect( d.locator( '[data-dgb-progress]' ) ).toHaveText( 'Step 1 of 3: Your trip' );
			await expect( d.locator( `.dgb-day[data-date="${ tomorrow }"]` ) ).toHaveClass( /is-selected/ );
			await expect( d.getByRole( 'radio', { name: /^1 hour/ } ) ).toBeChecked();
			await expect( d.locator( 'input[name="units"]' ) ).toHaveValue( '2' );
			await expect( d.locator( 'input[name="adults"]' ) ).toHaveValue( '4' );
			await expect( d.locator( '[data-dgb-error="session"]' ) ).toBeVisible();
			if ( width >= 768 ) {
				const box = await d.boundingBox();
				expect( box!.width ).toBeLessThanOrEqual( 560 );
				expect( Math.abs( box!.x + box!.width / 2 - width / 2 ) ).toBeLessThan( 2 );
				expect( box!.height ).toBeLessThanOrEqual( 900 * 0.9 + 1 );
			} else {
				const box = await d.boundingBox();
				expect( Math.round( box!.y + box!.height ) ).toBe( 900 );
			}
			await expect( page.locator( 'html' ) ).toHaveClass( /dgb-lock/ );
			await axe( page, '.dgb-portal' );

			// Step 2 after choosing the session; pickup and zone come from the card.
			await d.getByRole( 'radio', { name: /^Evening/ } ).check();
			await d.locator( '[data-dgb-next]' ).click();
			await expect( d.locator( '[data-dgb-progress]' ) ).toHaveText( 'Step 2 of 3: Pickup and details' );
			await expect( d.getByRole( 'radio', { name: /^Hotel pickup/ } ) ).toBeChecked();
			await expect( d.getByLabel( 'Pickup area' ) ).toHaveValue( await d.getByLabel( 'Pickup area' ).locator( 'option', { hasText: 'North Zone' } ).getAttribute( 'value' ) as string );
			// The footer never covers the fields: the last field ends above the footer once scrolled to.
			const last = d.locator( '[data-step="details"] input:visible' ).last();
			await last.scrollIntoViewIfNeeded();
			const fieldBox = await last.boundingBox();
			const footBox = await d.locator( '.dgb-foot' ).boundingBox();
			expect( fieldBox!.y + fieldBox!.height ).toBeLessThanOrEqual( footBox!.y + 1 );

			// Esc closes; focus goes back to the button; the page scroll is restored.
			await d.getByLabel( 'Full name' ).fill( 'Card Guest' );
			await page.keyboard.press( 'Escape' );
			await expect( modal( page ) ).toHaveCount( 0 );
			await expect( book ).toBeFocused();
			await expect( page.locator( 'html' ) ).not.toHaveClass( /dgb-lock/ );

			// Every trigger opens the same modal, with the data kept.
			const triggers = [ '.qa-header-book', '.qa-hero-book', '.qa-class-open', '.qa-data-open' ];
			for ( const trigger of triggers ) {
				await page.locator( trigger ).click();
				await expect( dialog( page ) ).toBeVisible();
				await expect( dialog( page ).getByLabel( 'Full name' ) ).toHaveValue( 'Card Guest' );
				if ( '.qa-header-book' === trigger ) {
					await dialog( page ).locator( '[data-dgb-close]' ).click();
				} else if ( '.qa-hero-book' === trigger ) {
					// Backdrop click (outside the dialog).
					await page.mouse.click( 5, 5 );
				} else {
					await page.keyboard.press( 'Escape' );
				}
				await expect( modal( page ) ).toHaveCount( 0 );
			}
			expect( await page.locator( '[data-dgb-widget]' ).count() ).toBe( 1 );
			expect( errors ).toEqual( [] );

			// Mobile bar: price line, WhatsApp and Book now; hidden while the modal is open.
			if ( width < 782 ) {
				const bar = page.locator( '[data-dgb-sticky]' );
				await expect( bar ).toBeVisible();
				await expect( bar.locator( '.dgb-sticky-basis' ) ).toHaveText( '2 seater, per buggy from' );
				await expect( bar.locator( 'strong' ) ).toHaveText( 'AED 699.00' );
				await expect( bar.locator( '.dgb-sticky-wa' ) ).toHaveAttribute( 'href', /^https:\/\/wa\.me\/15550100/ );
				await bar.getByRole( 'button', { name: 'Book now' } ).click();
				await expect( dialog( page ) ).toBeVisible();
				await expect( bar ).toBeHidden();
				await page.keyboard.press( 'Escape' );
				await expect( bar ).toBeVisible();
			}
		} );
	}

	test( '?book=1 opens the modal; a booking from the modal is confirmed inside it', async ( { page } ) => {
		await cookies( page, [ 'dgb_test_card' ] );
		await page.setViewportSize( { width: 1280, height: 900 } );
		await page.goto( path( tours.buggy.url ) + '?book=1' );
		const d = dialog( page );
		await expect( d ).toBeVisible();
		await expect( d.locator( '[data-dgb-progress]' ) ).toHaveText( 'Step 1 of 3: Your trip' );
		await d.getByRole( 'radio', { name: /^30 min/ } ).check();
		const day = d.locator( `.dgb-day[data-date="${ tomorrow }"]` );
		if ( ! ( await day.count() ) ) {
			await d.locator( '[data-dgb-next-month]' ).click();
		}
		await day.click();
		await d.getByRole( 'radio', { name: /^Evening/ } ).check();
		await d.locator( '[data-dgb-next]' ).click();
		await d.getByLabel( 'Full name' ).fill( 'Modal Guest' );
		await d.getByLabel( 'Email', { exact: true } ).fill( 'modal@example.com' );
		await d.getByLabel( 'Phone', { exact: true } ).fill( '501234567' );
		await d.locator( '[data-dgb-next]' ).click();
		await expect( d.locator( '[data-dgb-progress]' ) ).toHaveText( 'Step 3 of 3: Review and confirm' );
		await expect( d.locator( '[data-dgb-review]' ) ).toContainText( shown( tomorrow ) );
		await d.getByRole( 'radio', { name: /^Pay on arrival/ } ).check();
		await d.getByRole( 'checkbox', { name: /I accept the booking terms/ } ).check();
		await d.locator( '[data-dgb-submit]' ).click();
		const confirmation = d.locator( '[data-dgb-confirmation]' );
		await expect( confirmation ).toContainText( 'Booking confirmed' );
		await expect( confirmation ).toContainText( shown( tomorrow ) );
		await expect( page ).toHaveURL( /dgb_booking=DGB-/ );
		// After a reload the confirmation takes the card's place.
		await page.reload();
		await expect( page.locator( 'aside [data-dgb-confirmation]' ) ).toContainText( 'Booking confirmed' );
		await expect( page.locator( '[data-dgb-card]' ) ).toHaveCount( 0 );
	} );

	test( 'per-person tour with pickup included: one option, adults and children, opens on step 2', async ( { page } ) => {
		await cookies( page, [ 'dgb_test_card' ] );
		for ( const width of [ 375, 1440 ] ) {
			await page.setViewportSize( { width, height: 900 } );
			await page.goto( path( tours.safari.url ) );
			const card = page.locator( '[data-dgb-card]' );
			await expect( card.locator( '.dgb-bc-title' ) ).toHaveText( 'Book this tour' );
			await expect( card.locator( '.dgb-bc-pill' ) ).toHaveCount( 0 );
			await expect( card.getByLabel( 'Adults', { exact: true } ) ).toHaveValue( '2' );
			await expect( card.getByLabel( 'Children', { exact: true } ) ).toHaveValue( '0' );
			await expect( card.locator( '[data-dgb-card-pickup]' ) ).toHaveValue( '1' );
			await expect( card.locator( '[data-dgb-card-pickup] option' ).nth( 1 ) ).toHaveText( 'Hotel pickup, North Zone, included' );
			await expect( page.locator( '.dgb-price__basis' ) ).toHaveText( 'per person, 3 h · VAT included' );
			await expect( page.locator( '.qa-hero .dgb-chips .dgb-chip' ) ).toHaveText( [ '3 h', 'Pickup included' ] );
			await card.getByRole( 'button', { name: 'More: Children' } ).click();
			await expect( card.locator( '[data-dgb-card-total]' ) ).toHaveText( 'AED 277.00' );
			await pickCardDate( card, tomorrow );
			await card.locator( '[data-dgb-card-book]' ).click();
			const d = dialog( page );
			await expect( d.locator( '[data-dgb-progress]' ) ).toHaveText( 'Step 2 of 3: Pickup and details' );
			await expect( d.getByRole( 'radio', { name: /^Hotel pickup/ } ) ).toBeChecked();
			await expect( d.locator( 'input[name="children"]' ) ).toHaveValue( '1' );
			// Gallery fallback: only the featured image, no empty boxes.
			await page.keyboard.press( 'Escape' );
			await expect( page.locator( '.dgb-gallery .dgb-gallery__item' ) ).toHaveCount( 1 );
			await expect( page.locator( '.dgb-gallery' ) ).toHaveClass( /dgb-gallery--1/ );
		}
	} );

	test( 'gallery: large image and four, swipe row on phones, lightbox', async ( { page } ) => {
		await cookies( page, [ 'dgb_test_card' ] );
		await page.setViewportSize( { width: 1440, height: 900 } );
		await page.goto( path( tours.buggy.url ) );
		const gallery = page.locator( '.dgb-gallery' );
		const items = gallery.locator( '.dgb-gallery__item' );
		await expect( items ).toHaveCount( 5 );
		const first = await items.nth( 0 ).boundingBox();
		const second = await items.nth( 1 ).boundingBox();
		expect( first!.height ).toBeGreaterThan( second!.height * 1.8 );
		await expect( items.nth( 0 ).locator( 'figcaption' ) ).toHaveText( 'Sample caption' );
		await expect( items.nth( 1 ).locator( 'img' ) ).toHaveAttribute( 'alt', 'Sample view 2' );
		await items.nth( 1 ).locator( 'a' ).click();
		const box = page.locator( '.dgb-lightbox' );
		await expect( box ).toBeVisible();
		await expect( box.locator( 'img' ) ).toHaveAttribute( 'alt', 'Sample view 2' );
		await page.keyboard.press( 'ArrowRight' );
		await expect( box.locator( 'img' ) ).toHaveAttribute( 'alt', 'Sample view 3' );
		await page.keyboard.press( 'Escape' );
		await expect( box ).toHaveCount( 0 );
		await expect( items.nth( 2 ).locator( 'a' ) ).toBeFocused();

		await page.setViewportSize( { width: 375, height: 800 } );
		await expect( gallery ).toHaveCSS( 'scroll-snap-type', 'x mandatory' );
		const peek = await items.nth( 1 ).boundingBox();
		expect( peek!.x ).toBeLessThan( 375 );
	} );

	test( 'dark hero: price and chips take the widget colours; chips on light unchanged; one credit line', async ( { page } ) => {
		await cookies( page, [ 'dgb_test_card' ] );
		for ( const width of [ 390, 1440 ] ) {
			await page.setViewportSize( { width, height: 900 } );
			await page.goto( path( tours.buggy.url ) );
			const colour = ( l: Locator, prop = 'color' ) => l.evaluate( ( n, p ) => getComputedStyle( n ).getPropertyValue( p ), prop );
			const hero = page.locator( '.qa-hero' );
			expect( await colour( hero.locator( '.dgb-price__amount' ) ) ).toBe( 'rgb(255, 255, 255)' );
			expect( await colour( hero.locator( '.dgb-price__label' ) ) ).toBe( 'rgb(201, 184, 166)' );
			expect( await colour( hero.locator( '.dgb-price__basis' ) ) ).toBe( 'rgb(201, 184, 166)' );
			const chip = hero.locator( '.dgb-chip' ).first();
			expect( await colour( chip ) ).toBe( 'rgb(243, 233, 220)' );
			expect( await colour( chip, 'background-color' ) ).toBe( 'rgba(251, 246, 239, 0.08)' );
			expect( await colour( chip, 'border-top-color' ) ).toBe( 'rgba(251, 246, 239, 0.16)' );
			expect( Math.round( ( await chip.boundingBox() )!.height ) ).toBeGreaterThanOrEqual( 36 );
			// Chips on a light background: text colour of the page, border at 20% of it, no fill (as in 0.9.0).
			const light = page.locator( '.qa-light .dgb-chip' ).first();
			const text = await colour( page.locator( '.qa-light' ) );
			expect( await colour( light ) ).toBe( text );
			expect( await colour( light, 'background-color' ) ).toBe( 'rgba(0, 0, 0, 0)' );
			await expect( page.locator( '.dgb-credit' ) ).toHaveCount( 1 );
			await expect( page.locator( '[data-dgb-sticky] .dgb-sticky-basis' ) ).toHaveText( '2 seater, per buggy from' );
		}
	} );

	test( 'theme and kit button styles do not reach the card', async ( { page } ) => {
		await cookies( page, [ 'dgb_test_card', 'dgb_test_theme_css' ] );
		await page.setViewportSize( { width: 1440, height: 900 } );
		await page.goto( path( tours.buggy.url ) );
		const card = page.locator( '[data-dgb-card]' );
		const look = ( l: Locator ) => l.evaluate( ( n ) => {
			const c = getComputedStyle( n );
			return [ c.backgroundColor, c.color, c.borderTopLeftRadius, c.textTransform ].join( '|' );
		} );
		const before = await look( card.locator( '[data-dgb-card-book]' ) );
		expect( before ).toContain( 'rgb(180, 83, 9)' );
		await card.locator( '[data-dgb-card-book]' ).hover();
		await card.locator( '.dgb-bc-btn' ).first().hover();
		expect( await look( card.locator( '.dgb-bc-btn' ).first() ) ).not.toContain( 'rgb(204, 51, 102)' );
		expect( await look( card.locator( '[data-dgb-card-date]' ) ) ).not.toContain( 'rgb(204, 51, 102)' );
		expect( await look( card.locator( '[data-dgb-card-wa]' ) ) ).toContain( 'rgb(15, 122, 64)' );
	} );
} );
