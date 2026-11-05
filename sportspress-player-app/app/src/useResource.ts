import { useCallback, useEffect, useSyncExternalStore } from 'react';
import { ApiError, apiGetWithMeta } from './api';

export interface Resource<T> {
	/** The latest data, or the saved copy while a fresh one loads. Undefined until the first load. */
	data: T | undefined;
	error: ApiError | undefined;
	/** True while a request is in flight, including a refresh behind data already on screen. Show a skeleton on `loading && !data`. */
	loading: boolean;
	reload(): void;
	/** When the server produced `data` (its Date header). Undefined before the first load. */
	savedAt: Date | undefined;
}

/** Everything the app knows about one path. Replaced, never mutated, so it can be a store snapshot. */
interface Slot {
	data: unknown;
	savedAt: Date | undefined;
	error: ApiError | undefined;
	loading: boolean;
	/** Changed since `data` was fetched (see invalidate); the data still shows until a fresh copy lands. */
	stale: boolean;
}

/** What a path with no history looks like: nothing yet, first load pending. */
const EMPTY: Slot = { data: undefined, savedAt: undefined, error: undefined, loading: true, stale: false };

/** Last good response per path, kept for the life of the page so a screen you come back to paints at once. */
const slots = new Map<string, Slot>();

/** Per-path request counter: only the latest request for a path may write its slot. */
const generations = new Map<string, number>();

/** Paths with a request in flight, so two screens asking at once share one trip. */
const pending = new Set<string>();

/** How many mounted hooks watch each path. */
const watchers = new Map<string, number>();

const listeners = new Set<() => void>();

function getSlot( path: string ): Slot {
	return slots.get( path ) ?? EMPTY;
}

function setSlot( path: string, patch: Partial<Slot> ): void {
	slots.set( path, { ...getSlot( path ), ...patch } );
	for ( const listener of [ ...listeners ] ) {
		listener();
	}
}

function subscribe( listener: () => void ): () => void {
	listeners.add( listener );
	return () => {
		listeners.delete( listener );
	};
}

/** Start a new generation for a path; anything still in flight from an older one is now ignored. */
function supersede( path: string ): number {
	const next = ( generations.get( path ) ?? 0 ) + 1;
	generations.set( path, next );
	pending.delete( path );
	return next;
}

function toApiError( error: unknown ): ApiError {
	return error instanceof ApiError ? error : new ApiError( 0, 'unknown', error instanceof Error ? error.message : 'Something went wrong.' );
}

/** The request in flight per path, so a caller can wait for it (see refresh). */
const inflight = new Map<string, Promise<boolean>>();

/** What a superseded request answers to whoever waits on it: the newer request's outcome, if there is one. */
function successor( path: string ): Promise<boolean> | boolean {
	const newer = pending.has( path ) ? inflight.get( path ) : undefined;
	return newer ?? true;
}

/**
 * Fetch a path. Unless `force`, join a request that is already on its way. Resolves true when
 * fresh data landed. With `freshOnly`, a saved copy from the service worker does not replace data
 * already on screen (it may be older than what this page has merged since).
 */
function load( path: string, force: boolean, freshOnly = false ): Promise<boolean> {
	if ( ! force && pending.has( path ) ) {
		return inflight.get( path ) ?? Promise.resolve( false );
	}
	const generation = supersede( path );
	pending.add( path );
	setSlot( path, { loading: true, error: undefined } );

	const request = apiGetWithMeta<unknown>( path ).then(
		( result ) => {
			if ( generations.get( path ) !== generation ) {
				return successor( path ); // a newer request (or an invalidate) owns this path now
			}
			pending.delete( path );
			if ( freshOnly && result.fromCache && getSlot( path ).data !== undefined ) {
				setSlot( path, { loading: false } );
				return false;
			}
			const savedAt = result.savedAt ?? ( result.fromCache ? undefined : new Date() );
			setSlot( path, { data: result.data, savedAt, error: undefined, loading: false, stale: false } );
			return true;
		},
		( error: unknown ) => {
			if ( generations.get( path ) !== generation ) {
				return successor( path );
			}
			pending.delete( path );
			setSlot( path, { error: toApiError( error ), loading: false } );
			return false;
		}
	);
	inflight.set( path, request );
	return request;
}

