// @vitest-environment jsdom
import { act, cleanup, renderHook, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError, apiGetWithMeta } from './api';
import type { ApiResult } from './api';
import { _resetResourcesForTests, getCached, invalidate, isStale, patchCached, refresh, setCached, useResource } from './useResource';

vi.mock( './api', async ( importOriginal ) => ( { ...( await importOriginal<typeof import( './api' )>() ), apiGetWithMeta: vi.fn() } ) );

const fetchMock = vi.mocked( apiGetWithMeta );

interface Deferred {
	resolve( data: unknown, savedAt?: Date, fromCache?: boolean ): void;
	reject( error: unknown ): void;
}

/** Each call to apiGetWithMeta returns a promise the test settles by hand. */
function queueRequests(): Deferred[] {
	const calls: Deferred[] = [];
	fetchMock.mockImplementation(
		() =>
			new Promise<ApiResult<unknown>>( ( resolve, reject ) => {
				calls.push( {
					resolve: ( data, savedAt, fromCache = false ) => resolve( { data, savedAt, fromCache } ),
					reject,
				} );
			} )
	);
	return calls;
}

beforeEach( () => {
	fetchMock.mockReset();
	_resetResourcesForTests();
} );

afterEach( () => {
	cleanup();
} );

describe( 'useResource', () => {
	it( 'starts loading, then returns the data and when it was saved', async () => {
		const calls = queueRequests();
		const { result } = renderHook( () => useResource<string>( '/me' ) );
		expect( result.current ).toMatchObject( { data: undefined, loading: true, error: undefined, savedAt: undefined } );

		const saved = new Date( '2026-11-04T19:42:00Z' );
		await act( async () => calls[ 0 ].resolve( 'one', saved ) );
		expect( result.current ).toMatchObject( { data: 'one', loading: false, savedAt: saved } );
	} );

	it( 'shows a cached copy straight away and refreshes behind it', async () => {
		const calls = queueRequests();
		setCached( '/me', 'cached' );
		const { result } = renderHook( () => useResource<string>( '/me' ) );
		expect( result.current.data ).toBe( 'cached' );
		expect( result.current.loading ).toBe( true );

		await act( async () => calls[ 0 ].resolve( 'fresh' ) );
		expect( result.current ).toMatchObject( { data: 'fresh', loading: false } );
	} );

	it( 'shares one request between screens that ask for the same path together', () => {
		queueRequests();
		renderHook( () => useResource( '/me' ) );
		renderHook( () => useResource( '/me' ) );
		expect( fetchMock ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'keeps showing the data when a refresh fails, and says why', async () => {
		const calls = queueRequests();
		const { result } = renderHook( () => useResource<string>( '/me' ) );
		await act( async () => calls[ 0 ].resolve( 'one' ) );

		act( () => result.current.reload() );
		await act( async () => calls[ 1 ].reject( new ApiError( 0, 'offline', 'No connection.' ) ) );
		expect( result.current.data ).toBe( 'one' );
		expect( result.current.loading ).toBe( false );
		expect( result.current.error?.code ).toBe( 'offline' );
	} );

	it( 'follows a change of path without showing the old path data', async () => {
		const calls = queueRequests();
		const { result, rerender } = renderHook( ( { path } ) => useResource<string>( path ), { initialProps: { path: '/games/1' } } );
		await act( async () => calls[ 0 ].resolve( 'game one' ) );
		rerender( { path: '/games/2' } );
		expect( result.current ).toMatchObject( { data: undefined, loading: true } );
		await act( async () => calls[ 1 ].resolve( 'game two' ) );
		expect( result.current.data ).toBe( 'game two' );
	} );
} );

describe( 'invalidate', () => {
	it( 'keeps the old data on screen while it refetches, then swaps in the new', async () => {
		const calls = queueRequests();
		const { result } = renderHook( () => useResource<string>( '/me/games' ) );
		await act( async () => calls[ 0 ].resolve( 'old' ) );

		act( () => invalidate( '/me' ) );
		expect( calls ).toHaveLength( 2 );
		expect( result.current ).toMatchObject( { data: 'old', loading: true } ); // no drop back to a skeleton
		expect( isStale( '/me/games' ) ).toBe( true );

		await act( async () => calls[ 1 ].resolve( 'new' ) );
		expect( result.current ).toMatchObject( { data: 'new', loading: false } );
		expect( isStale( '/me/games' ) ).toBe( false );
	} );

	it( 'refetches only mounted paths that match the prefix', async () => {
		const calls = queueRequests();
		renderHook( () => useResource( '/me/games' ) );
		await act( async () => calls[ 0 ].resolve( 'a' ) );
		setCached( '/spares', 'b' );

		act( () => invalidate( '/me' ) );
		expect( calls ).toHaveLength( 2 ); // /me/games again; /spares neither mounted nor matching
		expect( getCached( '/spares' ) ).toBe( 'b' );
		expect( isStale( '/spares' ) ).toBe( false );
	} );

	it( 'marks an unmounted path stale without fetching and keeps its data', () => {
		const calls = queueRequests();
		setCached( '/spares', 'b' );
		invalidate( '/spares' );
		expect( calls ).toHaveLength( 0 );
		expect( getCached( '/spares' ) ).toBe( 'b' );
		expect( isStale( '/spares' ) ).toBe( true );
	} );

	it( 'drops a response that was already in flight when invalidate ran', async () => {
		const calls = queueRequests();
		const { result } = renderHook( () => useResource<string>( '/me/games' ) );
		await waitFor( () => expect( calls ).toHaveLength( 1 ) );

		act( () => invalidate( '/me/games' ) );
		expect( calls ).toHaveLength( 2 );

		// The newer request answers first, then the old one limps in with older data.
		await act( async () => calls[ 1 ].resolve( 'newer' ) );
		await act( async () => calls[ 0 ].resolve( 'older' ) );
		expect( result.current.data ).toBe( 'newer' );
		expect( getCached( '/me/games' ) ).toBe( 'newer' );
	} );

	it( 'does not let a late failure of a dropped request show an error', async () => {
		const calls = queueRequests();
		const { result } = renderHook( () => useResource<string>( '/me/games' ) );
		await waitFor( () => expect( calls ).toHaveLength( 1 ) );
		act( () => invalidate( '/me/games' ) );
		await act( async () => calls[ 1 ].resolve( 'newer' ) );
		await act( async () => calls[ 0 ].reject( new ApiError( 500, 'http_error', 'Boom' ) ) );
		expect( result.current ).toMatchObject( { data: 'newer', error: undefined } );
	} );
} );

describe( 'newest request wins', () => {
	it( 'ignores an older reload that resolves after a newer one', async () => {
		const calls = queueRequests();
		const { result } = renderHook( () => useResource<string>( '/me' ) );
		await act( async () => calls[ 0 ].resolve( 'first' ) );

		act( () => result.current.reload() ); // calls[1]
		act( () => result.current.reload() ); // calls[2]
		await act( async () => calls[ 2 ].resolve( 'third' ) );
		await act( async () => calls[ 1 ].resolve( 'second' ) );
		expect( result.current.data ).toBe( 'third' );
		expect( result.current.loading ).toBe( false );
	} );

	it( 'does not let a request in flight overwrite data stored by setCached', async () => {
		const calls = queueRequests();
		const { result } = renderHook( () => useResource<string>( '/me' ) );
		await waitFor( () => expect( calls ).toHaveLength( 1 ) );

		act( () => setCached( '/me', 'confirmed' ) );
		await act( async () => calls[ 0 ].resolve( 'older' ) );
		expect( result.current.data ).toBe( 'confirmed' );
	} );
} );

describe( 'resource cache', () => {
	it( 'returns what was cached for a path', () => {
		setCached( '/me', { linked: true } );
		expect( getCached( '/me' ) ).toEqual( { linked: true } );
		expect( getCached( '/spares' ) ).toBeUndefined();
	} );

	it( 'invalidates by prefix and leaves other paths alone', () => {
		setCached( '/me', 1 );
		setCached( '/me/games', 2 );
		setCached( '/games/9', 3 );
		invalidate( '/me' );
		expect( isStale( '/me' ) ).toBe( true );
		expect( isStale( '/me/games' ) ).toBe( true );
		expect( isStale( '/games/9' ) ).toBe( false );
		expect( getCached( '/me/games' ) ).toBe( 2 ); // data is kept
	} );
} );

describe( 'refresh', () => {
	it( 'joins a request already out, so only one is in flight', async () => {
		const calls = queueRequests();
		renderHook( () => useResource<string>( '/games/9/log' ) );
		const first = refresh( '/games/9/log' );
		const second = refresh( '/games/9/log' );
		expect( fetchMock ).toHaveBeenCalledTimes( 1 );
		await act( () => {
			calls[ 0 ].resolve( 'log' );
		} );
		await expect( Promise.all( [ first, second ] ) ).resolves.toEqual( [ true, true ] );
	} );

	it( 'follows the request that replaced it when an acknowledgement is merged meanwhile', async () => {
		const calls = queueRequests();
		setCached( '/games/9/log', 'old' );
		const waiting = refresh( '/games/9/log' );
		patchCached( '/games/9/log', 'merged' ); // asks again
		expect( fetchMock ).toHaveBeenCalledTimes( 2 );
		await act( () => {
			calls[ 0 ].resolve( 'stale answer' );
		} );
		await act( () => {
			calls[ 1 ].resolve( 'full log' );
		} );
		await expect( waiting ).resolves.toBe( true );
		expect( getCached( '/games/9/log' ) ).toBe( 'full log' );
	} );

	it( 'keeps an acknowledgement merged during a poll when the asking-again is answered from the saved copy', async () => {
		const calls = queueRequests();
		setCached( '/games/9/log', 'log before the goal' );
		const poll = refresh( '/games/9/log' );
		patchCached( '/games/9/log', 'log with the acknowledged goal' ); // asks again behind the poll
		expect( fetchMock ).toHaveBeenCalledTimes( 2 );
		await act( () => {
			calls[ 0 ].resolve( 'answer from before the goal' );
		} );
		await act( () => {
			calls[ 1 ].resolve( 'older saved copy', undefined, true ); // network too slow: the worker's copy
		} );
		await expect( poll ).resolves.toBe( false );
		expect( getCached( '/games/9/log' ) ).toBe( 'log with the acknowledged goal' );
	} );

	it( 'never replaces data on screen with a saved copy from the service worker, and reports no fresh data', async () => {
		setCached( '/games/9/log', 'merged here' );
		fetchMock.mockResolvedValue( { data: 'older saved copy', savedAt: undefined, fromCache: true } );
		await expect( refresh( '/games/9/log' ) ).resolves.toBe( false );
		expect( getCached( '/games/9/log' ) ).toBe( 'merged here' );
	} );

	it( 'keeps the data and reports a failure', async () => {
		setCached( '/games/9/log', 'good' );
		fetchMock.mockRejectedValue( new ApiError( 503, 'http_error', 'Busy' ) );
		await expect( refresh( '/games/9/log' ) ).resolves.toBe( false );
		expect( getCached( '/games/9/log' ) ).toBe( 'good' );
	} );
} );
