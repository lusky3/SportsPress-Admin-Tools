import { ApiError } from '../api';

/** Why the server would not let this person take a spare call (403 spbn_not_eligible, data.reason). */
const NOT_ELIGIBLE: Record<string, string> = {
	not_opted_in: 'Turn on “I’m happy to spare” first, then you can take calls.',
	position: 'You haven’t offered to play that position. Add it above if you’re up for it.',
	on_team: 'You play for one of the teams in this game, so you can’t spare in it.',
	busy: 'You have a game of your own too close to this one.',
	not_registered: 'Sparing is for players on a team this season.',
	past: 'That game has already started.',
	called_off: 'That game is postponed or cancelled.',
	no_player: 'Your account isn’t linked to a player yet.',
};

/** One plain sentence for a failed write. `fallback` is used when there is nothing more specific to say. */
export function errorText( error: unknown, fallback = 'That didn’t work. Try again.', overrides: Record<string, string> = {} ): string {
	if ( ! ( error instanceof ApiError ) ) {
		return fallback;
	}
	if ( overrides[ error.code ] ) {
		return overrides[ error.code ];
	}
	switch ( error.code ) {
		case 'offline':
			return 'No connection. Try again when you have signal.';
		case 'signed_out':
			return 'Your session has ended. Reload the page to sign in again.';
		case 'spbn_full':
		case 'spbn_closed':
			return 'Someone beat you to it. That call is already taken.';
		case 'spbn_not_eligible':
			return NOT_ELIGIBLE[ error.reason ] ?? 'You can’t take that spare call.';
		case 'spbn_locked':
			return 'This game is over, so answers are locked.';
	}
	return error.status >= 400 && error.status < 500 && error.message ? error.message : fallback;
}
