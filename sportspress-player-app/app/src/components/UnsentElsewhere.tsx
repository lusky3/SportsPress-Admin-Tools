import { outbox, useSwitched, useUnsentElsewhere } from '../lib/outbox';

/*
 * What this session will not send, said in words a keeper can act on (see lib/outbox.ts, owners).
 */

const one = ( n: number, single: string, many: string ) => ( n === 1 ? single : many );

/** Phone-wide, on the Score list: another keeper's entries, waiting (sign in) or refused (they fix them). */
export function UnsentElsewhere() {
	const { waiting, parked } = useUnsentElsewhere();
	if ( ! waiting && ! parked ) {
		return null;
	}
	return (
		<p className="notice" role="status">
			{ waiting ? `${ waiting } unsent ${ one( waiting, 'entry', 'entries' ) } from another account on this phone — sign in as that keeper to send ${ one( waiting, 'it', 'them' ) }.` : '' }
			{ waiting && parked ? ' ' : '' }
			{ parked ? `${ parked } ${ one( parked, 'entry', 'entries' ) } from another account on this phone ${ one( parked, 'needs', 'need' ) } fixing by that keeper.` : '' }
		</p>
	);
}

/** The words for one game: Finish would leave these out. */
export function unsentForGameText( n: number ): string {
	return `${ n } ${ one( n, 'entry', 'entries' ) } for this game from another keeper ${ one( n, 'hasn’t', 'haven’t' ) } been sent — finishing now leaves ${ one( n, 'it', 'them' ) } out. Sign in as them first.`;
}

/** How many of this game's entries this session will not send. */
export function useUnsentForGame( gameId: number ): number {
	const { waiting, parked, unknown } = useUnsentElsewhere( gameId );
	return waiting + parked + unknown;
}

/** Entries saved while nobody was signed in: asked once, sent only when the keeper says so. */
export function SavedBeforeSignIn( { name }: { name: string } ) {
	const { unknown } = useUnsentElsewhere();
	if ( ! unknown ) {
		return null;
	}
	return (
		<div className="notice" role="status">
			<p>
				{ unknown } { one( unknown, 'entry was', 'entries were' ) } saved before sign-in. Send { one( unknown, 'it', 'them' ) } as { name }?
			</p>
			<button type="button" className="btn-quiet" onClick={ () => outbox().adopt() }>
				Send as { name }
			</button>
		</div>
	);
}

/** The server said another account is signed in on this phone: nothing more is sent until a reload. */
export function AccountSwitched() {
	if ( ! useSwitched() ) {
		return null;
	}
	return (
		<div className="notice rejected" role="alert">
			<p>Another account is signed in on this phone. Reload to continue.</p>
			<button type="button" className="btn-quiet" onClick={ () => window.location.reload() }>
				Reload
			</button>
		</div>
	);
}
