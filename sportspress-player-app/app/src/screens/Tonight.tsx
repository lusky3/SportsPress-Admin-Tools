import { goalieLabel } from '../components/CountBar';
import { DayStepper, useDay } from '../components/DayStepper';
import { EmptyState } from '../components/EmptyState';
import { Problem, Skeleton } from '../components/Problem';
import { GameChips, StatusChip } from '../components/StatusChip';
import { timeLabel } from '../lib/dates';
import { callLabel, countLabel, isCalledOff, venueShort } from '../lib/games';
import { liveText } from '../lib/live';
import { Link } from '../router';
import type { NightPayload, NightSide } from '../types';
import { useResource } from '../useResource';

function Side( { side }: { side: NightSide } ) {
	const g = side.goalies_in;
	return (
		<div className="side">
			<p className="side__name">{ side.team.name || 'Team to be set' }</p>
			<p className="side__counts">{ countLabel( side.counts ) }</p>
			<p className={ g ? 'side__goalies' : 'side__goalies is-none' }>
				<span className="dots" aria-hidden="true">
					{ g ? '●'.repeat( Math.min( g, 3 ) ) : '○' }
				</span>{ ' ' }
				{ goalieLabel( g ) }
			</p>
		</div>
	);
}

/** Game-night board for organizers: one day's games, both sides' counts, goalies, open calls, live scores, scores in. */
export function Tonight() {
	const day = useDay();
	const { date } = day;
	const night = useResource<NightPayload>( `/ops/night?date=${ date }` );
	const data = night.data && night.data.date === date ? night.data : undefined;
	const now = new Date();

	let body;
	if ( ! data ) {
		body = night.loading ? <Skeleton /> : <Problem error={ night.error } reload={ night.reload } />;
	} else if ( ! data.data.length ) {
		body = <EmptyState message="No games on this day." />;
	} else {
		body = (
			<ul className="board">
				{ data.data.map( ( game ) => (
					<li key={ game.id } className={ isCalledOff( game ) ? 'board__game is-off' : 'board__game' }>
						<div className="board__head">
							<Link to={ `/games/${ game.id }` } className="board__time">
								{ game.status === 'tbd' ? 'TBD' : timeLabel( game.start ) }
								<span className="sr-only"> game details</span>
							</Link>
							<span className="board__venue">{ venueShort( game.venue ) }</span>
							<span className="chips">
								<GameChips game={ game } />
								{ game.live ? <StatusChip label={ liveText( game.live ) } tone={ game.live.status === 'live' ? 'ice' : 'plain' } /> : null }
								{ game.needs_review_count ? <StatusChip label={ `Needs review (${ game.needs_review_count })` } tone="alert" /> : null }
							</span>
						</div>
						<div className="board__sides">
							{ game.sides.map( ( side ) => (
								<Side key={ side.team.id } side={ side } />
							) ) }
						</div>
						{ game.open_calls.length ? (
							<ul className="board__calls">
								{ game.open_calls.map( ( call ) => (
									<li key={ call.id }>
										{ callLabel( call ) }
										{ call.accepted ? ` (${ call.accepted } found)` : '' }
									</li>
								) ) }
							</ul>
						) : null }
						{ game.has_result ? <p className="board__result">Score in ✓</p> : Date.parse( game.start ) < now.getTime() && ! isCalledOff( game ) && ! game.live ? <p className="board__result is-missing">No score yet</p> : null }
					</li>
				) ) }
			</ul>
		);
	}

	return (
		<section className="screen">
			<DayStepper { ...day } />
			{ body }
		</section>
	);
}
