/**
 * Digitum Bookings – booking widget.
 *
 * Vanilla JS, no dependencies. Prices always come from the server (POST /quote), so the page,
 * the booking and the schema use the same calculation.
 */
( function () {
	'use strict';

	const ATTR_KEYS = [ 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid' ];
	const MOBILE = '(max-width: 781px)';

	/* Attribution cookie (first party) ------------------------------------ */

	function storeAttribution( days ) {
		try {
			const params = new URLSearchParams( window.location.search );
			const found = {};
			ATTR_KEYS.forEach( ( key ) => {
				const value = params.get( key );
				if ( value ) {
					found[ key ] = value.slice( 0, 191 );
				}
			} );
			if ( ! Object.keys( found ).length ) {
				return;
			}
			document.cookie =
				'dgb_attr=' + encodeURIComponent( JSON.stringify( found ) ) +
				'; max-age=' + ( days * 86400 ) + '; path=/; SameSite=Lax' +
				( 'https:' === window.location.protocol ? '; Secure' : '' );
		} catch ( e ) {
			// Cookies blocked: bookings are stored without attribution.
		}
	}

	/* dataLayer (GTM fires the tags) --------------------------------------- */

	function push( enabled, event, data ) {
		if ( ! enabled ) {
			return;
		}
		window.dataLayer = window.dataLayer || [];
		if ( data && data.ecommerce ) {
			window.dataLayer.push( { ecommerce: null } );
		}
		window.dataLayer.push( Object.assign( { event }, data || {} ) );
	}

	function pushOnce( event ) {
		if ( ! event || ! event.event ) {
			return;
		}
		const id = event.ecommerce && event.ecommerce.transaction_id ? event.ecommerce.transaction_id : JSON.stringify( event ).length + event.event;
		const key = 'dgb_sent_' + id;
		try {
			if ( window.sessionStorage.getItem( key ) ) {
				return;
			}
			window.sessionStorage.setItem( key, '1' );
		} catch ( e ) {
			// Without storage the event may repeat on reload; GA4 dedupes purchases by transaction_id.
		}
		const data = Object.assign( {}, event );
		delete data.event;
		push( true, event.event, data );
	}

	/* Helpers ----------------------------------------------------------------- */

	function el( tag, attrs, children ) {
		const node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( ( key ) => {
			const value = attrs[ key ];
			if ( null === value || undefined === value || false === value ) {
				return;
			}
			if ( 'text' === key ) {
				node.textContent = value;
			} else if ( 'class' === key ) {
				node.className = value;
			} else {
				node.setAttribute( key, true === value ? '' : value );
			}
		} );
		( children || [] ).forEach( ( child ) => child && node.appendChild( 'string' === typeof child ? document.createTextNode( child ) : child ) );
		return node;
	}

	function sprintf( text ) {
		const args = Array.prototype.slice.call( arguments, 1 );
		let i = 0;
		return text
			.replace( /%(\d)\$s/g, ( m, n ) => args[ n - 1 ] )
			.replace( /%%/g, '\u0000' )
			.replace( /%s/g, () => args[ i++ ] )
			.replace( /\u0000/g, '%' );
	}

	/* Payment script: loaded only when a booking needs online payment or a screenshot upload. */
	function loadPayments( root ) {
		if ( window.dgbPayments ) {
			window.dgbPayments.scan( root );
			return;
		}
		const cfgNode = document.querySelector( '[data-dgb-config]' );
		const src = cfgNode ? JSON.parse( cfgNode.textContent ).payment : '';
		if ( src && ! document.querySelector( 'script[data-dgb-payment]' ) ) {
			const s = document.createElement( 'script' );
			s.src = src;
			s.setAttribute( 'data-dgb-payment', '' );
			document.body.appendChild( s );
		}
	}

	/* Widget -------------------------------------------------------------------- */

	function init( root ) {
		const done = root.querySelector( '[data-dgb-confirmation]' );
		if ( done && done.getAttribute( 'data-dgb-event' ) ) {
			try {
				pushOnce( JSON.parse( done.getAttribute( 'data-dgb-event' ) ) );
			} catch ( e ) {}
		}
		const cfgNode = root.querySelector( '[data-dgb-config]' );
		if ( ! cfgNode ) {
			return;
		}

		const cfg = JSON.parse( cfgNode.textContent );
		const t = cfg.i18n;
		const q = ( s ) => root.querySelector( s );
		const qa = ( s ) => Array.prototype.slice.call( root.querySelectorAll( s ) );
		const form = q( '[data-dgb-form]' );
		const panel = q( '[data-dgb-panel]' );
		const track = ( event, data ) => push( cfg.analytics.enabled, event, data );
		const money = new Intl.NumberFormat( cfg.locale, {
			style: 'currency',
			currency: cfg.currency,
			minimumFractionDigits: cfg.decimals,
			maximumFractionDigits: cfg.decimals,
		} );
		const fmt = ( v ) => ( null === v || undefined === v ? '' : money.format( Number( v ) ) );
		// Optional second currency, for display only: " (about USD 190)".
		const sc = cfg.second;
		const scNum = sc && new Intl.NumberFormat( cfg.locale, { minimumFractionDigits: sc.decimals, maximumFractionDigits: sc.decimals } );
		const second = ( v ) => ( sc && null !== v && undefined !== v ? ' (' + sc.template.replace( '{amount}', sc.code + ' ' + scNum.format( Number( v ) / sc.rate ) ) + ')' : '' );
		const timeFmt = new Intl.DateTimeFormat( cfg.locale, { hour: 'numeric', minute: '2-digit' } );
		const fmtTime = ( v ) => {
			if ( ! v ) {
				return '';
			}
			const p = v.split( ':' );
			return timeFmt.format( new Date( 2000, 0, 1, +p[ 0 ], +p[ 1 ] ) );
		};

		const state = {
			month: cfg.today.slice( 0, 7 ),
			date: '',
			session: 0,
			sessions: [],
			quote: null,
			step: 0,
			mobile: false,
			seq: 0,
			sent: {},
			opener: null,
			busy: false,
		};
		let dgbNonce = cfg.rest.dgbNonce;
		let wpNonce = cfg.rest.wpNonce;

		/* REST -------------------------------------------------------------- */

		async function api( path, body, retry ) {
			const headers = { 'X-DGB-Nonce': dgbNonce };
			if ( wpNonce ) {
				headers[ 'X-WP-Nonce' ] = wpNonce;
			}
			if ( body ) {
				headers[ 'Content-Type' ] = 'application/json';
			}
			const res = await fetch( cfg.rest.root + path, {
				method: body ? 'POST' : 'GET',
				headers,
				body: body ? JSON.stringify( body ) : undefined,
				credentials: 'same-origin',
			} );
			let data = null;
			try {
				data = await res.json();
			} catch ( e ) {}
			const code = data && data.code;
			if ( 403 === res.status && false !== retry && ( 'dgb_nonce' === code || 'rest_cookie_invalid_nonce' === code ) ) {
				// The page came from a cache: get fresh nonces once and retry.
				if ( 'rest_cookie_invalid_nonce' === code ) {
					wpNonce = '';
				}
				try {
					const s = await fetch( cfg.rest.root + 'session', { credentials: 'same-origin' } ).then( ( r ) => r.json() );
					dgbNonce = s.nonce || dgbNonce;
				} catch ( e ) {}
				return api( path, body, false );
			}
			if ( ! res.ok ) {
				const err = new Error( ( data && data.message ) || t.networkError );
				err.data = data;
				throw err;
			}
			return data;
		}

		/* Values -------------------------------------------------------------- */

		const field = ( name ) => form.elements.namedItem( name );
		const radio = ( name ) => {
			const checked = form.querySelector( 'input[name="' + name + '"]:checked' );
			return checked ? checked.value : '';
		};
		const num = ( name ) => {
			const input = field( name );
			return input ? Math.max( 0, parseInt( input.value, 10 ) || 0 ) : 0;
		};
		const variant = () => cfg.variants.find( ( v ) => String( v.id ) === radio( 'variant_id' ) ) || null;
		const pickupOn = () => '1' === radio( 'pickup' );

		function collect() {
			const data = new FormData( form );
			const out = {
				product: cfg.product.id,
				variant_id: +radio( 'variant_id' ) || 0,
				date: state.date,
				session_id: state.session,
				adults: num( 'adults' ),
				children: num( 'children' ),
				infants: num( 'infants' ),
				units: num( 'units' ) || 1,
				pickup: pickupOn(),
				zone_id: pickupOn() ? +( field( 'zone_id' ) || {} ).value || 0 : 0,
				addons: data.getAll( 'addons[]' ).map( Number ),
				payment_method: radio( 'payment_method' ),
				pay_deposit: '1' === radio( 'pay_deposit' ),
			};
			if ( state.coupon ) {
				out.coupon = state.coupon;
			}
			[ 'name', 'email', 'phone_code', 'phone', 'nationality', 'requests', 'hotel', 'room', 'dgb_website', 'turnstile' ].forEach( ( key ) => {
				if ( data.has( key ) ) {
					out[ key ] = String( data.get( key ) );
				}
			} );
			out.marketing = data.has( 'marketing' );
			out.terms = data.has( 'terms' );
			return out;
		}

		function gaItem() {
			const v = variant();
			return {
				item_id: String( cfg.product.id ),
				item_name: cfg.product.name,
				item_variant: v ? v.label : undefined,
				price: v && v.price ? Number( v.price ) : 0,
				quantity: 1,
			};
		}

		/* Calendar -------------------------------------------------------------- */

		const days = q( '[data-dgb-days]' );

		async function loadMonth() {
			const parts = state.month.split( '-' ).map( Number );
			const first = new Date( parts[ 0 ], parts[ 1 ] - 1, 1 );
			q( '[data-dgb-month]' ).textContent = new Intl.DateTimeFormat( cfg.locale, { month: 'long', year: 'numeric' } ).format( first );
			q( '[data-dgb-prev]' ).disabled = state.month <= cfg.today.slice( 0, 7 );
			days.setAttribute( 'aria-busy', 'true' );

			let data = { days: {} };
			try {
				data = await api( 'availability?product=' + cfg.product.id + '&month=' + state.month );
			} catch ( e ) {
				days.textContent = t.networkError;
				return;
			}

			days.textContent = '';
			t.weekdays.forEach( ( w ) => days.appendChild( el( 'span', { class: 'dgb-cal-wd', 'aria-hidden': 'true', text: w } ) ) );
			for ( let i = 0; i < ( first.getDay() + 6 ) % 7; i++ ) {
				days.appendChild( el( 'span', { class: 'dgb-cal-blank', 'aria-hidden': 'true' } ) );
			}
			const label = new Intl.DateTimeFormat( cfg.locale, { weekday: 'long', day: 'numeric', month: 'long' } );
			let focusable = null;
			Object.keys( data.days ).forEach( ( date ) => {
				const status = data.days[ date ];
				const open = 'open' === status;
				const d = new Date( +date.slice( 0, 4 ), +date.slice( 5, 7 ) - 1, +date.slice( 8, 10 ) );
				const btn = el(
					'button',
					{
						type: 'button',
						class: 'dgb-day is-' + status,
						'data-date': date,
						disabled: ! open,
						'aria-pressed': date === state.date ? 'true' : 'false',
						'aria-label': label.format( d ) + ', ' + ( t.status[ status ] || status ),
						tabindex: '-1',
					},
					[ String( d.getDate() ) ]
				);
				days.appendChild( btn );
				if ( open && ( ! focusable || date === state.date ) ) {
					focusable = btn;
				}
			} );
			if ( focusable ) {
				focusable.tabIndex = 0;
			}
			days.removeAttribute( 'aria-busy' );
		}

		function moveMonth( delta ) {
			const parts = state.month.split( '-' ).map( Number );
			const d = new Date( parts[ 0 ], parts[ 1 ] - 1 + delta, 1 );
			state.month = d.getFullYear() + '-' + String( d.getMonth() + 1 ).padStart( 2, '0' );
			loadMonth();
		}

		days.addEventListener( 'click', ( e ) => {
			const btn = e.target.closest( '.dgb-day' );
			if ( btn && ! btn.disabled ) {
				chooseDate( btn.getAttribute( 'data-date' ) );
			}
		} );

		days.addEventListener( 'keydown', ( e ) => {
			// In a right-to-left page the next day is on the left.
			const rtl = 'rtl' === window.getComputedStyle( days ).direction;
			const moves = { ArrowLeft: rtl ? 1 : -1, ArrowRight: rtl ? -1 : 1, ArrowUp: -7, ArrowDown: 7 };
			if ( ! ( e.key in moves ) && 'Home' !== e.key && 'End' !== e.key ) {
				return;
			}
			const all = qa( '.dgb-day' );
			const open = all.filter( ( b ) => ! b.disabled );
			const current = all.indexOf( document.activeElement );
			if ( ! open.length || current < 0 ) {
				return;
			}
			e.preventDefault();
			let target = null;
			if ( 'Home' === e.key ) {
				target = open[ 0 ];
			} else if ( 'End' === e.key ) {
				target = open[ open.length - 1 ];
			} else {
				// Day buttons are in date order, so ±7 is the same weekday; skip days that cannot be booked.
				const step = moves[ e.key ];
				for ( let i = current + step; i >= 0 && i < all.length; i += step ) {
					if ( ! all[ i ].disabled ) {
						target = all[ i ];
						break;
					}
				}
			}
			if ( target ) {
				all.forEach( ( b ) => ( b.tabIndex = -1 ) );
				target.tabIndex = 0;
				target.focus();
			}
		} );

		async function chooseDate( date ) {
			state.date = date;
			state.session = 0;
			qa( '.dgb-day' ).forEach( ( b ) => b.setAttribute( 'aria-pressed', b.getAttribute( 'data-date' ) === date ? 'true' : 'false' ) );
			showError( 'date', '' );
			const box = q( '[data-dgb-sessions]' );
			box.setAttribute( 'aria-busy', 'true' );
			box.textContent = '';
			box.appendChild( el( 'p', { class: 'dgb-hint', text: t.loading } ) );
			try {
				state.sessions = ( await api( 'availability/sessions?product=' + cfg.product.id + '&date=' + date ) ).sessions || [];
			} catch ( e ) {
				state.sessions = [];
			}
			renderSessions();
			box.removeAttribute( 'aria-busy' );
			update();
		}

		function renderSessions() {
			const box = q( '[data-dgb-sessions]' );
			box.textContent = '';
			if ( ! state.sessions.length ) {
				box.appendChild( el( 'p', { class: 'dgb-hint', text: t.noSessions } ) );
				return;
			}
			const bookable = state.sessions.filter( ( s ) => s.bookable );
			if ( 1 === bookable.length ) {
				state.session = bookable[ 0 ].id;
			}
			state.sessions.forEach( ( s ) => {
				const meta = [];
				if ( s.start_time ) {
					meta.push( sprintf( t.starts, fmtTime( s.start_time ) ) );
				}
				if ( s.pickup_window_start && 'none' !== cfg.pickup.policy ) {
					meta.push( sprintf( t.pickupWindow, fmtTime( s.pickup_window_start ) + '–' + fmtTime( s.pickup_window_end ) ) );
				}
				if ( ! s.bookable ) {
					meta.push( t.status[ s.status ] || t.unavailable );
				}
				box.appendChild(
					el( 'label', { class: 'dgb-card' + ( s.bookable ? '' : ' is-disabled' ) }, [
						el( 'input', { type: 'radio', name: 'session_id', value: String( s.id ), disabled: ! s.bookable, checked: s.id === state.session } ),
						el( 'span', { class: 'dgb-card-body' }, [
							el( 'span', { class: 'dgb-card-title', text: s.name } ),
							el( 'span', { class: 'dgb-card-meta', text: meta.join( ' · ' ) } ),
						] ),
					] )
				);
			} );
			syncCards();
		}

		/* Live price -------------------------------------------------------------- */

		let timer = 0;
		function update() {
			syncCards();
			syncPickup();
			if ( cfg.product.onRequest ) {
				return;
			}
			window.clearTimeout( timer );
			timer = window.setTimeout( quote, 200 );
		}

		async function quote() {
			if ( ! variant() ) {
				renderBreakdown( null );
				return;
			}
			const seq = ++state.seq;
			try {
				const result = await api( 'quote', collect() );
				if ( seq === state.seq ) {
					state.quote = result;
					renderBreakdown( result );
				}
			} catch ( e ) {
				if ( seq === state.seq ) {
					renderBreakdown( null );
				}
			}
		}

		function renderBreakdown( r ) {
			const box = q( '[data-dgb-breakdown]' );
			const submit = q( '[data-dgb-submit]' );
			box.textContent = '';
			if ( ! r ) {
				submit.textContent = t.book;
				return;
			}
			const rows = r.lines.map( ( line ) => {
				let amount = fmt( line.amount );
				if ( 'quote' === line.type ) {
					amount = t.onRequest;
				} else if ( 'free' === line.type ) {
					amount = t.free;
				}
				const label = line.quantity > 1 ? line.label + ' × ' + line.quantity : line.label;
				return el( 'div', { class: 'dgb-line is-' + line.type }, [ el( 'dt', { text: label } ), el( 'dd', { text: amount } ) ] );
			} );
			const list = el( 'dl', { class: 'dgb-lines' }, rows );
			list.appendChild( el( 'div', { class: 'dgb-line is-total' }, [ el( 'dt', { text: t.total } ), el( 'dd', { text: fmt( r.total ) + second( r.total ) } ) ] ) );
			list.appendChild(
				el( 'div', { class: 'dgb-line is-vat' }, [
					el( 'dt', { text: sprintf( r.vat_inclusive ? t.includesVat : t.plusVat, r.vat_rate + '%' ) } ),
					el( 'dd', { text: fmt( r.vat ) } ),
				] )
			);
			if ( Number( r.due_on_arrival ) > 0 ) {
				list.appendChild( el( 'div', { class: 'dgb-line' }, [ el( 'dt', { text: t.payNow } ), el( 'dd', { text: fmt( r.pay_now ) } ) ] ) );
				list.appendChild( el( 'div', { class: 'dgb-line' }, [ el( 'dt', { text: t.dueOnArrival } ), el( 'dd', { text: fmt( r.due_on_arrival ) } ) ] ) );
			}
			box.appendChild( list );

			const couponStatus = q( '[data-dgb-coupon-status]' );
			if ( couponStatus && state.coupon ) {
				const bad = ( r.errors || [] ).find( ( e ) => 'coupon_invalid' === e.code );
				couponStatus.textContent = bad ? bad.message : sprintf( t.couponApplied, state.coupon );
				couponStatus.classList.toggle( 'is-error', !! bad );
				if ( bad ) {
					// Price without the code, so the guest can still book.
					state.coupon = '';
					update();
				}
			}
			const problems = ( r.errors || [] ).filter( ( e ) => e.message && 'coupon_invalid' !== e.code );
			if ( problems.length ) {
				box.appendChild( el( 'ul', { class: 'dgb-price-errors' }, problems.map( ( e ) => el( 'li', { text: e.message } ) ) ) );
			}

			const amount = q( '[data-dgb-amount]' );
			if ( amount ) {
				amount.hidden = ! r.deposit_available;
				if ( r.deposit_available ) {
					const share = ( Number( r.total ) * r.deposit_percent ) / 100;
					q( '[data-dgb-full-text]' ).textContent = fmt( r.total );
					q( '[data-dgb-deposit-title]' ).textContent = sprintf( t.depositOf, String( r.deposit_percent ) );
					q( '[data-dgb-deposit-text]' ).textContent = sprintf( t.depositSplit, fmt( share ), fmt( Number( r.total ) - share ) );
				} else if ( '1' === radio( 'pay_deposit' ) ) {
					form.querySelector( 'input[name="pay_deposit"][value="0"]' ).checked = true;
				}
			}

			const method = radio( 'payment_method' );
			if ( 'arrival' === method ) {
				submit.textContent = t.bookArrival;
			} else if ( 'bank_transfer' === method ) {
				submit.textContent = sprintf( t.bookTransfer, fmt( r.pay_now ) );
			} else if ( method ) {
				submit.textContent = sprintf( t.bookPay, fmt( r.pay_now ) );
			} else {
				submit.textContent = t.book;
			}
		}

		/* UI sync ------------------------------------------------------------------ */

		function syncCards() {
			qa( '.dgb-card' ).forEach( ( card ) => {
				const input = card.querySelector( 'input' );
				card.classList.toggle( 'is-selected', !! ( input && input.checked ) );
			} );
		}

		function syncPickup() {
			const fields = q( '[data-dgb-pickup-fields]' );
			const meet = q( '[data-dgb-meet]' );
			const on = pickupOn();
			if ( fields ) {
				fields.hidden = ! on;
			}
			if ( meet ) {
				meet.hidden = on && !! fields;
			}
			const select = field( 'zone_id' );
			const note = q( '[data-dgb-zone-note]' );
			if ( select && note ) {
				const zone = cfg.zones.find( ( z ) => String( z.id ) === select.value );
				const blocked = !! ( zone && ! zone.allowed );
				note.hidden = ! blocked;
				note.textContent = blocked ? ( zone.notes || t.chooseZone ) : '';
				qa( '[data-dgb-hotel]' ).forEach( ( n ) => ( n.hidden = blocked ) );
			}
			const windowText = q( '[data-dgb-window]' );
			const session = state.sessions.find( ( s ) => s.id === state.session );
			if ( windowText ) {
				windowText.textContent = session && session.pickup_window_start ? sprintf( t.pickupWindow, fmtTime( session.pickup_window_start ) + '–' + fmtTime( session.pickup_window_end ) ) : '';
			}
		}

		/* Errors ---------------------------------------------------------------------- */

		const fieldFor = { name: 'name', email: 'email', phone: 'phone', hotel: 'hotel', zone: 'zone_id', terms: 'terms' };

		function showError( key, message ) {
			const box = q( '[data-dgb-error="' + key + '"]' );
			if ( box ) {
				box.textContent = message;
				box.hidden = ! message;
				if ( ! box.id ) {
					box.id = cfg.id + '-err-' + key;
				}
			}
			const input = fieldFor[ key ] ? field( fieldFor[ key ] ) : null;
			if ( input && input.setAttribute ) {
				if ( message ) {
					input.setAttribute( 'aria-invalid', 'true' );
					input.setAttribute( 'aria-describedby', ( box && box.id ) || '' );
				} else {
					input.removeAttribute( 'aria-invalid' );
				}
			}
			return !! box;
		}

		function stepOf( key ) {
			const box = q( '[data-dgb-error="' + key + '"]' );
			const step = box ? box.closest( '.dgb-step' ) : null;
			return step ? steps().indexOf( step ) : -1;
		}

		function validate( index ) {
			const name = steps()[ index ].getAttribute( 'data-step' );
			const errors = {};
			if ( 'choose' === name ) {
				if ( cfg.variants.length && ! variant() ) {
					errors.variant = t.chooseOption;
				}
				if ( ! state.date ) {
					errors.date = t.chooseSession;
				} else if ( state.sessions.length && ! state.session ) {
					errors.session = t.chooseSession;
				}
			} else if ( 'guests' === name ) {
				if ( num( 'adults' ) + num( 'children' ) < 1 ) {
					errors.guests = t.addGuest;
				}
			} else if ( 'pickup' === name && pickupOn() ) {
				const zone = cfg.zones.find( ( z ) => String( z.id ) === ( field( 'zone_id' ) || {} ).value );
				if ( ! zone || ! zone.allowed ) {
					errors.zone = zone ? zone.notes || t.chooseZone : t.chooseZone;
				} else if ( ! ( field( 'hotel' ).value || '' ).trim() ) {
					errors.hotel = t.hotel;
				}
			} else if ( 'details' === name ) {
				if ( ( field( 'name' ).value || '' ).trim().length < 2 ) {
					errors.name = t.required;
				}
				if ( ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( ( field( 'email' ).value || '' ).trim() ) ) {
					errors.email = t.required;
				}
				if ( ( field( 'phone' ).value || '' ).replace( /\D/g, '' ).length < 6 ) {
					errors.phone = t.required;
				}
				if ( ! field( 'terms' ).checked ) {
					errors.terms = t.terms;
				}
			} else if ( 'pay' === name && ! radio( 'payment_method' ) ) {
				errors.payment_method = t.choosePayment;
			}
			[ 'variant', 'date', 'session', 'guests', 'zone', 'hotel', 'name', 'email', 'phone', 'terms', 'payment_method' ].forEach( ( key ) => {
				if ( stepOf( key ) === index ) {
					showError( key, errors[ key ] || '' );
				}
			} );
			return errors;
		}

		function focusFirstError( index ) {
			const step = steps()[ index ];
			const target = step.querySelector( '[aria-invalid="true"]' ) || step.querySelector( '.dgb-field-error:not([hidden])' );
			if ( target ) {
				if ( target.classList.contains( 'dgb-field-error' ) ) {
					const legend = step.querySelector( '.dgb-legend' );
					legend.focus();
				} else {
					target.focus();
				}
			}
		}

		function summary( messages ) {
			const box = q( '[data-dgb-errors]' );
			box.textContent = '';
			box.hidden = ! messages.length;
			if ( messages.length ) {
				box.appendChild( el( 'p', { text: t.fix } ) );
				box.appendChild( el( 'ul', {}, messages.map( ( m ) => el( 'li', { text: m } ) ) ) );
				box.focus();
			}
		}

		/* Steps (phones) and sheet ------------------------------------------------------------ */

		const steps = () => qa( '.dgb-step' );

		function layout() {
			state.mobile = window.matchMedia( MOBILE ).matches;
			root.classList.toggle( 'is-stepped', state.mobile );
			showStep( state.step, false );
		}

		function showStep( index, focus ) {
			const all = steps();
			state.step = Math.max( 0, Math.min( index, all.length - 1 ) );
			all.forEach( ( s, i ) => s.classList.toggle( 'is-current', ! state.mobile || i === state.step ) );
			const last = state.step === all.length - 1;
			q( '[data-dgb-back]' ).hidden = ! state.mobile || 0 === state.step;
			q( '[data-dgb-next]' ).hidden = ! state.mobile || last;
			q( '[data-dgb-submit]' ).hidden = state.mobile && ! last;
			q( '[data-dgb-progress]' ).textContent = state.mobile ? sprintf( t.stepOf, String( state.step + 1 ), String( all.length ) ) : '';
			if ( focus ) {
				all[ state.step ].querySelector( '.dgb-legend' ).focus();
			}
			if ( 'details' === all[ state.step ].getAttribute( 'data-step' ) && state.mobile ) {
				beginCheckout();
			}
		}

		function open( opener ) {
			state.opener = opener || null;
			panel.classList.add( 'is-open' );
			panel.setAttribute( 'role', 'dialog' );
			panel.setAttribute( 'aria-modal', 'true' );
			panel.setAttribute( 'aria-labelledby', cfg.id + '-title' );
			document.documentElement.classList.add( 'dgb-lock' );
			q( '.dgb-title' ).focus();
		}

		function close() {
			if ( ! panel.classList.contains( 'is-open' ) ) {
				return;
			}
			panel.classList.remove( 'is-open' );
			panel.removeAttribute( 'role' );
			panel.removeAttribute( 'aria-modal' );
			document.documentElement.classList.remove( 'dgb-lock' );
			if ( state.opener ) {
				state.opener.focus();
			}
		}

		function beginCheckout() {
			if ( state.sent.checkout ) {
				return;
			}
			state.sent.checkout = true;
			track( 'begin_checkout', { ecommerce: { currency: cfg.currency, value: state.quote ? Number( state.quote.total ) : 0, items: [ gaItem() ] } } );
		}

		/* Submit -------------------------------------------------------------------------------- */

		async function submit( e ) {
			e.preventDefault();
			if ( state.busy ) {
				return;
			}
			const all = steps();
			for ( let i = 0; i < all.length; i++ ) {
				const errors = validate( i );
				if ( Object.keys( errors ).length ) {
					showStep( i, false );
					summary( Object.values( errors ) );
					focusFirstError( i );
					return;
				}
			}
			summary( [] );
			const button = q( '[data-dgb-submit]' );
			const label = button.textContent;
			state.busy = true;
			button.disabled = true;
			button.textContent = t.sending;
			try {
				const res = await api( cfg.product.onRequest ? 'enquiries' : 'bookings', collect() );
				const result = q( '[data-dgb-result]' );
				result.innerHTML = res.html;
				panel.hidden = true;
				panel.classList.remove( 'is-open' );
				document.documentElement.classList.remove( 'dgb-lock' );
				qa( '.dgb-teaser, [data-dgb-sticky]' ).forEach( ( n ) => ( n.hidden = true ) );
				const confirmation = result.querySelector( '[data-dgb-confirmation]' );
				if ( result.querySelector( '[data-dgb-pay], [data-dgb-proof]' ) ) {
					loadPayments( result );
				}
				if ( res.url ) {
					try {
						window.history.replaceState( null, '', res.url );
					} catch ( err ) {}
				}
				if ( res.event ) {
					pushOnce( res.event );
				}
				if ( confirmation ) {
					confirmation.scrollIntoView( { block: 'start' } );
					confirmation.focus();
				}
			} catch ( err ) {
				const fields = ( err.data && err.data.data && err.data.data.fields ) || null;
				const messages = [];
				if ( fields ) {
					let firstStep = -1;
					Object.keys( fields ).forEach( ( key ) => {
						messages.push( fields[ key ] );
						if ( showError( key, fields[ key ] ) && ( firstStep < 0 || stepOf( key ) < firstStep ) ) {
							firstStep = stepOf( key );
						}
					} );
					if ( firstStep >= 0 ) {
						showStep( firstStep, false );
					}
				} else {
					messages.push( err.message || t.networkError );
				}
				summary( messages );
			} finally {
				state.busy = false;
				button.disabled = false;
				if ( t.sending === button.textContent ) {
					button.textContent = label;
				}
			}
		}

		/* Events ------------------------------------------------------------------------------------- */

		form.addEventListener( 'submit', submit );

		form.addEventListener( 'change', ( e ) => {
			const name = e.target.name;
			if ( 'variant_id' === name ) {
				showError( 'variant', '' );
				track( 'select_item', { ecommerce: { item_list_name: cfg.product.name, items: [ gaItem() ] } } );
			}
			if ( 'session_id' === name ) {
				state.session = +e.target.value;
				showError( 'session', '' );
				const key = [ radio( 'variant_id' ), state.date, state.session ].join( '|' );
				if ( ! state.sent[ key ] ) {
					state.sent[ key ] = true;
					track( 'add_to_cart', { ecommerce: { currency: cfg.currency, value: gaItem().price, items: [ gaItem() ] } } );
				}
			}
			if ( 'payment_method' === name ) {
				showError( 'payment_method', '' );
				track( 'add_payment_info', { ecommerce: { currency: cfg.currency, value: state.quote ? Number( state.quote.total ) : 0, payment_type: e.target.value, items: [ gaItem() ] } } );
			}
			if ( 'zone_id' === name || 'pickup' === name ) {
				showError( 'zone', '' );
			}
			update();
		} );

		form.addEventListener( 'input', ( e ) => {
			if ( 'number' === e.target.type ) {
				update();
			}
			const key = Object.keys( fieldFor ).find( ( k ) => fieldFor[ k ] === e.target.name );
			if ( key && e.target.getAttribute( 'aria-invalid' ) ) {
				showError( key, '' );
			}
		} );

		form.addEventListener( 'focusin', ( e ) => {
			const step = e.target.closest( '.dgb-step' );
			if ( step && 'details' === step.getAttribute( 'data-step' ) ) {
				beginCheckout();
			}
		} );

		form.addEventListener( 'click', ( e ) => {
			const btn = e.target.closest( 'button' );
			if ( ! btn ) {
				return;
			}
			const inc = btn.getAttribute( 'data-dgb-inc' );
			const dec = btn.getAttribute( 'data-dgb-dec' );
			if ( inc || dec ) {
				const input = field( inc || dec );
				const min = parseInt( input.min, 10 ) || 0;
				const max = parseInt( input.max, 10 ) || 50;
				const value = ( parseInt( input.value, 10 ) || 0 ) + ( inc ? 1 : -1 );
				input.value = String( Math.max( min, Math.min( max, value ) ) );
				showError( 'guests', '' );
				update();
			}
			if ( btn.hasAttribute( 'data-dgb-next' ) ) {
				const errors = validate( state.step );
				if ( Object.keys( errors ).length ) {
					focusFirstError( state.step );
				} else {
					showStep( state.step + 1, true );
				}
			}
			if ( btn.hasAttribute( 'data-dgb-back' ) ) {
				showStep( state.step - 1, true );
			}
		} );

		const couponButton = q( '[data-dgb-coupon]' );
		if ( couponButton ) {
			const applyCoupon = () => {
				state.coupon = String( field( 'coupon_entry' ).value || '' ).trim().toUpperCase();
				q( '[data-dgb-coupon-status]' ).textContent = '';
				update();
			};
			couponButton.addEventListener( 'click', applyCoupon );
			field( 'coupon_entry' ).addEventListener( 'keydown', ( e ) => {
				if ( 'Enter' === e.key ) {
					e.preventDefault();
					applyCoupon();
				}
			} );
		}

		q( '[data-dgb-prev]' ).addEventListener( 'click', () => moveMonth( -1 ) );
		q( '[data-dgb-next-month]' ).addEventListener( 'click', () => moveMonth( 1 ) );
		qa( '[data-dgb-open]' ).forEach( ( b ) => b.addEventListener( 'click', () => open( b ) ) );
		q( '[data-dgb-close]' ).addEventListener( 'click', close );
		panel.addEventListener( 'keydown', ( e ) => {
			if ( 'Escape' === e.key ) {
				close();
			}
			if ( 'Tab' === e.key && panel.classList.contains( 'is-open' ) ) {
				// Keep focus inside the sheet.
				const focusables = Array.prototype.slice.call( panel.querySelectorAll( 'button:not([disabled]):not([hidden]), input:not([disabled]):not([type="hidden"]), select, textarea, a[href], [tabindex="0"]' ) ).filter( ( n ) => n.offsetParent !== null );
				const first = focusables[ 0 ];
				const last = focusables[ focusables.length - 1 ];
				if ( e.shiftKey && document.activeElement === first ) {
					e.preventDefault();
					last.focus();
				} else if ( ! e.shiftKey && document.activeElement === last ) {
					e.preventDefault();
					first.focus();
				}
			}
		} );
		window.matchMedia( MOBILE ).addEventListener( 'change', layout );

		layout();
		loadMonth();
		update();
		track( 'view_item', { ecommerce: { currency: cfg.currency, value: cfg.analytics.value, items: [ Object.assign( gaItem(), { price: cfg.analytics.value } ) ] } } );
		storeAttribution( cfg.analytics.cookieDays || 90 );
		root.classList.add( 'is-ready' );
	}

	function start() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-dgb-widget]' ), init );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
