/**
 * Digitum Bookings calendar: drag a booking to another day (or session) to move it; close or open a tour's
 * dates and sessions. Both call the staff REST API and reload the view.
 */
( function () {
	const cfg = window.dgbCalendar;
	const root = document.querySelector( '[data-dgb-calendar]' );
	if ( ! cfg || ! root ) {
		return;
	}
	const status = root.querySelector( '[data-dgb-cal-status]' );
	const say = ( text ) => {
		status.textContent = text;
	};

	async function post( path, body ) {
		const res = await fetch( cfg.root + path, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: JSON.stringify( body ),
			credentials: 'same-origin',
		} );
		const data = await res.json().catch( () => ( {} ) );
		if ( ! res.ok ) {
			throw new Error( data.message || res.statusText );
		}
		return data;
	}

	let dragged = null;

	root.addEventListener( 'dragstart', ( e ) => {
		const chip = e.target.closest( '[data-dgb-booking]' );
		if ( ! chip ) {
			return;
		}
		dragged = chip;
		e.dataTransfer.effectAllowed = 'move';
		e.dataTransfer.setData( 'text/plain', chip.getAttribute( 'data-dgb-booking' ) );
		chip.classList.add( 'is-dragging' );
	} );

	root.addEventListener( 'dragend', () => {
		if ( dragged ) {
			dragged.classList.remove( 'is-dragging' );
		}
		dragged = null;
		root.querySelectorAll( '.is-drop-target' ).forEach( ( n ) => n.classList.remove( 'is-drop-target' ) );
	} );

	root.addEventListener( 'dragover', ( e ) => {
		const target = e.target.closest( '[data-dgb-drop]' );
		if ( dragged && target ) {
			e.preventDefault();
			root.querySelectorAll( '.is-drop-target' ).forEach( ( n ) => n !== target && n.classList.remove( 'is-drop-target' ) );
			target.classList.add( 'is-drop-target' );
		}
	} );

	root.addEventListener( 'drop', async ( e ) => {
		const target = e.target.closest( '[data-dgb-drop]' );
		if ( ! dragged || ! target ) {
			return;
		}
		e.preventDefault();
		const id = dragged.getAttribute( 'data-dgb-booking' );
		const date = target.getAttribute( 'data-date' );
		const session = target.getAttribute( 'data-session' ) || dragged.getAttribute( 'data-session' );
		const label = date + ( target.hasAttribute( 'data-session' ) ? ' · ' + target.querySelector( '.dgb-cal-session-name' ).textContent.trim() : '' );
		if ( ! window.confirm( cfg.confirm.replace( '%s', label ) ) ) {
			return;
		}
		try {
			await post( 'bookings/' + id + '/reschedule', { date, session: Number( session ) || 0 } );
			say( cfg.moved );
			window.location.reload();
		} catch ( err ) {
			say( cfg.failed + ' ' + err.message );
		}
	} );

	root.addEventListener( 'click', async ( e ) => {
		const button = e.target.closest( '[data-dgb-close]' );
		if ( ! button ) {
			return;
		}
		button.disabled = true;
		try {
			await post( 'closures', {
				product: Number( root.getAttribute( 'data-product' ) ),
				date: button.getAttribute( 'data-date' ),
				session: Number( button.getAttribute( 'data-session' ) ) || 0,
				closed: '1' === button.getAttribute( 'data-closed' ),
			} );
			window.location.reload();
		} catch ( err ) {
			button.disabled = false;
			say( cfg.failed + ' ' + err.message );
		}
	} );
}() );
