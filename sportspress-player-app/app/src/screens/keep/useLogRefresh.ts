import { useEffect } from 'react';
import { refresh } from '../../useResource';

/** How often an open sheet fetches the full log, and the longest it waits after failures. */
export const REFRESH_MS = 10_000;
const REFRESH_MAX_MS = 60_000;

/** Showing, and with a connection: worth asking. */
const awake = () => document.visibilityState !== 'hidden' && navigator.onLine;

/**
 * While the sheet is on screen, fetch the full log every 10 s, so another keeper's entries show up
 * here (acknowledgements only carry this phone's own). Asks at once when the tab comes back or the
 * connection returns; sleeps while hidden or offline; one request at a time; after a failure waits
 * 20 s, then 40 s, up to a minute. The fetched log replaces the cached one; this phone's waiting
 * entries are drawn over it by uid (see rows), so none is dropped or counted twice.
 * An empty `path` (no log yet, or a final game) asks for nothing.
 */
export function useLogRefresh( path: string ): void {
	useEffect( () => {
		if ( ! path ) {
			return;
		}
		const life = new AbortController();
		const stopped = () => life.signal.aborted;
		let timer: ReturnType<typeof setTimeout> | undefined;
		let failures = 0;
		let busy = false;
		const later = ( ms: number ) => {
			timer = setTimeout( () => {
				void run();
			}, ms );
		};
		const run = async () => {
			clearTimeout( timer );
			if ( stopped() || busy || ! awake() ) {
				return; // asleep: showing the tab again, or the connection coming back, wakes it
			}
			busy = true;
			const fresh = await refresh( path );
			busy = false;
			if ( stopped() ) {
				return;
			}
			failures = fresh ? 0 : failures + 1;
			later( Math.min( REFRESH_MS * 2 ** failures, REFRESH_MAX_MS ) );
		};
		const wake = () => {
			void run();
		};
		document.addEventListener( 'visibilitychange', wake );
		window.addEventListener( 'online', wake );
		later( REFRESH_MS );
		return () => {
			life.abort();
			clearTimeout( timer );
			document.removeEventListener( 'visibilitychange', wake );
			window.removeEventListener( 'online', wake );
		};
	}, [ path ] );
}
