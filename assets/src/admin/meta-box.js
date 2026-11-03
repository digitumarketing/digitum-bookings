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
			field.hidden = ! field.getAttribute( 'data-dgb-show-when' ).split( ' ' ).includes( mode );
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

	/* Tour gallery box: add from the Media Library, captions, order, remove. */
	const gallery = document.querySelector( '[data-dgb-gallery-box]' );
	if ( gallery ) {
		const list = gallery.querySelector( '[data-dgb-gallery-list]' );
		const ids = gallery.querySelector( '[data-dgb-gallery-ids]' );
		const add = gallery.querySelector( '[data-dgb-gallery-add]' );
		const max = Number( gallery.getAttribute( 'data-max' ) ) || 5;
		const sync = () => {
			ids.value = Array.from( list.children ).map( ( li ) => li.getAttribute( 'data-id' ) ).join( ',' );
			add.disabled = list.children.length >= max;
		};
		const item = ( attachment ) => {
			const li = document.createElement( 'li' );
			li.setAttribute( 'data-id', String( attachment.id ) );
			const sizes = attachment.sizes || {};
			const img = document.createElement( 'img' );
			img.src = ( sizes.thumbnail || sizes.full || {} ).url || attachment.url;
			img.alt = attachment.alt || '';
			img.width = 150;
			img.height = 150;
			const label = document.createElement( 'label' );
			const input = document.createElement( 'input' );
			input.type = 'text';
			input.name = 'dgb[gallery_captions][' + attachment.id + ']';
			input.maxLength = 80;
			input.placeholder = gallery.getAttribute( 'data-caption-label' );
			input.setAttribute( 'aria-label', gallery.getAttribute( 'data-caption-label' ) );
			input.value = attachment.caption || '';
			label.appendChild( input );
			const up = document.createElement( 'button' );
			up.type = 'button';
			up.className = 'button-link';
			up.setAttribute( 'data-dgb-gallery-up', '' );
			up.textContent = gallery.getAttribute( 'data-up-label' );
			const remove = document.createElement( 'button' );
			remove.type = 'button';
			remove.className = 'button-link button-link-delete';
			remove.setAttribute( 'data-dgb-gallery-remove', '' );
			remove.textContent = gallery.getAttribute( 'data-remove-label' );
			li.append( img, label, up, remove );
			return li;
		};
		list.addEventListener( 'click', ( e ) => {
			const li = e.target.closest( 'li' );
			if ( ! li ) {
				return;
			}
			if ( e.target.closest( '[data-dgb-gallery-remove]' ) ) {
				li.remove();
				add.focus();
			} else if ( e.target.closest( '[data-dgb-gallery-up]' ) && li.previousElementSibling ) {
				list.insertBefore( li, li.previousElementSibling );
				e.target.focus();
			}
			sync();
		} );
		if ( window.wp && window.wp.media ) {
			add.addEventListener( 'click', () => {
				const frame = window.wp.media( {
					title: gallery.getAttribute( 'data-title' ),
					button: { text: gallery.getAttribute( 'data-button' ) },
					library: { type: 'image' },
					multiple: 'add',
				} );
				frame.on( 'select', () => {
					const have = Array.from( list.children ).map( ( li ) => li.getAttribute( 'data-id' ) );
					frame.state().get( 'selection' ).toJSON().forEach( ( a ) => {
						if ( list.children.length < max && ! have.includes( String( a.id ) ) ) {
							list.appendChild( item( a ) );
						}
					} );
					sync();
					add.focus();
				} );
				frame.open();
			} );
		} else {
			add.hidden = true;
		}
		sync();
	}

	/* Gallery picker (the IDs field works without it). */
	box.querySelectorAll( '[data-dgb-gallery]' ).forEach( ( button ) => {
		const field = document.getElementById( button.getAttribute( 'data-dgb-gallery' ) );
		if ( ! field || ! window.wp || ! window.wp.media ) {
			button.hidden = true;
			return;
		}
		button.addEventListener( 'click', () => {
			const frame = window.wp.media( {
				title: button.getAttribute( 'data-title' ),
				button: { text: button.getAttribute( 'data-button' ) },
				library: { type: 'image' },
				multiple: 'add',
			} );
			frame.on( 'open', () => {
				const selection = frame.state().get( 'selection' );
				field.value.split( /[\s,]+/ ).filter( Boolean ).forEach( ( id ) => {
					const attachment = window.wp.media.attachment( id );
					attachment.fetch();
					selection.add( attachment );
				} );
			} );
			frame.on( 'select', () => {
				field.value = frame.state().get( 'selection' ).map( ( a ) => a.id ).join( ',' );
				field.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				button.focus();
			} );
			frame.open();
		} );
	} );
}() );
