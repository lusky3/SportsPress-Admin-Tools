import { unsentForGameText } from '../../components/UnsentElsewhere';
import type { GameLog } from '../../types';
import { Sheet } from './Sheet';
import { plural } from './status';
import type { FinishFlow } from './useFinishFlow';

const side = ( n: number | null ) => ( n === null ? '?' : n );

interface Props {
	d: GameLog;
	flow: FinishFlow;
	/** Why Finish is closed ('' when it is not). */
	finishBlock: string;
	/** Another keeper's entries for this game that this session will not send. */
	elsewhere: number;
	note: string;
}

/** The totals Finish will write, and what to check first. */
function Summary( { d, flow, elsewhere }: Pick<Props, 'd' | 'flow' | 'elsewhere'> ) {
	const t = d.totals;
	return (
		<>
			<p className="finish__score">
				{ d.game.home.name } { d.score.home } – { d.game.away.name } { d.score.away }
			</p>
			<p className="muted">{ flow.checking ? 'Checking the latest log…' : `${ t.goals } ${ plural( t.goals, 'goal', 'goals' ) } · ${ t.penalties } ${ plural( t.penalties, 'penalty', 'penalties' ) } · ${ t.pim.home + t.pim.away } PIM` }</p>
			{ flow.changed ? <p className="notice">Another keeper changed this log since you opened it. Check the score above before you finish.</p> : null }
			{ elsewhere ? <p className="notice">{ unsentForGameText( elsewhere ) }</p> : null }
			<p>{ d.entries.some( ( e ) => ! e.voided ) ? 'This writes the score and stats to the league site.' : 'Nothing has been logged, so this finishes 0–0.' }</p>
		</>
	);
}

/** "Finish this game?", or "Replace the result?" when the server already has one. */
export function FinishSheet( { d, flow, finishBlock, elsewhere, note }: Props ) {
	const { exists, busy, checking } = flow;
	let question = null;
	if ( exists ) {
		question = d.finalized ? 'This game’s result or stats were changed after you finished. Replace them?' : `This game already has a result (${ side( exists.home ) }–${ side( exists.away ) }). Replace it?`;
	}
	return (
		<Sheet title={ exists ? 'Replace the result?' : 'Finish this game?' } busy={ checking || busy } onClose={ flow.close }>
			<div className="details">
				{ question ? <p className="finish__q">{ question }</p> : <Summary d={ d } flow={ flow } elsewhere={ elsewhere } /> }
				{ note ? <p className="inline-error">{ note }</p> : null }
				<button
					type="button"
					className="btn-primary btn-block"
					disabled={ busy || checking || Boolean( finishBlock ) }
					aria-busy={ busy || undefined }
					onClick={ () => {
						void flow.finish( Boolean( exists ) );
					} }
				>
					<span>{ exists ? 'Replace' : 'Finish game' }</span>
				</button>
			</div>
		</Sheet>
	);
}
