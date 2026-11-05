import type { DecidedIn, EntryInput, GoalFlag, LiveSummary, OffenseKey, PublicEntry, RosterPlayer, Score } from '../types';

/*
 * Live scorekeeping rules on the phone. deriveScore() mirrors SPPA_Game_Log::derive() and ::score()
 * (includes/class-game-log.php) so the sheet can show the score before the server has answered:
 *   - voided entries are ignored; periods 1-3 regulation, 4 OT, 5 shootout;
 *   - score = goals in periods 1-4 per team (a jersey-only goal counts for its team);
 *   - level after 1-4 and one side has more period-5 goals: that side gets exactly +1;
 *     a shootout goal in a game that is not level changes nothing;
 *   - period = furthest period in any active entry (min 1).
 * The server's own score always wins once it arrives; this only fills the gap while entries wait to sync.
 */

export const OFFENSES: Array<{ key: OffenseKey; label: string; minutes: number; review: boolean }> = [
	{ key: 'minor', label: 'Minor', minutes: 2, review: false },
	{ key: 'double_minor', label: 'Double minor', minutes: 4, review: false },
	{ key: 'major', label: 'Major', minutes: 5, review: false },
	{ key: 'misconduct', label: 'Misconduct', minutes: 10, review: false },
	{ key: 'game_misconduct', label: 'Game misconduct', minutes: 10, review: true },
	{ key: 'gross_misconduct', label: 'Gross misconduct', minutes: 10, review: true },
	{ key: 'match', label: 'Match', minutes: 5, review: true },
	{ key: 'fighting', label: 'Fighting', minutes: 5, review: true },
	{ key: 'ejection', label: 'Ejection', minutes: 10, review: true },
	{ key: 'other', label: 'Other', minutes: 2, review: false },
];

export const offense = ( key: OffenseKey | null | undefined ) => OFFENSES.find( ( o ) => o.key === key );

export const FLAGS: Array<{ key: GoalFlag; label: string; long: string }> = [
	{ key: 'pp', label: 'PP', long: 'power play' },
	{ key: 'sh', label: 'SH', long: 'short-handed' },
	{ key: 'en', label: 'EN', long: 'empty net' },
];

/** "P1", "P2", "P3", "OT", "SO". */
export function periodLabel( p: number ): string {
	return p === 4 ? 'OT' : p === 5 ? 'SO' : `P${ p }`;
}

/** "Live 2–1 · P2" / "Final 4–3", home first unless `flip` (the viewer is the away side). */
export function liveText( l: NonNullable<LiveSummary>, flip = false ): string {
	const [ a, b ] = flip ? [ l.score.away, l.score.home ] : [ l.score.home, l.score.away ];
	return l.status === 'final' ? `Final ${ a }–${ b }` : `Live ${ a }–${ b } · ${ periodLabel( l.period ) }`;
}

/** What deriveScore needs from an entry, sent or stored. */
export type ScoreEntry = Pick<EntryInput, 'type' | 'team_id' | 'period'> & { voided?: boolean };

export interface Derived extends Score {
	period: number;
	decided_in: DecidedIn;
}

export function deriveScore( entries: ScoreEntry[], homeId: number, awayId: number ): Derived {
	const goals: Record<number, number[]> = { [ homeId ]: [ 0, 0, 0, 0, 0, 0 ], [ awayId ]: [ 0, 0, 0, 0, 0, 0 ] };
	let period = 1;
	for ( const e of entries ) {
		if ( e.voided ) {
			continue;
		}
		period = Math.max( period, e.period );
		if ( e.type === 'goal' && e.team_id && goals[ e.team_id ] ) {
			goals[ e.team_id ][ e.period ]++;
		}
	}
	const reg = ( t: number ) => goals[ t ][ 1 ] + goals[ t ][ 2 ] + goals[ t ][ 3 ];
	const total = { home: reg( homeId ) + goals[ homeId ][ 4 ], away: reg( awayId ) + goals[ awayId ][ 4 ] };
	let decided: DecidedIn = 'tie';
	if ( total.home !== total.away ) {
		decided = reg( homeId ) === reg( awayId ) ? 'overtime' : 'regulation';
	} else if ( goals[ homeId ][ 5 ] !== goals[ awayId ][ 5 ] ) {
		decided = 'shootout';
		total[ goals[ homeId ][ 5 ] > goals[ awayId ][ 5 ] ? 'home' : 'away' ]++;
	}
	return { ...total, period, decided_in: decided };
}

