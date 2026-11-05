import { useEffect, useState } from 'react';
import type { Persona } from './server';

const PERSONAS: Array<{ key: Persona; label: string }> = [
	{ key: 'player', label: 'Player' },
	{ key: 'spare', label: 'Spare' },
	{ key: 'organizer', label: 'Organizer' },
	{ key: 'keeper', label: 'Timekeeper' },
];

interface BannerProps {
	persona: Persona;
	onChange: ( next: Persona ) => void;
	/** Timekeeper only: whether the fake connection is down. */
	offline: boolean;
	onOffline: ( value: boolean ) => void;
}

/** The bar on top of the demo: what this is, who to view it as, and for the timekeeper a fake connection switch. */
export function Banner( { persona, onChange, offline, onOffline }: BannerProps ) {
	const [ note, setNote ] = useState( false );
	const keeper = persona === 'keeper';

	// Sign out does nothing in the demo; say so for a few seconds.
	useEffect( () => {
		let timer: ReturnType<typeof setTimeout> | undefined;
		const show = () => {
			setNote( true );
			clearTimeout( timer );
			timer = setTimeout( () => setNote( false ), 4000 );
		};
		window.addEventListener( 'sppa:demo-signout', show );
		return () => {
			window.removeEventListener( 'sppa:demo-signout', show );
			clearTimeout( timer );
		};
	}, [] );

	// The timekeeper's bar has a second row; the app below moves down to match.
	useEffect( () => {
		document.documentElement.classList.toggle( 'demo-tall', keeper );
	}, [ keeper ] );

	return (
		<div className="demo-banner" role="region" aria-label="Demo controls">
			<div className="demo-banner__main">
				<p className="demo-banner__label" role="status">
					<strong>{ note ? 'Sign out is off' : 'Demo' }</strong>
					<span>View as</span>
				</p>
				<div className="demo-banner__seg" role="radiogroup" aria-label="View as">
					{ PERSONAS.map( ( p ) => (
						<button key={ p.key } type="button" role="radio" aria-checked={ p.key === persona } className="demo-banner__opt" onClick={ () => p.key !== persona && onChange( p.key ) }>
							{ p.label }
						</button>
					) ) }
				</div>
			</div>
			{ keeper ? (
				<div className="demo-banner__row">
					<span>Live recording is simulated.</span>
					<button type="button" className="demo-banner__toggle" aria-pressed={ offline } onClick={ () => onOffline( ! offline ) }>
						<span className="demo-banner__knob" aria-hidden="true" />
						Simulate offline
					</button>
				</div>
			) : null }
		</div>
	);
}
