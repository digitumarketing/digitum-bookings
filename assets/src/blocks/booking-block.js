/**
 * Editor side of the "Booking widget" block (no build step needed: uses the wp.* globals).
 */
( function ( wp ) {
	'use strict';

	const el = wp.element.createElement;
	const __ = wp.i18n.__;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { PanelBody, TextControl } = wp.components;
	const ServerSideRender = wp.serverSideRender;

	wp.blocks.registerBlockType( 'digitum-bookings/booking', {
		edit( props ) {
			const id = props.attributes.productId || 0;
			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Tour', 'digitum-bookings' ) },
						el( TextControl, {
							label: __( 'Tour ID', 'digitum-bookings' ),
							help: __( 'Leave empty to use the tour being viewed.', 'digitum-bookings' ),
							type: 'number',
							value: id || '',
							onChange( value ) {
								props.setAttributes( { productId: parseInt( value, 10 ) || 0 } );
							},
						} )
					)
				),
				el( ServerSideRender, { block: 'digitum-bookings/booking', attributes: props.attributes } )
			);
		},
		save() {
			return null;
		},
	} );
}( window.wp ) );
