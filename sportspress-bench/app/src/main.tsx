import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import './styles/tokens.css';
import './styles/app.css';
import { App } from './App';

createRoot( document.getElementById( 'root' ) as HTMLElement ).render(
	<StrictMode>
		<App />
	</StrictMode>
);

// The worker is served by the PHP shell at /bench/sw.js and controls /bench/ only.
// Registered in production builds; `vite dev` has no worker to register.
if ( import.meta.env.PROD && 'serviceWorker' in navigator ) {
	window.addEventListener( 'load', () => {
		navigator.serviceWorker.register( '/bench/sw.js', { scope: '/bench/' } ).catch( () => {
			// No worker just means no offline copy; the app still runs.
		} );
	} );
}
