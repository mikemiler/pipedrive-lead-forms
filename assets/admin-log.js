/**
 * Pipedrive Lead Forms submission log.
 *
 * Asks for confirmation before a submission is discarded, since a discarded
 * lead is never sent and is later deleted by the cleanup.
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var link = event.target.closest( '.pdlead-discard' );
		if ( link && ! window.confirm( link.getAttribute( 'data-pdlead-confirm' ) ) ) {
			event.preventDefault();
		}
	} );
} )();
