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
	 * Resolve the consent row that belongs to a field row.
	 *
	 * The pairing is cached on first lookup, which happens while the rows are
	 * still rendered in their original adjacent order (on init and right after a
	 * row is added). We must not rely on the live DOM sibling afterwards: drag
	 * and drop can move a field row away from its consent row, and the save
	 * handler pairs consent texts to fields purely by submit position. Caching
	 * keeps that pairing stable no matter how the rows are reordered.
	 *
	 * @param {HTMLElement} row Field row.
	 * @return {HTMLElement|null} The consent row, or null when absent.
	 */
	function consentRowFor( row ) {
		if ( ! row ) {
			return null;
		}
		if ( row._pdleadConsentRow === undefined ) {
			var next = row.nextElementSibling;
			row._pdleadConsentRow = next && next.classList.contains( 'pdlead-consent-row' ) ? next : null;
		}
		return row._pdleadConsentRow;
	}

	/**
	 * Re-attach every consent row directly after its own field row.
	 *
	 * Called after a drag so the consent textareas post in the same order as
	 * their field rows, which is what save() relies on for index alignment.
	 */
	function normalizeConsentRows() {
		var body = document.querySelector( '#pdlead-fields-table tbody' );
		if ( ! body ) {
			return;
		}
		var rows = body.querySelectorAll( '.pdlead-field-row' );
		Array.prototype.forEach.call( rows, function ( fieldRow ) {
			var consentRow = consentRowFor( fieldRow );
			if ( consentRow && fieldRow.nextSibling !== consentRow ) {
				body.insertBefore( consentRow, fieldRow.nextSibling );
			}
		} );
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

	/**
	 * Prefill an empty Options column when a Pipedrive choice field is chosen
	 * as mapping target, and switch the field to a Select, so the submitted
	 * value matches a Pipedrive option label. Hidden fields are left alone:
	 * their single fixed value is typed in manually.
	 *
	 * @param {HTMLElement} row Field row.
	 */
	function prefillOptions( row ) {
		var map = row.querySelector( 'select[name="pdlead_field_map[]"]' );
		var input = row.querySelector( 'input[name="pdlead_field_options[]"]' );
		var type = row.querySelector( 'select[name="pdlead_field_type[]"]' );
		var selected = map ? map.options[ map.selectedIndex ] : null;
		var labels = selected ? selected.getAttribute( 'data-pdlead-options' ) : null;
		if ( ! labels || ! input || input.value.trim() !== '' || ( type && type.value === 'hidden' ) ) {
			return;
		}
		input.value = labels;
		if ( type ) {
			type.value = 'select';
			toggleConsent( row );
		}
	}

	function onChange( event ) {
		var row = event.target.closest( '.pdlead-field-row' );
		if ( ! row ) {
			return;
		}
		if ( event.target.name === 'pdlead_field_type[]' ) {
			toggleConsent( row );
		}
		if ( event.target.name === 'pdlead_field_map[]' ) {
			prefillOptions( row );
		}
	}

	/**
	 * Enable drag and drop reordering of field rows via jQuery UI sortable.
	 *
	 * Only field rows are sortable. Each consent row must stay directly after
	 * its field row, because save() pairs the consent textarea to its field by
	 * submit position. Sortable can leave consent rows stranded between other
	 * fields, so on stop we restore the adjacency of every pair (see
	 * normalizeConsentRows) to keep the index alignment intact.
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

				// Pull the paired consent row out of the way during the drag.
				// It is restored, together with every other pair, on stop.
				var consentRow = consentRowFor( ui.item[ 0 ] );
				if ( consentRow ) {
					$( consentRow ).detach();
				}
			},
			stop: function () {
				// Restore the field row / consent row adjacency for all pairs so
				// the consent textareas post in the same order as their fields.
				normalizeConsentRows();
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
