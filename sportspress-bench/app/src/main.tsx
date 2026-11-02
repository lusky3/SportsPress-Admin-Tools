import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import './styles/tokens.css';
import './styles/app.css';
import './styles/screens.css';
import { App } from './App';
import { watchInstall } from './lib/install';

// The install prompt fires once, early; hold it for the tip on Next and Me.
watchInstall();

createRoot( document.getElementById( 'root' ) as HTMLElement ).render(
	<StrictMode>
		<App />
	</StrictMode>
);

// The worker is served by the PHP shell at /bench/sw (no .js: nginx answers any *.js URL
// as a static file before WordPress sees it) and controls /bench/ only.
// Registered in production builds; `vite dev` has no worker to register.
if ( import.meta.env.PROD && 'serviceWorker' in navigator ) {
	window.addEventListener( 'load', () => {
		navigator.serviceWorker.register( '/bench/sw', { scope: '/bench/' } ).catch( () => {
			// No worker just means no offline copy; the app still runs.
		} );
	} );
}
