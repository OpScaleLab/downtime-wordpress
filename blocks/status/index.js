/*
 * Editor UI for the dynamic "DownTime Status" block. No build step: plain
 * wp.* globals. Rendering happens in PHP (Downtime_Embed::render_block), so
 * the front end and the editor preview are the same markup.
 */
( function ( blocks, element, components, blockEditor, ServerSideRender, i18n ) {
	var el = element.createElement;
	var __ = i18n.__;

	blocks.registerBlockType( 'downtime/status', {
		edit: function ( props ) {
			var a = props.attributes;
			return el(
				element.Fragment,
				null,
				el(
					blockEditor.InspectorControls,
					null,
					el(
						components.PanelBody,
						{ title: __( 'DownTime', 'downtime-uptime-monitoring' ) },
						el( components.SelectControl, {
							label: __( 'Show', 'downtime-uptime-monitoring' ),
							value: a.mode,
							options: [
								{ label: __( 'Status page', 'downtime-uptime-monitoring' ), value: 'page' },
								{ label: __( 'Status badge', 'downtime-uptime-monitoring' ), value: 'badge' },
								{ label: __( 'Uptime badge', 'downtime-uptime-monitoring' ), value: 'uptime' },
							],
							onChange: function ( v ) { props.setAttributes( { mode: v } ); },
						} ),
						el( components.TextControl, {
							label: __( 'Status page slug', 'downtime-uptime-monitoring' ),
							help: __( 'Leave empty to use the one set under Settings → DownTime.', 'downtime-uptime-monitoring' ),
							value: a.slug,
							onChange: function ( v ) { props.setAttributes( { slug: v } ); },
						} ),
						a.mode === 'page' && el( components.RangeControl, {
							label: __( 'Initial height (px)', 'downtime-uptime-monitoring' ),
							value: a.height,
							min: 100,
							max: 2000,
							onChange: function ( v ) { props.setAttributes( { height: v } ); },
						} )
					)
				),
				el(
					'div',
					blockEditor.useBlockProps(),
					el( ServerSideRender, { block: 'downtime/status', attributes: a } )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( wp.blocks, wp.element, wp.components, wp.blockEditor, wp.serverSideRender, wp.i18n );
