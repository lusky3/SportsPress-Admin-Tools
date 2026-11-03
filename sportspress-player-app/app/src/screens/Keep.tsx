import { DayStepper, useDay } from '../components/DayStepper';
import { EmptyState } from '../components/EmptyState';
import { Problem, Skeleton } from '../components/Problem';
import { StatusChip } from '../components/StatusChip';
import { timeLabel } from '../lib/dates';
import { venueShort } from '../lib/games';
import { liveText } from '../lib/live';
import { Link } from '../router';
import type { KeeperNight } from '../types';
import { useResource } from '../useResource';

/** Score: the day's games a timekeeper can record, with where each one stands. */
export function Keep() {
	const day = useDay();
	const night = useResource<KeeperNight>( `/keep/night?date=${ day.date }` );
	const data = night.data && night.data.date === day.date ? night.data : undefined;

	let body;
	if ( ! data ) {
		body = night.loading ? <Skeleton /> : <Problem error={ night.error } reload={ night.reload } forbidden="Scoring games is for timekeepers and organizers." />;
	} else if ( ! data.data.length ) {
		body = <EmptyState message="No games to keep on this day." />;
	} else {
		body = (
			<ul className="rows">
				{ data.data.map( ( g ) => (
					<li key={ g.id }>
						<Link to={ `/keep/${ g.id }` } className="row keep-row">
							<span className="row__when">
								<span className="row__day">{ g.status === 'tbd' ? 'TBD' : timeLabel( g.start ) }</span>
							</span>
							<span className="row__main">
								<span className="row__opp">
									{ g.home.name } <span className="row__vs">vs</span> { g.away.name }
								</span>
								<span className="row__venue">{ venueShort( g.venue ) }</span>
								<span className="chips">
									{ g.live ? <StatusChip label={ liveText( g.live ) } tone={ g.live.status === 'live' ? 'ice' : 'plain' } /> : <StatusChip label={ g.has_result ? 'Score in' : 'Not started' } tone="ghost" /> }
									{ g.needs_review_count ? <StatusChip label={ `Needs review (${ g.needs_review_count })` } tone="alert" /> : null }
								</span>
							</span>
							<span className="row__side" aria-hidden="true">
								›
							</span>
						</Link>
					</li>
				) ) }
			</ul>
		);
	}

	return (
		<section className="screen">
			<p className="kicker">Score</p>
			<DayStepper { ...day } />
			{ body }
		</section>
	);
}
