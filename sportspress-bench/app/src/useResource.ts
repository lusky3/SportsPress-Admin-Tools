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

/** Fetch a path. Unless `force`, join a request that is already on its way. */
function load( path: string, force: boolean ): void {
	if ( ! force && pending.has( path ) ) {
		return;
	}
	const generation = supersede( path );
	pending.add( path );
	setSlot( path, { loading: true, error: undefined } );

	apiGetWithMeta<unknown>( path ).then(
		( result ) => {
			if ( generations.get( path ) !== generation ) {
				return; // a newer request (or an invalidate) owns this path now
			}
			pending.delete( path );
			const savedAt = result.savedAt ?? ( result.fromCache ? undefined : new Date() );
			setSlot( path, { data: result.data, savedAt, error: undefined, loading: false, stale: false } );
		},
		( error: unknown ) => {
			if ( generations.get( path ) !== generation ) {
				return;
			}
			pending.delete( path );
			setSlot( path, { error: toApiError( error ), loading: false } );
		}
	);
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
		const online = () => load( path, true );
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
	watchers.clear();
	listeners.clear();
}
