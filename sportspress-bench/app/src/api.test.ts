import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError, _resetApiForTests, apiGet, apiGetWithMeta, apiSend, isOffline, onOffline } from './api';

const BOOT = {
	restRoot: 'https://example.test/wp-json/spbn/v1/',
	nonce: 'oldnonce01',
	ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php',
	loginUrl: 'https://example.test/wp-login.php',
	logoutUrl: 'https://example.test/wp-login.php?action=logout',
	accountUrl: 'https://example.test/my-account/',
	helpUrl: 'https://help.example.test/',
	appBase: '/bench/',
	siteName: 'ARL',
};

function json( body: unknown, status = 200, headers: Record<string, string> = {} ): Response {
	return new Response( JSON.stringify( body ), { status, headers: { 'Content-Type': 'application/json', ...headers } } );
}

function text( body: string, status = 200 ): Response {
	return new Response( body, { status } );
}

const nonceError = () => json( { code: 'rest_cookie_invalid_nonce', message: 'Cookie check failed', data: { status: 403 } }, 403 );

type FetchMock = ReturnType<typeof vi.fn>;
let fetchMock: FetchMock;

function nonceSentOn( call: number ): string | null {
	const init = fetchMock.mock.calls[ call ][ 1 ] as RequestInit;
	return new Headers( init.headers ).get( 'X-WP-Nonce' );
}

beforeEach( () => {
	vi.stubGlobal( 'window', { SPBN_BOOT: { ...BOOT } } );
	fetchMock = vi.fn();
	vi.stubGlobal( 'fetch', fetchMock );
	_resetApiForTests();
} );

afterEach( () => {
	vi.unstubAllGlobals();
} );

describe( 'apiGet', () => {
	it( 'GETs under the REST root with the nonce and returns the JSON', async () => {
		fetchMock.mockResolvedValueOnce( json( { linked: true } ) );
		await expect( apiGet( '/me' ) ).resolves.toEqual( { linked: true } );
		expect( fetchMock.mock.calls[ 0 ][ 0 ] ).toBe( 'https://example.test/wp-json/spbn/v1/me' );
		expect( ( fetchMock.mock.calls[ 0 ][ 1 ] as RequestInit ).method ).toBe( 'GET' );
		expect( ( fetchMock.mock.calls[ 0 ][ 1 ] as RequestInit ).credentials ).toBe( 'same-origin' );
		expect( nonceSentOn( 0 ) ).toBe( 'oldnonce01' );
	} );

	it( 'accepts a path without a leading slash and keeps a query string', async () => {
		fetchMock.mockResolvedValueOnce( json( {} ) );
		await apiGet( 'ops/night?date=2026-11-04' );
		expect( fetchMock.mock.calls[ 0 ][ 0 ] ).toBe( 'https://example.test/wp-json/spbn/v1/ops/night?date=2026-11-04' );
	} );

	it( 'joins a query with & when the REST root already has one (plain permalinks)', async () => {
		vi.stubGlobal( 'window', { SPBN_BOOT: { ...BOOT, restRoot: 'https://example.test/?rest_route=/spbn/v1/' } } );
		fetchMock.mockResolvedValueOnce( json( {} ) );
		await apiGet( '/ops/night?date=2026-11-04' );
		expect( fetchMock.mock.calls[ 0 ][ 0 ] ).toBe( 'https://example.test/?rest_route=/spbn/v1/ops/night&date=2026-11-04' );
	} );

	it( 'throws an ApiError carrying the status, code and message of a WP_Error', async () => {
		fetchMock.mockResolvedValueOnce( json( { code: 'spbn_forbidden', message: 'Not your game.', data: { status: 403 } }, 403 ) );
		const error = await apiGet( '/games/9' ).catch( ( e: unknown ) => e );
		expect( error ).toBeInstanceOf( ApiError );
		expect( error ).toMatchObject( { status: 403, code: 'spbn_forbidden', message: 'Not your game.' } );
		expect( fetchMock ).toHaveBeenCalledTimes( 1 ); // no nonce retry for an ordinary 403
	} );

	it( 'gives a plain error code when the body is not JSON', async () => {
		fetchMock.mockResolvedValueOnce( text( '<html>Bad gateway</html>', 502 ) );
		await expect( apiGet( '/me' ) ).rejects.toMatchObject( { status: 502, code: 'http_error' } );
	} );

	it( 'reports a dropped connection as an offline ApiError', async () => {
		fetchMock.mockRejectedValueOnce( new TypeError( 'Failed to fetch' ) );
		await expect( apiGet( '/me' ) ).rejects.toMatchObject( { status: 0, code: 'offline' } );
	} );
} );

