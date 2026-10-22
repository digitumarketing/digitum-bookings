/**
 * Validates the structured data of pages against schema.org and Google's rich result requirements
 * (@adobe/structured-data-validator, the checks behind Google's Rich Results Test for Product and friends).
 *
 * Usage: node tests/schema/rich-results.mjs <url>…
 * Prints one JSON object: { "<url>": { "types": [...], "errors": [...], "warnings": [...] } }.
 * The schema.org vocabulary is read from DGB_SCHEMAORG, else .wp-test/schemaorg-all-https.jsonld
 * (downloaded from the schema.org repository on first use).
 */
import Validator from '@adobe/structured-data-validator';
import WebAutoExtractor from '@marbec/web-auto-extractor';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'fs';
import { dirname, resolve } from 'path';
import { fileURLToPath } from 'url';

const root = resolve( dirname( fileURLToPath( import.meta.url ) ), '../..' );
const vocabPath = process.env.DGB_SCHEMAORG || resolve( root, '.wp-test/schemaorg-all-https.jsonld' );
const vocabUrl = 'https://raw.githubusercontent.com/schemaorg/schemaorg/main/data/releases/30.0/schemaorg-all-https.jsonld';

if ( ! existsSync( vocabPath ) ) {
	const response = await fetch( vocabUrl );
	if ( ! response.ok ) {
		throw new Error( `Could not download the schema.org vocabulary (${ response.status })` );
	}
	mkdirSync( dirname( vocabPath ), { recursive: true } );
	writeFileSync( vocabPath, await response.text() );
}
const vocabulary = JSON.parse( readFileSync( vocabPath, 'utf8' ) );

const out = {};
for ( const url of process.argv.slice( 2 ) ) {
	const html = await ( await fetch( url ) ).text();
	const data = new WebAutoExtractor( { addLocation: true, embedSource: [ 'rdfa', 'microdata' ] } ).parse( html );
	const issues = await new Validator( vocabulary ).validate( data );
	out[ url ] = {
		types: Object.keys( data.jsonld || {} ),
		errors: issues.filter( ( i ) => 'ERROR' === i.severity ).map( ( i ) => `${ i.issueMessage } @ ${ ( i.path || [] ).map( ( p ) => p.type || p.property ).join( ' > ' ) }` ),
		warnings: issues.filter( ( i ) => 'ERROR' !== i.severity ).map( ( i ) => i.issueMessage ),
	};
}
process.stdout.write( JSON.stringify( out ) + '\n' );