/**
 * Fetch a path again behind what is on screen, joining a request already out (one at a time).
 * Resolves true when fresh data landed; a failure or a saved copy leaves the data as it was.
 */
export function refresh( path: string ): Promise<boolean> {
	return load( path, false, true );
}

/** Whether a mounted screen shows this path. */
export function isWatched( path: string ): boolean {
	return ( watchers.get( path ) ?? 0 ) > 0;
}

/** The cached data for a path, if any. Stale data is still returned. */
export function getCached<T>( path: string ): T | undefined {
	return slots.get( path )?.data as T | undefined;
}

/** Whether the cached data for a path was invalidated and has not been refreshed yet. */
export function isStale( path: string ): boolean {
	return getSlot( path ).stale;
}

/** Store data the server just confirmed. Any request still in flight for the path is ignored, so it cannot overwrite this. */
export function setCached<T>( path: string, data: T ): void {
	supersede( path );
	setSlot( path, { data, savedAt: new Date(), error: undefined, loading: false, stale: false } );
}

/**
 * Fold a partial server answer (say, entries just acknowledged) into the cached data. Unlike
 * setCached this keeps the path loading when a request is in flight, and asks again: that request
 * may have been answered before the change, so a fresh one replaces it and the full answer still wins.
 */
export function patchCached<T>( path: string, data: T ): void {
	setSlot( path, { data } );
	if ( pending.has( path ) ) {
		// freshOnly: if the network is slow and the service worker answers with its saved copy, that
		// copy is older than what was just merged; letting it in would drop an acknowledged entry.
		void load( path, true, true );
	}
}

/**
 * Mark cached paths (all, or those starting with `prefix`) stale and refetch the
 * ones a mounted screen is showing. The old data stays on screen until the new
 * response arrives; a response still in flight from before the invalidate is dropped.
 * Call after a write that changes what a list says, e.g. `invalidate('/me/games')`.
 */
export function invalidate( prefix = '' ): void {
	for ( const path of [ ...slots.keys() ] ) {
		if ( ! path.startsWith( prefix ) ) {
			continue;
		}
		supersede( path );
		setSlot( path, { stale: true, loading: false } );
		if ( ( watchers.get( path ) ?? 0 ) > 0 ) {
			load( path, true );
		}
	}
}

/**
 * Drop every path's data (another account's, see lib/account.ts). Requests in flight still land,
 * so a screen waiting on one gets its answer or its error. With `reload`, the paths a mounted
 * screen shows are fetched again at once.
 */
export function forgetData( reload: boolean ): void {
	for ( const path of [ ...slots.keys() ] ) {
		setSlot( path, { data: undefined, savedAt: undefined, stale: false } );
		if ( reload && ( watchers.get( path ) ?? 0 ) > 0 ) {
			load( path, true );
		}
	}
}

/**
 * GET `path` from the REST API and keep the result in step with the screen.
 * Results are cached in memory by path; a cached copy shows straight away and
 * is refreshed behind it. Refetches when the path changes, on reload(), after
 * invalidate(), and when the browser comes back online. An empty path loads nothing.
 */
export function useResource<T>( path: string ): Resource<T> {
	const slot = useSyncExternalStore(
		subscribe,
		() => getSlot( path ),
		() => EMPTY
	);

	useEffect( () => {
		if ( path === '' ) {
			return; // nothing to load (e.g. a screen that waits on another resource)
		}
		watchers.set( path, ( watchers.get( path ) ?? 0 ) + 1 );
		load( path, false );
		const online = () => {
			void load( path, true );
		};
		window.addEventListener( 'online', online );
		return () => {
			window.removeEventListener( 'online', online );
			const left = ( watchers.get( path ) ?? 1 ) - 1;
			if ( left > 0 ) {
				watchers.set( path, left );
			} else {
				watchers.delete( path );
			}
		};
	}, [ path ] );

	const reload = useCallback( () => {
		if ( path !== '' ) {
			load( path, true );
		}
	}, [ path ] );

	return { data: slot.data as T | undefined, error: slot.error, loading: path !== '' && slot.loading, reload, savedAt: slot.savedAt };
}

/** Test helper: forget every cached path and request. */
export function _resetResourcesForTests(): void {
	slots.clear();
	generations.clear();
	pending.clear();
	inflight.clear();
	watchers.clear();
	listeners.clear();
}
