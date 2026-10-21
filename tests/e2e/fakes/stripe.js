/*
 * Stand-in for https://js.stripe.com/v3/ in browser tests (the sandbox cannot reach Stripe). Same calls as
 * the real Stripe.js uses in payment.js: Stripe(), elements(), create('payment'), mount(), on('ready'),
 * confirmPayment(). The "card" is checked by the fake Stripe API in tests/Integration/fake-gateways.php.
 */
( function () {
	const api = ( path, body ) =>
		fetch( '/?rest_route=/dgb-test/v1/' + path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( body ) } ).then( ( r ) => r.json() );

	function challenge() {
		return new Promise( ( resolve ) => {
			const dialog = document.createElement( 'div' );
			dialog.setAttribute( 'role', 'dialog' );
			dialog.setAttribute( 'aria-label', '3D Secure test authentication' );
			dialog.innerHTML = '<p>3D Secure 2 test page</p><button type="button" data-ok>Complete authentication</button> <button type="button" data-fail>Fail authentication</button>';
			document.body.appendChild( dialog );
			dialog.querySelector( '[data-ok]' ).onclick = () => {
				dialog.remove();
				resolve( true );
			};
			dialog.querySelector( '[data-fail]' ).onclick = () => {
				dialog.remove();
				resolve( false );
			};
		} );
	}

	window.Stripe = function ( key ) {
		if ( ! /^pk_test_/.test( key ) ) {
			throw new Error( 'fake Stripe: test key expected' );
		}
		let input = null;
		return {
			elements( options ) {
				return {
					clientSecret: options.clientSecret,
					create() {
						const handlers = {};
						return {
							mount( node ) {
								node.innerHTML = '<label>Card number <input data-fake-card inputmode="numeric" autocomplete="cc-number"></label>';
								input = node.querySelector( 'input' );
								setTimeout( () => handlers.ready && handlers.ready(), 20 );
							},
							on( name, cb ) {
								handlers[ name ] = cb;
							},
						};
					},
				};
			},
			async confirmPayment( { elements } ) {
				const card = input ? input.value : '';
				if ( ! card ) {
					return { error: { type: 'validation_error', message: 'Your card number is incomplete.' } };
				}
				let res = await api( 'stripe/confirm', { client_secret: elements.clientSecret, card } );
				if ( 'requires_action' === res.status ) {
					res = await api( 'stripe/confirm', { client_secret: elements.clientSecret, card, authenticate: await challenge() } );
				}
				if ( 'succeeded' !== res.status ) {
					return { error: res.error || { type: 'api_error', message: 'Unknown error' } };
				}
				return { paymentIntent: { id: res.id, status: 'succeeded' } };
			},
		};
	};
}() );
