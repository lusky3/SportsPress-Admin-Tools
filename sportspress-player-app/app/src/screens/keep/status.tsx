import { useEffect, useRef, useState } from 'react';
import { boot } from '../../boot';
import type { SyncState } from '../../lib/outbox';

export const plural = ( n: number, one: string, many: string ) => ( n === 1 ? one : many );

/** Why Finish is closed ('' when it is not): an entry the server refused, or one still waiting. */
export function finishBlockText( parked: number, waiting: number ): string {
	if ( parked ) {
		return `${ parked } ${ plural( parked, 'entry wasn’t', 'entries weren’t' ) } saved — fix ${ plural( parked, 'it', 'them' ) } first`;
	}
	return waiting ? 'Waiting to sync…' : '';
}

/** The sync badge's words and tone. */
function badge( waiting: number, sync: SyncState, signedOut: boolean ): { text: string; tone: 'off' | 'wait' | 'ok' } {
	if ( signedOut ) {
		return { text: `Sign in again to send ${ waiting } ${ plural( waiting, 'entry', 'entries' ) }`, tone: 'off' };
	}
	if ( waiting ) {
		return sync === 'offline' ? { text: `Offline · ${ waiting } waiting`, tone: 'off' } : { text: `${ waiting } waiting to sync`, tone: 'wait' };
	}
	return { text: sync === 'offline' ? 'Offline' : 'All saved', tone: 'ok' };
}

export function SyncBadge( { waiting, sync, signedOut }: { waiting: number; sync: SyncState; signedOut: boolean } ) {
	const { text, tone } = badge( waiting, sync, signedOut );
	return <span className={ `sync sync--${ tone }` }>{ text }</span>;
}

/** The session ended with entries waiting: nothing is lost, signing in sends them. */
export function SignedOutNotice() {
	return (
		<p className="notice rejected">
			<span>Your session ended. Nothing is lost: sign in and these entries send.</span>
			<a className="btn-quiet" href={ boot().loginUrl }>
				Sign in
			</a>
		</p>
	);
}

/**
 * What the screen reader hears: `announce` says something (again, if it is the same words), and
 * "Synced" once the queue empties, "Waiting to sync" when it gets stuck.
 */
export function useAnnouncer( waiting: number, sync: SyncState ): { say: string; announce: ( text: string ) => void } {
	const [ say, setSay ] = useState( '' );
	const announce = ( text: string ) => setSay( ( s ) => ( s === text ? text + ' ' : text ) );
	const before = useRef( waiting );
	useEffect( () => {
		if ( before.current > 0 && waiting === 0 ) {
			announce( 'Synced' );
		}
		before.current = waiting;
	}, [ waiting ] );
	useEffect( () => {
		if ( waiting && ( sync === 'offline' || sync === 'retrying' ) ) {
			announce( 'Waiting to sync' );
		}
	}, [ sync, waiting ] );
	return { say, announce };
}
