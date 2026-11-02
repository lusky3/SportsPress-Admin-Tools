import { StrictMode, useState } from 'react';
import { createRoot } from 'react-dom/client';
import '../styles/tokens.css';
import '../styles/app.css';
import '../styles/screens.css';
import './demo.css';
import { App } from '../App';
import { setTransport } from '../api';
import { navigate } from '../router';
import { _resetResourcesForTests as forgetEverything } from '../useResource';
import { Banner } from './Banner';
import { createDemoServer } from './server';
import type { Persona } from './server';

/*
 * The demo entry: the real app, a fake server in memory, hash routing, no service worker,
 * no install prompt. Switching "View as" starts that person's world over.
 */

window.SPPA_BOOT = {
	restRoot: '/demo-api/',
	nonce: 'demo',
	ajaxUrl: '#',
	loginUrl: '#',
	logoutUrl: '#',
	accountUrl: '#/me',
	helpUrl: 'https://help.arl.hockey/',
	appBase: '/',
	appName: 'My ARL',
	siteName: 'ARL',
};

function start( persona: Persona ): void {
	const server = createDemoServer( { persona } );
	setTransport( server.fetch );
	forgetEverything(); // whatever the last person's screens had cached
	navigate( persona === 'organizer' ? '/tonight' : '/', { replace: true } );
}

start( 'player' );

function Demo() {
	const [ persona, setPersona ] = useState<Persona>( 'player' );
	const [ round, setRound ] = useState( 0 );
	return (
		<>
			<Banner
				persona={ persona }
				onChange={ ( next ) => {
					start( next );
					setPersona( next );
					setRound( round + 1 ); // a fresh App, so nothing of the last person lingers
				} }
			/>
			<div className="demo-root">
				<App key={ round } />
			</div>
		</>
	);
}

createRoot( document.getElementById( 'root' ) as HTMLElement ).render(
	<StrictMode>
		<Demo />
	</StrictMode>
);
