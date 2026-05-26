/* global jQuery, spemLeagueTable */
( function ( $ ) {
	'use strict';

	function openLeagueTableModal() {
		var el = document.getElementById( 'league-table-modal' );
		if ( el ) {
			el.style.display = 'block';
		}
	}

	function closeLeagueTableModal() {
		var el = document.getElementById( 'league-table-modal' );
		if ( el ) {
			el.style.display = 'none';
		}
	}

	// Expose for inline onclick handlers used by existing markup.
	window.openLeagueTableModal  = openLeagueTableModal;
	window.closeLeagueTableModal = closeLeagueTableModal;

	$( document ).ready( function () {
		$( '#league-table-form' ).on( 'submit', function ( e ) {
			e.preventDefault();

			$.post(
				( window.ajaxurl || spemLeagueTable.ajaxUrl ),
				{
					action:     'generate_league_table',
					league_id:  $( '#league_select' ).val(),
					season_id:  $( '#season_select' ).val(),
					table_name: $( '#table_name' ).val(),
					nonce:      spemLeagueTable.nonce
				},
				function ( response ) {
					if ( response && response.success ) {
						window.alert( response.data.message );
						if ( response.data.edit_url ) {
							window.open( response.data.edit_url, '_blank' );
						}
						closeLeagueTableModal();
					} else {
						window.alert( response && response.data ? response.data : 'Error' );
					}
				}
			);
		} );
	} );
} )( jQuery );