describe( 'nonce refresh', () => {
	it( 'refreshes an expired nonce once and retries the request', async () => {
		fetchMock
			.mockResolvedValueOnce( nonceError() )
			.mockResolvedValueOnce( text( 'freshnonce2' ) )
			.mockResolvedValueOnce( json( { ok: 1 } ) );

		await expect( apiGet( '/me' ) ).resolves.toEqual( { ok: 1 } );

		expect( fetchMock ).toHaveBeenCalledTimes( 3 );
		expect( fetchMock.mock.calls[ 1 ][ 0 ] ).toBe( 'https://example.test/wp-admin/admin-ajax.php?action=rest-nonce' );
		expect( nonceSentOn( 0 ) ).toBe( 'oldnonce01' );
		expect( nonceSentOn( 2 ) ).toBe( 'freshnonce2' );
	} );

	it( 'keeps the fresh nonce for later requests', async () => {
		fetchMock
			.mockResolvedValueOnce( nonceError() )
			.mockResolvedValueOnce( text( 'freshnonce2' ) )
			.mockResolvedValueOnce( json( {} ) )
			.mockResolvedValueOnce( json( {} ) );
		await apiGet( '/me' );
		await apiGet( '/spares' );
		expect( nonceSentOn( 3 ) ).toBe( 'freshnonce2' );
	} );

	it( 'resends the body of a write after refreshing', async () => {
		fetchMock
			.mockResolvedValueOnce( nonceError() )
			.mockResolvedValueOnce( text( 'freshnonce2' ) )
			.mockResolvedValueOnce( json( { success: true } ) );
		await expect( apiSend( 'PUT', '/games/9/availability', { status: 'in' } ) ).resolves.toEqual( { success: true } );
		const retry = fetchMock.mock.calls[ 2 ][ 1 ] as RequestInit;
		expect( retry.method ).toBe( 'PUT' );
		expect( retry.body ).toBe( JSON.stringify( { status: 'in' } ) );
		expect( new Headers( retry.headers ).get( 'Content-Type' ) ).toBe( 'application/json' );
	} );

	it( 'is signed_out when the retry fails the same way', async () => {
		fetchMock
			.mockResolvedValueOnce( nonceError() )
			.mockResolvedValueOnce( text( 'freshnonce2' ) )
			.mockResolvedValueOnce( nonceError() );
		await expect( apiGet( '/me' ) ).rejects.toMatchObject( { code: 'signed_out' } );
		expect( fetchMock ).toHaveBeenCalledTimes( 3 ); // one refresh, one retry, no loop
	} );

	it( 'is signed_out on a second 401', async () => {
		const unauthorised = () => json( { code: 'rest_forbidden', message: 'Sorry', data: { status: 401 } }, 401 );
		fetchMock.mockResolvedValueOnce( unauthorised() ).mockResolvedValueOnce( text( 'freshnonce2' ) ).mockResolvedValueOnce( unauthorised() );
		await expect( apiGet( '/me' ) ).rejects.toMatchObject( { status: 401, code: 'signed_out' } );
	} );

	it( 'is signed_out when the session is gone and the nonce endpoint has nothing to give', async () => {
		fetchMock.mockResolvedValueOnce( json( { code: 'rest_forbidden', message: 'Sorry', data: { status: 401 } }, 401 ) ).mockResolvedValueOnce( text( '0', 400 ) );
		await expect( apiGet( '/me' ) ).rejects.toMatchObject( { code: 'signed_out' } );
		expect( fetchMock ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'does not treat other 403s as an expired nonce', async () => {
		fetchMock.mockResolvedValueOnce( json( { code: 'spbn_locked', message: 'Locked', data: { status: 409 } }, 409 ) );
		await expect( apiGet( '/me' ) ).rejects.toMatchObject( { status: 409, code: 'spbn_locked' } );
		expect( fetchMock ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'shares one refresh between requests that expire together', async () => {
		let refreshes = 0;
		fetchMock.mockImplementation( ( url: string, init?: RequestInit ) => {
			if ( String( url ).includes( 'rest-nonce' ) ) {
				refreshes += 1;
				return Promise.resolve( text( 'freshnonce2' ) );
			}
			const sent = new Headers( init?.headers ).get( 'X-WP-Nonce' );
			return Promise.resolve( sent === 'freshnonce2' ? json( { ok: 1 } ) : nonceError() );
		} );
		await expect( Promise.all( [ apiGet( '/me' ), apiGet( '/spares' ) ] ) ).resolves.toEqual( [ { ok: 1 }, { ok: 1 } ] );
		expect( refreshes ).toBe( 1 );
	} );
} );

describe( 'apiSend', () => {
	it( 'sends a JSON body with the method', async () => {
		fetchMock.mockResolvedValueOnce( json( { success: true } ) );
		await apiSend( 'POST', '/spare-calls/4/accept' );
		const init = fetchMock.mock.calls[ 0 ][ 1 ] as RequestInit;
		expect( init.method ).toBe( 'POST' );
		expect( init.body ).toBeUndefined();
	} );

	it( 'surfaces a 409 as an ApiError', async () => {
		fetchMock.mockResolvedValueOnce( json( { code: 'spbn_full', message: 'Someone beat you to it.', data: { status: 409 } }, 409 ) );
		await expect( apiSend( 'POST', '/spare-calls/4/accept' ) ).rejects.toMatchObject( { status: 409, code: 'spbn_full' } );
	} );
} );

describe( 'offline signal', () => {
	it( 'reads the Date header as the time the data was saved', async () => {
		fetchMock.mockResolvedValueOnce( json( { a: 1 }, 200, { Date: 'Wed, 04 Nov 2026 19:42:00 GMT' } ) );
		const result = await apiGetWithMeta<{ a: number }>( '/me' );
		expect( result.data ).toEqual( { a: 1 } );
		expect( result.savedAt?.toISOString() ).toBe( '2026-11-04T19:42:00.000Z' );
		expect( result.fromCache ).toBe( false );
	} );

	it( 'marks data stale when the service worker says it came from cache', async () => {
		const seen: boolean[] = [];
		const stop = onOffline( ( offline ) => seen.push( offline ) );
		fetchMock.mockResolvedValueOnce( json( {}, 200, { 'X-SPBN-From-Cache': '1' } ) );
		const result = await apiGetWithMeta( '/me' );
		expect( result.fromCache ).toBe( true );
		expect( isOffline() ).toBe( true );
		expect( seen ).toEqual( [ true ] );

		fetchMock.mockResolvedValueOnce( json( {} ) );
		await apiGet( '/me' );
		expect( isOffline() ).toBe( false );
		expect( seen ).toEqual( [ true, false ] );
		stop();
	} );

	it( 'marks data stale when the browser reports no connection', async () => {
		vi.stubGlobal( 'navigator', { onLine: false } );
		fetchMock.mockResolvedValueOnce( json( {} ) );
		const result = await apiGetWithMeta( '/me' );
		expect( result.fromCache ).toBe( true );
		expect( isOffline() ).toBe( true );
	} );

	it( 'does not call a listener after it unsubscribes', async () => {
		const seen: boolean[] = [];
		const stop = onOffline( ( offline ) => seen.push( offline ) );
		stop();
		fetchMock.mockResolvedValueOnce( json( {}, 200, { 'X-SPBN-From-Cache': '1' } ) );
		await apiGet( '/me' );
		expect( seen ).toEqual( [] );
	} );
} );
