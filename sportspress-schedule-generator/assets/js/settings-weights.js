( function () {
	var WEIGHT_KEYS = [ 'day_balance', 'time_of_night', 'season_pacing', 'venue_utilization', 'preferred_venue', 'division_distance', 'division_disruption', 'overlap_avoidance', 'double_header' ];

	function rowFor( id ) {
		var el = document.getElementById( id );
		return el ? el.closest( 'tr' ) : null;
	}

	function sync() {
		var advanced = document.getElementById( 'spsg_advanced_weights_enabled' );
		var timeSlots = document.getElementById( 'spsg_balance_time_slots' );
		var show = !! ( advanced && advanced.checked );

		var intro = document.getElementById( 'spsg-weights-intro' );
		if ( intro ) { intro.style.display = show ? '' : 'none'; }

		var marker = document.getElementById( 'spsg-weights-table-marker' );
		var table = marker ? marker.nextElementSibling : null;
		if ( table ) { table.style.display = show ? '' : 'none'; }

		var resetForm = document.getElementById( 'spsg-weights-reset-form' );
		if ( resetForm ) { resetForm.style.display = show ? '' : 'none'; }

		WEIGHT_KEYS.forEach( function ( key ) {
			var row = rowFor( 'spsg_weight_' + key );
			if ( row ) { row.style.display = show ? '' : 'none'; }
		} );

		var nightSlider = document.getElementById( 'spsg_weight_time_of_night' );
		if ( nightSlider && timeSlots ) {
			nightSlider.classList.toggle( 'spsg-weight-disabled', ! timeSlots.checked );
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var advanced = document.getElementById( 'spsg_advanced_weights_enabled' );
		var timeSlots = document.getElementById( 'spsg_balance_time_slots' );
		if ( advanced ) { advanced.addEventListener( 'change', sync ); }
		if ( timeSlots ) { timeSlots.addEventListener( 'change', sync ); }
		sync();

		document.querySelectorAll( '.spsg-weight-slider' ).forEach( function ( slider ) {
			slider.addEventListener( 'input', function () {
				var out = slider.nextElementSibling;
				if ( out ) { out.textContent = slider.value + '%'; }
			} );
		} );
	} );
} )();
