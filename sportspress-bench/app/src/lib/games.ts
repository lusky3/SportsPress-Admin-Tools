import type { Counts, Game } from '../types';
import { LEAGUE_TZ, monthKey, monthLabel } from './dates';

const HOUR_MS = 3_600_000;

/** A game this close with too few players (or no goalie) is "short". */
const SHORT_WINDOW_HOURS = 72;
const SHORT_BELOW = 10;

function startMs( game: Pick<Game, 'start'> ): number {
	return new Date( game.start ).getTime();
}

function isCalledOff( game: Pick<Game, 'status'> ): boolean {
	return game.status === 'postponed' || game.status === 'cancelled';
}

/** Games grouped by month in the order they first appear, e.g. `{ key: '2026-11', label: 'November 2026' }`. */
export function groupByMonth( games: Game[], tz: string = LEAGUE_TZ ): Array<{ key: string; label: string; games: Game[] }> {
	const groups = new Map<string, { key: string; label: string; games: Game[] }>();
	for ( const game of games ) {
		const key = monthKey( game.start, tz );
		let group = groups.get( key );
		if ( ! group ) {
			group = { key, label: monthLabel( key ), games: [] };
			groups.set( key, group );
		}
		group.games.push( game );
	}
	return [ ...groups.values() ];
}

/** The earliest game that has not started and is not postponed or cancelled. */
export function nextGame( games: Game[], now: Date ): Game | undefined {
	let best: Game | undefined;
	for ( const game of games ) {
		const t = startMs( game );
		if ( Number.isNaN( t ) || t < now.getTime() || isCalledOff( game ) ) {
			continue;
		}
		if ( ! best || t < startMs( best ) ) {
			best = game;
		}
	}
	return best;
}

/**
 * Worth offering "Call a spare": the game is on and starts within 72 hours, and
 * either fewer than 10 are in or no goalie is in. The goalie count only exists
 * on a game's detail, so a list row with no `goalies_in` is judged on head count alone.
 */
export function isShort( game: Pick<Game, 'start' | 'status' | 'counts'>, now: Date ): boolean {
	const ahead = startMs( game ) - now.getTime();
	if ( Number.isNaN( ahead ) || ahead < 0 || ahead > SHORT_WINDOW_HOURS * HOUR_MS || isCalledOff( game ) ) {
		return false;
	}
	return game.counts.in < SHORT_BELOW || game.counts.goalies_in === 0;
}

/** "11 in · 2 out · 4 no reply". Maybe is shown only when someone said maybe. */
export function countLabel( counts: Counts ): string {
	const bits = [ `${ counts.in } in`, `${ counts.out } out` ];
	if ( counts.maybe > 0 ) {
		bits.push( `${ counts.maybe } maybe` );
	}
	if ( typeof counts.none === 'number' ) {
		bits.push( `${ counts.none } no reply` );
	}
	return bits.join( ' · ' );
}
