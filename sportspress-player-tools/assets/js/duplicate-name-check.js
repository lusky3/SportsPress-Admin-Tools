/**
 * Duplicate-name warning on the sp_player edit screen (PT-7).
 *
 * On blur of the Title field, asks the check-duplicate-name REST endpoint
 * whether another published player already has that exact name and shows an
 * inline, non-blocking notice if so. Advisory only: a REST failure hides the
 * notice and never alarms or blocks the editor.
 *
 * Configuration arrives as window.sptDuplicateName (restUrl, nonce, i18n).
 */
( function ( $, config ) {
	'use strict';

	if ( ! config ) {
		return;
	}

	$( function () {
		var $title = $( '#title' );
		if ( ! $title.length ) {
			return;
		}

		var $notice = $(
			'<div class="notice notice-warning inline spt-duplicate-name-warning" style="display:none;margin-top:8px;"><p></p></div>'
		);
		$title.after( $notice );

		function showDuplicate( response ) {
			$notice.find( 'p' ).empty().text(
				config.i18n.prefix + ' "' + response.name + '" ' + config.i18n.suffix + ' '
			).append(
				$( '<a>', { href: response.edit_link, text: config.i18n.link } )
			);
			$notice.show();
		}

		function checkDuplicate() {
			var name = $.trim( $title.val() );
			if ( ! name ) {
				$notice.hide();
				return;
			}

			$.ajax( {
				url: config.restUrl,
				method: 'GET',
				data: { name: name, exclude: $( '#post_ID' ).val() || 0 },
				beforeSend: function ( xhr ) {
					xhr.setRequestHeader( 'X-WP-Nonce', config.nonce );
				}
			} ).done( function ( response ) {
				if ( response && response.duplicate ) {
					showDuplicate( response );
				} else {
					$notice.hide();
				}
			} ).fail( function () {
				$notice.hide();
			} );
		}

		$title.on( 'blur', checkDuplicate );
	} );
}( window.jQuery, window.sptDuplicateName ) );
