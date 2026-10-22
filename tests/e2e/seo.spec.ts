import { test, expect, Page, Browser } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'child_process';
import { readFileSync } from 'fs';
import { e2e, tours, path } from './helpers';

/*
 * Integrations (M6): structured data matches the visible price, Rich Results validation, llms.txt, Tools screen.
 */

type Node = Record< string, any >;

async function tourNode( page: Page ): Promise< Node > {
	const graphs: Node[] = await page.locator( 'script[type="application/ld+json"]' ).evaluateAll( ( scripts ) => scripts.map( ( s ) => JSON.parse( s.textContent || '{}' ) ) );
	const nodes = graphs.flatMap( ( g ) => g[ '@graph' ] || [ g ] );
	const found = nodes.filter( ( n ) => [ 'Product', 'TouristTrip' ].includes( n[ '@type' ] ) );
	expect( found ).toHaveLength( 1 );
	return found[ 0 ];
}

const amount = ( text: string ) => Number( text.replace( /[^0-9.]/g, '' ) );

test.describe( 'structured data', () => {
	for ( const key of [ 'rzr', 'safari' ] ) {
		test( `${ key }: schema price is the price on the page`, async ( { page } ) => {
			await page.goto( path( tours[ key ].url ) );
			const node = await tourNode( page );
			const offers = node.offers;
			const schemaPrice = Number( offers.lowPrice ?? offers.price );
			const visible = page.locator( '.dgb-from strong' ).first();
			await expect( visible ).toBeAttached();
			expect( amount( ( await visible.textContent() ) || '' ) ).toBe( schemaPrice );
			expect( offers.priceCurrency ).toBe( 'AED' );
		} );
	}

	test( 'price on request: no price in the schema', async ( { page } ) => {
		await page.goto( path( tours.newyear.url ) );
		const node = await tourNode( page );
		expect( node[ '@type' ] ).toBe( 'TouristTrip' );
		expect( JSON.stringify( node ) ).not.toMatch( /"(price|lowPrice|highPrice)"/ );
		expect( node.potentialAction[ '@type' ] ).toBe( 'QuoteAction' );
		await expect( page.getByText( 'Price on request' ).first() ).toBeAttached();
	} );

	test( 'Rich Results: no errors on tour pages', async ( { baseURL, isMobile } ) => {
		test.skip( isMobile, 'same markup on mobile' );
		const urls = [ tours.rzr.url, tours.safari.url, tours.newyear.url ].map( ( u ) => baseURL + path( u ) );
		const out = JSON.parse( execFileSync( 'node', [ 'tests/schema/rich-results.mjs', ...urls ], { encoding: 'utf8' } ) );
		for ( const url of urls ) {
			expect( out[ url ].errors, url ).toEqual( [] );
		}
	} );
} );

test( 'llms.txt lists the tours with the page prices', async ( { request, isMobile } ) => {
	test.skip( isMobile, 'not a page' );
	const response = await request.get( '/llms.txt' );
	expect( response.status() ).toBe( 200 );
	expect( response.headers()[ 'content-type' ] ).toContain( 'text/plain' );
	const text = await response.text();
	expect( text ).toMatch( /^# Desert Bookings/ );
	expect( text ).toContain( `(${ tours.rzr.url }): From AED 699.00 per vehicle` );
	expect( text ).toContain( `(${ tours.newyear.url }): Price on request` );
} );

async function login( browser: Browser ): Promise< Page > {
	const page = await ( await browser.newContext() ).newPage();
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( 'admin' );
	await page.locator( '#user_pass' ).fill( 'password' );
	await page.locator( '#wp-submit' ).click();
	await page.waitForURL( /wp-admin|confirm_admin_email/, { timeout: 30_000 } );
	if ( page.url().includes( 'confirm_admin_email' ) ) {
		await page.getByRole( 'button', { name: /The email is correct/ } ).click();
	}
	return page;
}

test( 'Tools: CSV export and the Traveler importer', async ( { browser, isMobile } ) => {
	test.skip( isMobile, 'desktop screen' );
	const page = await login( browser );
	await page.goto( '/wp-admin/admin.php?page=dgb-tools' );
	await expect( page.getByRole( 'heading', { name: 'Tools', level: 1 } ) ).toBeVisible();
	await expect( page.getByText( 'No Traveler tours (post type st_tours) were found on this site.' ) ).toBeVisible();
	const result = await new AxeBuilder( { page } ).include( '.dgb-wrap' ).withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ] ).analyze();
	expect( result.violations.map( ( v ) => v.id ) ).toEqual( [] );

	const [ download ] = await Promise.all( [ page.waitForEvent( 'download' ), page.getByRole( 'link', { name: 'Export options' } ).click() ] );
	expect( download.suggestedFilename() ).toMatch( /^tours-variants-\d{4}-\d{2}-\d{2}\.csv$/ );
	const csv = readFileSync( ( await download.path() ) as string, 'utf8' );
	expect( csv ).toContain( 'id,product_id,product_slug,label' );
	expect( csv ).toContain( 'rzr,"2 hours"' );

	await page.goto( '/wp-admin/admin.php?page=dgb-settings&tab=seo' );
	await expect( page.getByLabel( 'Structured data' ) ).toHaveValue( 'auto' );
	await expect( page.getByRole( 'link', { name: /\/llms\.txt$/ } ) ).toBeVisible();
} );

test( 'Booking by Digitum is shown under the widget', async ( { page } ) => {
	await page.goto( path( tours.rzr.url ) );
	const credit = page.locator( '.dgb-credit' );
	await expect( credit ).toHaveCount( 1 );
	await expect( credit ).toBeVisible();
	await expect( credit.getByRole( 'link', { name: 'Digitum' } ) ).toHaveAttribute( 'href', 'https://digitum.marketing/' );
	const result = await new AxeBuilder( { page } ).include( '.dgb-credit' ).withTags( [ 'wcag2a', 'wcag2aa' ] ).analyze();
	expect( result.violations.map( ( v ) => v.id ) ).toEqual( [] );
} );
