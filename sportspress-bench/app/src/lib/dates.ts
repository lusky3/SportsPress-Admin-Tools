/** League timezone; every game time in the app is shown in it, whatever the device says. */
export const LEAGUE_TZ = 'America/Toronto';

const formatters = new Map<string, Intl.DateTimeFormat>();

function formatter( tz: string, options: Intl.DateTimeFormatOptions ): Intl.DateTimeFormat {
	const key = tz + JSON.stringify( options );
	let f = formatters.get( key );
	if ( ! f ) {
		f = new Intl.DateTimeFormat( 'en-US', { ...options, timeZone: tz } );
		formatters.set( key, f );
	}
	return f;
}

/** Newer ICU puts a narrow no-break space before AM/PM; the app wants a plain one. */
function plain( s: string ): string {
	return s.replace( /[\u202f\u00a0]/g, ' ' );
}

function parse( iso: string ): Date | null {
	const d = new Date( iso );
	return Number.isNaN( d.getTime() ) ? null : d;
}

interface Parts {
	year: number;
	month: number;
	day: number;
	hour: number;
}

function parts( d: Date, tz: string ): Parts {
	const out: Record<string, number> = {};
	for ( const p of formatter( tz, { year: 'numeric', month: 'numeric', day: 'numeric', hour: 'numeric', hourCycle: 'h23' } ).formatToParts( d ) ) {
		if ( p.type === 'year' || p.type === 'month' || p.type === 'day' || p.type === 'hour' ) {
			out[ p.type ] = Number( p.value );
		}
	}
	return { year: out.year, month: out.month, day: out.day, hour: out.hour % 24 };
}

/** Calendar day number in a timezone, so differences are whole days across clock changes. */
function dayNumber( p: Parts ): number {
	return Math.round( Date.UTC( p.year, p.month - 1, p.day ) / 86_400_000 );
}

/**
 * How to say when a game is: Tonight (same day, 5 PM or later), Today, Tomorrow,
 * a weekday name within six days, otherwise "Thu, Nov 6". A game with no time yet is "TBD".
 */
export function relativeDay( iso: string, now: Date, tz: string = LEAGUE_TZ ): string {
	const d = parse( iso );
	if ( ! d ) {
		return 'TBD';
	}
	const game = parts( d, tz );
	const diff = dayNumber( game ) - dayNumber( parts( now, tz ) );
	if ( diff === 0 ) {
		return game.hour >= 17 ? 'Tonight' : 'Today';
	}
	if ( diff === 1 ) {
		return 'Tomorrow';
	}
	if ( diff >= 2 && diff <= 6 ) {
		return formatter( tz, { weekday: 'long' } ).format( d );
	}
	return plain( formatter( tz, { weekday: 'short', month: 'short', day: 'numeric' } ).format( d ) );
}

/** "9:45 PM" in the given timezone; "TBD" for a game with no time yet. */
export function timeLabel( iso: string, tz: string = LEAGUE_TZ ): string {
	const d = parse( iso );
	return d ? plain( formatter( tz, { hour: 'numeric', minute: '2-digit', hour12: true } ).format( d ) ) : 'TBD';
}

/** "2026-11"; "tbd" for a game with no time yet. */
export function monthKey( iso: string, tz: string = LEAGUE_TZ ): string {
	const d = parse( iso );
	if ( ! d ) {
		return 'tbd';
	}
	const p = parts( d, tz );
	return `${ p.year }-${ String( p.month ).padStart( 2, '0' ) }`;
}

/** "November 2026" for a month key; "Date to be announced" for "tbd". */
export function monthLabel( key: string ): string {
	const match = /^(\d{4})-(\d{2})$/.exec( key );
	if ( ! match ) {
		return 'Date to be announced';
	}
	return formatter( 'UTC', { month: 'long', year: 'numeric' } ).format( new Date( Date.UTC( Number( match[ 1 ] ), Number( match[ 2 ] ) - 1, 1 ) ) );
}
