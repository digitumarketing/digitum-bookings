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

	/* Formatting shared by the widget and the booking card ------------------------------------------- */

	const toDate = ( date ) => new Date( +date.slice( 0, 4 ), +date.slice( 5, 7 ) - 1, +date.slice( 8, 10 ) );

	// Amounts in the site currency; the "whole units" setting drops the decimals of whole amounts.
	function moneyFormatter( cfg ) {
		const money = new Intl.NumberFormat( cfg.locale, {
			style: 'currency',
			currency: cfg.currency,
			minimumFractionDigits: cfg.decimals,
			maximumFractionDigits: cfg.decimals,
		} );
		const whole = cfg.wholeUnits && cfg.decimals > 0 && new Intl.NumberFormat( cfg.locale, {
			style: 'currency',
			currency: cfg.currency,
			minimumFractionDigits: 0,
			maximumFractionDigits: cfg.decimals,
		} );
		return ( v ) => {
			if ( null === v || undefined === v ) {
				return '';
			}
			const n = Number( v );
			return ( whole && 0 === Math.round( n * 10 ** cfg.decimals ) % 10 ** cfg.decimals ? whole : money ).format( n );
		};
	}

	// "Fri 6 Nov 2026" (or "Fri, Nov 6, 2026"), with the site's day and month names: the same text as the emails.
	function dateFormatter( cfg ) {
		const names = cfg.dates || {};
		return {
			format: ( d ) => {
				const day = ( names.days || [] )[ d.getDay() ];
				const month = ( names.months || [] )[ d.getMonth() ];
				if ( ! day || ! month ) {
					return new Intl.DateTimeFormat( 'en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' } ).format( d ).replace( /,/g, '' );
				}
				return 'us' === names.style ? `${ day }, ${ month } ${ d.getDate() }, ${ d.getFullYear() }` : `${ day } ${ d.getDate() } ${ month } ${ d.getFullYear() }`;
			},
		};
	}

	/* Widgets on the page, by tour: the booking card and the modal triggers open them. */
	const widgets = [];
	const findWidget = ( product ) => widgets.find( ( w ) => ! product || String( w.product ) === String( product ) ) || null;
	window.dgbBooking = {
		/**
		 * Open the booking form of a tour (the first one on the page when none is given), optionally filled in.
		 *
		 * @param {number|string} product Tour ID.
		 * @param {Object}        values  variant_id, date, units, adults, children, pickup, zone_id.
		 * @param {Element}       opener  Element that gets the focus back on close.
		 * @return {boolean} Whether a booking form was found.
		 */
		open( product, values, opener ) {
			const w = findWidget( product );
			if ( w ) {
				w.start( values || null, opener || null );
			}
			return !! w;
		},
	};

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
		const panel = root.querySelector( '[data-dgb-panel]' );
		const form = panel.querySelector( '[data-dgb-form]' );
		const body = panel.querySelector( '[data-dgb-body]' );
		// On phones the open sheet moves to <body> (above theme bars and stacking contexts): look in both places.
		const q = ( s ) => root.querySelector( s ) || ( root.contains( panel ) ? null : panel.querySelector( s ) );
		const qa = ( s ) => Array.prototype.slice.call( root.querySelectorAll( s ) ).concat( root.contains( panel ) ? [] : Array.prototype.slice.call( panel.querySelectorAll( s ) ) );
		const track = ( event, data ) => push( cfg.analytics.enabled, event, data );
		const fmt = moneyFormatter( cfg );
		const couponCodes = [ 'coupon_invalid', 'coupon_not_combinable' ];
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
		const shortDate = dateFormatter( cfg );
		const longDate = new Intl.DateTimeFormat( cfg.locale, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' } );

		const state = {
			month: cfg.today.slice( 0, 7 ),
			date: '',
			session: 0,
			sessions: [],
			quote: null,
			step: 0,
			stepped: false,
			mobile: false,
			seq: 0,
			sent: {},
			opener: null,
			busy: false,
			scrollY: 0,
		};
		let dgbNonce = cfg.rest.dgbNonce;
		let wpNonce = cfg.rest.wpNonce;

		/* REST -------------------------------------------------------------- */

		async function api( path, data, retry ) {
			const headers = { 'X-DGB-Nonce': dgbNonce };
			if ( wpNonce ) {
				headers[ 'X-WP-Nonce' ] = wpNonce;
			}
			if ( data ) {
				headers[ 'Content-Type' ] = 'application/json';
			}
			const res = await fetch( cfg.rest.root + path, {
				method: data ? 'POST' : 'GET',
				headers,
				body: data ? JSON.stringify( data ) : undefined,
				credentials: 'same-origin',
			} );
			let json = null;
			try {
				json = await res.json();
			} catch ( e ) {}
			const code = json && json.code;
			if ( 403 === res.status && false !== retry && ( 'dgb_nonce' === code || 'rest_cookie_invalid_nonce' === code ) ) {
				// The page came from a cache: get fresh nonces once and retry.
				if ( 'rest_cookie_invalid_nonce' === code ) {
					wpNonce = '';
				}
				try {
					const s = await fetch( cfg.rest.root + 'session', { credentials: 'same-origin' } ).then( ( r ) => r.json() );
					dgbNonce = s.nonce || dgbNonce;
				} catch ( e ) {}
				return api( path, data, false );
			}
			if ( ! res.ok ) {
				const err = new Error( ( json && json.message ) || t.networkError );
				err.data = json;
				throw err;
			}
			return json;
		}

		/* Values -------------------------------------------------------------- */

		const field = ( name ) => form.elements.namedItem( name );
		const radio = ( name ) => {
			const checked = form.querySelector( 'input[name="' + name + '"]:checked' ) || form.querySelector( 'input[type="hidden"][name="' + name + '"]' );
			return checked ? checked.value : '';
		};
		const num = ( name ) => {
			const input = field( name );
			return input ? Math.max( 0, parseInt( input.value, 10 ) || 0 ) : 0;
		};
		const text = ( name ) => ( field( name ) ? String( field( name ).value || '' ).trim() : '' );
		const variant = () => cfg.variants.find( ( v ) => String( v.id ) === radio( 'variant_id' ) ) || null;
		const pickupOn = () => '1' === radio( 'pickup' );
		const zone = () => cfg.zones.find( ( z ) => String( z.id ) === ( field( 'zone_id' ) || {} ).value ) || null;
		const session = () => state.sessions.find( ( s ) => s.id === state.session ) || null;

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
			let focusable = null;
			Object.keys( data.days ).forEach( ( date ) => {
				const status = data.days[ date ];
				const open = 'open' === status;
				const d = toDate( date );
				const chosen = date === state.date;
				const today = date === cfg.today;
				const btn = el(
					'button',
					{
						type: 'button',
						class: 'dgb-day is-' + status + ( chosen ? ' is-selected' : '' ) + ( today ? ' is-today' : '' ),
						'data-date': date,
						disabled: ! open,
						'aria-pressed': chosen ? 'true' : 'false',
						'aria-label': longDate.format( d ) + ( today ? ', ' + t.today : '' ) + ', ' + ( t.status[ status ] || status ),
						tabindex: '-1',
					},
					[ String( d.getDate() ) ]
				);
				days.appendChild( btn );
				if ( open && ( ! focusable || chosen ) ) {
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
			qa( '.dgb-day' ).forEach( ( b ) => {
				const on = b.getAttribute( 'data-date' ) === date;
				b.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
				b.classList.toggle( 'is-selected', on );
			} );
			q( '[data-dgb-selected]' ).textContent = sprintf( t.selected, shortDate.format( toDate( date ) ) );
			showError( 'date', '' );
			showError( 'session', '' );
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
			renderReview();
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

		// Seat problems belong to the guests of step 1; other price problems are checked step by step, never in the price box.
		const seatError = ( r ) => ( ( r && r.errors ) || [] ).find( ( e ) => 'too_many_guests' === e.code || 'no_guests' === e.code ) || null;

		function renderBreakdown( r ) {
			const box = q( '[data-dgb-breakdown]' );
			const live = q( '[data-dgb-live]' );
			const submitButton = q( '[data-dgb-submit]' );
			box.textContent = '';
			if ( live ) {
				live.textContent = r ? sprintf( t.liveTotal, fmt( r.total ) + second( r.total ) ) : '';
			}
			if ( ! r ) {
				submitButton.textContent = t.book;
				return;
			}
			const seats = seatError( r );
			showError( 'guests', seats ? seats.message || t.addGuest : '' );

			const arrival = 'arrival' === radio( 'payment_method' );
			const rows = r.lines.map( ( line ) => {
				let amount = fmt( line.amount );
				if ( 'quote' === line.type ) {
					amount = t.onRequest;
				} else if ( 'free' === line.type ) {
					amount = t.free;
				} else if ( 'included' === line.type ) {
					amount = t.included;
				}
				const label = line.quantity > 1 ? line.label + ' × ' + line.quantity : line.label;
				return el( 'div', { class: 'dgb-line is-' + line.type }, [ el( 'dt', { text: label } ), el( 'dd', { text: amount } ) ] );
			} );
			const list = el( 'dl', { class: 'dgb-lines' }, rows );
			list.appendChild( el( 'div', { class: 'dgb-line is-total' }, [ el( 'dt', { text: t.total } ), el( 'dd', { text: fmt( r.total ) + second( r.total ) } ) ] ) );
			if ( Number( r.vat_rate ) > 0 ) {
				list.appendChild(
					r.vat_inclusive
						? el( 'div', { class: 'dgb-line is-vat' }, [ el( 'dt', { text: sprintf( t.pricesIncludeVat, r.vat_rate + '%' ) } ) ] )
						: el( 'div', { class: 'dgb-line is-vat' }, [ el( 'dt', { text: sprintf( t.plusVat, r.vat_rate + '%' ) } ), el( 'dd', { text: fmt( r.vat ) } ) ] )
				);
			}
			// Pay on arrival: the amount is said once, in the payment line under the price.
			if ( ! arrival && Number( r.due_on_arrival ) > 0 ) {
				list.appendChild( el( 'div', { class: 'dgb-line' }, [ el( 'dt', { text: t.payNow } ), el( 'dd', { text: fmt( r.pay_now ) } ) ] ) );
				list.appendChild( el( 'div', { class: 'dgb-line' }, [ el( 'dt', { text: t.dueOnArrival } ), el( 'dd', { text: fmt( r.due_on_arrival ) } ) ] ) );
			}
			box.appendChild( list );
			payLine();
			( r.hint_texts || [] ).forEach( ( hint ) => box.appendChild( el( 'p', { class: 'dgb-offer-hint', text: hint } ) ) );

			const couponStatus = q( '[data-dgb-coupon-status]' );
			if ( couponStatus && state.coupon ) {
				const bad = ( r.errors || [] ).find( ( e ) => couponCodes.includes( e.code ) );
				couponStatus.textContent = bad ? bad.message : sprintf( t.couponApplied, state.coupon );
				couponStatus.classList.toggle( 'is-error', !! bad );
				if ( bad ) {
					// Price without the code, so the guest can still book.
					state.coupon = '';
					update();
				}
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
			if ( arrival ) {
				submitButton.textContent = t.bookArrival;
			} else if ( 'bank_transfer' === method ) {
				submitButton.textContent = sprintf( t.bookTransfer, fmt( r.pay_now ) );
			} else if ( method ) {
				submitButton.textContent = sprintf( t.bookPay, fmt( r.pay_now ) );
			} else {
				submitButton.textContent = t.book;
			}
		}

		/* Review summary ------------------------------------------------------------ */

		function renderReview() {
			const box = q( '[data-dgb-review]' );
			if ( ! box ) {
				return;
			}
			const rows = [ [ t.tour, cfg.product.name ] ];
			const v = variant();
			if ( v && cfg.variants.length > 1 ) {
				rows.push( [ t.option, v.label ] );
			}
			if ( state.date ) {
				rows.push( [ t.date, shortDate.format( toDate( state.date ) ) ] );
			}
			const s = session();
			if ( s ) {
				rows.push( [ t.session, s.name + ( s.start_time ? ' · ' + sprintf( t.starts, fmtTime( s.start_time ) ) : '' ) ] );
			}
			const people = [];
			if ( num( 'units' ) ) {
				people.push( sprintf( t.nUnits, String( num( 'units' ) ) ) );
			}
			[ [ 'adults', t.nAdults ], [ 'children', t.nChildren ], [ 'infants', t.nInfants ] ].forEach( ( pair ) => {
				if ( num( pair[ 0 ] ) ) {
					people.push( sprintf( pair[ 1 ], String( num( pair[ 0 ] ) ) ) );
				}
			} );
			rows.push( [ t.guests, people.join( ', ' ) ] );
			if ( q( '[data-dgb-pickup-group]' ) ) {
				if ( pickupOn() ) {
					const z = zone();
					rows.push( [ t.pickup, [ z ? sprintf( t.withZone, t.hotelPickup, z.name ) : t.hotelPickup, text( 'hotel' ), text( 'room' ) ].filter( Boolean ).join( ', ' ) ] );
				} else {
					rows.push( [ t.pickup, cfg.pointName ? sprintf( t.meetAt, cfg.pointName ) : t.meetPoint ] );
				}
			}
			box.textContent = '';
			rows.forEach( ( row ) => {
				box.appendChild( el( 'dt', { text: row[ 0 ] } ) );
				box.appendChild( el( 'dd', { text: row[ 1 ] } ) );
			} );
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
				const z = zone();
				const blocked = !! ( z && ! z.allowed );
				note.hidden = ! blocked;
				note.textContent = blocked ? ( z.notes || t.chooseZone ) : '';
				qa( '[data-dgb-hotel]' ).forEach( ( n ) => ( n.hidden = blocked ) );
			}
			const windowText = q( '[data-dgb-window]' );
			const s = session();
			if ( windowText ) {
				windowText.textContent = s && s.pickup_window_start ? sprintf( t.pickupWindow, fmtTime( s.pickup_window_start ) + '–' + fmtTime( s.pickup_window_end ) ) : '';
			}
			// Pay on arrival: where the guest pays depends on pickup.
			qa( '[data-dgb-method-note="arrival"]' ).forEach( ( n ) => ( n.textContent = on ? t.arrivalPickup : t.arrivalMeet ) );
			payLine();
		}

		// The one payment line when pay on arrival is the only method: "Pay AED 899 on the day, cash or card, …".
		function payLine() {
			const line = q( '[data-dgb-pay-line]' );
			const r = state.quote;
			if ( line && r && variant() && null !== r.total && undefined !== r.total ) {
				line.textContent = sprintf( pickupOn() ? t.payLinePickup : t.payLineMeet, fmt( r.total ) );
			}
		}

		/* Errors (always under their field, only for the step being checked) ------------------- */

		const fieldFor = { name: 'name', email: 'email', phone: 'phone', hotel: 'hotel', zone: 'zone_id', terms: 'terms' };
		const ERROR_KEYS = [ 'variant', 'date', 'session', 'guests', 'zone', 'hotel', 'name', 'phone', 'email', 'payment_method', 'terms' ];

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

		function check( name ) {
			const errors = {};
			if ( 'trip' === name ) {
				if ( cfg.variants.length && ! variant() ) {
					errors.variant = t.chooseOption;
				}
				if ( ! state.date ) {
					errors.date = t.chooseSession;
				} else if ( state.sessions.length && ! state.session ) {
					errors.session = t.chooseSession;
				}
				const seats = seatError( state.quote );
				if ( num( 'adults' ) + num( 'children' ) < 1 ) {
					errors.guests = t.addGuest;
				} else if ( seats && ! cfg.product.onRequest ) {
					errors.guests = seats.message || t.addGuest;
				}
			} else if ( 'details' === name ) {
				if ( pickupOn() && field( 'zone_id' ) ) {
					const z = zone();
					if ( ! z || ! z.allowed ) {
						errors.zone = z ? z.notes || t.chooseZone : t.chooseZone;
					}
					if ( ( ! z || z.allowed ) && text( 'hotel' ).length < 3 ) {
						errors.hotel = t.hotel;
					}
				}
				if ( text( 'name' ).length < 2 ) {
					errors.name = t.nameRequired;
				}
				const code = text( 'phone_code' ).replace( /\D/g, '' );
				const phone = text( 'phone' ).replace( /\D/g, '' ).replace( /^0+/, '' );
				if ( ! code ) {
					errors.phone = t.phoneCode;
				} else if ( phone.length < 6 || phone.length > 14 || ( code + phone ).length > 15 ) {
					errors.phone = t.phoneDigits;
				}
				if ( ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( text( 'email' ) ) ) {
					errors.email = t.emailInvalid;
				}
			} else if ( 'review' === name ) {
				if ( ! cfg.product.onRequest && ! radio( 'payment_method' ) ) {
					errors.payment_method = t.choosePayment;
				}
				if ( ! field( 'terms' ).checked ) {
					errors.terms = t.terms;
				}
			}
			return errors;
		}

		function validate( index ) {
			const step = steps()[ index ];
			const errors = check( step.getAttribute( 'data-step' ) );
			ERROR_KEYS.forEach( ( key ) => {
				if ( stepOf( key ) === index ) {
					showError( key, errors[ key ] || '' );
				}
			} );
			return errors;
		}

		function focusFirstError( index ) {
			const step = steps()[ index ];
			const box = Array.prototype.slice.call( step.querySelectorAll( '.dgb-field-error' ) ).find( ( n ) => ! n.hidden );
			if ( ! box ) {
				return;
			}
			const group = box.closest( '.dgb-field, .dgb-group' ) || step;
			const target = step.querySelector( '[aria-invalid="true"]' ) ||
				group.querySelector( '.dgb-day[tabindex="0"], input:not([type="hidden"]):not(:disabled), select, textarea' );
			box.scrollIntoView( { block: 'center' } );
			if ( target ) {
				target.focus( { preventScroll: true } );
			}
		}

		function summary( messages ) {
			const box = q( '[data-dgb-errors]' );
			box.textContent = '';
			box.hidden = ! messages.length;
			if ( messages.length ) {
				box.appendChild( el( 'ul', {}, messages.map( ( m ) => el( 'li', { text: m } ) ) ) );
				box.focus();
			}
		}

		/* Steps and sheet ------------------------------------------------------------ */

		const steps = () => qa( '.dgb-step' );

		function layout() {
			state.mobile = window.matchMedia( MOBILE ).matches;
			state.stepped = state.mobile || !! cfg.stepped || !! cfg.modal;
			[ root, panel.parentNode ].forEach( ( n ) => n.classList && n.classList.toggle( 'is-stepped', state.stepped ) );
			if ( ! state.mobile && ! cfg.modal ) {
				close();
			}
			showStep( state.step, false );
		}

		function showStep( index, focus ) {
			const all = steps();
			state.step = Math.max( 0, Math.min( index, all.length - 1 ) );
			all.forEach( ( s, i ) => s.classList.toggle( 'is-current', ! state.stepped || i === state.step ) );
			const last = state.step === all.length - 1;
			q( '[data-dgb-back]' ).hidden = ! state.stepped || 0 === state.step;
			q( '[data-dgb-next]' ).hidden = ! state.stepped || last;
			q( '[data-dgb-submit]' ).hidden = state.stepped && ! last;
			q( '[data-dgb-progress]' ).textContent = state.stepped ? sprintf( t.stepOf, String( state.step + 1 ), String( all.length ), all[ state.step ].getAttribute( 'data-name' ) ) : '';
			summary( [] );
			if ( focus ) {
				body.scrollTop = 0;
				all[ state.step ].querySelector( '.dgb-legend' ).focus( { preventScroll: ! state.mobile } );
				if ( ! state.mobile ) {
					const rect = panel.getBoundingClientRect();
					if ( rect.top < 0 ) {
						panel.scrollIntoView( { block: 'start' } );
					}
				}
			}
			if ( 'details' === all[ state.step ].getAttribute( 'data-step' ) && state.stepped ) {
				beginCheckout();
			}
		}

		// The open sheet lives in a wrapper on <body>, so no theme container, transform or sticky bar sits above it.
		const home = document.createComment( 'dgb-panel' );
		let portal = null;

		function fitViewport() {
			const vv = window.visualViewport;
			const height = vv ? vv.height : window.innerHeight;
			const bottom = vv ? Math.max( 0, window.innerHeight - vv.height - vv.offsetTop ) : 0;
			panel.style.setProperty( '--dgb-vvh', height + 'px' );
			panel.style.setProperty( '--dgb-vvb', bottom + 'px' );
		}

		function open( opener ) {
			if ( ( ! state.mobile && ! cfg.modal ) || panel.classList.contains( 'is-open' ) ) {
				return;
			}
			state.opener = opener || null;
			if ( ! portal ) {
				portal = el( 'div', { class: root.className + ' dgb-portal', style: root.getAttribute( 'style' ), dir: root.getAttribute( 'dir' ) || document.documentElement.getAttribute( 'dir' ) } );
				// In modal mode the wrapper is the backdrop: a click outside the dialog closes it.
				portal.addEventListener( 'click', ( e ) => e.target === portal && close() );
			}
			portal.className = root.className + ' dgb-portal' + ( cfg.modal ? ' is-modal-open' : '' );
			panel.parentNode.insertBefore( home, panel );
			portal.appendChild( panel );
			document.body.appendChild( portal );
			// Lock the page where it is (no jump): the body is fixed at the current scroll position.
			state.scrollY = window.scrollY;
			document.documentElement.style.setProperty( '--dgb-scroll-y', -state.scrollY + 'px' );
			document.documentElement.classList.add( 'dgb-lock' );
			fitViewport();
			panel.classList.add( 'is-open' );
			panel.setAttribute( 'role', 'dialog' );
			panel.setAttribute( 'aria-modal', 'true' );
			panel.setAttribute( 'aria-labelledby', cfg.id + '-title' );
			q( '.dgb-title' ).focus();
			document.documentElement.classList.add( 'dgb-modal-open' );
		}

		function close() {
			if ( ! panel.classList.contains( 'is-open' ) ) {
				return;
			}
			panel.classList.remove( 'is-open' );
			panel.removeAttribute( 'role' );
			panel.removeAttribute( 'aria-modal' );
			if ( home.parentNode ) {
				home.parentNode.insertBefore( panel, home );
				home.parentNode.removeChild( home );
			}
			if ( portal && portal.parentNode ) {
				portal.parentNode.removeChild( portal );
			}
			document.documentElement.classList.remove( 'dgb-lock', 'dgb-modal-open' );
			window.scrollTo( 0, state.scrollY );
			if ( state.opener ) {
				state.opener.focus( { preventScroll: true } );
			}
		}

		if ( window.visualViewport ) {
			window.visualViewport.addEventListener( 'resize', () => panel.classList.contains( 'is-open' ) && fitViewport() );
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
					if ( state.stepped ) {
						showStep( i, false );
					}
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
				let result = q( '[data-dgb-result]' );
				if ( cfg.modal && panel.classList.contains( 'is-open' ) ) {
					// Modal: the confirmation (and any payment box) replaces the form inside the dialog.
					result = el( 'div', { class: 'dgb-modal-result', 'data-dgb-modal-result': '' } );
					form.hidden = true;
					q( '[data-dgb-progress]' ).textContent = '';
					panel.appendChild( result );
					result.innerHTML = res.html;
				} else {
					close();
					result.innerHTML = res.html;
					panel.hidden = true;
				}
				qa( '.dgb-teaser, [data-dgb-sticky]' ).forEach( ( n ) => ( n.hidden = true ) );
				document.body.classList.remove( 'dgb-has-sticky-bar' );
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
				let first = -1;
				if ( fields ) {
					Object.keys( fields ).forEach( ( key ) => {
						const name = key.replace( /^price_/, '' );
						if ( ERROR_KEYS.includes( name ) && showError( name, fields[ key ] ) ) {
							first = first < 0 ? stepOf( name ) : Math.min( first, stepOf( name ) );
						} else {
							messages.push( fields[ key ] );
						}
					} );
				} else {
					messages.push( err.message || t.networkError );
				}
				if ( first >= 0 ) {
					if ( state.stepped ) {
						showStep( first, false );
					}
					focusFirstError( first );
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
				showError( 'hotel', '' );
			}
			if ( 'terms' === name ) {
				showError( 'terms', '' );
			}
			update();
		} );

		form.addEventListener( 'input', ( e ) => {
			const name = e.target.name;
			if ( 'phone' === name || 'phone_code' === name ) {
				// Digits only.
				const clean = e.target.value.replace( /\D/g, '' );
				if ( clean !== e.target.value ) {
					e.target.value = clean;
				}
				showError( 'phone', '' );
			}
			if ( 'number' === e.target.type ) {
				update();
			}
			const key = Object.keys( fieldFor ).find( ( k ) => fieldFor[ k ] === name );
			if ( key ) {
				showError( key, '' );
			}
			if ( 'hotel' === name ) {
				renderReview();
			}
		} );

		form.addEventListener( 'focusin', ( e ) => {
			const step = e.target.closest( '.dgb-step' );
			if ( step && 'details' === step.getAttribute( 'data-step' ) ) {
				beginCheckout();
			}
			// Phones: keep the focused field clear of the keyboard and the footer.
			if ( panel.classList.contains( 'is-open' ) && e.target.matches( 'input, select, textarea' ) ) {
				window.setTimeout( () => e.target.scrollIntoView( { block: 'center' } ), 300 );
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
				const focusables = Array.prototype.slice.call( panel.querySelectorAll( 'button:not([disabled]):not([hidden]), input:not([disabled]):not([type="hidden"]), select, textarea, summary, a[href], [tabindex="0"]' ) ).filter( ( n ) => n.offsetParent !== null );
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

		/* Modal: open from the card, a trigger or ?book=1, optionally filled in --------------- */

		async function prefill( values ) {
			const set = ( name, value ) => {
				const input = field( name );
				if ( input && undefined !== value && null !== value && '' !== value ) {
					const min = parseInt( input.min, 10 ) || 0;
					const max = parseInt( input.max, 10 ) || 50;
					input.value = String( Math.max( min, Math.min( max, parseInt( value, 10 ) || 0 ) ) );
				}
			};
			if ( values.variant_id ) {
				const option = form.querySelector( 'input[name="variant_id"][value="' + Number( values.variant_id ) + '"]' );
				if ( option ) {
					option.checked = true;
				}
			}
			set( 'units', values.units );
			set( 'adults', values.adults );
			set( 'children', values.children );
			if ( undefined !== values.pickup ) {
				const choice = form.querySelector( 'input[name="pickup"][value="' + ( values.pickup ? '1' : '0' ) + '"]' );
				if ( choice ) {
					choice.checked = true;
				}
				if ( values.pickup && values.zone_id && field( 'zone_id' ) ) {
					field( 'zone_id' ).value = String( values.zone_id );
				}
			}
			syncCards();
			if ( values.date && /^\d{4}-\d{2}-\d{2}$/.test( values.date ) && values.date !== state.date ) {
				if ( state.month !== values.date.slice( 0, 7 ) ) {
					state.month = values.date.slice( 0, 7 );
					await loadMonth();
				}
				const day = q( '.dgb-day[data-date="' + values.date + '"]' );
				if ( day && ! day.disabled ) {
					await chooseDate( values.date );
				}
			}
			update();
			if ( ! cfg.product.onRequest && variant() ) {
				window.clearTimeout( timer );
				await quote();
			}
		}

		// Called by window.dgbBooking.open(): fill in, open, and go to step 2 when step 1 is complete.
		async function start( values, opener ) {
			if ( values ) {
				await prefill( values );
			}
			if ( ! state.mobile && ! cfg.modal ) {
				root.scrollIntoView( { block: 'start' } );
				return;
			}
			open( opener );
			if ( values ) {
				if ( 0 === Object.keys( check( 'trip' ) ).length ) {
					showStep( 1, true );
				} else {
					showStep( 0, false );
					validate( 0 );
					focusFirstError( 0 );
				}
			}
		}

		widgets.push( { product: cfg.product.id, start } );

		layout();
		loadMonth();
		update();
		track( 'view_item', { ecommerce: { currency: cfg.currency, value: cfg.analytics.value, items: [ Object.assign( gaItem(), { price: cfg.analytics.value } ) ] } } );
		storeAttribution( cfg.analytics.cookieDays || 90 );
		root.classList.add( 'is-ready' );
	}

	/* Booking card --------------------------------------------------------------------------------- */

	function initCard( root ) {
		const cfgNode = root.querySelector( '[data-dgb-card-config]' );
		if ( ! cfgNode || root.classList.contains( 'is-ready' ) ) {
			return;
		}
		const cfg = JSON.parse( cfgNode.textContent );
		const t = cfg.i18n;
		const form = root.querySelector( '[data-dgb-card-form]' );
		const fmt = moneyFormatter( cfg );
		const dateText = dateFormatter( cfg );
		const longDate = new Intl.DateTimeFormat( cfg.locale, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' } );
		const dateButton = root.querySelector( '[data-dgb-card-date]' );
		const pop = root.querySelector( '[data-dgb-card-pop]' );
		const grid = root.querySelector( '[data-dgb-card-days]' );
		const state = { date: '', month: cfg.today.slice( 0, 7 ), seq: 0 };
		const field = ( name ) => form.elements.namedItem( name );
		const num = ( name ) => ( field( name ) ? Math.max( 0, parseInt( field( name ).value, 10 ) || 0 ) : 0 );
		const variantId = () => {
			const checked = form.querySelector( 'input[name="variant_id"]:checked' ) || form.querySelector( 'input[type="hidden"][name="variant_id"]' );
			return checked ? Number( checked.value ) : 0;
		};
		const variantLabel = () => {
			const checked = form.querySelector( 'input[name="variant_id"]:checked' );
			const name = checked && checked.closest( 'label' ).querySelector( '.dgb-bc-pill-name' );
			return name ? name.textContent.trim() : '';
		};
		const pickup = () => {
			const select = form.querySelector( '[data-dgb-card-pickup]' );
			return cfg.pickups[ select ? Number( select.value ) : 0 ] || null;
		};

		// Values for the booking form, as the modal expects them.
		function values() {
			const p = pickup();
			const units = 'per_vehicle' === cfg.mode ? Math.max( 1, num( 'units' ) ) : 0;
			return {
				variant_id: variantId(),
				date: state.date,
				units: units || undefined,
				// Per vehicle: full vehicles to start with; the guest can change the number of people in the form.
				adults: units ? units * cfg.seats : Math.max( 1, num( 'adults' ) ),
				children: units ? 0 : num( 'children' ),
				pickup: !! ( p && 'zone' === p.value ),
				zone_id: p && 'zone' === p.value ? p.zone : 0,
			};
		}

		/* Live total, from the same calculator as the booking form. */
		async function total() {
			const out = root.querySelector( '[data-dgb-card-total]' );
			if ( ! out || cfg.onRequest ) {
				return;
			}
			const v = values();
			const seq = ++state.seq;
			try {
				const res = await fetch( cfg.rest + 'quote', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					credentials: 'same-origin',
					body: JSON.stringify( Object.assign( { product: cfg.product, units: v.units || 1 }, v ) ),
				} );
				const r = await res.json();
				if ( seq !== state.seq || ! res.ok ) {
					return;
				}
				out.textContent = null === r.total || undefined === r.total ? t.onRequest : fmt( r.total );
				let label = t.total;
				if ( Number( r.vat_rate ) > 0 ) {
					label = r.vat_inclusive ? t.totalIncl : t.totalExcl;
				}
				root.querySelector( '[data-dgb-card-total-label]' ).textContent = label;
			} catch ( e ) {}
		}

		/* WhatsApp link with the choices so far. */
		function whatsapp() {
			const link = root.querySelector( '[data-dgb-card-wa]' );
			if ( ! link || ! cfg.whatsapp ) {
				return;
			}
			const v = values();
			const parts = [];
			if ( state.date ) {
				parts.push( sprintf( t.waDate, dateText.format( toDate( state.date ) ) ) );
			}
			if ( variantLabel() ) {
				parts.push( sprintf( t.waOption, variantLabel() ) );
			}
			if ( v.units ) {
				parts.push( sprintf( t.waQuantity, sprintf( 1 === v.units ? t.unit : t.units, String( v.units ) ) ) );
			} else {
				parts.push( sprintf( t.adults, String( v.adults ) ) + ( v.children ? ', ' + sprintf( t.children, String( v.children ) ) : '' ) );
			}
			const p = pickup();
			if ( p ) {
				parts.push( sprintf( t.waPickup, p.text ) );
			}
			const message = t.waText.replace( '{tour}', cfg.name ).replace( '{details}', parts.join( ', ' ) + '.' );
			link.href = 'https://wa.me/' + cfg.whatsapp + '?text=' + encodeURIComponent( message );
		}

		function changed() {
			root.querySelectorAll( '.dgb-bc-pill' ).forEach( ( pill ) => pill.classList.toggle( 'is-selected', pill.querySelector( 'input' ).checked ) );
			whatsapp();
			total();
		}

		/* Date popover: the plugin's calendar with the same day states as the booking form. */
		async function month() {
			const parts = state.month.split( '-' ).map( Number );
			const first = new Date( parts[ 0 ], parts[ 1 ] - 1, 1 );
			root.querySelector( '[data-dgb-card-month]' ).textContent = new Intl.DateTimeFormat( cfg.locale, { month: 'long', year: 'numeric' } ).format( first );
			root.querySelector( '[data-dgb-card-prev]' ).disabled = state.month <= cfg.today.slice( 0, 7 );
			let data = { days: {} };
			try {
				data = await fetch( cfg.rest + 'availability?product=' + cfg.product + '&month=' + state.month, { credentials: 'same-origin' } ).then( ( r ) => r.json() );
			} catch ( e ) {}
			grid.textContent = '';
			t.weekdays.forEach( ( w ) => grid.appendChild( el( 'span', { class: 'dgb-cal-wd', 'aria-hidden': 'true', text: w } ) ) );
			for ( let i = 0; i < ( first.getDay() + 6 ) % 7; i++ ) {
				grid.appendChild( el( 'span', { class: 'dgb-cal-blank', 'aria-hidden': 'true' } ) );
			}
			let focusable = null;
			Object.keys( data.days || {} ).forEach( ( date ) => {
				const status = data.days[ date ];
				const open = 'open' === status;
				const chosen = date === state.date;
				const today = date === cfg.today;
				const btn = el(
					'button',
					{
						type: 'button',
						class: 'dgb-day is-' + status + ( chosen ? ' is-selected' : '' ) + ( today ? ' is-today' : '' ),
						'data-date': date,
						disabled: ! open,
						'aria-pressed': chosen ? 'true' : 'false',
						'aria-label': longDate.format( toDate( date ) ) + ( today ? ', ' + t.today : '' ) + ', ' + ( t.status[ status ] || status ),
						tabindex: '-1',
					},
					[ String( toDate( date ).getDate() ) ]
				);
				grid.appendChild( btn );
				if ( open && ( ! focusable || chosen ) ) {
					focusable = btn;
				}
			} );
			if ( focusable ) {
				focusable.tabIndex = 0;
			}
			return focusable;
		}

		function closePop( focus ) {
			if ( pop.hidden ) {
				return;
			}
			pop.hidden = true;
			dateButton.setAttribute( 'aria-expanded', 'false' );
			if ( focus ) {
				dateButton.focus();
			}
		}

		async function openPop() {
			pop.classList.remove( 'is-up' );
			pop.hidden = false;
			dateButton.setAttribute( 'aria-expanded', 'true' );
			const day = await month();
			// Open above the field when the calendar would end below the screen and there is room above.
			const field = dateButton.getBoundingClientRect();
			const height = pop.getBoundingClientRect().height;
			if ( field.bottom + height + 8 > window.innerHeight && field.top - height - 8 > 0 ) {
				pop.classList.add( 'is-up' );
			}
			if ( day ) {
				day.focus();
			}
		}

		dateButton.addEventListener( 'click', () => ( pop.hidden ? openPop() : closePop( true ) ) );
		root.querySelector( '[data-dgb-card-prev]' ).addEventListener( 'click', () => {
			const p = state.month.split( '-' ).map( Number );
			const d = new Date( p[ 0 ], p[ 1 ] - 2, 1 );
			state.month = d.getFullYear() + '-' + String( d.getMonth() + 1 ).padStart( 2, '0' );
			month();
		} );
		root.querySelector( '[data-dgb-card-next]' ).addEventListener( 'click', () => {
			const p = state.month.split( '-' ).map( Number );
			const d = new Date( p[ 0 ], p[ 1 ], 1 );
			state.month = d.getFullYear() + '-' + String( d.getMonth() + 1 ).padStart( 2, '0' );
			month();
		} );
		grid.addEventListener( 'click', ( e ) => {
			const btn = e.target.closest( '.dgb-day' );
			if ( ! btn || btn.disabled ) {
				return;
			}
			state.date = btn.getAttribute( 'data-date' );
			root.querySelector( '[data-dgb-card-date-text]' ).textContent = dateText.format( toDate( state.date ) );
			dateButton.classList.add( 'has-value' );
			dateButton.classList.remove( 'is-missing' );
			closePop( true );
			changed();
		} );
		grid.addEventListener( 'keydown', ( e ) => {
			const rtl = 'rtl' === window.getComputedStyle( grid ).direction;
			const moves = { ArrowLeft: rtl ? 1 : -1, ArrowRight: rtl ? -1 : 1, ArrowUp: -7, ArrowDown: 7 };
			if ( ! ( e.key in moves ) ) {
				return;
			}
			const all = Array.prototype.slice.call( grid.querySelectorAll( '.dgb-day' ) );
			const current = all.indexOf( document.activeElement );
			if ( current < 0 ) {
				return;
			}
			e.preventDefault();
			for ( let i = current + moves[ e.key ]; i >= 0 && i < all.length; i += moves[ e.key ] ) {
				if ( ! all[ i ].disabled ) {
					all.forEach( ( b ) => ( b.tabIndex = -1 ) );
					all[ i ].tabIndex = 0;
					all[ i ].focus();
					break;
				}
			}
		} );
		pop.addEventListener( 'keydown', ( e ) => {
			if ( 'Escape' === e.key ) {
				e.stopPropagation();
				closePop( true );
			}
		} );
		document.addEventListener( 'click', ( e ) => {
			if ( ! pop.hidden && ! pop.contains( e.target ) && ! dateButton.contains( e.target ) ) {
				closePop( false );
			}
		} );

		/* Steppers, options and pickup. */
		form.addEventListener( 'click', ( e ) => {
			const btn = e.target.closest( '[data-dgb-card-inc], [data-dgb-card-dec]' );
			if ( ! btn ) {
				return;
			}
			const input = field( btn.getAttribute( 'data-dgb-card-inc' ) || btn.getAttribute( 'data-dgb-card-dec' ) );
			const min = parseInt( input.min, 10 ) || 0;
			const max = parseInt( input.max, 10 ) || 50;
			input.value = String( Math.max( min, Math.min( max, ( parseInt( input.value, 10 ) || 0 ) + ( btn.hasAttribute( 'data-dgb-card-inc' ) ? 1 : -1 ) ) ) );
			changed();
		} );
		form.addEventListener( 'change', changed );

		/* Check availability: the booking form opens with these values (step 2 when the trip is complete). */
		form.addEventListener( 'submit', ( e ) => {
			e.preventDefault();
			const button = root.querySelector( '[data-dgb-card-book]' );
			if ( ! state.date ) {
				dateButton.classList.add( 'is-missing' );
			}
			if ( ! window.dgbBooking.open( cfg.product, values(), button ) ) {
				window.location.href = cfg.url + ( cfg.url.indexOf( '?' ) < 0 ? '?' : '&' ) + 'book=1';
			}
		} );

		// Sticky card: when it is taller than the screen below the sticky offset, it sticks by its bottom edge.
		const holder = root.closest( '.elementor-widget-dgb_booking_card' ) || root;
		const fit = () => {
			if ( ! root.classList.contains( 'is-sticky' ) ) {
				return;
			}
			holder.style.setProperty( '--dgb-sticky-fit', Math.round( window.innerHeight - holder.getBoundingClientRect().height - 16 ) + 'px' );
		};
		fit();
		window.addEventListener( 'resize', fit );
		if ( window.ResizeObserver ) {
			new window.ResizeObserver( fit ).observe( holder );
		}

		changed();
		root.classList.add( 'is-ready' );
	}

	function start() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-dgb-widget]' ), init );
		Array.prototype.forEach.call( document.querySelectorAll( '[data-dgb-card]' ), initCard );

		// Triggers anywhere on the page: links to #book or #dgb-book, class dgb-open, data-dgb-open="{tour ID}".
		document.addEventListener( 'click', ( e ) => {
			if ( e.defaultPrevented || e.button || e.metaKey || e.ctrlKey || e.shiftKey ) {
				return;
			}
			const trigger = e.target.closest( 'a[href="#book"], a[href="#dgb-book"], .dgb-open, [data-dgb-open]' );
			if ( ! trigger || trigger.closest( '[data-dgb-widget], .dgb-portal' ) ) {
				return;
			}
			const product = trigger.getAttribute( 'data-dgb-open' ) || '';
			if ( window.dgbBooking.open( product, null, trigger ) ) {
				e.preventDefault();
			}
		} );
		try {
			if ( '1' === new URLSearchParams( window.location.search ).get( 'book' ) ) {
				window.dgbBooking.open( '', null, null );
			}
		} catch ( e ) {}
		document.dispatchEvent( new CustomEvent( 'dgb:ready' ) );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
