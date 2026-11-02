import { CountBar } from '../components/CountBar';
import { GameHero } from '../components/GameCard';
import { Problem, Skeleton } from '../components/Problem';
import { gameIcsHref } from '../lib/calendar';
import { isCalledOff, isPast } from '../lib/games';
import { Link } from '../router';
import type { AvailabilityPlayer, Me, SideAvailability } from '../types';
import { GameActions } from './Next';
import { useGame } from './useGame';

const GROUPS: Array<{ key: AvailabilityPlayer['status']; label: string }> = [
	{ key: 'in', label: 'In' },
	{ key: 'maybe', label: 'Maybe' },
	{ key: 'out', label: 'Out' },
	{ key: 'none', label: 'No reply' },
];

/** A side's players by answer: names and numbers only, notes as they wrote them. Out is listed plainly, never ranked. */
function Roster( { side, title }: { side: SideAvailability; title?: string } ) {
	return (
		<div className="roster">
			{ title ? <h2 className="kicker">{ title }</h2> : null }
			{ title ? <CountBar counts={ side.counts } /> : null }
			{ GROUPS.map( ( group ) => {
				const players = side.players.filter( ( p ) => p.status === group.key );
				if ( ! players.length ) {
					return null;
				}
				const id = `g-${ side.team_id }-${ group.key }`;
				return (
					<section key={ group.key } className={ `group group--${ group.key }` } aria-labelledby={ id }>
						<h3 id={ id } className="group__head">
							{ group.label } <span className="group__n">{ players.length }</span>
						</h3>
						<ul className="people">
							{ players.map( ( p ) => (
								<li key={ `${ p.player_id }-${ p.spare ? 's' : '' }` } className="person">
									<span className="person__num" aria-hidden={ ! p.number }>
										{ p.number ? `#${ p.number }` : '' }
									</span>
									<span className="person__name">
										{ p.name }
										{ p.spare ? <span className="chip chip--ice">Spare</span> : null }
										{ p.note ? <span className="person__note">{ p.note }</span> : null }
									</span>
									<span className="person__pos">{ p.position }</span>
								</li>
							) ) }
						</ul>
					</section>
				);
			} ) }
		</div>
	);
}

/** One game in full: the hero, the answer, who's coming, the team's spare calls and a calendar file. */
export function Game( { id, me }: { id: string; me: Me } ) {
	const game = useGame( Number( id ) || 0 );
	const now = new Date();
	const d = game.data && game.data.id === Number( id ) ? game.data : undefined;

	let body;
	if ( ! d ) {
		body = game.loading ? (
			<Skeleton rows={ 5 } />
		) : (
			<Problem error={ game.error } reload={ game.reload } forbidden="This game isn’t one of your games, so it’s not shown here." missing="We couldn’t find that game. It may have been taken off the schedule." />
		);
	} else {
		const ics = isPast( d, now ) || isCalledOff( d ) || d.status === 'tbd' ? '' : gameIcsHref( d, window.location.host );
		body = (
			<>
				<GameHero game={ d } now={ now } title={ d.as_spare ? 'Your spare game' : 'Game' }>
					<GameActions game={ d } detail={ d } me={ me } now={ now } answer={ game.answer } addCall={ game.addCall } dropCall={ game.dropCall } alwaysOfferCall />
				</GameHero>
				{ ics ? (
					<a className="btn-link" href={ ics } download={ `arl-game-${ d.id }.ics` }>
						Add this game to your calendar
					</a>
				) : null }
				{ d.availability.players.length ? <Roster side={ d.availability } /> : null }
				{ d.opponent_availability ? <Roster side={ d.opponent_availability } title={ d.opponent.name || 'Other team' } /> : null }
			</>
		);
	}

	return (
		<section className="screen">
			<Link to="/schedule" className="back">
				<span aria-hidden="true">‹</span> Schedule
			</Link>
			<h1 className="sr-only">Game</h1>
			{ body }
		</section>
	);
}
