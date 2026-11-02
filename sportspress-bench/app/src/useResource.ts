import { useCallback, useEffect, useState } from 'react';
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

interface Entry {
	data: unknown;
	savedAt: Date | undefined;
}

/** Last good response per path, kept for the life of the page so a screen you come back to paints at once. */
const cache = new Map<string, Entry>();

/** Mounted hooks, so invalidate() can make them refetch. */
const mounted = new Set<{ path: string; reload: () => void }>();

/** The cached data for a path, if any. */
export function getCached<T>( path: string ): T | undefined {
	return cache.get( path )?.data as T | undefined;
}

/** Overwrite the cached data for a path (after a write the server confirmed). Mounted screens pick it up on their next load. */
export function setCached<T>( path: string, data: T ): void {
	cache.set( path, { data, savedAt: new Date() } );
}

/**
 * Forget cached paths (all, or those starting with `prefix`) and refetch any
 * mounted screen that shows one. Call after a write that changes what a list says.
 */
export function invalidate( prefix = '' ): void {
	for ( const path of [ ...cache.keys() ] ) {
		if ( path.startsWith( prefix ) ) {
			cache.delete( path );
		}
	}
	for ( const entry of [ ...mounted ] ) {
		if ( entry.path.startsWith( prefix ) ) {
			entry.reload();
		}
	}
}

interface State {
	path: string;
	data: unknown;
	error: ApiError | undefined;
	loading: boolean;
	savedAt: Date | undefined;
}

function toApiError( error: unknown ): ApiError {
	return error instanceof ApiError ? error : new ApiError( 0, 'unknown', error instanceof Error ? error.message : 'Something went wrong.' );
}

function seed( path: string ): State {
	const hit = cache.get( path );
	return { path, data: hit?.data, error: undefined, loading: true, savedAt: hit?.savedAt };
}

/**
 * GET `path` from the REST API and keep the result in step with the screen.
 * Results are cached in memory by path; a cached copy shows straight away and
 * is refreshed behind it. Refetches when the path changes, on reload(), after
 * invalidate(), and when the browser comes back online.
 */
export function useResource<T>( path: string ): Resource<T> {
	const [ state, setState ] = useState<State>( () => seed( path ) );
	const [ tick, setTick ] = useState( 0 );
	const reload = useCallback( () => setTick( ( n ) => n + 1 ), [] );

	useEffect( () => {
		let live = true;
		setState( seed( path ) );
		apiGetWithMeta<T>( path ).then(
			( result ) => {
				const savedAt = result.savedAt ?? ( result.fromCache ? undefined : new Date() );
				cache.set( path, { data: result.data, savedAt } );
				if ( live ) {
					setState( { path, data: result.data, error: undefined, loading: false, savedAt } );
				}
			},
			( error: unknown ) => {
				if ( live ) {
					setState( ( previous ) => ( { ...previous, path, error: toApiError( error ), loading: false } ) );
				}
			}
		);
		return () => {
			live = false;
		};
	}, [ path, tick ] );

	useEffect( () => {
		const entry = { path, reload };
		mounted.add( entry );
		window.addEventListener( 'online', reload );
		return () => {
			mounted.delete( entry );
			window.removeEventListener( 'online', reload );
		};
	}, [ path, reload ] );

	// For one render after the path changes, state still belongs to the old path.
	const view = state.path === path ? state : seed( path );
	return { data: view.data as T | undefined, error: view.error, loading: view.loading, reload, savedAt: view.savedAt };
}
