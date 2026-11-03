/**
 * Tour gallery lightbox: click an image to see it large; arrows, swipe and Esc work. Without JavaScript each
 * image opens as a link.
 */
( function () {
	'use strict';

	function open( gallery, index ) {
		const items = Array.prototype.slice.call( gallery.querySelectorAll( '[data-dgb-gallery-item]' ) );
		const opener = items[ index ];
		const box = document.createElement( 'div' );
		box.className = 'dgb-o dgb-lightbox';
		box.setAttribute( 'role', 'dialog' );
		box.setAttribute( 'aria-modal', 'true' );
		const figure = document.createElement( 'figure' );
		const img = document.createElement( 'img' );
		const caption = document.createElement( 'figcaption' );
		figure.append( img, caption );
		const button = ( attr, text, label ) => {
			const b = document.createElement( 'button' );
			b.type = 'button';
			b.setAttribute( attr, '' );
			b.setAttribute( 'aria-label', label );
			b.textContent = text;
			return b;
		};
		const close = button( 'data-close', '×', gallery.getAttribute( 'data-close' ) );
		const prev = button( 'data-prev', '‹', gallery.getAttribute( 'data-prev' ) );
		const next = button( 'data-next', '›', gallery.getAttribute( 'data-next' ) );
		box.append( figure, close );
		if ( items.length > 1 ) {
			box.append( prev, next );
		}
		let current = index;
		const show = ( i ) => {
			current = ( i + items.length ) % items.length;
			const link = items[ current ];
			const thumb = link.querySelector( 'img' );
			const text = link.parentNode.querySelector( 'figcaption' );
			img.src = link.href;
			img.alt = thumb ? thumb.alt : '';
			caption.textContent = text ? text.textContent : '';
			box.setAttribute( 'aria-label', img.alt );
		};
		const shut = () => {
			box.remove();
			document.removeEventListener( 'keydown', keys );
			document.documentElement.style.overflow = '';
			items[ current ].focus();
		};
		const keys = ( e ) => {
			if ( 'Escape' === e.key ) {
				shut();
			} else if ( 'ArrowRight' === e.key || 'ArrowLeft' === e.key ) {
				const rtl = 'rtl' === document.documentElement.dir;
				show( current + ( ( 'ArrowRight' === e.key ) !== rtl ? 1 : -1 ) );
			} else if ( 'Tab' === e.key ) {
				// Focus stays on the lightbox buttons.
				const buttons = Array.prototype.slice.call( box.querySelectorAll( 'button' ) );
				const at = buttons.indexOf( document.activeElement );
				e.preventDefault();
				buttons[ ( at + ( e.shiftKey ? -1 : 1 ) + buttons.length ) % buttons.length ].focus();
			}
		};
		close.addEventListener( 'click', shut );
		prev.addEventListener( 'click', () => show( current - 1 ) );
		next.addEventListener( 'click', () => show( current + 1 ) );
		box.addEventListener( 'click', ( e ) => e.target === box && shut() );
		let startX = null;
		box.addEventListener( 'touchstart', ( e ) => ( startX = e.touches[ 0 ].clientX ), { passive: true } );
		box.addEventListener( 'touchend', ( e ) => {
			if ( null !== startX ) {
				const dx = e.changedTouches[ 0 ].clientX - startX;
				if ( Math.abs( dx ) > 40 ) {
					show( current + ( dx < 0 ? 1 : -1 ) );
				}
			}
			startX = null;
		} );
		document.addEventListener( 'keydown', keys );
		document.documentElement.style.overflow = 'hidden';
		show( index );
		document.body.appendChild( box );
		close.focus();
		if ( ! opener ) {
			shut();
		}
	}

	document.addEventListener( 'click', ( e ) => {
		const link = e.target.closest( '[data-dgb-gallery-item]' );
		if ( ! link || e.metaKey || e.ctrlKey || e.shiftKey || e.button ) {
			return;
		}
		const gallery = link.closest( '[data-dgb-gallery]' );
		e.preventDefault();
		open( gallery, Array.prototype.indexOf.call( gallery.querySelectorAll( '[data-dgb-gallery-item]' ), link ) );
	} );
}() );
