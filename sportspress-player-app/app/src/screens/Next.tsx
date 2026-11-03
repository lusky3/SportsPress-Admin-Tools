import { useState } from 'react';
import { boot } from '../boot';
import { AvailabilityToggle } from '../components/AvailabilityToggle';
import { CountBar } from '../components/CountBar';
import { EmptyState } from '../components/EmptyState';
import { GameHero, GameRow } from '../components/GameCard';
import { InstallTip } from '../components/InstallTip';
import { useLive } from '../components/LiveCard';
import { Problem, Skeleton } from '../components/Problem';
import { liveText } from '../lib/live';
import { CallForm, CallList } from '../components/SpareCalls';
import { isCalledOff, isPast, isShort, nextGame } from '../lib/games';
import type { Game, GameDetail, Me, Paged } from '../types';
import { useResource } from '../useResource';
import { useGame } from './useGame';

/** Logged in, but no player is linked to this account yet (about one in six). Not an error. */
export function Unlinked() {
	const { accountUrl, helpUrl, appName } = boot();
	return (
		<section className="screen">
			<h1 className="sr-only">{ appName }</h1>
			<EmptyState
				message="We couldn't match your account to a player yet."
				action={
					<div className="actions actions--center">
						<a className="btn-primary" href={ accountUrl }>
							<span>Go to My Account</span>
						</a>
						<a className="btn-link" href={ helpUrl }>
							Get help
						</a>
					</div>
				}
			/>
		</section>
	);
}

interface GameBodyProps {
	game: Game;
	detail: GameDetail | undefined;
	me: Me;
	now: Date;
	answer: ReturnType<typeof useGame>['answer'];
	addCall: ReturnType<typeof useGame>['addCall'];
	dropCall: ReturnType<typeof useGame>['dropCall'];
	/** Next shows the spare call button only when the team is short; the game screen always offers it. */
	alwaysOfferCall?: boolean;
}

/** What goes inside and under the hero on both Next and the game screen: answer, counts, spare calls. */
export function GameActions( { game, detail, me, now, answer, addCall, dropCall, alwaysOfferCall }: GameBodyProps ) {
	const [ calling, setCalling ] = useState( false );
	const off = isCalledOff( game );
	const past = isPast( game, now );
	const side = detail?.availability;
	const counts = side?.counts ?? game.counts;
	const canAnswer = ! game.as_spare && ! off && ( side ? Boolean( side.mine ) && side.editable : ! past );
	const value = side?.mine ? side.mine.status : game.my_availability;
	const member = me.is_organizer || me.teams.some( ( t ) => t.id === game.team.id );
	const short = isShort( { ...game, counts }, now );
	const canCall = member && ! game.as_spare && ! off && ! past && Boolean( detail );
	const calls = detail?.spare_calls ?? [];

	return (
		<>
			{ canAnswer ? (
				<div className="hero__answer">
					<p className="hero__ask" aria-hidden="true">
						Are you playing?
					</p>
					<AvailabilityToggle value={ value } onChange={ answer } />
				</div>
			) : null }
			{ game.as_spare && ! off ? <p className="hero__note">You’re playing as a spare. Thanks for helping out.</p> : null }
			{ past || off ? null : <CountBar counts={ counts } /> }
			{ canCall && ( short || alwaysOfferCall || calls.length ) ? (
				<div className="hero__spares">
					<CallList calls={ calls } onCancelled={ dropCall } />
					{ calling ? (
						<CallForm
							gameId={ game.id }
							teamId={ game.team.id }
							onClose={ () => setCalling( false ) }
							onPosted={ ( call ) => {
								addCall( call );
								setCalling( false );
							} }
						/>
					) : short || alwaysOfferCall ? (
						<button type="button" className="btn-ice" onClick={ () => setCalling( true ) }>
							<span>{ short ? 'Short? Call a spare' : calls.length ? 'Call another spare' : 'Call a spare' }</span>
						</button>
					) : null }
				</div>
			) : null }
		</>
	);
}

/** Home: the next game, big, with the answer and the head count; the one after it below. */
export function Next( { me }: { me: Me } ) {
	const games = useResource<Paged<Game>>( me.linked ? '/me/games' : '' );
	const now = new Date();
	const list = games.data?.data ?? [];
	// A game being played right now (a timekeeper is logging it) takes the hero, with its score.
	const playing = list.find( ( g ) => g.live?.status === 'live' && ! isCalledOff( g ) );
	const next = playing ?? nextGame( list, now );
	const game = useGame( next?.id ?? 0 );
	const polled = useLive( playing?.id ?? 0, Boolean( playing ) );
	const live = polled && polled.status !== 'none' ? { status: polled.status, score: polled.score, period: polled.period } : playing?.live;

	if ( ! me.linked ) {
		return <Unlinked />;
	}

	const first = me.user.name.split( ' ' )[ 0 ];
	let body;
	if ( ! games.data ) {
		body = games.loading ? <Skeleton /> : <Problem error={ games.error } reload={ games.reload } />;
	} else if ( ! next ) {
		body = <EmptyState message="No games coming up. Your next one will show up here." />;
	} else {
		// Prefer the detail once it is here (it has goalies and the team's answers); the list row paints first.
		const shown = game.data && game.data.id === next.id ? game.data : next;
		const after = nextGame(
			list.filter( ( g ) => g.id !== next.id ),
			now
		);
		body = (
			<>
				<GameHero game={ shown } now={ now } title={ playing ? 'Playing now' : 'Next game' }>
					{ live ? <p className="hero__live">{ liveText( live, ! shown.is_home ) }</p> : null }
					<GameActions game={ shown } detail={ game.data?.id === next.id ? game.data : undefined } me={ me } now={ now } answer={ game.answer } addCall={ game.addCall } dropCall={ game.dropCall } />
				</GameHero>
				{ after ? (
					<section className="after" aria-labelledby="after-h">
						<h2 id="after-h" className="kicker">
							After that
						</h2>
						<GameRow game={ after } now={ now } />
					</section>
				) : null }
			</>
		);
	}

	return (
		<section className="screen">
			<h1 className="greeting">{ first ? `Hi, ${ first }` : 'Hi there' }</h1>
			{ body }
			<InstallTip />
		</section>
	);
}
