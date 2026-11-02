/*
 * An in-memory stand-in for the sppa/v1 REST API, for the demo. It answers every route the app
 * calls, keeps its writes in memory (so counts move and calls fill) and forgets them on reload.
 * Shapes follow includes/class-rest.php and src/types.ts.
 */
import { addDays, leagueDate } from '../lib/dates';
import type {
	AvailabilityPlayer,
	AvailabilityStatus,
	Counts,
	Game,
	GameDetail,
	GameStatus,
	Me,
	NightGame,
	NightPayload,
	Position,
	SideAvailability,
	SpareCall,
	SparePrefs,
	TeamDetail,
	TeamRef,
} from '../types';
import {
	CHERRY,
	DUCKS,
	JAGR,
	KINGS,
	KNIGHTS,
	PERSONA_PLAYER,
	PUCK,
	REPLACEMENTS,
	VENUES,
	gameDay,
	hash,
	isGoalie,
	leagueISO,
	playerAt,
	roster,
	team,
} from './league';
import type { Player, VenueKey } from './league';

export type Persona = 'player' | 'spare' | 'organizer';

export interface DemoOptions {
	persona: Persona;
	/** The clock, for tests. Defaults to the real one. */
	now?: Date;
	/** Milliseconds each answer takes. 250 by default. */
	latency?: number;
}

type Status = AvailabilityStatus | 'none';

interface GameRec {
	id: number;
	start: string;
	status: GameStatus;
	venue: VenueKey;
	home: number;
	away: number;
	changed: boolean;
	/** Home goals, away goals; set for games already played. */
	score?: [ number, number ];
	generic?: boolean;
}

interface CallRec {
	id: number;
	event_id: number;
	team_id: number;
	position: Position;
	needed: number;
	note: string;
	status: 'open' | 'filled' | 'cancelled';
	accepted: number;
	created_at: string;
	creator: 'me' | 'other';
	mine: boolean;
}

interface Reply {
	status: number;
	body: unknown;
}

const HOUR = 3_600_000;
const SEASON = { id: 1, name: 'Fall 2026' };

/** The Ducks' answers for tonight, by roster slot: 8 in, 2 out, 1 maybe, 4 not yet. The goalie is out. */
const TONIGHT_DUCKS: Status[] = [ 'in', 'in', 'none', 'in', 'out', 'none', 'in', 'maybe', 'in', 'none', 'in', 'out', 'none', 'in', 'in' ];
const NOTES: Record<number, string> = { 4: 'Work trip', 7: 'Might be late', 11: 'Away this week' };

const TONIGHT = 101;
const NIGHT_BASE = 100_000;

function nightId( date: string, slot: number ): number {
	return NIGHT_BASE + Math.round( Date.parse( date ) / 86_400_000 ) * 10 + slot;
}

function error( status: number, code: string, message: string, reason?: string ): Reply {
	return { status, body: { code, message, data: { status, ...( reason ? { reason } : {} ) } } };
}

const FORBIDDEN = () => error( 403, 'sppa_forbidden', 'You do not have access to that.' );
const NOT_FOUND = () => error( 404, 'sppa_not_found', 'Not found.' );

/** The night's four games: slot, time, pad, home team, away team. Slot 3 is the Ducks'. */
const NIGHT: Array<[ string, VenueKey, number, number ]> = [
	[ '20:15', 'black', KINGS, CHERRY ],
	[ '21:00', 'appleby2', REPLACEMENTS, JAGR ],
	[ '21:15', 'red', PUCK, CHERRY ],
	[ '21:45', 'black', DUCKS, KNIGHTS ],
];

