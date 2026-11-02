/*
 * The make-believe league behind the demo: teams, venues, rosters and clock helpers.
 * Everything here is fictional; the team names are the league's own joke names.
 */
import { LEAGUE_TZ, addDays, leagueDate } from '../lib/dates';

export interface TeamInfo {
	id: number;
	name: string;
	division: string;
}

export const TEAMS: TeamInfo[] = [
	{ id: 1, name: 'Ducks', division: 'C Division' },
	{ id: 2, name: 'Knights of Ni', division: 'C Division' },
	{ id: 3, name: 'Cherry Pickers', division: 'C Division' },
	{ id: 4, name: 'Jagrbombers', division: 'C Division' },
	{ id: 5, name: 'Puck Dynasty', division: 'C Division' },
	{ id: 6, name: 'Replacements', division: 'C Division' },
	{ id: 7, name: 'Kings', division: 'C Division' },
];

export const DUCKS = 1;
export const KNIGHTS = 2;
export const CHERRY = 3;
export const JAGR = 4;
export const PUCK = 5;
export const REPLACEMENTS = 6;
export const KINGS = 7;

export function team( id: number ): TeamInfo {
	return TEAMS.find( ( t ) => t.id === id ) ?? TEAMS[ 0 ];
}

/* Venues. */

export type VenueKey = 'black' | 'red' | 'appleby2';

const WAVE = { rink: 'Wave Twin Rinks', address: '1179 Northside Rd, Burlington, ON', map_url: 'https://www.google.com/maps/search/?api=1&query=Wave+Twin+Rinks+1179+Northside+Rd+Burlington+ON' };
const APPLEBY = { rink: 'Appleby Ice Centre', address: '1201 Appleby Line, Burlington, ON', map_url: 'https://www.google.com/maps/search/?api=1&query=Appleby+Ice+Centre+1201+Appleby+Line+Burlington+ON' };

export const VENUES = {
	black: { name: 'Black', ...WAVE },
	red: { name: 'Red', ...WAVE },
	appleby2: { name: 'Appleby 2', ...APPLEBY },
} as const;

/* Clock helpers: every time in the demo is relative to the real clock, in the league's timezone. */

function offsetMinutes( at: Date ): number {
	const name = new Intl.DateTimeFormat( 'en-US', { timeZone: LEAGUE_TZ, timeZoneName: 'longOffset' } ).formatToParts( at ).find( ( p ) => p.type === 'timeZoneName' )?.value ?? 'GMT';
	const m = /GMT([+-])(\d{2}):(\d{2})/.exec( name );
	return m ? ( m[ 1 ] === '-' ? -1 : 1 ) * ( Number( m[ 2 ] ) * 60 + Number( m[ 3 ] ) ) : 0;
}

/** "2026-11-04" + "21:45" as an ISO string with the league's UTC offset for that moment. */
export function leagueISO( date: string, hhmm: string ): string {
	const [ y, mo, d ] = date.split( '-' ).map( Number );
	const [ h, mi ] = hhmm.split( ':' ).map( Number );
	const off = offsetMinutes( new Date( Date.UTC( y, mo - 1, d, h, mi ) ) );
	const abs = Math.abs( off );
	return `${ date }T${ hhmm }:00${ off < 0 ? '-' : '+' }${ String( Math.floor( abs / 60 ) ).padStart( 2, '0' ) }:${ String( abs % 60 ).padStart( 2, '0' ) }`;
}

function leagueMinutes( now: Date ): number {
	const parts = new Intl.DateTimeFormat( 'en-US', { timeZone: LEAGUE_TZ, hour: 'numeric', minute: 'numeric', hourCycle: 'h23' } ).formatToParts( now );
	const get = ( type: string ) => Number( parts.find( ( p ) => p.type === type )?.value ?? 0 );
	return ( get( 'hour' ) % 24 ) * 60 + get( 'minute' );
}

/** The demo's "tonight": today, unless it is already evening, in which case tomorrow (so the game is always still ahead). */
export function gameDay( now: Date ): string {
	const today = leagueDate( now );
	return leagueMinutes( now ) >= 19 * 60 + 30 ? addDays( today, 1 ) : today;
}

/* Rosters. */

