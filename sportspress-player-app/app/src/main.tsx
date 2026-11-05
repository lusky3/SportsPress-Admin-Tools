import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import './styles/tokens.css';
import './styles/app.css';
import './styles/screens.css';
import { App } from './App';
import { boot } from './boot';
import { checkBootUser } from './lib/account';
import { watchInstall } from './lib/install';

// The install prompt fires once, early; hold it for the tip on Next and Me.
watchInstall();

// Clear another account's saved replies before the first request can be answered from them.
void checkBootUser().finally( () => {
	createRoot( document.getElementById( 'root' ) as HTMLElement ).render(
		<StrictMode>
			<App />
		</StrictMode>
	);
} );

// The worker is served by the PHP shell at {appBase}sw (no .js: nginx answers any *.js URL
// as a static file before WordPress sees it) and controls the app's base only.
// The base comes from the shell's boot data: the site can change it.
// Registered in production builds; `vite dev` has no worker to register.
if ( import.meta.env.PROD && 'serviceWorker' in navigator ) {
	window.addEventListener( 'load', () => {
		const { appBase } = boot();
		navigator.serviceWorker.register( `${ appBase }sw`, { scope: appBase } ).catch( () => {
			// No worker just means no offline copy; the app still runs.
		} );
	} );
}
