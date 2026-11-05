import { useMemo, useState } from 'react';
import { Problem, Skeleton } from '../components/Problem';
import { AccountSwitched, useUnsentForGame } from '../components/UnsentElsewhere';
import { usePending, useSwitched } from '../lib/outbox';
import { Link } from '../router';
import type { GameLog, Me } from '../types';
import { useResource } from '../useResource';
import { GameSheet } from './keep/GameSheet';
import type { Flow, Names } from './keep/GameSheet';
import { useAnnouncer } from './keep/status';
import { useLogRefresh } from './keep/useLogRefresh';

export { NumberPad } from './keep/EntryFlow';
export { reasonText } from './keep/rows';
export { REFRESH_MS } from './keep/useLogRefresh';

/*
 * The timekeeper's game sheet: score, period, Goal and Penalty, the log, Finish.
 * Every entry goes to the outbox first (lib/outbox.ts) and shows at once ("saving", then
 * "waiting to sync"); the server's copy replaces it when acknowledged. Finish only runs on a
 * complete log: nothing waiting, nothing the server refused. The parts live in ./keep/.
 */

/** Player names and numbers by id, from both rosters. */
function useNames( d: GameLog | undefined ): Names {
	return useMemo( () => {
		const n = new Map<number, string>();
		const numbers = new Map<number, string>();
		for ( const p of d ? [ ...d.game.home.roster, ...d.game.away.roster ] : [] ) {
			n.set( p.player_id, p.name );
			numbers.set( p.player_id, p.number );
		}
		return { n, numbers };
	}, [ d ] );
}

const back = (
	<Link to="/keep" className="back">
		<span aria-hidden="true">‹</span> Games
	</Link>
);

/** One game's sheet. */
export function KeepGame( { id, me }: { id: string; me: Me } ) {
	const gameId = Number( id ) || 0;
	const path = `/games/${ gameId }/log`;
	const log = useResource<GameLog>( gameId ? path : '' );
	const out = usePending( gameId );
	const { say, announce } = useAnnouncer( out.pending.length, out.sync );
	const d = log.data?.game.id === gameId ? log.data : undefined;
	useLogRefresh( d && d.status !== 'final' ? path : '' );
	const names = useNames( d );
	const switched = useSwitched();
	const elsewhere = useUnsentForGame( gameId );
	// Kept here, not in the sheet, so they survive the log briefly going (an account check reloading it).
	const [ flow, setFlow ] = useState<Flow>();
	const [ chosen, setChosen ] = useState<number>();
	const [ note, setNote ] = useState( '' );

	if ( switched ) {
		// The server refused this page's sign-in: nothing can be recorded until a reload.
		return (
			<section className="screen">
				{ back }
				<AccountSwitched />
			</section>
		);
	}

	if ( ! d ) {
		return (
			<section className="screen">
				{ back }
				{ log.loading ? <Skeleton rows={ 5 } /> : <Problem error={ log.error } reload={ log.reload } missing="We couldn’t find that game." forbidden="Scoring games is for timekeepers and organizers." /> }
			</section>
		);
	}

	return <GameSheet { ...{ gameId, path, log, d, me, out, names, elsewhere, back, flow, setFlow, chosen, setChosen, note, setNote, say, announce } } />;
}
