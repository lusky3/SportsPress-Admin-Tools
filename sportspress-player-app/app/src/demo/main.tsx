import { StrictMode, useState } from 'react';
import { createRoot } from 'react-dom/client';
import '../styles/tokens.css';
import '../styles/app.css';
import '../styles/screens.css';
import './demo.css';
import { App } from '../App';
import { setTransport } from '../api';
import { setOutbox } from '../lib/outbox';
import { navigate } from '../router';
import { _resetResourcesForTests as forgetEverything } from '../useResource';
import { Banner } from './Banner';
import { Notice } from './Notice';
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

let server: ReturnType<typeof createDemoServer>;

function start( persona: Persona ): void {
	server = createDemoServer( { persona } );
	setTransport( server.fetch );
	forgetEverything(); // whatever the last person's screens had cached
	setOutbox(); // and anything the last timekeeper had waiting to sync
	navigate( { organizer: '/tonight', keeper: '/keep', player: '/', spare: '/' }[ persona ], { replace: true } );
}

start( 'player' );

function Demo() {
	const [ persona, setPersona ] = useState<Persona>( 'player' );
	const [ round, setRound ] = useState( 0 );
	const [ offline, setOffline ] = useState( false );
	return (
		<>
			<Banner
				persona={ persona }
				onChange={ ( next ) => {
					start( next );
					setPersona( next );
					setOffline( false );
					setRound( round + 1 ); // a fresh App, so nothing of the last person lingers
				} }
				offline={ offline }
				onOffline={ ( value ) => {
					server.setOffline( value );
					setOffline( value );
					// Tell the app what a phone would: the outbox sends again on 'online'.
					window.dispatchEvent( new Event( value ? 'offline' : 'online' ) );
				} }
			/>
			<div className="demo-root">
				<App key={ round } />
			</div>
			<Notice url={ window.location.host + window.location.pathname } />
		</>
	);
}

createRoot( document.getElementById( 'root' ) as HTMLElement ).render(
	<StrictMode>
		<Demo />
	</StrictMode>
);
