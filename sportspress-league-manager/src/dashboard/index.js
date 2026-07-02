import { createRoot } from '@wordpress/element';
import App from './App';

const root = document.getElementById( 'splm-dashboard-root' );
if ( root ) {
	createRoot( root ).render( <App /> );
}
