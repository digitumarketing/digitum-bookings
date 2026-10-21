import { expect, Page, Locator } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { readFileSync } from 'fs';

export const e2e = JSON.parse( readFileSync( '.wp-test/e2e.json', 'utf8' ) );
export const { tours, tomorrow } = e2e.demo;
export const path = ( url: string ) => new URL( url ).pathname;

declare global {
	interface Window {
		dataLayer: Array< Record< string, any > >;
	}
}

export const events = ( page: Page ) => page.evaluate( () => ( window.dataLayer || [] ).filter( ( e ) => e.event ).map( ( e ) => e.event ) );

export async function axe( page: Page, scope = '[data-dgb-widget]' ) {
	const result = await new AxeBuilder( { page } ).include( scope ).withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ] ).analyze();
	expect( result.violations.map( ( v ) => `${ v.id }: ${ v.nodes.map( ( n ) => n.target.join( ' ' ) ).join( ', ' ) }` ) ).toEqual( [] );
}

export async function pickTomorrow( w: Locator ) {
	const day = w.locator( `.dgb-day[data-date="${ tomorrow }"]` );
	await expect( w.locator( '.dgb-day' ).first() ).toBeVisible();
	if ( ! ( await day.count() ) ) {
		await w.locator( '[data-dgb-next-month]' ).click();
	}
	await day.click();
}

export async function chooseRide( w: Locator ) {
	await w.getByRole( 'radio', { name: /^1 hour/ } ).check();
	await pickTomorrow( w );
	await w.getByRole( 'radio', { name: /^Evening/ } ).check();
	await w.getByRole( 'button', { name: 'More: Adults' } ).click();
}

export async function fillDetails( w: Locator ) {
	await w.getByLabel( 'Full name' ).fill( 'Test Guest' );
	await w.getByLabel( 'Email', { exact: true } ).fill( 'guest@example.com' );
	await w.getByLabel( 'Phone', { exact: true } ).fill( '501234567' );
	await w.getByRole( 'checkbox', { name: /I accept the booking terms/ } ).check();
}