export function createDemoServer( options: DemoOptions ) {
	const persona = options.persona;
	const latency = options.latency ?? 250;
	const clock = () => options.now ?? new Date();
	const today = leagueDate( clock() );
	const G = gameDay( clock() );
	const day = ( offset: number ) => addDays( G, offset );

	const me = playerAt( PERSONA_PLAYER[ persona ].team, PERSONA_PLAYER[ persona ].index );
	const myTeam = me.team;
	const isOrganizer = persona === 'organizer';
	const userNames = { player: 'Jordan Pike', spare: 'Casey Marlow', organizer: 'Taylor Brandt' };

	/* Games. */

	const games = new Map<number, GameRec>();
	const add = ( id: number, offset: number, time: string, venue: VenueKey, home: number, away: number, extra: Partial<GameRec> = {} ) => {
		games.set( id, { id, start: leagueISO( day( offset ), time ), status: 'ok', venue, home, away, changed: false, ...extra } );
	};
	// The Ducks: tonight, four to come, four played.
	add( 101, 0, '21:45', 'black', DUCKS, KNIGHTS );
	add( 102, 7, '20:30', 'appleby2', PUCK, DUCKS );
	add( 103, 14, '19:15', 'red', DUCKS, CHERRY, { changed: true } );
	add( 104, 21, '21:00', 'appleby2', DUCKS, REPLACEMENTS, { status: 'postponed' } );
	add( 105, 28, '21:45', 'black', KINGS, DUCKS );
	add( 106, -7, '21:45', 'black', DUCKS, JAGR, { score: [ 6, 2 ] } );
	add( 107, -14, '20:30', 'appleby2', KINGS, DUCKS, { score: [ 3, 3 ] } );
	add( 108, -21, '21:15', 'red', CHERRY, DUCKS, { score: [ 4, 2 ] } );
	add( 109, -28, '21:45', 'black', DUCKS, REPLACEMENTS, { score: [ 5, 3 ] } );
	// The Kings, for the spare: two played, three to come (plus the Ducks games above).
	add( 201, -21, '20:15', 'appleby2', KINGS, PUCK, { score: [ 4, 1 ] } );
	add( 202, -10, '19:45', 'red', JAGR, KINGS, { score: [ 5, 2 ] } );
	add( 203, 2, '20:45', 'appleby2', KINGS, CHERRY );
	add( 204, 9, '19:30', 'black', REPLACEMENTS, KINGS );
	add( 205, 16, '21:00', 'red', KINGS, KNIGHTS );

	const started = ( rec: GameRec ) => Date.parse( rec.start ) <= clock().getTime();

	/** Ids from nightId() are made up on demand, so the board works for any date. */
	function rec( id: number ): GameRec | undefined {
		const known = games.get( id );
		if ( known || id < NIGHT_BASE ) {
			return known;
		}
		const slot = ( id - NIGHT_BASE ) % 10;
		const date = new Date( Math.floor( ( id - NIGHT_BASE ) / 10 ) * 86_400_000 ).toISOString().slice( 0, 10 );
		const night = NIGHT[ slot ];
		if ( ! night ) {
			return undefined;
		}
		const made: GameRec = { id, start: leagueISO( date, night[ 0 ] ), status: 'ok', venue: night[ 1 ], home: night[ 2 ], away: night[ 3 ], changed: false, generic: true };
		games.set( id, made );
		return made;
	}

	function scoreOf( g: GameRec ): [ number, number ] | undefined {
		if ( g.score ) {
			return g.score;
		}
		return g.generic && started( g ) ? [ 1 + ( hash( g.id ) % 6 ), hash( g.id + 1 ) % 6 ] : undefined;
	}

	/* Answers. */

	const answers = new Map<string, { status: Status; note: string }>();

	function answerOf( gameId: number, p: Player ): { status: Status; note: string } {
		const given = answers.get( `${ gameId }:${ p.id }` );
		if ( given ) {
			return given;
		}
		if ( gameId === TONIGHT && p.team === DUCKS ) {
			return { status: TONIGHT_DUCKS[ p.index ], note: NOTES[ p.index ] ?? '' };
		}
		if ( p.id === me.id ) {
			return { status: 'none', note: '' };
		}
		const r = hash( gameId * 977 + p.id ) % 100;
		return { status: r < 62 ? 'in' : r < 74 ? 'out' : r < 82 ? 'maybe' : 'none', note: '' };
	}

	/* Spare calls. */

	const calls: CallRec[] = [];
	let nextCall = 10;
	const ago = ( hours: number ) => new Date( clock().getTime() - hours * HOUR ).toISOString();
	if ( persona === 'spare' ) {
		calls.push( { id: 1, event_id: TONIGHT, team_id: DUCKS, position: 'goalie', needed: 1, note: 'Black jerseys tonight', status: 'open', accepted: 0, created_at: ago( 2 ), creator: 'other', mine: false } );
	}
	calls.push( { id: 2, event_id: nightId( day( 3 ), 1 ), team_id: JAGR, position: 'skater', needed: 2, note: 'Any skater helps, we have 8', status: 'open', accepted: 0, created_at: ago( 5 ), creator: 'other', mine: false } );
	if ( isOrganizer ) {
		calls.push( { id: 3, event_id: nightId( G, 1 ), team_id: JAGR, position: 'goalie', needed: 1, note: 'Our goalie is hurt', status: 'open', accepted: 0, created_at: ago( 3 ), creator: 'other', mine: false } );
	}

	let prefs: SparePrefs = persona === 'spare' ? { enabled: true, positions: [ 'skater', 'goalie' ] } : { enabled: false, positions: [] };
	let feed = 'https://arl.hockey/?sppa-ical=demo-sample-feed-0001';
	let feeds = 1;

	function callOut( c: CallRec ): SpareCall {
		return {
			id: c.id,
			event_id: c.event_id,
			team_id: c.team_id,
			position: c.position,
			needed: c.needed,
			note: c.note,
			status: c.status,
			accepted: c.accepted,
			created_at: c.created_at,
			is_creator: c.creator === 'me',
			accepted_by_me: c.mine,
			can_cancel: c.creator === 'me' || ( isOrganizer && c.status === 'open' ),
		};
	}

	/* Presenting games. */

	function ref( id: number ): TeamRef {
		return { id, name: team( id ).name, logo: '' };
	}

	function counts( g: GameRec, teamId: number ): Counts & { none: number; goalies_in: number; spares: number } {
		const c = { in: 0, out: 0, maybe: 0, none: 0, goalies_in: 0, spares: 0 };
		for ( const p of roster( teamId ) ) {
			const s = answerOf( g.id, p ).status;
			c[ s ]++;
			if ( s === 'in' && isGoalie( p ) ) {
				c.goalies_in++;
			}
		}
		for ( const call of calls ) {
			if ( call.event_id === g.id && call.team_id === teamId && call.status !== 'cancelled' && call.accepted > 0 ) {
				c.spares += call.accepted;
				c.in += call.accepted;
				c.goalies_in += call.position === 'goalie' ? call.accepted : 0;
			}
		}
		return c;
	}

	function present( g: GameRec, from: number, asSpare = false ): Game {
		const home = g.home === from;
		const sc = scoreOf( g );
		const f = sc ? ( home ? sc[ 0 ] : sc[ 1 ] ) : 0;
		const a = sc ? ( home ? sc[ 1 ] : sc[ 0 ] ) : 0;
		const c = counts( g, from );
		const mine = from === myTeam && ! asSpare ? answerOf( g.id, me ).status : 'none';
		return {
			id: g.id,
			start: g.start,
			status: g.status,
			venue: { ...VENUES[ g.venue ] },
			team: ref( from ),
			opponent: ref( home ? g.away : g.home ),
			is_home: home,
			result: sc ? { for: f, against: a, outcome: f > a ? 'W' : f < a ? 'L' : 'T' } : null,
			changed_recently: g.changed,
			my_availability: mine === 'none' ? null : mine,
			counts: { in: c.in, out: c.out, maybe: c.maybe },
			as_spare: asSpare,
		};
	}

	const sparedCall = ( g: GameRec ) => calls.find( ( c ) => c.event_id === g.id && c.mine && c.status !== 'cancelled' );
	const callGame = ( c: CallRec ) => rec( c.event_id ) as GameRec;
	const callPresented = ( c: CallRec, asSpare: boolean ) => ( { ...callOut( c ), game: present( callGame( c ), c.team_id, asSpare ) } );

	function side( g: GameRec, teamId: number, own: boolean, listed: boolean ): SideAvailability {
		const mine = own && me.team === teamId ? answerOf( g.id, me ) : undefined;
		const players: AvailabilityPlayer[] = listed
			? roster( teamId ).map( ( p ) => {
					const ans = answerOf( g.id, p );
					return { player_id: p.id, name: p.name, number: p.number, positions: p.positions, position: p.positions.join( ', ' ), status: ans.status, note: ans.note };
			  } )
			: [];
		return {
			team_id: teamId,
			mine: mine ? { player_id: me.id, status: mine.status === 'none' ? null : mine.status, note: mine.note } : null,
			editable: isOrganizer || clock().getTime() - Date.parse( g.start ) < 3 * HOUR,
			counts: counts( g, teamId ),
			players,
		};
	}

	function detail( g: GameRec ): Reply {
		const member = g.home === myTeam || g.away === myTeam;
		const spared = sparedCall( g );
		if ( ! member && ! spared && ! isOrganizer ) {
			return FORBIDDEN();
		}
		const from = member ? myTeam : spared ? spared.team_id : g.home;
		const asSpare = ! member && Boolean( spared );
		const other = g.home === from ? g.away : g.home;
		const body: GameDetail = {
			...present( g, from, asSpare ),
			availability: side( g, from, true, ! asSpare ),
			opponent_availability: isOrganizer ? side( g, other, false, true ) : null,
			spare_calls: asSpare ? [] : calls.filter( ( c ) => c.event_id === g.id && c.team_id === from && c.status !== 'cancelled' ).map( callOut ),
		};
		return { status: 200, body };
	}

	/* The schedule of whoever is looking. */

	function myGames(): Game[] {
		const own = [ ...games.values() ].filter( ( g ) => ! g.generic && ( g.home === myTeam || g.away === myTeam ) ).map( ( g ) => present( g, myTeam ) );
		const spared = calls.filter( ( c ) => c.mine && c.status !== 'cancelled' ).map( ( c ) => present( callGame( c ), c.team_id, true ) );
		return [ ...own, ...spared ].sort( ( x, y ) => Date.parse( x.start ) - Date.parse( y.start ) );
	}

	function record( teamId: number ): { w: number; l: number; t: number } {
		const r = { w: 0, l: 0, t: 0 };
		const played = [ ...games.values() ].filter( ( g ) => ! g.generic && g.score && ( g.home === teamId || g.away === teamId ) );
		if ( ! played.length ) {
			const n = hash( teamId );
			return { w: 3 + ( n % 4 ), l: 2 + ( ( n >>> 3 ) % 4 ), t: ( n >>> 6 ) % 3 };
		}
		for ( const g of played ) {
			const [ h, a ] = g.score as [ number, number ];
			const mine = g.home === teamId ? h : a;
			const theirs = g.home === teamId ? a : h;
			r[ mine > theirs ? 'w' : mine < theirs ? 'l' : 't' ]++;
		}
		return r;
	}

	function teamDetail( id: number ): Reply {
		if ( id < 1 || id > 7 ) {
			return NOT_FOUND();
		}
		if ( id !== myTeam && ! isOrganizer ) {
			return FORBIDDEN();
		}
		const t = team( id );
		const body: TeamDetail = {
			id,
			name: t.name,
			logo: '',
			division: t.division,
			record: record( id ),
			roster: roster( id ).map( ( p ) => ( { player_id: p.id, name: p.name, number: p.number, positions: p.positions, is_captain: p.isCaptain, is_me: p.id === me.id } ) ),
		};
		return { status: 200, body };
	}

	function night( date: string ): Reply {
		if ( ! isOrganizer ) {
			return FORBIDDEN();
		}
		if ( ! /^\d{4}-\d{2}-\d{2}$/.test( date ) ) {
			return error( 400, 'sppa_invalid_date', 'Date must look like 2026-11-04.' );
		}
		const list: NightGame[] = NIGHT.map( ( _slot, i ) => {
			const g = ( date === G && i === 3 ? rec( TONIGHT ) : rec( nightId( date, i ) ) ) as GameRec;
			const sides = [ g.home, g.away ].map( ( id ) => {
				const c = counts( g, id );
				return { team: ref( id ), counts: c, goalies_in: c.goalies_in };
			} );
			return {
				...present( g, g.home ),
				sides,
				open_calls: calls.filter( ( c ) => c.event_id === g.id && c.status === 'open' ).map( callOut ),
				has_result: scoreOf( g ) !== undefined,
			};
		} );
		const body: NightPayload = { data: list, total: list.length, page: 1, total_pages: 1, date };
		return { status: 200, body };
	}

	/* Writes. */

	function setAnswer( g: GameRec, body: { status?: string; player_id?: number } ): Reply {
		if ( body.status !== 'in' && body.status !== 'out' && body.status !== 'maybe' ) {
			return error( 400, 'sppa_invalid_status', 'Status must be in, out or maybe.' );
		}
		const playerId = body.player_id ?? me.id;
		const player = [ g.home, g.away ].flatMap( roster ).find( ( p ) => p.id === playerId );
		if ( ! player || ( player.id !== me.id && ! isOrganizer ) ) {
			return FORBIDDEN();
		}
		if ( ! isOrganizer && clock().getTime() - Date.parse( g.start ) >= 3 * HOUR ) {
			return error( 409, 'sppa_locked', 'This game is over; answers are locked.' );
		}
		answers.set( `${ g.id }:${ player.id }`, { status: body.status, note: '' } );
		return {
			status: 200,
			body: {
				success: true,
				availability: { player_id: player.id, team_id: player.team, status: body.status, note: '', updated_at: clock().toISOString(), event_id: g.id },
				counts: counts( g, player.team ),
			},
		};
	}

	function postCall( g: GameRec, body: { team_id?: number; position?: string; needed?: number; note?: string } ): Reply {
		const teamId = Number( body.team_id );
		if ( teamId !== g.home && teamId !== g.away ) {
			return error( 400, 'sppa_team_not_on_game', 'That team is not playing in this game.' );
		}
		if ( teamId !== myTeam && ! isOrganizer ) {
			return FORBIDDEN();
		}
		const needed = Number( body.needed );
		if ( ( body.position !== 'skater' && body.position !== 'goalie' ) || ! Number.isInteger( needed ) || needed < 1 || needed > 3 ) {
			return error( 400, 'sppa_invalid_call', 'A spare call needs a skater or goalie position and 1 to 3 players.' );
		}
		if ( started( g ) || g.status === 'postponed' || g.status === 'cancelled' ) {
			return error( 409, 'sppa_closed', 'That game is over or called off, so it cannot take a spare call.' );
		}
		const call: CallRec = { id: nextCall++, event_id: g.id, team_id: teamId, position: body.position, needed, note: String( body.note ?? '' ).slice( 0, 140 ), status: 'open', accepted: 0, created_at: clock().toISOString(), creator: 'me', mine: false };
		calls.push( call );
		return { status: 200, body: { success: true, call: callOut( call ) } };
	}

	function accept( c: CallRec ): Reply {
		const g = callGame( c );
		if ( c.status === 'filled' ) {
			return error( 409, 'sppa_full', 'That spare call is already full.' );
		}
		if ( c.status === 'cancelled' ) {
			return error( 409, 'sppa_closed', 'That spare call was cancelled.' );
		}
		const no = ( reason: string ) => error( 403, 'sppa_not_eligible', 'You cannot take that spare call.', reason );
		if ( g.home === myTeam || g.away === myTeam ) {
			return no( 'on_team' );
		}
		if ( ! prefs.enabled ) {
			return no( 'not_opted_in' );
		}
		if ( ! prefs.positions.includes( c.position ) ) {
			return no( 'position' );
		}
		if ( started( g ) ) {
			return no( 'past' );
		}
		if ( c.mine ) {
			return no( 'already_sparing' );
		}
		c.mine = true;
		c.accepted++;
		if ( c.accepted >= c.needed ) {
			c.status = 'filled';
		}
		return { status: 200, body: { success: true, call: callOut( c ) } };
	}

	function withdraw( c: CallRec ): Reply {
		if ( ! c.mine ) {
			return FORBIDDEN();
		}
		if ( started( callGame( c ) ) ) {
			return error( 409, 'sppa_started', 'The game has started, so this spare place cannot be given back.' );
		}
		c.mine = false;
		c.accepted = Math.max( 0, c.accepted - 1 );
		c.status = 'open';
		return { status: 200, body: { success: true, call: callOut( c ) } };
	}

	function cancel( c: CallRec ): Reply {
		if ( ! callOut( c ).can_cancel ) {
			return FORBIDDEN();
		}
		c.status = 'cancelled';
		return { status: 200, body: { success: true, call: callOut( c ) } };
	}

	function spares(): Reply {
		const open = calls
			.filter( ( c ) => c.status === 'open' && ! c.mine && prefs.enabled && prefs.positions.includes( c.position ) )
			.filter( ( c ) => ! started( callGame( c ) ) && callGame( c ).home !== myTeam && callGame( c ).away !== myTeam )
			.map( ( c ) => callPresented( c, false ) );
		const accepted = calls.filter( ( c ) => c.mine && c.status !== 'cancelled' ).map( ( c ) => ( { ...callPresented( c, true ), can_withdraw: ! started( callGame( c ) ) } ) );
		const ours = calls.filter( ( c ) => c.team_id === myTeam && c.status !== 'cancelled' && ! started( callGame( c ) ) ).map( ( c ) => callPresented( c, false ) );
		return { status: 200, body: { prefs, open_calls: open, my_accepted: accepted, my_team_calls: ours } };
	}

	/* Routing. */

	function route( method: string, path: string, query: URLSearchParams, body: Record<string, unknown> ): Reply {
		let m: RegExpExecArray | null;
		if ( method === 'GET' ) {
			if ( path === '/me' ) {
				const user: Me = {
					user: { id: 9000 + me.index, name: userNames[ persona ] },
					linked: true,
					players: [ { id: me.id, name: me.name, number: me.number, positions: me.positions } ],
					teams: [ { id: myTeam, name: team( myTeam ).name, logo: '', division: team( myTeam ).division } ],
					season: SEASON,
					is_organizer: isOrganizer,
					spare_prefs: prefs,
					ical_url: feed,
				};
				return { status: 200, body: user };
			}
			if ( path === '/me/games' ) {
				const list = myGames();
				return { status: 200, body: { data: list, total: list.length, page: 1, total_pages: 1 } };
			}
			if ( ( m = /^\/games\/(\d+)$/.exec( path ) ) ) {
				const g = rec( Number( m[ 1 ] ) );
				return g ? detail( g ) : NOT_FOUND();
			}
			if ( ( m = /^\/teams\/(\d+)$/.exec( path ) ) ) {
				return teamDetail( Number( m[ 1 ] ) );
			}
			if ( path === '/spares' ) {
				return spares();
			}
			if ( path === '/ops/night' ) {
				return night( query.get( 'date' ) ?? today );
			}
		}
		if ( method === 'PUT' ) {
			if ( ( m = /^\/games\/(\d+)\/availability$/.exec( path ) ) ) {
				const g = rec( Number( m[ 1 ] ) );
				return g ? setAnswer( g, body ) : NOT_FOUND();
			}
			if ( path === '/spares/prefs' ) {
				const positions = Array.isArray( body.positions ) ? ( body.positions as Position[] ).filter( ( p ) => p === 'skater' || p === 'goalie' ) : [];
				prefs = { enabled: Boolean( body.enabled ), positions };
				return { status: 200, body: { success: true, ...prefs } };
			}
		}
		if ( method === 'POST' ) {
			if ( ( m = /^\/games\/(\d+)\/spare-calls$/.exec( path ) ) ) {
				const g = rec( Number( m[ 1 ] ) );
				return g ? postCall( g, body ) : NOT_FOUND();
			}
			if ( ( m = /^\/spare-calls\/(\d+)\/(accept|withdraw|cancel)$/.exec( path ) ) ) {
				const c = calls.find( ( x ) => x.id === Number( m![ 1 ] ) );
				if ( ! c ) {
					return NOT_FOUND();
				}
				return m[ 2 ] === 'accept' ? accept( c ) : m[ 2 ] === 'withdraw' ? withdraw( c ) : cancel( c );
			}
			if ( path === '/me/ical-token/rotate' ) {
				feed = `https://arl.hockey/?sppa-ical=demo-sample-feed-${ String( ++feeds ).padStart( 4, '0' ) }`;
				return { status: 200, body: { success: true, ical_url: feed } };
			}
		}
		return NOT_FOUND();
	}

	/** A `fetch` for api.ts's seam: any URL ending in the REST path, e.g. '/demo-api/games/101'. */
	const fetchLike = async ( input: RequestInfo | URL, init: RequestInit = {} ): Promise<Response> => {
		const url = new URL( typeof input === 'string' ? input : input instanceof URL ? input.href : input.url, 'http://demo.invalid' );
		const path = url.pathname.replace( /^\/demo-api/, '' ).replace( /\/+$/, '' ) || '/';
		let body: Record<string, unknown> = {};
		try {
			body = typeof init.body === 'string' ? ( JSON.parse( init.body ) as Record<string, unknown> ) : {};
		} catch {
			body = {};
		}
		if ( latency > 0 ) {
			await new Promise( ( resolve ) => setTimeout( resolve, latency ) );
		}
		const reply = route( ( init.method ?? 'GET' ).toUpperCase(), path, url.searchParams, body );
		return new Response( JSON.stringify( reply.body ), { status: reply.status, headers: { 'Content-Type': 'application/json', Date: new Date().toUTCString() } } );
	};

	return { fetch: fetchLike as typeof fetch, tonight: TONIGHT, gameDay: G };
}
