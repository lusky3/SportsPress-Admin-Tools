import { useState } from 'react';
import { ApiError, apiSend } from '../../api';
import { errorText } from '../../lib/errors';
import type { GameLog, Me, SavedResult, Score } from '../../types';
import { invalidate, setCached } from '../../useResource';
import type { Resource } from '../../useResource';

/** What the log says, to notice another keeper's change while the Finish sheet is open. */
const signature = ( d: GameLog ) => d.entries.map( ( e ) => e.uid + ( e.voided ? '-' : '' ) ).join();

const NOT_FINISHED = { sppa_log_locked: 'Someone else is saving to this game. Try again in a moment.', sppa_not_started: 'This game hasn’t started yet. Finish opens 15 minutes before puck drop.' };

interface Args {
	gameId: number;
	path: string;
	d: GameLog;
	log: Resource<GameLog>;
	me: Me;
	/** The Finish sheet is the one open. */
	isOpen: boolean;
	setOpen: ( open: boolean ) => void;
	setNote: ( note: string ) => void;
	announce: ( text: string ) => void;
}

export interface FinishFlow {
	/** The server already has a result: Finish asks before replacing it. */
	exists: SavedResult | undefined;
	busy: boolean;
	/** Reloading the log before finishing. */
	checking: boolean;
	/** Another keeper changed the log since the sheet opened. */
	changed: boolean;
	open: () => void;
	close: () => void;
	finish: ( overwrite: boolean ) => Promise<void>;
}

/** Finish: open from the server's log, write the result, or ask before replacing someone else's. */
export function useFinishFlow( { gameId, path, d, log, me, isOpen, setOpen, setNote, announce }: Args ): FinishFlow {
	const [ exists, setExists ] = useState<SavedResult>();
	const [ busy, setBusy ] = useState( false );
	const [ seen, setSeen ] = useState( '' );

	const open = () => {
		setNote( '' );
		setSeen( signature( d ) );
		setOpen( true );
		log.reload(); // finish from the server's log, not the copy on screen
	};

	const close = () => {
		setOpen( false );
		setExists( undefined );
		setNote( '' );
	};

	const finish = async ( overwrite: boolean ) => {
		setBusy( true );
		setNote( '' );
		try {
			const res = await apiSend<{ score: Score }>( 'POST', `/games/${ gameId }/finish`, { overwrite } );
			setCached( path, { ...d, status: 'final', score: res.score, can_reopen: me.is_organizer, needs_finish: false } );
			invalidate( path );
			invalidate( '/keep/night' );
			invalidate( '/ops/night' );
			setOpen( false );
			setExists( undefined );
			window.scrollTo?.( 0, 0 ); // show the Final banner
			announce( `Game finished, ${ d.game.home.name } ${ res.score.home }, ${ d.game.away.name } ${ res.score.away }` );
		} catch ( e ) {
			if ( e instanceof ApiError && e.code === 'sppa_result_exists' ) {
				setExists( ( e.data.result as SavedResult | undefined ) ?? { home: null, away: null } );
			} else {
				setNote( errorText( e, 'The game could not be finished. Try again.', NOT_FINISHED ) );
			}
		} finally {
			setBusy( false );
		}
	};

	return {
		exists,
		busy,
		checking: isOpen && log.loading,
		changed: isOpen && ! log.loading && seen !== signature( d ),
		open,
		close,
		finish,
	};
}
