import { useId } from 'react';
import type { ReactNode } from 'react';
import { relativeDay, timeLabel } from '../lib/dates';
import { isCalledOff, isPast, venueLine, venueShort } from '../lib/games';
import { Link } from '../router';
import type { Game } from '../types';
import { Crest } from './Crest';
import { AnswerChip, GameChips, ResultPill } from './StatusChip';

function opponentName( game: Game ): string {
	return game.opponent.name || 'Opponent to be set';
}

interface GameHeroProps {
	game: Game;
	now: Date;
	/** The hero's heading: "Next game", "Game". */
	title: string;
	/** The answer toggle, counts, calls: whatever the screen adds under the facts. */
	children?: ReactNode;
}

/** The big navy card: when, who, where, and anything that changed. */
export function GameHero( { game, now, title, children }: GameHeroProps ) {
	const id = useId();
	const off = isCalledOff( game );
	const when = (
		<>
			<span className="hero__day">{ relativeDay( game.start, now ) }</span>
			<span className="hero__time">{ game.status === 'tbd' ? 'Time TBD' : timeLabel( game.start ) }</span>
		</>
	);
	const venue = venueLine( game.venue );
	return (
		<section className={ off ? 'hero is-off' : 'hero' } aria-labelledby={ id }>
			<div className="hero__top">
				<h2 id={ id } className="hero__eyebrow">
					{ title }
				</h2>
				<div className="chips">
					<GameChips game={ game } />
				</div>
			</div>
			<p className="hero__when">{ off ? <s>{ when }</s> : when }</p>
			{ off ? <p className="hero__banner">This game is { game.status }. If it gets a new date, it will show up here.</p> : null }
			{ ! off && game.changed_recently ? <p className="hero__banner">The time or rink changed recently. This is the latest.</p> : null }
			<div className="hero__match">
				<Crest name={ game.team.name } logo={ game.team.logo } size={ 44 } />
				<span className="hero__vs">{ game.is_home ? 'vs' : 'at' }</span>
				<Crest name={ game.opponent.name } logo={ game.opponent.logo } size={ 44 } />
				<div className="hero__teams">
					<p className="hero__opp">{ opponentName( game ) }</p>
					<p className="hero__us">
						{ game.team.name } · { game.is_home ? 'Home' : 'Away' }
					</p>
				</div>
			</div>
			{ venue || game.venue.address ? (
				<div className="hero__where">
					{ venue ? <p className="hero__venue">{ venue }</p> : null }
					{ game.venue.address ? <p className="hero__addr">{ game.venue.address }</p> : null }
					{ game.venue.map_url ? (
						<a className="hero__map" href={ game.venue.map_url } target="_blank" rel="noopener noreferrer">
							Directions<span className="sr-only"> (opens in a new tab)</span>
						</a>
					) : null }
				</div>
			) : null }
			{ game.result && isPast( game, now ) ? (
				<p className="hero__result">
					Final <ResultPill result={ game.result } />
				</p>
			) : null }
			{ children }
		</section>
	);
}

/** One schedule line: when, who, where, and either my answer and the head count (upcoming) or the score (played). */
export function GameRow( { game, now, anchor }: { game: Game; now: Date; anchor?: boolean } ) {
	const off = isCalledOff( game );
	const past = isPast( game, now );
	const who = (
		<>
			<span className="row__vs">{ game.is_home ? 'vs' : 'at' }</span> { opponentName( game ) }
		</>
	);
	return (
		<Link to={ `/games/${ game.id }` } className={ `row${ off ? ' is-off' : '' }${ past ? ' is-past' : '' }` } id={ anchor ? 'next-row' : undefined }>
			<span className="row__when">
				<span className="row__day">{ relativeDay( game.start, now ) }</span>
				<span className="row__time">{ game.status === 'tbd' ? 'TBD' : timeLabel( game.start ) }</span>
			</span>
			<span className="row__main">
				<span className="row__opp">{ off ? <s>{ who }</s> : who }</span>
				<span className="row__venue">{ venueShort( game.venue ) }</span>
				<span className="chips">
					<GameChips game={ game } />
					{ ! past && ! off && ! game.as_spare ? <AnswerChip value={ game.my_availability } /> : null }
				</span>
			</span>
			<span className="row__side">
				{ past ? game.result ? <ResultPill result={ game.result } /> : null : off ? null : <span className="row__count">{ game.counts.in } in</span> }
			</span>
		</Link>
	);
}
