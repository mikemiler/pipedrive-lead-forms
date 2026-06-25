/**
 * Field repeater for the lead form editor.
 * Clones a hidden template row to add fields and removes rows on demand.
 */
( function () {
	'use strict';

	function getTemplate() {
		var tpl = document.getElementById( 'pdlead-field-template' );
		return tpl ? tpl.innerHTML : '';
	}

	function addRow() {
		var body = document.querySelector( '#pdlead-fields-table tbody' );
		if ( ! body ) {
			return;
		}
		var temp = document.createElement( 'tbody' );
		temp.innerHTML = getTemplate().trim();
		var row = temp.querySelector( 'tr' );
		if ( row ) {
			body.appendChild( row );
		}
	}

	function onClick( event ) {
		var target = event.target;

		if ( target.id === 'pdlead-add-field' ) {
			event.preventDefault();
			addRow();
			return;
		}

		if ( target.classList.contains( 'pdlead-remove-field' ) ) {
			event.preventDefault();
			var row = target.closest( '.pdlead-field-row' );
			if ( row ) {
				row.parentNode.removeChild( row );
			}
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.addEventListener( 'click', onClick );
	} );
} )();
