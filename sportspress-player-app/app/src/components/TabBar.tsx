import type { ReactNode } from 'react';
import { Link } from '../router';

interface Tab {
	key: string;
	label: string;
	to: string;
	/** Route patterns that light this tab. */
	matches: string[];
	icon: ReactNode;
}

interface TabBarProps {
	/** Route pattern from useRoute().path. */
	current: string;
	/** The viewer's first team, for the Team tab. The tab is left out without one. */
	teamId?: number;
	/** Adds the Tonight tab. */
	organizer?: boolean;
}

function Icon( { d }: { d: string } ) {
	return (
		<svg className="tab-bar__icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">
			<path d={ d } />
		</svg>
	);
}

function tabsFor( teamId: number | undefined, organizer: boolean | undefined ): Tab[] {
	const tabs: Tab[] = [
		{ key: 'next', label: 'Next', to: '/', matches: [ '/' ], icon: <Icon d="M5 12h14M13 6l6 6-6 6" /> },
		{ key: 'schedule', label: 'Schedule', to: '/schedule', matches: [ '/schedule', '/games/:id' ], icon: <Icon d="M4 6h16v14H4zM4 10h16M8 3v4M16 3v4" /> },
	];
	if ( teamId !== undefined ) {
		tabs.push( {
			key: 'team',
			label: 'Team',
			to: `/team/${ teamId }`,
			matches: [ '/team/:id' ],
			icon: <Icon d="M9 11a3 3 0 100-6 3 3 0 000 6zM3 20c0-3.3 2.7-6 6-6s6 2.7 6 6M17 11a2.5 2.5 0 100-5M17 14c2.5 0 4 2 4 5" />,
		} );
	}
	tabs.push(
		{ key: 'spares', label: 'Spares', to: '/spares', matches: [ '/spares' ], icon: <Icon d="M12 3a9 9 0 100 18 9 9 0 000-18zM12 8v8M8 12h8" /> },
		{ key: 'me', label: 'Me', to: '/me', matches: [ '/me' ], icon: <Icon d="M12 12a4 4 0 100-8 4 4 0 000 8zM4 21c0-4.4 3.6-7 8-7s8 2.6 8 7" /> }
	);
	if ( organizer ) {
		tabs.push( { key: 'tonight', label: 'Tonight', to: '/tonight', matches: [ '/tonight' ], icon: <Icon d="M20 14.5A8 8 0 019.5 4 8 8 0 1020 14.5z" /> } );
	}
	return tabs;
}

/** Bottom tab bar, within thumb reach: Next, Schedule, Team, Spares, Me (and Tonight for organizers). */
export function TabBar( { current, teamId, organizer }: TabBarProps ) {
	return (
		<nav className="tab-bar" aria-label="Main">
			{ tabsFor( teamId, organizer ).map( ( tab ) => {
				const active = tab.matches.includes( current );
				return (
					<Link key={ tab.key } to={ tab.to } className={ active ? 'tab-bar__item is-active' : 'tab-bar__item' } aria-current={ active ? 'page' : undefined }>
						{ tab.icon }
						<span className="tab-bar__label">{ tab.label }</span>
					</Link>
				);
			} ) }
		</nav>
	);
}
