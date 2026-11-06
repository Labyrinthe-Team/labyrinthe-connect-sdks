/**
 * Formulaire OTP de Labyrinthe Connect (connexion, inscription, commande).
 *
 * Le navigateur n'envoie que le numéro puis le code à admin-ajax.php ; l'identifiant
 * du code (otp_id) reste côté serveur. Aucune dépendance (ni jQuery ni React).
 *
 * Le champ du code suit la forme renvoyée par l'API (longueur, chiffres ou lettres) ; le
 * compte à rebours « Renvoyer » suit resend_after_seconds ; avec Web OTP (Chrome sur
 * Android), le code reçu par SMS est rempli et vérifié automatiquement.
 */
( function () {
	'use strict';

	var config = window.labyrintheConnectOtp || {};
	var i18n = config.i18n || {};

	function format( text, value ) {
		return String( text || '' ).replace( /%(\d\$)?[sd]/, value );
	}

	function post( action, data ) {
		var body = new window.FormData();
		body.append( 'action', action );
		body.append( 'nonce', config.nonce || '' );
		Object.keys( data ).forEach( function ( key ) {
			body.append( key, data[ key ] );
		} );

		return window
			.fetch( config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json().catch( function () {
					return { success: false, data: { message: i18n.networkError } };
				} );
			} );
	}

	function init( root ) {
		if ( root.getAttribute( 'data-ready' ) ) {
			return;
		}
		root.setAttribute( 'data-ready', '1' );

		var context = root.getAttribute( 'data-context' );
		var redirect = root.getAttribute( 'data-redirect' ) || '';
		var phoneSource = root.getAttribute( 'data-phone-source' );
		var toggle = root.querySelector( '.labyrinthe-otp__toggle' );
		var body = root.querySelector( '.labyrinthe-otp__body' );
		var phoneInput = root.querySelector( '.labyrinthe-otp__phone' );
		var codeStep = root.querySelector( '.labyrinthe-otp__step--code' );
		var codeInput = root.querySelector( '.labyrinthe-otp__code' );
		var sendButton = root.querySelector( '.labyrinthe-otp__send' );
		var verifyButton = root.querySelector( '.labyrinthe-otp__verify' );
		var resendButton = root.querySelector( '.labyrinthe-otp__resend' );
		var countdown = root.querySelector( '.labyrinthe-otp__countdown' );
		var message = root.querySelector( '.labyrinthe-otp__message' );
		var testCode = root.querySelector( '.labyrinthe-otp__test-code' );
		var remember = root.querySelector( '.labyrinthe-otp__remember-input' );
		var timer = null;
		var webOtp = null;

		function phone() {
			if ( phoneSource ) {
				var source = document.querySelector( phoneSource );
				return source ? source.value.trim() : '';
			}
			return phoneInput ? phoneInput.value.trim() : '';
		}

		// Forme du code : longueur, clavier numérique (numeric) ou texte (alphanumeric).
		function shape( length, type ) {
			var size = parseInt( length, 10 );
			if ( size >= 4 && size <= 8 ) {
				codeInput.maxLength = size;
				codeInput.size = size;
				codeInput.setAttribute( 'data-code-length', String( size ) );
				codeInput.title = format( i18n.codeLength, size );
			}
			if ( 'numeric' === type || 'alphanumeric' === type ) {
				codeInput.inputMode = 'alphanumeric' === type ? 'text' : 'numeric';
				codeInput.setAttribute( 'autocapitalize', 'alphanumeric' === type ? 'characters' : 'off' );
				codeInput.setAttribute( 'data-code-type', type );
			}
		}

		// Code collé ou saisi : sans espaces ; chiffres seuls pour un code numérique, majuscules sinon.
		function clean( value ) {
			var text = String( value || '' ).replace( /\s+/g, '' );
			return 'alphanumeric' === codeInput.getAttribute( 'data-code-type' )
				? text.toUpperCase()
				: text.replace( /\D/g, '' );
		}

		// Web OTP : le navigateur lit le SMS (dernière ligne « @domaine #code ») et propose le code.
		function listenForSms() {
			stopListening();
			if ( ! ( 'OTPCredential' in window ) || ! window.AbortController || ! navigator.credentials ) {
				return;
			}
			webOtp = new window.AbortController();
			navigator.credentials
				.get( { otp: { transport: [ 'sms' ] }, signal: webOtp.signal } )
				.then( function ( credential ) {
					if ( credential && credential.code && ! codeStep.hidden ) {
						codeInput.value = clean( credential.code );
						verify();
					}
				} )
				.catch( function () {} );
		}

		function stopListening() {
			if ( webOtp ) {
				webOtp.abort();
				webOtp = null;
			}
		}

		function say( text, isError ) {
			message.textContent = text || '';
			message.classList.toggle( 'labyrinthe-otp__message--error', !! isError );
		}

		function wait( seconds, button ) {
			window.clearInterval( timer );
			var left = Math.max( 0, parseInt( seconds, 10 ) || 0 );
			button.disabled = left > 0;
			countdown.textContent = left > 0 ? format( i18n.countdown, left ) : '';
			if ( left <= 0 ) {
				return;
			}
			timer = window.setInterval( function () {
				left -= 1;
				countdown.textContent = left > 0 ? format( i18n.countdown, left ) : '';
				if ( left <= 0 ) {
					window.clearInterval( timer );
					button.disabled = false;
				}
			}, 1000 );
		}

		function send() {
			var number = phone();
			if ( ! number ) {
				say( i18n.phoneMissing, true );
				return;
			}
			sendButton.disabled = true;
			resendButton.disabled = true;
			say( i18n.sending );
			post( 'labyrinthe_otp_send', { context: context, phone: number } ).then(
				function ( response ) {
					var data = response.data || {};
					say( data.message, ! response.success );
					if ( response.success ) {
						codeStep.hidden = false;
						sendButton.hidden = true;
						shape( data.code_length, data.code_type );
						codeInput.value = '';
						codeInput.focus();
						wait( data.resend_in, resendButton );
						listenForSms();
						if ( data.test_code ) {
							testCode.hidden = false;
							testCode.textContent = format( i18n.testCode, data.test_code );
						}
					} else if ( data.retry_after ) {
						// OTP_RESEND_TOO_SOON, limite par IP : compte à rebours avant un nouvel essai.
						wait( data.retry_after, codeStep.hidden ? sendButton : resendButton );
						if ( codeStep.hidden ) {
							window.setTimeout( function () {
								sendButton.disabled = false;
							}, data.retry_after * 1000 );
						}
					} else {
						sendButton.disabled = false;
						resendButton.disabled = false;
					}
				},
				function () {
					say( i18n.networkError, true );
					sendButton.disabled = false;
					resendButton.disabled = false;
				}
			);
		}

		function verify() {
			var code = clean( codeInput.value );
			if ( ! code ) {
				say( i18n.codeMissing, true );
				return;
			}
			verifyButton.disabled = true;
			say( i18n.checking );
			post( 'labyrinthe_otp_verify', {
				context: context,
				code: code,
				redirect: redirect,
				remember: remember && remember.checked ? '1' : '',
			} ).then(
				function ( response ) {
					var data = response.data || {};
					say( data.message, ! response.success );
					if ( response.success ) {
						stopListening();
						testCode.hidden = true;
						if ( data.redirect ) {
							window.location.assign( data.redirect );
							return;
						}
						root.classList.add( 'labyrinthe-otp--verified' );
						codeStep.hidden = true;
						root.setAttribute( 'data-verified', phone() );
						return;
					}
					verifyButton.disabled = false;
					if ( 'invalid_code' !== data.code ) {
						// Code expiré, déjà utilisé ou bloqué : il faut en demander un autre.
						stopListening();
						codeInput.value = '';
						resendButton.disabled = false;
						window.clearInterval( timer );
						countdown.textContent = '';
					} else {
						codeInput.select();
					}
				},
				function () {
					say( i18n.networkError, true );
					verifyButton.disabled = false;
				}
			);
		}

		if ( toggle ) {
			toggle.addEventListener( 'click', function () {
				var open = body.hidden;
				body.hidden = ! open;
				toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
				if ( open && phoneInput ) {
					phoneInput.focus();
				}
			} );
		}
		codeInput.addEventListener( 'paste', function ( event ) {
			var text = event.clipboardData ? event.clipboardData.getData( 'text' ) : '';
			if ( text ) {
				// Sans cela, maxlength tronquerait « 123 456 » en « 123 45 ».
				event.preventDefault();
				codeInput.value = clean( text ).slice( 0, codeInput.maxLength > 0 ? codeInput.maxLength : 20 );
			}
		} );
		sendButton.addEventListener( 'click', send );
		resendButton.addEventListener( 'click', send );
		verifyButton.addEventListener( 'click', verify );

		// Entrée dans un champ du formulaire OTP : on ne soumet pas le formulaire englobant.
		[ phoneInput, codeInput ].forEach( function ( input ) {
			if ( ! input ) {
				return;
			}
			input.addEventListener( 'keydown', function ( event ) {
				if ( 'Enter' === event.key ) {
					event.preventDefault();
					( input === codeInput ? verify : send )();
				}
			} );
		} );

		// Le numéro change après vérification : il faudra le vérifier à nouveau.
		var watched = phoneSource ? document.querySelector( phoneSource ) : phoneInput;
		if ( watched ) {
			watched.addEventListener( 'change', function () {
				if ( root.getAttribute( 'data-verified' ) && root.getAttribute( 'data-verified' ) !== phone() ) {
					root.removeAttribute( 'data-verified' );
					root.classList.remove( 'labyrinthe-otp--verified' );
					sendButton.hidden = false;
					sendButton.disabled = false;
					say( '' );
				}
			} );
		}
	}

	function initAll() {
		Array.prototype.forEach.call( document.querySelectorAll( '.labyrinthe-otp' ), init );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initAll );
	} else {
		initAll();
	}
	// Validation de commande en blocs : le formulaire peut être rendu après le chargement.
	if ( window.MutationObserver ) {
		var scheduled = false;
		new window.MutationObserver( function () {
			if ( scheduled ) {
				return;
			}
			scheduled = true;
			window.requestAnimationFrame( function () {
				scheduled = false;
				initAll();
			} );
		} ).observe( document.documentElement, { childList: true, subtree: true } );
	}
} )();
