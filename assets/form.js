/**
 * Pipedrive Lead Forms front-end handler.
 *
 * The form markup is fully cacheable. A fresh nonce and signed timestamp are
 * fetched from an uncached REST endpoint, so caching plugins never serve a
 * stale token. The token is fetched on page load so the server can measure how
 * long the visitor took to fill the form (the time trap reads now minus ts).
 */
( function () {
	'use strict';

	var config = window.pdleadConfig || {};

	/**
	 * Fetch a fresh token. A cache buster query defeats aggressive CDN caching.
	 *
	 * @return {Promise<Object>}
	 */
	function fetchToken() {
		var url = config.restUrl + 'token?_=' + Date.now();
		return fetch( url, {
			method: 'GET',
			credentials: 'same-origin',
			cache: 'no-store',
			headers: { 'Cache-Control': 'no-store' }
		} ).then( function ( res ) {
			return res.json();
		} );
	}

	/**
	 * Get a token for a form, using the one cached at page load when available.
	 *
	 * @param {HTMLElement} form  Form element.
	 * @param {boolean}     fresh Force a brand new token.
	 * @return {Promise<Object>}
	 */
	function getToken( form, fresh ) {
		if ( ! fresh && form.pdleadToken ) {
			return Promise.resolve( form.pdleadToken );
		}
		return fetchToken().then( function ( token ) {
			form.pdleadToken = token;
			return token;
		} );
	}

	/**
	 * Write the token values into the hidden form fields.
	 *
	 * @param {HTMLElement} form  Form element.
	 * @param {Object}      token Token object.
	 */
	function applyToken( form, token ) {
		form.querySelector( 'input[name="pdlead_nonce"]' ).value = token.nonce;
		form.querySelector( 'input[name="pdlead_ts"]' ).value = token.ts;
		form.querySelector( 'input[name="pdlead_sig"]' ).value = token.sig;
	}

	/**
	 * Show a status message inside the form. The message may contain HTML: every
	 * source is trusted (our own i18n strings, or admin text sanitized server
	 * side with wp_kses_post), so it is rendered as markup to allow links.
	 *
	 * @param {HTMLElement} form    Form element.
	 * @param {string}      message Text or trusted HTML to display.
	 * @param {boolean}     isError Whether this is an error.
	 */
	function setStatus( form, message, isError ) {
		var box = form.querySelector( '.pdlead-status' );
		if ( ! box ) {
			return;
		}
		box.innerHTML = message;
		box.classList.remove( 'pdlead-status-error', 'pdlead-status-success' );
		box.classList.add( isError ? 'pdlead-status-error' : 'pdlead-status-success' );
	}

	/**
	 * The error text to show when the server sends none, preferring the form's
	 * own message over the global default.
	 *
	 * @param {HTMLElement} form Form element.
	 * @return {string}
	 */
	function errorFallback( form ) {
		return form.dataset.pdleadError || config.i18n.genericErr;
	}

	/**
	 * Enable the submit button only when every required field is filled.
	 *
	 * @param {HTMLElement} form Form element.
	 */
	function updateSubmitState( form ) {
		var button = form.querySelector( '.pdlead-submit' );
		if ( ! button ) {
			return;
		}

		var required = form.querySelectorAll( '[required]' );
		var complete = Array.prototype.every.call( required, function ( field ) {
			if ( field.type === 'checkbox' || field.type === 'radio' ) {
				return field.checked;
			}
			if ( field.type === 'file' ) {
				return field.files.length > 0;
			}
			return field.value.trim() !== '';
		} );

		button.disabled = ! complete;
	}

	/**
	 * Send the submission. Retries once with a fresh token when it expired.
	 *
	 * @param {HTMLElement} form    Form element.
	 * @param {boolean}     isRetry Whether this is the retry attempt.
	 * @return {Promise}
	 */
	function send( form, isRetry ) {
		return getToken( form, isRetry ).then( function ( token ) {
			if ( ! token || ! token.nonce ) {
				throw new Error( 'no-token' );
			}
			applyToken( form, token );

			var data = new FormData( form );
			data.append( 'form_id', form.getAttribute( 'data-pdlead-form' ) );

			return fetch( config.restUrl + 'submit', {
				method: 'POST',
				credentials: 'same-origin',
				cache: 'no-store',
				body: data
			} );
		} ).then( function ( res ) {
			return res.json().then( function ( body ) {
				return { status: res.status, body: body };
			} );
		} ).then( function ( result ) {
			var body = result.body || {};

			if ( body.ok ) {
				form.reset();
				setStatus( form, body.message || '', false );
				resetTurnstile( form );
				return;
			}

			// A stale token can happen if the page sat open a long time.
			if ( ! isRetry && ( body.code === 'invalid_token' || body.code === 'expired' ) ) {
				return send( form, true );
			}

			setStatus( form, body.message || errorFallback( form ), true );
			resetTurnstile( form );
		} );
	}

	/**
	 * Reset the Turnstile widget if present so a new token is issued.
	 *
	 * @param {HTMLElement} form Form element.
	 */
	function resetTurnstile( form ) {
		if ( window.turnstile && form.querySelector( '.cf-turnstile' ) ) {
			try {
				window.turnstile.reset();
			} catch ( e ) {} // eslint-disable-line no-empty
		}
	}

	/**
	 * Handle a form submit.
	 *
	 * @param {Event} event Submit event.
	 */
	function onSubmit( event ) {
		event.preventDefault();
		var form = event.currentTarget;

		if ( form.dataset.pdleadBusy === '1' ) {
			return;
		}
		form.dataset.pdleadBusy = '1';

		var button = form.querySelector( '.pdlead-submit' );
		if ( button ) {
			button.disabled = true;
		}
		setStatus( form, form.dataset.pdleadSending || config.i18n.sending, false );

		send( form, false ).catch( function () {
			setStatus( form, errorFallback( form ), true );
		} ).then( function () {
			form.dataset.pdleadBusy = '0';
			// Recompute instead of unconditionally enabling: after a successful
			// reset the fields are empty again, so the button must stay disabled.
			updateSubmitState( form );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var forms = document.querySelectorAll( '.pdlead-form' );
		Array.prototype.forEach.call( forms, function ( form ) {
			// Prefetch the token now so the time trap measures real fill time.
			getToken( form, false );
			form.addEventListener( 'submit', onSubmit );

			// Keep the submit button in sync with the required fields.
			updateSubmitState( form );
			form.addEventListener( 'input', function () {
				updateSubmitState( form );
			} );
			form.addEventListener( 'change', function () {
				updateSubmitState( form );
			} );
		} );
	} );
} )();
