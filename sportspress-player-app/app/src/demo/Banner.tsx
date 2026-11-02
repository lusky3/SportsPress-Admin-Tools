import { useEffect, useState } from 'react';
import type { Persona } from './server';

const PERSONAS: Array<{ key: Persona; label: string }> = [
	{ key: 'player', label: 'Player' },
	{ key: 'spare', label: 'Spare' },
	{ key: 'organizer', label: 'Organizer' },
];

/** The slim bar on top of the demo: what this is, and who to view it as. */
export function Banner( { persona, onChange }: { persona: Persona; onChange: ( next: Persona ) => void } ) {
	const [ note, setNote ] = useState( false );

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

	return (
		<div className="demo-banner" role="region" aria-label="Demo controls">
			<p className="demo-banner__label" role="status">
				<strong>{ note ? 'Demo — sign out is disabled' : 'Demo · sample data' }</strong>
				<span>View as:</span>
			</p>
			<div className="demo-banner__seg" role="radiogroup" aria-label="View as">
				{ PERSONAS.map( ( p ) => (
					<button key={ p.key } type="button" role="radio" aria-checked={ p.key === persona } className="demo-banner__opt" onClick={ () => p.key !== persona && onChange( p.key ) }>
						{ p.label }
					</button>
				) ) }
			</div>
		</div>
	);
}
