import { deriveScore, offense } from '../../lib/live';
import type { Queued } from '../../lib/outbox';
import type { EntryInput, GameLog, KeeperEntry, Score } from '../../types';

/** A log line: a stored entry, or one of this phone's waiting entries drawn like one. */
export type Row = KeeperEntry & { waiting?: boolean; saving?: boolean; undoing?: boolean };

/** Every refusal the server can give, in words a volunteer can act on. */
const REASONS: Record<string, string> = {
	invalid_uid: 'That entry wasn’t valid',
	invalid_type: 'That entry wasn’t valid',
	rest_invalid_param: 'That entry wasn’t valid',
	rest_missing_callback_param: 'That entry wasn’t valid',
	invalid_period: 'The period wasn’t valid',
	invalid_clock: 'The clock time wasn’t valid',
	text_too_long: 'The note was too long',
	text_required: 'The note was empty',
	invalid_flags: 'The PP, SH or EN marks weren’t valid',
	team_not_on_game: 'That team isn’t playing in this game',
	team_not_allowed: 'A period marker can’t have a team',
	player_required: 'No player or number was picked',
	player_not_on_team: 'That player isn’t on the team’s list',
	invalid_jersey: 'The jersey number wasn’t valid',
	assist_not_on_team: 'An assist isn’t on the team’s list',
	assist_is_scorer: 'The scorer can’t also get the assist',
	assists_same: 'Both assists were the same player',
	no_assists_in_shootout: 'Shootout goals don’t get assists',
	invalid_offense: 'The penalty type wasn’t valid',
	invalid_minutes: 'The penalty minutes weren’t valid',
	finalized: 'The game was already finished',
	called_off: 'The game is postponed or cancelled',
	no_teams: 'This game doesn’t have two teams yet',
	not_found: 'That game or team wasn’t found',
	forbidden: 'You can’t change that entry',
};

export function reasonText( reason: string ): string {
	return REASONS[ reason.replace( /^void_/, '' ) ] ?? 'The server didn’t accept it';
}

/** A pending entry drawn like a stored one. */
export function asRow( e: EntryInput, names: Map<number, string> ): Row {
	const name = ( id?: number ) => ( id ? names.get( id ) ?? null : null );
	const o = offense( e.offense );
	// Absent ids stay undefined, which every reader treats like the server's null.
	return {
		...e,
		team_id: e.team_id ?? 0,
		player_name: name( e.player_id ),
		assist1_name: name( e.assist1_id ),
		assist2_name: name( e.assist2_id ),
		not_on_list: ! e.player_id && Boolean( e.jersey ),
		minutes: e.minutes ?? o?.minutes ?? null,
		flags: e.flags ?? [],
		needs_review: Boolean( o?.review ),
		can_void: true,
		waiting: true,
	} as unknown as Row;
}

/**
 * The log as drawn: this phone's waiting entries first (newest on top), then the server's rows.
 * A server row whose uid is still waiting here is left out until acknowledged, so nothing shows
 * or counts twice; one being undone shows struck through.
 */
export function buildRows( pending: Queued[], entries: KeeperEntry[], names: Map<number, string> ): Row[] {
	const queued = new Map<string, Queued>( pending.map( ( q ) => [ q.entry.uid, q ] ) );
	return [
		...pending
			.filter( ( q ) => ! q.acked )
			.map( ( q ) => ( { ...asRow( q.entry, names ), saving: q.saving, undoing: q.undo } ) )
			.reverse(),
		...entries.filter( ( e ) => ! queued.get( e.uid ) || queued.get( e.uid )?.acked ).map( ( e ) => ( queued.get( e.uid )?.undo ? { ...e, undoing: true } : e ) ),
	];
}

/** Score and period from the rows (undone entries no longer count). */
export function scoreOf( rows: Row[], d: GameLog ): { home: number; away: number; period: number } {
	return deriveScore(
		rows.map( ( r ) => ( r.undoing ? { ...r, voided: true } : r ) ),
		d.game.home.id,
		d.game.away.id
	);
}

/** What the board shows: the worked-out score while anything waits, else the server's. */
export function shownScore( derived: Score, d: GameLog, waiting: number ): Score {
	return waiting ? derived : d.score;
}

/** The small print under a log line. */
export function rowMeta( r: Row, team: string ): string {
	let state = '';
	if ( r.undoing ) {
		state = 'undoing…';
	} else if ( r.saving ) {
		state = 'saving…';
	} else if ( r.waiting ) {
		state = 'waiting to sync';
	}
	const voided = r.voided ? `voided${ r.voided_by ? ` by ${ r.voided_by.name }` : '' }` : '';
	return [ team, r.flags.map( ( f ) => f.toUpperCase() ).join( ' ' ), r.not_on_list ? 'not on the list' : '', r.needs_review ? 'needs review' : '', state, voided ].filter( Boolean ).join( ' · ' );
}
