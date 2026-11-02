import { boot } from '../boot';
import type { Game } from '../types';
import { venueLine } from './games';

/** The feed URL as a subscribe link: https://… becomes webcal://… (whatever the path ends in). */
export function webcalUrl( feed: string ): string {
	return feed.replace( /^https?:\/\//i, 'webcal://' );
}

/** Google Calendar's "add by URL" link for the feed. */
export function googleUrl( feed: string ): string {
	return `https://calendar.google.com/calendar/r?cid=${ encodeURIComponent( webcalUrl( feed ) ) }`;
}

/** Game length in a calendar, matching the feed (75 minutes). */
const GAME_MINUTES = 75;

function stamp( d: Date ): string {
	return d.toISOString().replace( /[-:]/g, '' ).replace( /\.\d{3}/, '' );
}

function esc( s: string ): string {
	return s.replace( /[\\;,]/g, ( c ) => '\\' + c ).replace( /\r?\n/g, '\\n' );
}

const encoder = new TextEncoder();

/** RFC 5545 folding, like the PHP feed: lines of at most 75 octets, continued with a leading space, never inside a character. */
function fold( line: string ): string {
	const out: string[] = [];
	let current = '';
	let size = 0;
	for ( const ch of line ) {
		const n = encoder.encode( ch ).length;
		if ( size + n > 75 ) {
			out.push( current );
			current = ' ';
			size = 1;
		}
		current += ch;
		size += n;
	}
	out.push( current );
	return out.join( '\r\n' );
}

/**
 * The site's host from the REST root (the PHP feed's UID uses home_url's host), so the UID
 * matches even when the app was opened under another name for the same site.
 */
function siteHost(): string {
	try {
		return new URL( boot().restRoot, window.location.href ).host;
	} catch {
		return window.location.host;
	}
}

/**
 * A one-event .ics for "Add to calendar" on a game, as a data: URL. Its UID matches the feed's
 * (spbn-{event}-{team}@host), so a calendar that also subscribes keeps one copy. '' when the game has no time.
 */
export function gameIcsHref( game: Game, host: string = siteHost() ): string {
	const start = new Date( game.start );
	if ( Number.isNaN( start.getTime() ) ) {
		return '';
	}
	const where = [ venueLine( game.venue ), game.venue.address ].filter( Boolean ).join( ', ' );
	const lines = [
		'BEGIN:VCALENDAR',
		'VERSION:2.0',
		'PRODID:-//ARL//Bench//EN',
		'BEGIN:VEVENT',
		`UID:spbn-${ game.id }-${ game.team.id }@${ host }`,
		`DTSTAMP:${ stamp( new Date() ) }`,
		`DTSTART:${ stamp( start ) }`,
		`DTEND:${ stamp( new Date( start.getTime() + GAME_MINUTES * 60_000 ) ) }`,
		`SUMMARY:${ esc( `${ game.team.name } ${ game.is_home ? 'vs' : 'at' } ${ game.opponent.name || 'TBD' }` ) }`,
		where ? `LOCATION:${ esc( where ) }` : '',
		'END:VEVENT',
		'END:VCALENDAR',
	]
		.filter( Boolean )
		.map( fold );
	return 'data:text/calendar;charset=utf-8,' + encodeURIComponent( lines.join( '\r\n' ) + '\r\n' );
}
