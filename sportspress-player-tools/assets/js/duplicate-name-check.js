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

		var noticeBox = document.createElement( 'div' );
		noticeBox.className = 'notice notice-warning inline spt-duplicate-name-warning';
		noticeBox.style.display = 'none';
		noticeBox.style.marginTop = '8px';
		var noticeText = document.createElement( 'p' );
		noticeBox.appendChild( noticeText );
		$title[ 0 ].insertAdjacentElement( 'afterend', noticeBox );

		function hideNotice() {
			noticeBox.style.display = 'none';
		}

		function showDuplicate( response ) {
			var link = document.createElement( 'a' );
			link.href = response.edit_link;
			link.textContent = config.i18n.link;

			noticeText.textContent = config.i18n.prefix + ' "' + response.name + '" ' + config.i18n.suffix + ' ';
			noticeText.appendChild( link );
			noticeBox.style.display = '';
		}

		function checkDuplicate() {
			var name = $.trim( $title.val() );
			if ( ! name ) {
				hideNotice();
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
					hideNotice();
				}
			} ).fail( function () {
				hideNotice();
			} );
		}

		$title.on( 'blur', checkDuplicate );
	} );
}( window.jQuery, window.sptDuplicateName ) );