/**
 * The number pad against a roster: `exact` is the one player wearing exactly that number (undefined
 * when none, or when two share it), `matches` are those whose number starts with what was typed.
 */
export function matchRoster( roster: RosterPlayer[], typed: string ): { exact: RosterPlayer | undefined; matches: RosterPlayer[] } {
	if ( typed === '' ) {
		return { exact: undefined, matches: roster };
	}
	const matches = roster.filter( ( p ) => p.number !== '' && p.number.startsWith( typed ) );
	const same = matches.filter( ( p ) => p.number === typed );
	return { exact: same.length === 1 ? same[ 0 ] : undefined, matches };
}

/** Roster sorted by jersey number (numberless players last), for the chips. */
export function byNumber( roster: RosterPlayer[] ): RosterPlayer[] {
	const n = ( p: RosterPlayer ) => ( p.number === '' ? 1000 : Number( p.number ) );
	return [ ...roster ].sort( ( a, b ) => n( a ) - n( b ) || a.name.localeCompare( b.name ) );
}

/**
 * The clock as typed, normalised to "m:ss"/"mm:ss": "1241" → "12:41", "941" → "9:41", "" → "" (none).
 * Null when it cannot be a game clock (minutes 0-59, seconds 00-59), matching the server's pattern.
 */
export function normalizeClock( raw: string ): string | null {
	let s = raw.trim().replace( /[.\s]/g, ':' );
	if ( s === '' ) {
		return '';
	}
	if ( /^\d{3,4}$/.test( s ) ) {
		s = `${ s.slice( 0, -2 ) }:${ s.slice( -2 ) }`;
	}
	return /^[0-5]?\d:[0-5]\d$/.test( s ) ? s : null;
}

type Named = Pick<PublicEntry, 'type' | 'period' | 'clock' | 'player_id' | 'player_name' | 'jersey' | 'assist1_id' | 'assist1_name' | 'assist2_id' | 'assist2_name' | 'minutes' | 'offense'>;

/** Jersey numbers by player id, from both rosters (the log carries names, not numbers). */
export type Numbers = Map<number, string>;

function who( id: number | null, name: string | null, numbers: Numbers | undefined, jersey?: string | null ): string {
	if ( ! id ) {
		return jersey ? `#${ jersey }` : 'Unknown';
	}
	const n = numbers?.get( id );
	return [ n ? `#${ n }` : '', name ?? '' ].filter( Boolean ).join( ' ' ) || 'Unknown';
}

function short( id: number | null, name: string | null, numbers: Numbers | undefined ): string {
	const n = id ? numbers?.get( id ) : '';
	return n ? `#${ n }` : name ?? '';
}

/** "P2 · 12:41 · Goal — #17 Sam Lee (A: #4, #30)"; "P1 · Penalty — #4 Olive Opponent · Fighting 5 min". */
export function entryLine( e: Named, numbers?: Numbers ): string {
	const head = [ periodLabel( e.period ), e.clock ].filter( Boolean ).join( ' · ' );
	if ( e.type === 'period' ) {
		return `${ head } · ${ e.period === 5 ? 'Shootout' : e.period === 4 ? 'Overtime' : `Period ${ e.period }` } started`;
	}
	const player = who( e.player_id, e.player_name, numbers, e.jersey );
	if ( e.type === 'goal' ) {
		const assists = [ e.assist1_id && short( e.assist1_id, e.assist1_name, numbers ), e.assist2_id && short( e.assist2_id, e.assist2_name, numbers ) ].filter( Boolean );
		return `${ head } · Goal — ${ player }${ assists.length ? ` (A: ${ assists.join( ', ' ) })` : '' }`;
	}
	if ( e.type === 'penalty' ) {
		return `${ head } · Penalty — ${ player } · ${ offense( e.offense )?.label ?? 'Penalty' } ${ e.minutes ?? '' } min`;
	}
	return head;
}
