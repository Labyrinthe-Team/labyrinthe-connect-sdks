/**
 * Bloc « Connexion par SMS » : rendu côté serveur (même formulaire que le shortcode
 * [labyrinthe_otp_login]). Dans l'éditeur, un simple aperçu et les réglages du bloc.
 */
( function ( blocks, element, blockEditor, components, i18n ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;

	blocks.registerBlockType( 'labyrinthe-connect/otp-login', {
		edit: function ( props ) {
			var attributes = props.attributes;

			return el(
				element.Fragment,
				null,
				el(
					blockEditor.InspectorControls,
					null,
					el(
						components.PanelBody,
						{ title: __( 'Settings', 'labyrinthe-connect' ) },
						el( components.TextControl, {
							label: __( 'Title', 'labyrinthe-connect' ),
							value: attributes.title,
							onChange: function ( value ) {
								props.setAttributes( { title: value } );
							},
						} ),
						el( components.TextControl, {
							label: __( 'Redirect after login (URL)', 'labyrinthe-connect' ),
							help: __( 'Leave empty to go to the home page.', 'labyrinthe-connect' ),
							value: attributes.redirect,
							onChange: function ( value ) {
								props.setAttributes( { redirect: value } );
							},
						} )
					)
				),
				el(
					'div',
					blockEditor.useBlockProps(),
					el(
						components.Placeholder,
						{
							icon: 'smartphone',
							label: attributes.title || __( 'Log in with a code received by SMS', 'labyrinthe-connect' ),
							instructions: __( 'Visitors enter their phone number, then the code received by SMS. Enable SMS login in Labyrinthe Connect > Settings > OTP.', 'labyrinthe-connect' ),
						}
					)
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n );
