/**
 * Editor side of the tour detail blocks (from price, price table, tour list, tour facts).
 * Rendered on the server by the same code as the shortcodes; no build step needed beyond bundling.
 */
( function ( wp ) {
	'use strict';

	const el = wp.element.createElement;
	const __ = wp.i18n.__;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { PanelBody, TextControl, ToggleControl, SelectControl } = wp.components;
	const ServerSideRender = wp.serverSideRender;

	const tourControl = ( props ) =>
		el( TextControl, {
			label: __( 'Tour ID', 'digitum-bookings' ),
			help: __( 'Leave empty to use the tour being viewed.', 'digitum-bookings' ),
			type: 'number',
			value: props.attributes.productId || '',
			onChange: ( value ) => props.setAttributes( { productId: parseInt( value, 10 ) || 0 } ),
		} );

	const extra = {
		'from-price': ( props ) => [
			el( ToggleControl, { key: 'b', label: __( 'Show per person / per vehicle', 'digitum-bookings' ), checked: props.attributes.showBasis, onChange: ( v ) => props.setAttributes( { showBasis: v } ) } ),
			el( ToggleControl, { key: 's', label: __( 'Show the second currency', 'digitum-bookings' ), checked: props.attributes.secondCurrency, onChange: ( v ) => props.setAttributes( { secondCurrency: v } ) } ),
		],
		'tour-list': ( props ) => [
			el( SelectControl, {
				key: 'f',
				label: __( 'List', 'digitum-bookings' ),
				value: props.attributes.field,
				options: [
					{ value: 'inclusions', label: __( 'Included', 'digitum-bookings' ) },
					{ value: 'exclusions', label: __( 'Not included', 'digitum-bookings' ) },
					{ value: 'requirements', label: __( 'Requirements', 'digitum-bookings' ) },
				],
				onChange: ( v ) => props.setAttributes( { field: v } ),
			} ),
			el( SelectControl, {
				key: 's',
				label: __( 'Style', 'digitum-bookings' ),
				value: props.attributes.style,
				options: [
					{ value: '', label: __( 'Automatic', 'digitum-bookings' ) },
					{ value: 'check', label: __( 'Ticks', 'digitum-bookings' ) },
					{ value: 'cross', label: __( 'Crosses', 'digitum-bookings' ) },
					{ value: 'plain', label: __( 'Bullets', 'digitum-bookings' ) },
				],
				onChange: ( v ) => props.setAttributes( { style: v } ),
			} ),
		],
		'tour-facts': ( props ) => [
			el( TextControl, {
				key: 'f',
				label: __( 'Facts', 'digitum-bookings' ),
				help: __( 'Comma-separated, in order: duration, basis, capacity, age, licence, pickup, meeting_point, sessions. Empty shows all.', 'digitum-bookings' ),
				value: props.attributes.fields,
				onChange: ( v ) => props.setAttributes( { fields: v } ),
			} ),
		],
	};

	[ 'from-price', 'price-table', 'tour-list', 'tour-facts' ].forEach( ( name ) => {
		wp.blocks.registerBlockType( 'digitum-bookings/' + name, {
			edit( props ) {
				return el(
					'div',
					useBlockProps(),
					el( InspectorControls, null, el( PanelBody, { title: __( 'Tour', 'digitum-bookings' ) }, tourControl( props ), ...( extra[ name ] ? extra[ name ]( props ) : [] ) ) ),
					el( ServerSideRender, { block: 'digitum-bookings/' + name, attributes: props.attributes } )
				);
			},
			save() {
				return null;
			},
		} );
	} );
}( window.wp ) );
