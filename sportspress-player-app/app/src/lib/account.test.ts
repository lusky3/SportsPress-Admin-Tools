// @vitest-environment jsdom
import { IDBFactory } from 'fake-indexeddb';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError, apiGet } from '../api';
import { fakeApi, fakeStorage } from '../test/fake';
import { getCached, setCached } from '../useResource';
import { _resetAccountForTests, checkBootUser, currentUser, seenUser } from './account';
import { createOutbox } from './outbox';

/** A Cache Storage stand-in that records what was deleted. */
function fakeCaches() {
	const deleted: string[] = [];
	vi.stubGlobal( 'caches', {
		keys: async () => [ 'sppa-shell', 'sppa-api', 'sppa-fonts', 'workbox-precache-v2' ],
		delete: async ( name: string ) => {
			deleted.push( name );
			return true;
		},
	} );
	return deleted;
}

async function settle( rounds = 10 ) {
	for ( let i = 0; i < rounds; i++ ) {
		await new Promise( ( r ) => setTimeout( r, 0 ) );
	}
}

beforeEach( () => {
	fakeStorage();
	fakeApi( () => undefined ); // boot data and a clean in-memory cache
	_resetAccountForTests();
} );

afterEach( () => {
	vi.unstubAllGlobals();
	delete window.SPPA_BOOT;
} );

describe( 'saved replies belong to one account', () => {
	it( 'clears the saved replies and the in-memory copies when the app opens for a different user', async () => {
		const deleted = fakeCaches();
		localStorage.setItem( 'sppa_uid', '5' );
		window.SPPA_BOOT = { ...window.SPPA_BOOT!, userId: 9 };
		setCached( '/me', { user: { id: 5 } } );
		await checkBootUser();
		expect( deleted ).toEqual( [ 'sppa-shell', 'sppa-api' ] );
		expect( getCached( '/me' ) ).toBeUndefined();
		expect( localStorage.getItem( 'sppa_uid' ) ).toBe( '9' );
		expect( currentUser() ).toBe( 9 );
	} );

	it( 'keeps them when the same user opens the app again', async () => {
		const deleted = fakeCaches();
		localStorage.setItem( 'sppa_uid', '5' );
		window.SPPA_BOOT = { ...window.SPPA_BOOT!, userId: 5 };
		setCached( '/me', { user: { id: 5 } } );
		await checkBootUser();
		expect( deleted ).toEqual( [] );
		expect( getCached( '/me' ) ).toEqual( { user: { id: 5 } } );
	} );

	it( 'clears them when the app opens signed out after someone used it', async () => {
		const deleted = fakeCaches();
		localStorage.setItem( 'sppa_uid', '5' );
		window.SPPA_BOOT = { ...window.SPPA_BOOT!, userId: 0 };
		await checkBootUser();
		expect( deleted ).toEqual( [ 'sppa-shell', 'sppa-api' ] );
		expect( localStorage.getItem( 'sppa_uid' ) ).toBe( '0' );
	} );

	it( 'waits for /me when an older shell sends no user id', async () => {
		const deleted = fakeCaches();
		localStorage.setItem( 'sppa_uid', '5' );
		const { userId: _gone, ...older } = window.SPPA_BOOT!;
		window.SPPA_BOOT = older;
		await checkBootUser();
		expect( deleted ).toEqual( [] );
		expect( currentUser() ).toBe( 0 );
	} );

	it( 'clears them when /me names a different user than the one stored, once', async () => {
		const deleted = fakeCaches();
		localStorage.setItem( 'sppa_uid', '5' );
		setCached( '/schedule', [ 1 ] );
		seenUser( 9 );
		await settle();
		expect( deleted ).toEqual( [ 'sppa-shell', 'sppa-api' ] );
		expect( getCached( '/schedule' ) ).toBeUndefined();
		seenUser( 9 );
		await settle();
		expect( deleted ).toHaveLength( 2 );
		expect( currentUser() ).toBe( 9 );
	} );

	it( 'clears the saved replies when the server says the session is gone', async () => {
		const deleted = fakeCaches();
		fakeApi( () => ( { status: 401, body: { code: 'rest_not_logged_in', message: 'x', data: { status: 401 } } } ) );
		await expect( apiGet( '/me' ) ).rejects.toBeInstanceOf( ApiError );
		await settle();
		expect( deleted ).toEqual( [ 'sppa-shell', 'sppa-api' ] );
	} );

	it( 'never touches the timekeeper outbox', async () => {
		fakeCaches();
		const idb = new IDBFactory();
		const first = createOutbox( { send: () => Promise.reject( new ApiError( 0, 'offline', 'x' ) ), idb } );
		const uid = first.enqueue( 101, { type: 'goal', team_id: 12, period: 1, player_id: 51 } );
		await settle();
		localStorage.setItem( 'sppa_uid', '5' );
		window.SPPA_BOOT = { ...window.SPPA_BOOT!, userId: 9 };
		await checkBootUser();
		seenUser( 11 );
		await settle();
		const again = createOutbox( { send: () => new Promise( () => {} ), idb } );
		await again.ready;
		expect( again.getState().items.map( ( i ) => i.entry.uid ) ).toEqual( [ uid ] );
	} );
} );
