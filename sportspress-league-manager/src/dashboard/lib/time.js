// Timestamps arrive as UTC 'Y-m-d H:i:s'. Date can't parse that shape reliably
// across browsers, so normalise it to ISO with an explicit Z before parsing —
// without the Z it would be read as local time, which is the same four-to-five
// hour error the server side guards against.
export function parseUtc( value ) {
	if ( ! value ) {
		return null;
	}
	const parsed = new Date( value.replace( ' ', 'T' ) + 'Z' );
	return Number.isNaN( parsed.getTime() ) ? null : parsed;
}

export function formatLocal( value ) {
	const date = parseUtc( value );
	return date ? date.toLocaleString() : '—';
}

// A plain site-local 'YYYY-MM-DD' date (e.g. eligible_on). Built from its parts
// so no timezone shift can move it to the previous day.
export function formatDate( ymd ) {
	const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec( ymd || '' );
	if ( ! match ) {
		return '';
	}
	const month = Number( match[ 2 ] ) - 1;
	const day = Number( match[ 3 ] );
	const date = new Date( Number( match[ 1 ] ), month, day );
	// Reject impossible dates that Date would roll over (2026-02-30 -> Mar 2).
	if ( date.getMonth() !== month || date.getDate() !== day ) {
		return '';
	}
	return date.toLocaleDateString();
}
