/**
 * Booking settings box: tabs, repeatable rows and pricing-mode fields.
 * Without JavaScript every panel stays visible and rows can still be edited.
 */
( function () {
	'use strict';

	const box = document.querySelector( '[data-dgb-box]' );
	if ( ! box ) {
		return;
	}

	/* Tabs (vertical, arrow keys move between tabs). */
	const tabs = Array.from( box.querySelectorAll( '[role="tab"]' ) );
	const panels = Array.from( box.querySelectorAll( '[role="tabpanel"]' ) );
	const storageKey = 'dgbBookingTab';

	function select( tab, focus ) {
		tabs.forEach( ( t ) => {
			const on = t === tab;
			t.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			t.tabIndex = on ? 0 : -1;
		} );
		panels.forEach( ( p ) => {
			p.hidden = p.id !== tab.getAttribute( 'aria-controls' );
		} );
		if ( focus ) {
			tab.focus();
		}
		try {
			window.sessionStorage.setItem( storageKey, tab.id );
		} catch ( e ) {
			// Storage can be unavailable; the first tab is used then.
		}
	}

	tabs.forEach( ( tab, index ) => {
		tab.addEventListener( 'click', () => select( tab, false ) );
		tab.addEventListener( 'keydown', ( event ) => {
			const keys = { ArrowDown: 1, ArrowRight: 1, ArrowUp: -1, ArrowLeft: -1 };
			if ( event.key in keys ) {
				event.preventDefault();
				select( tabs[ ( index + keys[ event.key ] + tabs.length ) % tabs.length ], true );
			} else if ( 'Home' === event.key ) {
				event.preventDefault();
				select( tabs[ 0 ], true );
			} else if ( 'End' === event.key ) {
				event.preventDefault();
				select( tabs[ tabs.length - 1 ], true );
			}
		} );
	} );

	let initial = tabs[ 0 ];
	try {
		const saved = document.getElementById( window.sessionStorage.getItem( storageKey ) || '' );
		if ( saved && tabs.includes( saved ) ) {
			initial = saved;
		}
	} catch ( e ) {
		// Ignore storage errors.
	}
	if ( initial ) {
		select( initial, false );
	}

	/* Fields that only apply to one pricing mode. */
	const modeInputs = Array.from( box.querySelectorAll( '[data-dgb-mode]' ) );
	const modeFields = Array.from( box.querySelectorAll( '[data-dgb-show-when]' ) );

	function syncMode() {
		const checked = modeInputs.find( ( input ) => input.checked );
		const mode = checked ? checked.value : '';
		modeFields.forEach( ( field ) => {
			field.hidden = field.getAttribute( 'data-dgb-show-when' ) !== mode;
		} );
		modeInputs.forEach( ( input ) => {
			input.closest( '.dgb-card' ).classList.toggle( 'is-selected', input.checked );
		} );
	}
	modeInputs.forEach( ( input ) => input.addEventListener( 'change', syncMode ) );
	syncMode();

	/* Repeatable rows. */
	let counter = Date.now();

	box.querySelectorAll( '[data-dgb-repeater]' ).forEach( ( repeater ) => {
		const body = repeater.querySelector( '[data-dgb-rows]' );
		const template = repeater.querySelector( 'template[data-dgb-template]' );

		repeater.querySelector( '[data-dgb-add]' ).addEventListener( 'click', () => {
			const html = template.innerHTML.replace( /__i__/g, 'n' + counter++ );
			body.insertAdjacentHTML( 'beforeend', html );
			const first = body.lastElementChild.querySelector( 'input, select' );
			if ( first ) {
				first.focus();
			}
		} );

		body.addEventListener( 'click', ( event ) => {
			const button = event.target.closest( 'button' );
			if ( ! button ) {
				return;
			}
			const row = button.closest( '[data-dgb-row]' );
			if ( button.hasAttribute( 'data-dgb-remove' ) ) {
				const next = row.nextElementSibling || row.previousElementSibling;
				row.remove();
				const target = next ? next.querySelector( 'input, select' ) : repeater.querySelector( '[data-dgb-add]' );
				if ( target ) {
					target.focus();
				}
			} else if ( button.hasAttribute( 'data-dgb-up' ) && row.previousElementSibling ) {
				row.parentNode.insertBefore( row, row.previousElementSibling );
				button.focus();
			} else if ( button.hasAttribute( 'data-dgb-down' ) && row.nextElementSibling ) {
				row.parentNode.insertBefore( row.nextElementSibling, row );
				button.focus();
			}
		} );
	} );
}() );
