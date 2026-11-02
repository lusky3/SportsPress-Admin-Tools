import type { ReactNode } from 'react';
import { boot } from './boot';
import { EmptyState } from './components/EmptyState';
import { OfflineBanner } from './components/OfflineBanner';
import { TabBar } from './components/TabBar';
import { useRoute } from './router';
import type { Route } from './router';
import type { Me } from './types';
import { useResource } from './useResource';

/*
 * The frame: offline banner, the current screen, the tab bar.
 * Every screen below is a placeholder; each is replaced by its real screen.
 */

function Placeholder( { title, route }: { title: string; route: Route } ) {
	const id = route.params.id;
	return (
		<section className="screen">
			<h1>{ title }</h1>
			<p className="muted">{ id ? `${ title } ${ id } is coming.` : `${ title } is coming.` }</p>
		</section>
	);
}

const SCREENS: Record<Route['path'], ( route: Route, me: Me ) => ReactNode> = {
	'/': ( route ) => <Placeholder title="Next" route={ route } />,
	'/schedule': ( route ) => <Placeholder title="Schedule" route={ route } />,
	'/games/:id': ( route ) => <Placeholder title="Game" route={ route } />,
	'/team/:id': ( route ) => <Placeholder title="Team" route={ route } />,
	'/spares': ( route ) => <Placeholder title="Spares" route={ route } />,
	'/me': ( route ) => <Placeholder title="Me" route={ route } />,
	'/tonight': ( route ) => <Placeholder title="Tonight" route={ route } />,
};

function SignedOut() {
	return (
		<section className="screen">
			<EmptyState
				message="Your session has ended."
				action={
					<a className="btn-primary" href={ boot().loginUrl }>
						<span>Sign in</span>
					</a>
				}
			/>
		</section>
	);
}

/** Logged in, but no sp_player is linked to this account (about one in six). Not an error. */
function NotLinked() {
	const { accountUrl, helpUrl } = boot();
	return (
		<section className="screen">
			<div className="card">
				<h1>No player yet</h1>
				<p>We couldn&rsquo;t match your account to a player yet.</p>
				<p>
					<a href={ accountUrl }>Open My Account</a> to link yours, or <a href={ helpUrl }>get help</a>.
				</p>
			</div>
		</section>
	);
}

export function App() {
	const route = useRoute();
	const me = useResource<Me>( '/me' );

	let body: ReactNode;
	if ( me.error?.code === 'signed_out' ) {
		body = <SignedOut />;
	} else if ( ! me.data ) {
		body = me.loading ? (
			<section className="screen" role="status">
				<p className="muted">Loading&hellip;</p>
			</section>
		) : (
			<section className="screen">
				<EmptyState
					message="We can't reach the bench right now."
					action={
						<button type="button" className="btn-primary" onClick={ me.reload }>
							<span>Try again</span>
						</button>
					}
				/>
			</section>
		);
	} else if ( ! me.data.linked && ! me.data.is_organizer ) {
		body = <NotLinked />;
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
