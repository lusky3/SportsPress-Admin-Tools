import { TwoTap } from '../../components/TwoTap';
import { periodLabel } from '../../lib/live';
import { Link } from '../../router';
import type { GameLog, Score, TeamSide } from '../../types';

/** The score at the top of the sheet. */
export function ScoreBoard( { home, away, score, final, period }: { home: TeamSide; away: TeamSide; score: Score; final: boolean; period: number } ) {
	const mid = final ? 'Final' : periodLabel( period );
	return (
		<div className={ final ? 'board-score is-final' : 'board-score' } aria-label={ `${ home.name } ${ score.home }, ${ away.name } ${ score.away }, ${ final ? 'final' : periodLabel( period ) }` } role="group">
			{ [ home, away ].map( ( team, i ) => (
				<div key={ team.id } className="board-score__team">
					<span className="board-score__name">{ team.name }</span>
					<span className="board-score__n" aria-hidden="true">
						{ i ? score.away : score.home }
					</span>
					<span className="board-score__side" aria-hidden="true">
						{ i ? 'Away' : 'Home' }
					</span>
				</div>
			) ) }
			<span className="board-score__mid" aria-hidden="true">
				{ mid }
			</span>
		</div>
	);
}

/** Under a final game: who closed it, Reopen for organizers, Finish again when the log changed since. */
export function FinalBar( { d, finishBlock, onFinishAgain, onReopen }: { d: GameLog; finishBlock: string; onFinishAgain: () => void; onReopen: () => void } ) {
	return (
		<div className="final-bar">
			<p>
				<strong>Final.</strong> This sheet is closed{ d.finalized?.by ? ` by ${ d.finalized.by.name }` : '' }.
			</p>
			<Link to="/keep">Back to games</Link>
			{ d.can_reopen ? <TwoTap label="Reopen" confirm="Tap again to reopen" onConfirm={ onReopen } /> : null }
			{ d.needs_finish ? (
				<>
					<p>The log changed since this game was finished. Finish again to update the result.</p>
					<button type="button" className="btn-quiet" disabled={ Boolean( finishBlock ) } onClick={ onFinishAgain }>
						{ finishBlock || 'Finish again' }
					</button>
				</>
			) : null }
		</div>
	);
}