const FIRST = [ 'Alex', 'Ben', 'Cam', 'Drew', 'Eli', 'Finn', 'Gus', 'Hank', 'Ian', 'Jules', 'Kit', 'Luca', 'Miles', 'Nate', 'Otis', 'Pete', 'Quinn', 'Reid', 'Seth', 'Theo', 'Uri', 'Vic', 'Wes', 'Xavier', 'Yuri', 'Zane', 'Arlo', 'Bram', 'Cole', 'Dane' ];
const LAST = [
	'Abbott', 'Barlow', 'Calloway', 'Dunmore', 'Ellery', 'Fairbanks', 'Garrity', 'Hollis', 'Ingram', 'Jessup', 'Kendrick', 'Lindqvist', 'Mercer', 'Norwood', 'Oakley', 'Prescott',
	'Quigley', 'Radcliffe', 'Sutter', 'Thorne', 'Underhill', 'Vance', 'Whitaker', 'Yardley', 'Zeller', 'Ashby', 'Bellamy', 'Crandall', 'Dorsey', 'Eastwood', 'Fenwick', 'Gallagher',
];

export interface Player {
	id: number;
	team: number;
	index: number;
	name: string;
	number: string;
	positions: string[];
	isCaptain: boolean;
}

/** The three people the demo can be, by their place on a roster. */
export const PERSONA_PLAYER = { player: { team: DUCKS, index: 5 }, organizer: { team: DUCKS, index: 2 }, spare: { team: KINGS, index: 6 } } as const;

/** Hand-picked members; everyone else is generated. */
const NAMED: Record<string, { name: string; number: string; positions: string[] }> = {
	[ `${ DUCKS }:5` ]: { name: 'Jordan Pike', number: '17', positions: [ 'Forward' ] },
	[ `${ DUCKS }:2` ]: { name: 'Taylor Brandt', number: '4', positions: [ 'Defence' ] },
	[ `${ DUCKS }:11` ]: { name: 'Marcus Delacroix', number: '31', positions: [ 'Goalie' ] },
	[ `${ KNIGHTS }:3` ]: { name: 'Owen Faraday', number: '1', positions: [ 'Goalie' ] },
	[ `${ KINGS }:6` ]: { name: 'Casey Marlow', number: '9', positions: [ 'Forward' ] },
};

const POSITIONS = [ [ 'Forward' ], [ 'Defence' ], [ 'Forward', 'Defence' ], [ 'Defence' ], [ 'Forward' ] ];

export const ROSTER_SIZE = 15;

function buildRoster( teamId: number ): Player[] {
	const taken = new Set( Object.entries( NAMED ).filter( ( [ key ] ) => key.startsWith( `${ teamId }:` ) ).map( ( [ , v ] ) => v.number ) );
	const players: Player[] = [];
	for ( let i = 0; i < ROSTER_SIZE; i++ ) {
		const n = ( teamId - 1 ) * ROSTER_SIZE + i;
		const named = NAMED[ `${ teamId }:${ i }` ];
		let number = named?.number ?? '';
		if ( ! named ) {
			let candidate = ( i * 7 + teamId * 3 ) % 40 + 2;
			while ( taken.has( String( candidate ) ) ) {
				candidate++;
			}
			taken.add( String( candidate ) );
			number = String( candidate );
		}
		players.push( {
			id: 1000 + n,
			team: teamId,
			index: i,
			name: named?.name ?? `${ FIRST[ n % FIRST.length ] } ${ LAST[ n % LAST.length ] }`,
			number,
			positions: named?.positions ?? POSITIONS[ i % POSITIONS.length ],
			isCaptain: i === 0,
		} );
	}
	return players;
}

const ROSTERS = new Map<number, Player[]>( TEAMS.map( ( t ) => [ t.id, buildRoster( t.id ) ] ) );

export function roster( teamId: number ): Player[] {
	return ROSTERS.get( teamId ) ?? [];
}

export function playerAt( team: number, index: number ): Player {
	return roster( team )[ index ];
}

export function isGoalie( p: Player ): boolean {
	return p.positions.includes( 'Goalie' );
}

/** Small stable hash, so "random" answers do not change between loads. */
export function hash( seed: number ): number {
	let n = Math.imul( seed ^ ( seed >>> 15 ), 2246822507 );
	n = Math.imul( n ^ ( n >>> 13 ), 3266489909 );
	return ( n ^ ( n >>> 16 ) ) >>> 0;
}
