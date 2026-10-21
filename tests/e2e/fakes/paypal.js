/*
 * Stand-in for the PayPal JS SDK in browser tests: paypal.Buttons({ createOrder, onApprove, onCancel,
 * onError }).render(). "Pay with PayPal" approves the order at the fake PayPal API; "Declined card" makes
 * the capture fail with INSTRUMENT_DECLINED, like a declined funding source.
 */
( function () {
	const approve = ( order, outcome ) =>
		fetch( '/?rest_route=/dgb-test/v1/paypal/approve', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( { order_id: order, outcome } ) } );

	window.paypal = {
		Buttons( options ) {
			return {
				render( node ) {
					node.innerHTML = '<button type="button" data-outcome="approve">Pay with PayPal</button> <button type="button" data-outcome="decline">Declined card</button> <button type="button" data-outcome="cancel">Close PayPal window</button>';
					node.querySelectorAll( 'button' ).forEach( ( button ) => {
						button.onclick = async () => {
							let id;
							try {
								id = await options.createOrder();
							} catch ( e ) {
								options.onError( e );
								return;
							}
							const outcome = button.getAttribute( 'data-outcome' );
							if ( 'cancel' === outcome ) {
								options.onCancel( { orderID: id } );
								return;
							}
							await approve( id, outcome );
							await options.onApprove( { orderID: id }, { restart: () => Promise.resolve() } );
						};
					} );
					return Promise.resolve();
				},
			};
		},
	};
}() );
