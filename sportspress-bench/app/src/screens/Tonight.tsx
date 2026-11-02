import { useState } from 'react';
import { goalieLabel } from '../components/CountBar';
import { EmptyState } from '../components/EmptyState';
import { Problem, Skeleton } from '../components/Problem';
import { GameChips } from '../components/StatusChip';
import { addDays, leagueDate, longDate, timeLabel } from '../lib/dates';
import { callLabel, countLabel, isCalledOff, venueShort } from '../lib/games';
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

/** Game-night board for organizers: one day's games, both sides' counts, goalies, open calls, scores in. */
export function Tonight() {
	const today = leagueDate( new Date() );
	const [ date, setDate ] = useState( today );
	const night = useResource<NightPayload>( `/ops/night?date=${ date }` );
	const shift = Math.round( ( Date.parse( date ) - Date.parse( today ) ) / 86_400_000 );
	const title = { [ -1 ]: 'Last night', 0: 'Tonight', 1: 'Tomorrow' }[ shift ] ?? longDate( date );
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
						{ game.has_result ? <p className="board__result">Score in ✓</p> : Date.parse( game.start ) < now.getTime() && ! isCalledOff( game ) ? <p className="board__result is-missing">No score yet</p> : null }
					</li>
				) ) }
			</ul>
		);
	}

	return (
		<section className="screen">
			<header className="night-head">
				<button type="button" className="stepper" aria-label="Previous day" onClick={ () => setDate( addDays( date, -1 ) ) }>
					‹
				</button>
				<div className="night-head__title">
					<h1>{ title }</h1>
					<p className="muted">{ longDate( date ) }</p>
				</div>
				<button type="button" className="stepper" aria-label="Next day" onClick={ () => setDate( addDays( date, 1 ) ) }>
					›
				</button>
			</header>
			{ shift !== 0 ? (
				<button type="button" className="btn-link" onClick={ () => setDate( today ) }>
					Back to today
				</button>
			) : null }
			{ body }
		</section>
	);
}
