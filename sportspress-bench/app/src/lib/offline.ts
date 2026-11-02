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
		await Promise.all( names.filter( ( name ) => name === 'spbn-shell' || name === 'spbn-api' ).map( ( name ) => caches.delete( name ) ) );
	} catch {
		// Storage can be blocked; signing out should not fail because of it.
	}
}
