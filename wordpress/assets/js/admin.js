/**
 * Page de réglages de Labyrinthe Connect : test de connexion, SMS de test, Sender IDs,
 * aperçu de l'encodage et du nombre de segments des modèles.
 *
 * Le nombre de segments est compté par l'API (POST /sms/estimate, avec le retrait des accents
 * du réglage) ; en attendant sa réponse, ou sans clé API, calcul local avec la même règle :
 * tout caractère hors ASCII passe le SMS en Unicode (UCS-2), 70 caractères (67 par segment
 * au-delà) au lieu de 160 (153).
 */
( function () {
	'use strict';

	var config = window.labyrintheConnectAdmin || {};
	var i18n = config.i18n || {};

	function format( text ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var index = 0;
		return String( text || '' ).replace( /%(?:(\d)\$)?[sd]/g, function ( match, position ) {
			var value = position ? args[ position - 1 ] : args[ index++ ];
			return undefined === value ? match : value;
		} );
	}

	function post( action, data ) {
		var body = new window.FormData();
		body.append( 'action', action );
		body.append( 'nonce', config.nonce || '' );
		Object.keys( data || {} ).forEach( function ( key ) {
			body.append( key, data[ key ] );
		} );

		return window
			.fetch( config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json();
			} )
			.catch( function () {
				return { success: false, data: { message: i18n.error } };
			} );
	}

	function result( name, response ) {
		var node = document.querySelector( '[data-result="' + name + '"]' );
		if ( ! node ) {
			return;
		}
		var data = response.data || {};
		node.textContent = data.message || ( response.success ? '' : i18n.error );
		node.className = 'labyrinthe-result ' + ( response.success ? 'labyrinthe-result--ok' : 'labyrinthe-result--error' );
	}

	function busy( button, promise ) {
		button.disabled = true;
		var label = button.textContent;
		button.textContent = i18n.working;
		return promise.then( function ( response ) {
			button.disabled = false;
			button.textContent = label;
			return response;
		} );
	}

	/* Encodage et segments : compte de l'API (POST /sms/estimate), calcul local en attendant ou à défaut */

	function analyse( text ) {
		var unicode = /[^\x00-\x7F]/.test( text );
		var characters = Array.from( text ).length;
		var single = unicode ? 70 : 160;
		var multi = unicode ? 67 : 153;
		var segments = 0 === characters ? 0 : characters <= single ? 1 : Math.ceil( characters / multi );
		var offending = [];
		Array.from( text ).forEach( function ( c ) {
			if ( /[^\x00-\x7F]/.test( c ) && offending.indexOf( c ) < 0 && offending.length < 10 ) {
				offending.push( c );
			}
		} );
		return { unicode: unicode, characters: characters, segments: segments, offending: offending };
	}

	function render( template ) {
		var samples = config.samples || {};
		return Object.keys( samples ).reduce( function ( text, key ) {
			return text.split( key ).join( samples[ key ] );
		}, template );
	}

	function describe( counter, info, counted ) {
		var line;
		if ( ! info.unicode ) {
			line = format( i18n.gsm, info.characters, info.segments );
		} else if ( counted && config.stripAccents ) {
			// Encore en Unicode après le retrait des accents : emoji ou écriture non latine.
			line = format( i18n.unicodeStripped, info.characters, info.segments );
		} else {
			line = format( i18n.unicode, info.characters, info.segments, info.offending.join( ' ' ) );
		}
		if ( ! counted && config.stripAccents && info.unicode ) {
			line += ' ' + i18n.stripped;
		}
		counter.textContent = line + ' ' + ( counted ? i18n.counted : i18n.estimate );
		counter.classList.toggle( 'labyrinthe-counter--unicode', info.unicode );
	}

	function updateCounter( textarea ) {
		var counter = document.getElementById( textarea.getAttribute( 'data-counter' ) );
		if ( ! counter ) {
			return;
		}
		var text = render( textarea.value || textarea.getAttribute( 'placeholder' ) || '' );
		describe( counter, analyse( text ), false );

		if ( ! config.canEstimate || ! text.trim() ) {
			return;
		}
		// Le compte exact (accents retirés par l'API si le réglage est actif), une fois la saisie arrêtée.
		window.clearTimeout( textarea.labyrintheTimer );
		textarea.labyrintheTimer = window.setTimeout( function () {
			var sent = text;
			post( 'labyrinthe_connect_estimate', { message: sent } ).then( function ( response ) {
				var current = render( textarea.value || textarea.getAttribute( 'placeholder' ) || '' );
				if ( ! response.success || current !== sent ) {
					return;
				}
				var data = response.data || {};
				describe(
					counter,
					{
						unicode: 'ucs2' === data.encoding,
						characters: data.characters,
						segments: data.segments,
						offending: analyse( text ).offending,
					},
					true
				);
			} );
		}, 800 );
	}

	function init() {
		Array.prototype.forEach.call( document.querySelectorAll( '.labyrinthe-sms-template' ), function ( textarea ) {
			textarea.addEventListener( 'input', function () {
				updateCounter( textarea );
			} );
			updateCounter( textarea );
		} );

		var testConnection = document.querySelector( '.labyrinthe-test-connection' );
		if ( testConnection ) {
			testConnection.addEventListener( 'click', function () {
				busy( testConnection, post( 'labyrinthe_connect_test_connection' ) ).then( function ( response ) {
					result( 'connection', response );
				} );
			} );
		}

		var sendTest = document.querySelector( '.labyrinthe-send-test' );
		if ( sendTest ) {
			sendTest.addEventListener( 'click', function () {
				busy(
					sendTest,
					post( 'labyrinthe_connect_send_test_sms', {
						phone: document.getElementById( 'labyrinthe-test-phone' ).value,
						message: document.getElementById( 'labyrinthe-test-message' ).value,
					} )
				).then( function ( response ) {
					result( 'test-sms', response );
				} );
			} );
		}

		var refresh = document.querySelector( '.labyrinthe-refresh-senders' );
		if ( refresh ) {
			refresh.addEventListener( 'click', function () {
				busy( refresh, post( 'labyrinthe_connect_refresh_senders' ) ).then( function ( response ) {
					var select = document.getElementById( 'labyrinthe-sender' );
					if ( ! response.success || ! select ) {
						window.alert( ( response.data && response.data.message ) || i18n.error );
						return;
					}
					var current = select.value;
					while ( select.options.length > 1 ) {
						select.remove( 1 );
					}
					( response.data.senders || [] ).forEach( function ( sender ) {
						var option = document.createElement( 'option' );
						option.value = sender.value;
						option.textContent = sender.value;
						option.selected = sender.value === current;
						select.appendChild( option );
					} );
				} );
			} );
		}

		Array.prototype.forEach.call( document.querySelectorAll( '.labyrinthe-copy' ), function ( button ) {
			button.addEventListener( 'click', function () {
				var input = document.getElementById( button.getAttribute( 'data-target' ) );
				if ( ! input ) {
					return;
				}
				input.select();
				var done = function () {
					button.textContent = i18n.copied;
				};
				if ( window.navigator.clipboard ) {
					window.navigator.clipboard.writeText( input.value ).then( done );
				} else {
					document.execCommand( 'copy' );
					done();
				}
			} );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
