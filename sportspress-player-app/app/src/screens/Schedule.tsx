import { useEffect, useRef } from 'react';
import { EmptyState } from '../components/EmptyState';
import { GameRow } from '../components/GameCard';
import { Problem, Skeleton } from '../components/Problem';
import { groupByMonth, isPast } from '../lib/games';
import type { Game, Me, Paged } from '../types';
import { useResource } from '../useResource';

/** The whole season by month. Opens scrolled to the next game; past rows show the score. */
export function Schedule( { me }: { me: Me } ) {
	const games = useResource<Paged<Game>>( '/me/games' );
	const now = new Date();
	const list = games.data?.data ?? [];
	const nextId = list.find( ( g ) => ! isPast( g, now ) )?.id;
	const scrolled = useRef( false );

	useEffect( () => {
		if ( scrolled.current || nextId === undefined || list[ 0 ]?.id === nextId ) {
			return;
		}
		scrolled.current = true;
		document.getElementById( 'next-row' )?.scrollIntoView?.( { block: 'center' } );
	}, [ nextId, list ] );

	let body;
	if ( ! games.data ) {
		body = games.loading ? <Skeleton rows={ 6 } /> : <Problem error={ games.error } reload={ games.reload } />;
	} else if ( ! list.length ) {
		body = <EmptyState message="No games on the schedule yet. Check back once the season is set." />;
	} else {
		body = groupByMonth( list ).map( ( month ) => (
			<section key={ month.key } className="month" aria-labelledby={ `m-${ month.key }` }>
				<h2 id={ `m-${ month.key }` } className="kicker">
					{ month.label }
				</h2>
				<ul className="rows">
					{ month.games.map( ( game ) => (
						<li key={ game.id }>
							<GameRow game={ game } now={ now } anchor={ game.id === nextId } />
						</li>
					) ) }
				</ul>
			</section>
		) );
	}

	return (
		<section className="screen">
			<header className="screen__head">
				<h1>Schedule</h1>
				{ me.season.name ? <p className="muted">{ me.season.name }</p> : null }
			</header>
			{ body }
		</section>
	);
}
