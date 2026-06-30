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

	/**
	 * The consent text lives in its own row right after the field row.
	 *
	 * @param {HTMLElement} row Field row.
	 * @return {HTMLElement|null} The consent row, or null when absent.
	 */
	function consentRowFor( row ) {
		var next = row.nextElementSibling;
		return next && next.classList.contains( 'pdlead-consent-row' ) ? next : null;
	}

	/**
	 * Show the consent text row only when a consent-style type is selected.
	 *
	 * @param {HTMLElement} row Field row.
	 */
	function toggleConsent( row ) {
		var type = row.querySelector( 'select[name="pdlead_field_type[]"]' );
		var consentRow = consentRowFor( row );
		if ( ! type || ! consentRow ) {
			return;
		}
		var isConsent = type.value === 'consent' || type.value === 'checkbox';
		consentRow.classList.toggle( 'pdlead-consent-hidden', ! isConsent );
	}

	function addRow() {
		var body = document.querySelector( '#pdlead-fields-table tbody' );
		if ( ! body ) {
			return;
		}
		var temp = document.createElement( 'tbody' );
		temp.innerHTML = getTemplate().trim();
		var fieldRow = temp.querySelector( '.pdlead-field-row' );
		var consentRow = temp.querySelector( '.pdlead-consent-row' );
		if ( fieldRow ) {
			body.appendChild( fieldRow );
			if ( consentRow ) {
				body.appendChild( consentRow );
			}
			toggleConsent( fieldRow );
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
				var consentRow = consentRowFor( row );
				row.parentNode.removeChild( row );
				if ( consentRow ) {
					consentRow.parentNode.removeChild( consentRow );
				}
			}
		}
	}

	function onChange( event ) {
		if ( event.target.name === 'pdlead_field_type[]' ) {
			var row = event.target.closest( '.pdlead-field-row' );
			if ( row ) {
				toggleConsent( row );
			}
		}
	}

	/**
	 * Enable drag and drop reordering of field rows via jQuery UI sortable.
	 *
	 * Only field rows are sortable. Each consent row must stay directly after
	 * its field row, because save() pairs the consent textarea to its field by
	 * submit position. We detach the consent row on drag start and re-insert it
	 * after its field row on stop so the index alignment stays intact.
	 */
	function initSortable() {
		var $ = window.jQuery;
		if ( ! $ || ! $.fn || ! $.fn.sortable ) {
			return;
		}
		var $body = $( '#pdlead-fields-table tbody' );
		if ( ! $body.length ) {
			return;
		}

		$body.sortable( {
			items: '> tr.pdlead-field-row',
			handle: '.pdlead-drag-handle',
			axis: 'y',
			cursor: 'grabbing',
			placeholder: 'pdlead-sort-placeholder',
			forcePlaceholderSize: true,
			helper: function ( event, row ) {
				// Lock cell widths so the dragged row keeps the table layout.
				var $original = row.children();
				var $helper = row.clone();
				$helper.children().each( function ( index ) {
					$( this ).width( $original.eq( index ).width() );
				} );
				return $helper;
			},
			start: function ( event, ui ) {
				ui.placeholder.html( '<td colspan="8"></td>' );
				ui.placeholder.height( ui.item.outerHeight() );

				// Travel with the paired consent row, if any.
				var consentRow = consentRowFor( ui.item[ 0 ] );
				ui.item.data( 'pdleadConsentRow', consentRow );
				if ( consentRow ) {
					$( consentRow ).detach();
				}
			},
			stop: function ( event, ui ) {
				var consentRow = ui.item.data( 'pdleadConsentRow' );
				if ( consentRow ) {
					ui.item.after( consentRow );
					ui.item.removeData( 'pdleadConsentRow' );
				}
			}
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.addEventListener( 'click', onClick );
		document.addEventListener( 'change', onChange );

		// Apply the initial visibility to rows rendered on the server.
		var rows = document.querySelectorAll( '#pdlead-fields-table .pdlead-field-row' );
		Array.prototype.forEach.call( rows, toggleConsent );

		initSortable();
	} );
} )();
