import { boot } from '../boot';

/**
 * Drop everything the service worker saved for offline use (the HTML shell and
 * the API responses). Call before following the sign-out link, so the next person
 * to use a shared device does not open the last person's schedule from cache.
 */
export async function clearOfflineData(): Promise<void> {
	if ( typeof caches === 'undefined' ) {
		return;
	}
	try {
		const names = await caches.keys();
		await Promise.all( names.filter( ( name ) => name === 'sppa-shell' || name === 'sppa-api' ).map( ( name ) => caches.delete( name ) ) );
	} catch {
		// Storage can be blocked; signing out should not fail because of it.
	}
}

/** Clear the saved copies, then follow WordPress's logout link. */
export async function signOut(): Promise<void> {
	if ( import.meta.env.VITE_DEMO === '1' ) {
		// The demo has no session to end; its banner says so.
		window.dispatchEvent( new Event( 'sppa:demo-signout' ) );
		return;
	}
	await clearOfflineData();
	window.location.assign( boot().logoutUrl );
}
