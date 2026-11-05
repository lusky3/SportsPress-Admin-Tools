/*
 * The demo's live scorekeeping: an in-memory game log per game that answers the keeper routes
 * (/keep/night, /games/{id}/log, void, finish, reopen, live) with the shapes of includes/class-rest.php.
 * Validation and scoring follow SPPA_Game_Log, simplified (the score itself uses the app's mirror,
 * lib/live.ts deriveScore). Nothing leaves the page.
 */
import { OFFENSES, deriveScore } from '../lib/live';
import type { EntryInput, KeeperEntry, OffenseKey, KeeperGame, LiveSummary, LiveView, PublicEntry, SavedResult, Score, Totals, UserRef } from '../types';
import { roster, team } from './league';

export interface LogGame {
	id: number;
	start: string;
	home: number;
	away: number;
	/** A result SportsPress already has (entered by someone else), for the Replace flow. */
	preset?: SavedResult;
}

interface Stored {
	uid: string;
	type: EntryInput['type'];
	team_id: number;
	period: number;
	clock: string | null;
	player_id: number | null;
	assist1_id: number | null;
	assist2_id: number | null;
	jersey: string | null;
	minutes: number | null;
	offense: OffenseKey | null;
	flags: Array<'pp' | 'sh' | 'en'>;
	text: string | null;
	needs_review: boolean;
	recorded_by: UserRef;
	recorded_at: string;
	voided_by: UserRef | null;
	voided_at: string | null;
}

interface Reply {
	status: number;
	body: unknown;
}

function error( status: number, code: string, message: string, data: Record<string, unknown> = {} ): Reply {
	return { status, body: { code, message, data: { status, ...data } } };
}

const CLOCK = /^[0-5]?\d:[0-5]\d$/;

