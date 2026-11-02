import type { ReactNode } from 'react';
import { OfflineBanner } from './components/OfflineBanner';
import { Problem, SignedOut, Skeleton } from './components/Problem';
import { TabBar } from './components/TabBar';
import { useRoute } from './router';
import type { Route } from './router';
import { Game } from './screens/Game';
import { Next, Unlinked } from './screens/Next';
import { Schedule } from './screens/Schedule';
import type { Me } from './types';
import { useResource } from './useResource';

/*
 * The frame: offline banner, the current screen, the tab bar.
 */

function Placeholder( { title }: { title: string } ) {
	return (
		<section className="screen">
			<h1>{ title }</h1>
			<p className="muted">{ title } is coming.</p>
		</section>
	);
}

const SCREENS: Record<Route['path'], ( route: Route, me: Me ) => ReactNode> = {
	'/': ( _route, me ) => <Next me={ me } />,
	'/schedule': ( _route, me ) => <Schedule me={ me } />,
	'/games/:id': ( route, me ) => <Game key={ route.params.id } id={ route.params.id } me={ me } />,
	'/team/:id': () => <Placeholder title="Team" />,
	'/spares': () => <Placeholder title="Spares" />,
	'/me': () => <Placeholder title="Me" />,
	'/tonight': () => <Placeholder title="Tonight" />,
};

export function App() {
	const route = useRoute();
	const me = useResource<Me>( '/me' );

	let body: ReactNode;
	if ( me.error?.code === 'signed_out' ) {
		body = (
			<section className="screen">
				<SignedOut />
			</section>
		);
	} else if ( ! me.data ) {
		body = <section className="screen">{ me.loading ? <Skeleton /> : <Problem error={ me.error } reload={ me.reload } /> }</section>;
	} else if ( ! me.data.linked && ! me.data.is_organizer ) {
		body = <Unlinked />;
	} else {
		body = SCREENS[ route.path ]( route, me.data );
	}

	const showTabs = Boolean( me.data && ( me.data.linked || me.data.is_organizer ) );
	return (
		<div className="app">
			<OfflineBanner savedAt={ me.savedAt } />
			<main>{ body }</main>
			{ showTabs ? <TabBar current={ route.path } teamId={ me.data?.teams[ 0 ]?.id } organizer={ me.data?.is_organizer } /> : null }
		</div>
	);
}
