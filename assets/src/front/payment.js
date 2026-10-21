/**
 * Digitum Bookings: online payment (Stripe Payment Element, PayPal buttons) and transfer screenshot upload
 * on the private booking page. Loaded only there, or after a booking is made on a tour page.
 *
 * Card details never touch this script or the site: Stripe.js and the PayPal SDK collect them in their
 * own frames.
 */
( function () {
	'use strict';

	const scripts = {};

	function loadScript( src ) {
		if ( ! scripts[ src ] ) {
			scripts[ src ] = new Promise( ( resolve, reject ) => {
				const s = document.createElement( 'script' );
				s.src = src;
				s.async = true;
				s.onload = resolve;
				s.onerror = () => {
					delete scripts[ src ];
					reject( new Error( 'load' ) );
				};
				document.head.appendChild( s );
			} );
		}
		return scripts[ src ];
	}

	async function api( cfg, path, body ) {
		const send = () =>
			fetch( cfg.rest + path, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-DGB-Nonce': cfg.nonce },
				body: JSON.stringify( Object.assign( { reference: cfg.reference, key: cfg.key }, body || {} ) ),
				credentials: 'same-origin',
			} );
		let res = await send();
		let data = null;
		try {
			data = await res.json();
		} catch ( e ) {}
		if ( 403 === res.status && data && 'dgb_nonce' === data.code ) {
			// The page came from a cache: get a fresh nonce once and retry.
			try {
				const s = await fetch( cfg.rest + 'session', { credentials: 'same-origin' } ).then( ( r ) => r.json() );
				cfg.nonce = s.nonce || cfg.nonce;
			} catch ( e ) {}
			res = await send();
			try {
				data = await res.json();
			} catch ( e ) {
				data = null;
			}
		}
		if ( ! res.ok ) {
			const err = new Error( ( data && data.message ) || cfg.i18n.networkError );
			err.code = data && data.code;
			throw err;
		}
		return data;
	}

	function done( box, cfg, url ) {
		say( box, cfg.i18n.paid, false );
		window.location.replace( url );
	}

	function say( box, text, error ) {
		const node = box.querySelector( '[data-dgb-pay-message]' );
		if ( node ) {
			node.textContent = text || '';
			node.classList.toggle( 'is-error', !! error );
		}
	}

	/* Stripe ------------------------------------------------------------------------------------------ */

	async function stripePay( box, cfg ) {
		const button = box.querySelector( '[data-dgb-pay-button]' );
		const mount = box.querySelector( '[data-dgb-pay-element]' );
		try {
			await loadScript( 'https://js.stripe.com/v3/' );
		} catch ( e ) {
			say( box, cfg.i18n.loadError, true );
			return;
		}
		const stripe = window.Stripe( cfg.publishableKey, { locale: cfg.locale || 'auto' } );
		const elements = stripe.elements( {
			clientSecret: cfg.clientSecret,
			appearance: { theme: 'stripe', variables: cfg.accent ? { colorPrimary: cfg.accent } : {} },
		} );
		const element = elements.create( 'payment', { layout: 'tabs' } );
		element.mount( mount );
		element.on( 'ready', () => {
			button.disabled = false;
		} );

		button.addEventListener( 'click', async () => {
			if ( button.disabled ) {
				return;
			}
			button.disabled = true;
			const label = button.textContent;
			button.textContent = cfg.i18n.paying;
			say( box, '', false );
			const result = await stripe.confirmPayment( {
				elements,
				confirmParams: { return_url: cfg.returnUrl },
				redirect: 'if_required',
			} );
			if ( result.error ) {
				// Card declined, failed authentication or an incomplete form: Stripe's message says which.
				say( box, result.error.message || cfg.i18n.networkError, true );
				button.disabled = false;
				button.textContent = label;
				return;
			}
			try {
				const res = await api( cfg, 'payments/stripe/confirm' );
				done( box, cfg, res.url );
			} catch ( err ) {
				// The payment went through at Stripe; the webhook records it. Reload to show the status.
				window.location.replace( cfg.returnUrl );
			}
		} );
	}

	/* PayPal ------------------------------------------------------------------------------------------ */

	async function paypalPay( box, cfg ) {
		const mount = box.querySelector( '[data-dgb-pay-element]' );
		const src =
			'https://www.paypal.com/sdk/js?' +
			new URLSearchParams( { 'client-id': cfg.clientId, currency: cfg.currency, intent: 'capture', components: 'buttons' } ).toString();
		try {
			await loadScript( src );
		} catch ( e ) {
			say( box, cfg.i18n.loadError, true );
			return;
		}
		window.paypal
			.Buttons( {
				style: { layout: 'vertical', label: 'pay' },
				createOrder: async () => {
					say( box, '', false );
					const res = await api( cfg, 'payments/paypal/order' );
					return res.id;
				},
				onApprove: async ( data, actions ) => {
					say( box, cfg.i18n.paying, false );
					try {
						const res = await api( cfg, 'payments/paypal/capture', { order_id: data.orderID } );
						done( box, cfg, res.url );
					} catch ( err ) {
						if ( 'dgb_paypal_declined' === err.code ) {
							say( box, cfg.i18n.declined, true );
							return actions.restart();
						}
						say( box, err.message, true );
					}
				},
				onCancel: () => say( box, cfg.i18n.cancelled, false ),
				onError: ( err ) => say( box, ( err && err.message ) || cfg.i18n.networkError, true ),
			} )
			.render( mount );
	}

	/* Redirect (hosted payment pages) --------------------------------------------------------------------- */

	function redirectPay( box, cfg ) {
		const mount = box.querySelector( '[data-dgb-pay-element]' );
		const link = document.createElement( 'a' );
		link.className = 'dgb-btn dgb-btn-primary';
		link.href = cfg.url;
		link.textContent = cfg.i18n.pay;
		mount.appendChild( link );
	}

	/* Screenshot upload ----------------------------------------------------------------------------------- */

	function proof( form ) {
		const cfg = JSON.parse( form.getAttribute( 'data-dgb-proof' ) );
		const message = form.querySelector( '[data-dgb-proof-message]' );
		const button = form.querySelector( 'button[type="submit"]' );
		const input = form.querySelector( 'input[type="file"]' );
		const tell = ( text, error ) => {
			message.textContent = text;
			message.classList.toggle( 'is-error', !! error );
		};
		form.addEventListener( 'submit', async ( e ) => {
			const file = input && input.files && input.files[ 0 ];
			if ( ! file || ! window.fetch || ! window.FormData ) {
				return; // Let the browser post the form.
			}
			e.preventDefault();
			if ( file.size > cfg.maxBytes ) {
				tell( cfg.tooBig, true );
				input.focus();
				return;
			}
			const body = new FormData();
			body.append( 'file', file );
			body.append( 'key', cfg.key );
			button.disabled = true;
			tell( cfg.uploading, false );
			try {
				const res = await fetch( cfg.url, { method: 'POST', body, headers: { 'X-DGB-Nonce': cfg.nonce }, credentials: 'same-origin' } );
				const data = await res.json().catch( () => null );
				if ( ! res.ok ) {
					throw new Error( ( data && data.message ) || '' );
				}
				const holder = form.closest( '[data-dgb-confirmation]' );
				if ( holder && data.html ) {
					const tmp = document.createElement( 'div' );
					tmp.innerHTML = data.html;
					const fresh = tmp.querySelector( '[data-dgb-proof]' );
					if ( fresh ) {
						form.replaceWith( fresh );
						fresh.querySelector( '[data-dgb-proof-message]' ).textContent = data.message;
						scan( fresh.parentNode );
						return;
					}
				}
				tell( data.message, false );
			} catch ( err ) {
				tell( err.message || cfg.failed, true );
			} finally {
				button.disabled = false;
			}
		} );
	}

	/* Start --------------------------------------------------------------------------------------------- */

	function scan( root ) {
		Array.prototype.forEach.call( ( root || document ).querySelectorAll( '[data-dgb-pay]' ), ( box ) => {
			if ( box.classList.contains( 'is-ready' ) ) {
				return;
			}
			box.classList.add( 'is-ready' );
			let cfg = null;
			try {
				cfg = JSON.parse( box.getAttribute( 'data-dgb-pay' ) );
			} catch ( e ) {}
			if ( ! cfg ) {
				return;
			}
			if ( 'stripe' === cfg.type ) {
				stripePay( box, cfg );
			} else if ( 'paypal' === cfg.type ) {
				paypalPay( box, cfg );
			} else if ( 'redirect' === cfg.type && cfg.url ) {
				redirectPay( box, cfg );
			}
		} );
		Array.prototype.forEach.call( ( root || document ).querySelectorAll( '[data-dgb-proof]' ), ( form ) => {
			if ( ! form.classList.contains( 'is-ready' ) ) {
				form.classList.add( 'is-ready' );
				proof( form );
			}
		} );
	}

	window.dgbPayments = { scan };

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', () => scan( document ) );
	} else {
		scan( document );
	}
}() );
