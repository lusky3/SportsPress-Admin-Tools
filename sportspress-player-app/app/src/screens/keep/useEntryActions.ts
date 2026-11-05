import { ApiError, apiSend } from '../../api';
import { currentUser, markSwitched } from '../../lib/account';
import { errorText } from '../../lib/errors';
import { periodLabel } from '../../lib/live';
import { mergeIntoLog, outbox } from '../../lib/outbox';
import type { Rejected, SyncState, VoidResult } from '../../lib/outbox';
import type { EntryInput } from '../../types';
import { invalidate } from '../../useResource';
import type { Row } from './rows';

/** An open entry sheet: a new goal or penalty, or a refused one being entered again. */
export interface EntryFlowState {
	kind: 'goal' | 'penalty';
	initial?: EntryInput;
	replaces?: string;
}

const LOCKED = { sppa_log_locked: 'Someone else is saving to this game. Try again in a moment.' };

interface Args {
	gameId: number;
	path: string;
	sync: SyncState;
	/** The latest period anything says the game has reached. */
	reached: number;
	openEntry: ( flow: EntryFlowState | undefined ) => void;
	setChosen: ( period: number ) => void;
	setNote: ( note: string ) => void;
	announce: ( text: string ) => void;
}

function recorded( e: Omit<EntryInput, 'uid'> ): string {
	if ( e.type === 'goal' ) {
		return 'Goal recorded';
	}
	return e.type === 'penalty' ? 'Penalty recorded' : `${ periodLabel( e.period ) } started`;
}

/** What the keeper's taps do: record an entry, move the period, enter a refused one again, void, reopen. */
export function useEntryActions( { gameId, path, sync, reached, openEntry, setChosen, setNote, announce }: Args ) {
	const box = outbox();

	const record = ( e: Omit<EntryInput, 'uid'>, replaces?: string ) => {
		box.enqueue( gameId, e );
		if ( replaces ) {
			box.dismiss( replaces );
		}
		openEntry( undefined );
		const what = recorded( e );
		announce( sync === 'offline' ? `${ what }. Waiting to sync` : what );
	};

	const choosePeriod = ( p: number ) => {
		setChosen( p );
		if ( p > reached ) {
			record( { type: 'period', period: p } );
		}
	};

	const enterAgain = ( r: Rejected ) => {
		if ( r.entry.type === 'goal' || r.entry.type === 'penalty' ) {
			openEntry( { kind: r.entry.type, initial: r.entry, replaces: r.entry.uid } );
		} else {
			const { uid: _uid, ...rest } = r.entry;
			record( rest, r.entry.uid );
		}
	};

	const voidRow = async ( r: Row ) => {
		setNote( '' );
		if ( r.waiting ) {
			announce( box.discard( r.uid ) === 'undoing' ? 'Undoing' : 'Removed' );
			return;
		}
		try {
			const owner = currentUser();
			const res = await apiSend<VoidResult>( 'POST', `${ path }/${ encodeURIComponent( r.uid ) }/void`, owner > 0 ? { owner } : undefined );
			mergeIntoLog( gameId, [ res.entry ], res.live );
			announce( 'Voided' );
		} catch ( e ) {
			if ( e instanceof ApiError && e.code === 'sppa_wrong_user' ) {
				markSwitched(); // another account is signed in: the sheet asks for a reload
				return;
			}
			setNote( errorText( e, 'That didn’t void. Try again.', LOCKED ) );
		}
	};

	const reopen = async () => {
		try {
			await apiSend( 'POST', `/games/${ gameId }/reopen` );
			invalidate( path );
			announce( 'Game reopened' );
		} catch ( e ) {
			setNote( errorText( e ) );
		}
	};

	return { box, record, choosePeriod, enterAgain, voidRow, reopen };
}
