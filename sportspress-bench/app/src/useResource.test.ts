// @vitest-environment jsdom
import { act, cleanup, renderHook, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError, apiGetWithMeta } from './api';
import type { ApiResult } from './api';
import { _resetResourcesForTests, getCached, invalidate, isStale, setCached, useResource } from './useResource';

vi.mock( './api', async ( importOriginal ) => ( { ...( await importOriginal<typeof import( './api' )>() ), apiGetWithMeta: vi.fn() } ) );

const fetchMock = vi.mocked( apiGetWithMeta );

interface Deferred {
	resolve( data: unknown, savedAt?: Date ): void;
	reject( error: unknown ): void;
}

/** Each call to apiGetWithMeta returns a promise the test settles by hand. */
function queueRequests(): Deferred[] {
	const calls: Deferred[] = [];
	fetchMock.mockImplementation(
		() =>
			new Promise<ApiResult<unknown>>( ( resolve, reject ) => {
				calls.push( {
					resolve: ( data, savedAt ) => resolve( { data, savedAt, fromCache: false } ),
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
