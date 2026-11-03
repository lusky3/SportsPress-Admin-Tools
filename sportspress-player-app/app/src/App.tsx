import { Suspense, lazy } from 'react';
import type { ReactNode } from 'react';
import { OfflineBanner } from './components/OfflineBanner';
import { Problem, SignedOut, Skeleton } from './components/Problem';
import { TabBar } from './components/TabBar';
import { useRoute } from './router';
import type { Route } from './router';
import { Game } from './screens/Game';
import { Next, Unlinked } from './screens/Next';
import { Me as MeScreen } from './screens/Me';
import { Schedule } from './screens/Schedule';
import { Spares } from './screens/Spares';
import { Team } from './screens/Team';
import { Tonight } from './screens/Tonight';
import type { Me } from './types';
import { useResource } from './useResource';

/*
 * The frame: offline banner, the current screen, the tab bar.
 *
 * Keep time (the list, the game sheet and the outbox) is only for timekeepers and organizers,
 * so it is a separate chunk loaded on first use. The service worker precaches every
 * assets/*.js file, so the chunk is there offline once the worker has installed.
 */

const Keep = lazy( () => import( './screens/Keep' ).then( ( m ) => ( { default: m.Keep } ) ) );
const KeepGame = lazy( () => import( './screens/KeepGame' ).then( ( m ) => ( { default: m.KeepGame } ) ) );

/** May use Keep time (timekeepers and organizers). */
const isKeeper = ( me: Me ) => Boolean( me.is_timekeeper || me.is_organizer );

/** A timekeeper with no player of their own: Keep time and Me are their whole app. */
const keeperOnly = ( me: Me ) => ! me.linked && ! me.is_organizer && isKeeper( me );

const home = ( me: Me ) => ( keeperOnly( me ) ? <Keep /> : <Next me={ me } /> );

const SCREENS: Record<Route['path'], ( route: Route, me: Me ) => ReactNode> = {
	'/': ( _route, me ) => home( me ),
	'/schedule': ( _route, me ) => <Schedule me={ me } />,
	'/games/:id': ( route, me ) => <Game key={ route.params.id } id={ route.params.id } me={ me } />,
	'/team/:id': ( route, me ) => <Team key={ route.params.id } id={ route.params.id } me={ me } />,
	'/spares': ( _route, me ) => <Spares me={ me } />,
	'/me': ( _route, me ) => <MeScreen me={ me } />,
	// These tabs are hidden for everyone else, and the API would refuse them anyway.
	'/tonight': ( _route, me ) => ( me.is_organizer ? <Tonight /> : home( me ) ),
	'/keep': ( _route, me ) => ( isKeeper( me ) ? <Keep /> : home( me ) ),
	'/keep/:id': ( route, me ) => ( isKeeper( me ) ? <KeepGame key={ route.params.id } id={ route.params.id } me={ me } /> : home( me ) ),
};

export function App() {
	const route = useRoute();
	const me = useResource<Me>( '/me' );
	const m = me.data;

	let body: ReactNode;
	if ( me.error?.code === 'signed_out' ) {
		body = (
			<section className="screen">
				<SignedOut />
			</section>
		);
	} else if ( ! m ) {
		body = <section className="screen">{ me.loading ? <Skeleton /> : <Problem error={ me.error } reload={ me.reload } /> }</section>;
	} else if ( ! m.linked && ! isKeeper( m ) ) {
		body = <Unlinked />;
	} else {
		body = SCREENS[ route.path ]( route, m );
	}

	return (
		<div className="app">
			<OfflineBanner savedAt={ me.savedAt } />
			<main>
				<Suspense
					fallback={
						<section className="screen">
							<Skeleton />
						</section>
					}
				>
					{ body }
				</Suspense>
			</main>
			{ m && ( m.linked || isKeeper( m ) ) ? <TabBar current={ route.path } teamId={ m.teams[ 0 ]?.id } organizer={ m.is_organizer } keeper={ isKeeper( m ) } keeperOnly={ keeperOnly( m ) } /> : null }
		</div>
	);
}
