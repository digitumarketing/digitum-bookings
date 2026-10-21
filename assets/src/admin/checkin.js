/**
 * Check-in buttons without a page reload (the forms still work on their own).
 */
( function () {
	const cfg = window.dgbCheckin;
	const root = document.querySelector( '[data-dgb-checkin]' );
	if ( ! cfg || ! root || ! window.fetch ) {
		return;
	}
	const status = root.querySelector( '[data-dgb-checkin-status]' );

	root.addEventListener( 'submit', async ( e ) => {
		const form = e.target.closest( '[data-dgb-checkin-form]' );
		if ( ! form ) {
			return;
		}
		e.preventDefault();
		const card = form.closest( '.dgb-checkin-card' );
		const id = form.querySelector( 'input[name="booking"]' ).value;
		const button = form.querySelector( 'button' );
		button.disabled = true;
		try {
			const res = await fetch( cfg.root + 'bookings/' + id + '/status', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
				body: JSON.stringify( { action: form.getAttribute( 'data-action' ) } ),
				credentials: 'same-origin',
			} );
			const data = await res.json().catch( () => ( {} ) );
			if ( ! res.ok ) {
				throw new Error( data.message || res.statusText );
			}
			card.className = card.className.replace( /\bis-[a-z_]+/g, '' ).trim() + ' is-' + data.status;
			const state = card.querySelector( '[data-dgb-state]' );
			state.className = 'dgb-status is-' + data.status;
			state.textContent = cfg.labels[ data.status ] || data.status;
			card.querySelectorAll( '[data-dgb-checkin-form]' ).forEach( ( f ) => {
				f.hidden = ( cfg.available[ data.status ] || [] ).indexOf( f.getAttribute( 'data-action' ) ) < 0;
			} );
			status.textContent = cfg.saved.replace( '%s', data.reference );
			const next = card.querySelector( '[data-dgb-checkin-form]:not([hidden]) button' );
			if ( next ) {
				next.focus();
			}
		} catch ( err ) {
			status.textContent = err.message;
		} finally {
			button.disabled = false;
		}
	} );
}() );