export function createKeeperLog( opts: { user: UserRef; organizer: boolean; now: () => Date; onFinish: ( id: number, score: Score ) => void } ) {
	const logs = new Map<number, Stored[]>();
	const finals = new Map<number, { by: UserRef; at: string; home: number; away: number }>();
	const status = new Map<number, 'live' | 'final'>();
	const iso = () => opts.now().toISOString().replace( /\.\d+Z$/, 'Z' );

	const listFor = ( g: LogGame ) => logs.get( g.id ) ?? [];
	const active = ( g: LogGame ) => listFor( g ).filter( ( e ) => ! e.voided_at );
	const players = ( teamId: number ) => roster( teamId );
	const nameOf = ( id: number | null ) => ( id ? [ 1, 2, 3, 4, 5, 6, 7 ].flatMap( players ).find( ( p ) => p.id === id )?.name ?? null : null );

	function derived( g: LogGame ) {
		return deriveScore( active( g ).map( ( e ) => ( { type: e.type, team_id: e.team_id, period: e.period } ) ), g.home, g.away );
	}

	function summary( g: LogGame ): LiveSummary {
		const s = status.get( g.id );
		if ( ! s ) {
			return null;
		}
		const d = derived( g );
		return { status: s, score: { home: d.home, away: d.away }, period: d.period };
	}

	function publicOut( g: LogGame, e: Stored ): PublicEntry {
		return {
			uid: e.uid,
			type: e.type,
			team_id: e.team_id,
			side: e.team_id === g.home ? 'home' : e.team_id === g.away ? 'away' : null,
			period: e.period,
			clock: e.clock,
			player_id: e.player_id,
			player_name: nameOf( e.player_id ),
			jersey: e.jersey,
			not_on_list: ! e.player_id && Boolean( e.jersey ),
			assist1_id: e.assist1_id,
			assist1_name: nameOf( e.assist1_id ),
			assist2_id: e.assist2_id,
			assist2_name: nameOf( e.assist2_id ),
			minutes: e.minutes,
			offense: e.offense,
			flags: e.flags,
			recorded_at: e.recorded_at,
		};
	}

	function keeperOut( g: LogGame, e: Stored ): KeeperEntry {
		const final = status.get( g.id ) === 'final';
		return {
			...publicOut( g, e ),
			text: e.text,
			needs_review: e.needs_review,
			recorded_by: e.recorded_by,
			voided: Boolean( e.voided_at ),
			voided_by: e.voided_by,
			voided_at: e.voided_at,
			can_void: ! e.voided_at && ( e.recorded_by.id === opts.user.id || opts.organizer ) && ( ! final || opts.organizer ),
		};
	}

	function totals( g: LogGame ): Totals {
		const t: Totals = { goals: 0, penalties: 0, needs_review: 0, unattributed_goals: 0, pim: { home: 0, away: 0 }, shootout: { home: 0, away: 0 }, players: [] };
		const add = ( teamId: number, id: number | null, key: 'g' | 'a' | 'pim', by: number ) => {
			if ( ! id ) {
				return;
			}
			let row = t.players.find( ( p ) => p.player_id === id );
			if ( ! row ) {
				row = { team_id: teamId, player_id: id, name: nameOf( id ) ?? '', g: 0, a: 0, pim: 0 };
				t.players.push( row );
			}
			row[ key ] += by;
		};
		for ( const e of active( g ) ) {
			const side = e.team_id === g.home ? 'home' : 'away';
			t.needs_review += e.needs_review ? 1 : 0;
			if ( e.type === 'goal' && e.period === 5 ) {
				t.shootout[ side ]++;
			} else if ( e.type === 'goal' ) {
				t.goals++;
				t.unattributed_goals += e.player_id ? 0 : 1;
				add( e.team_id, e.player_id, 'g', 1 );
				add( e.team_id, e.assist1_id, 'a', 1 );
				add( e.team_id, e.assist2_id, 'a', 1 );
			} else if ( e.type === 'penalty' ) {
				t.penalties++;
				t.pim[ side ] += e.minutes ?? 0;
				add( e.team_id, e.player_id, 'pim', e.minutes ?? 0 );
			}
		}
		return t;
	}

	function keeperGame( g: LogGame ): KeeperGame {
		const side = ( id: number ) => ( {
			id,
			name: team( id ).name,
			logo: '',
			roster: players( id ).map( ( p ) => ( { player_id: p.id, name: p.name, number: p.number, position: p.positions.join( ', ' ), spare: false } ) ),
		} );
		const f = finals.get( g.id );
		const result = f ? { home: f.home, away: f.away } : g.preset ?? null;
		return {
			id: g.id,
			start: g.start,
			status: 'ok',
			venue: { name: '', rink: '', address: '', map_url: '' },
			home: side( g.home ),
			away: side( g.away ),
			live: summary( g ),
			needs_review_count: active( g ).filter( ( e ) => e.needs_review ).length,
			has_result: Boolean( result ),
			result,
		};
	}

	/** SPPA_Game_Log::validate, simplified: the reason codes the app can meet. */
	function validate( g: LogGame, i: Partial<EntryInput> ): string | Stored {
		if ( ! [ 'goal', 'penalty', 'period', 'note' ].includes( String( i.type ) ) ) {
			return 'invalid_type';
		}
		const period = Number( i.period );
		if ( ! Number.isInteger( period ) || period < 1 || period > 5 ) {
			return 'invalid_period';
		}
		const clock = i.clock ? String( i.clock ) : null;
		if ( clock && ! CLOCK.test( clock ) ) {
			return 'invalid_clock';
		}
		const e: Stored = { uid: String( i.uid ), type: i.type as Stored['type'], team_id: 0, period, clock, player_id: null, assist1_id: null, assist2_id: null, jersey: null, minutes: null, offense: null, flags: [], text: i.text ?? null, needs_review: false, recorded_by: opts.user, recorded_at: iso(), voided_by: null, voided_at: null };
		if ( e.type === 'period' || e.type === 'note' ) {
			return i.team_id ? 'team_not_allowed' : e.type === 'note' && ! e.text ? 'text_required' : e;
		}
		const teamId = Number( i.team_id );
		if ( teamId !== g.home && teamId !== g.away ) {
			return 'team_not_on_game';
		}
		e.team_id = teamId;
		const on = ( id?: number ) => players( teamId ).some( ( p ) => p.id === id );
		if ( i.player_id ) {
			if ( ! on( i.player_id ) ) {
				return 'player_not_on_team';
			}
			e.player_id = i.player_id;
		} else if ( ! /^\d{1,3}$/.test( String( i.jersey ?? '' ) ) ) {
			return i.jersey ? 'invalid_jersey' : 'player_required';
		} else {
			e.jersey = String( i.jersey );
		}
		if ( e.type === 'goal' ) {
			const assists = [ i.assist1_id, i.assist2_id ].filter( ( a ): a is number => Boolean( a ) );
			if ( assists.some( ( a ) => ! on( a ) ) ) {
				return 'assist_not_on_team';
			}
			if ( assists.length && period === 5 ) {
				return 'no_assists_in_shootout';
			}
			if ( e.player_id && assists.includes( e.player_id ) ) {
				return 'assist_is_scorer';
			}
			if ( assists.length === 2 && assists[ 0 ] === assists[ 1 ] ) {
				return 'assists_same';
			}
			[ e.assist1_id = null, e.assist2_id = null ] = assists;
			e.flags = ( [ 'pp', 'sh', 'en' ] as const ).filter( ( f ) => i.flags?.includes( f ) );
			return e;
		}
		const o = OFFENSES.find( ( x ) => x.key === i.offense );
		if ( ! o ) {
			return 'invalid_offense';
		}
		const minutes = i.minutes ?? o.minutes;
		if ( ! Number.isInteger( minutes ) || minutes < 1 || minutes > 20 ) {
			return 'invalid_minutes';
		}
		e.offense = o.key;
		e.minutes = minutes;
		e.needs_review = o.review;
		return e;
	}

	/** Seed a game with entries as if a keeper had been at it. */
	function seed( g: LogGame, entries: Array<Partial<EntryInput> & { by?: UserRef }> ): void {
		const list = listFor( g );
		entries.forEach( ( raw, n ) => {
			const e = validate( g, { ...raw, uid: `seed-${ g.id }-${ n }` } );
			if ( typeof e !== 'string' ) {
				e.recorded_by = raw.by ?? opts.user;
				list.push( e );
			}
		} );
		logs.set( g.id, list );
		status.set( g.id, 'live' );
	}

	function post( g: LogGame, body: { entries?: unknown } ): Reply {
		const entries = body.entries;
		if ( ! Array.isArray( entries ) || ! entries.length || entries.length > 50 || entries.some( ( e ) => ! e || ! /^[A-Za-z0-9-]{8,36}$/.test( String( ( e as EntryInput ).uid ) ) ) ) {
			return error( 400, 'rest_invalid_param', 'Invalid parameter(s): entries' );
		}
		const list = listFor( g );
		const isNew = ( e: EntryInput ) => ! list.some( ( s ) => s.uid === e.uid );
		if ( status.get( g.id ) === 'final' && ! opts.organizer && entries.some( isNew ) ) {
			return error( 409, 'sppa_finalized', 'This game is finished. An organizer can reopen it.' );
		}
		const results = ( entries as EntryInput[] ).map( ( raw ) => {
			const stored = list.find( ( s ) => s.uid === raw.uid );
			if ( stored ) {
				return { uid: raw.uid, status: 'duplicate', entry: keeperOut( g, stored ) };
			}
			const e = validate( g, raw );
			if ( typeof e === 'string' ) {
				return { uid: raw.uid, status: 'rejected', reason: e };
			}
			list.push( e );
			status.set( g.id, status.get( g.id ) ?? 'live' );
			return { uid: raw.uid, status: 'created', entry: keeperOut( g, e ) };
		} );
		logs.set( g.id, list );
		return { status: 200, body: { results, live: summary( g ) } };
	}

	function log( g: LogGame ): Reply {
		const d = derived( g );
		const f = finals.get( g.id );
		return {
			status: 200,
			body: {
				game: keeperGame( g ),
				status: status.get( g.id ) ?? 'none',
				score: { home: d.home, away: d.away },
				period: d.period,
				decided_in: d.decided_in,
				totals: totals( g ),
				finalized: f ?? null,
				can_reopen: status.get( g.id ) === 'final' && opts.organizer,
				needs_finish: false,
				entries: [ ...listFor( g ) ].reverse().map( ( e ) => keeperOut( g, e ) ),
			},
		};
	}

	function voidEntry( g: LogGame, uid: string ): Reply {
		const e = listFor( g ).find( ( s ) => s.uid === uid );
		if ( ! e ) {
			return error( 404, 'sppa_not_found', 'Not found.' );
		}
		if ( e.recorded_by.id !== opts.user.id && ! opts.organizer ) {
			return error( 403, 'sppa_forbidden', 'Only the person who recorded it, or an organizer, can void that.' );
		}
		if ( status.get( g.id ) === 'final' && ! opts.organizer ) {
			return error( 409, 'sppa_finalized', 'This game is finished. An organizer can reopen it.' );
		}
		if ( ! e.voided_at ) {
			e.voided_at = iso();
			e.voided_by = opts.user;
		}
		return { status: 200, body: { entry: keeperOut( g, e ), live: summary( g ) } };
	}

	function finish( g: LogGame, body: { overwrite?: boolean } ): Reply {
		if ( g.preset && ! body.overwrite ) {
			return error( 409, 'sppa_result_exists', 'This game already has a result. Confirm to replace it.', { result: g.preset } );
		}
		g.preset = undefined;
		const d = derived( g );
		const f = { by: opts.user, at: iso(), home: d.home, away: d.away };
		finals.set( g.id, f );
		status.set( g.id, 'final' );
		opts.onFinish( g.id, { home: d.home, away: d.away } );
		return {
			status: 200,
			body: { success: true, status: 'final', score: { home: d.home, away: d.away }, period: d.period, decided_in: d.decided_in, ot_loss_team_id: 0, totals: totals( g ), skipped_players: [], finalized: f },
		};
	}

	function reopen( g: LogGame ): Reply {
		if ( ! opts.organizer ) {
			return error( 403, 'rest_forbidden', 'Sorry, you are not allowed to do that.' );
		}
		if ( status.get( g.id ) === 'final' ) {
			status.set( g.id, 'live' );
		}
		return { status: 200, body: { success: true, status: status.get( g.id ) ?? 'none' } };
	}

	function live( g: LogGame ): Reply {
		const d = derived( g );
		const body: LiveView = {
			event_id: g.id,
			status: status.get( g.id ) ?? 'none',
			score: { home: d.home, away: d.away },
			period: d.period,
			home: { id: g.home, name: team( g.home ).name, logo: '' },
			away: { id: g.away, name: team( g.away ).name, logo: '' },
			entries: [ ...active( g ) ]
				.reverse()
				.filter( ( e ) => e.type !== 'note' )
				.slice( 0, 20 )
				.map( ( e ) => publicOut( g, e ) ),
		};
		return { status: 200, body };
	}

	return { seed, post, log, voidEntry, finish, reopen, live, keeperGame, summary, needsReview: ( g: LogGame ) => keeperGame( g ).needs_review_count };
}
