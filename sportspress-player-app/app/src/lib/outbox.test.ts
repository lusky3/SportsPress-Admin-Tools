// @vitest-environment jsdom
import { IDBFactory } from 'fake-indexeddb';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '../api';
import type { EntryInput, PostLogResult } from '../types';
import { BATCH, backoff, createOutbox, uuid } from './outbox';

type Send = ( gameId: number, entries: EntryInput[] ) => Promise<PostLogResult>;

const goal = ( n: number ): Omit<EntryInput, 'uid'> => ( { type: 'goal', team_id: 12, period: 1, player_id: 50 + n } );
const ok = ( entries: EntryInput[], status: 'created' | 'duplicate' = 'created' ): PostLogResult => ( { results: entries.map( ( e ) => ( { uid: e.uid, status } ) ), live: null } );

/** Let promise chains (and fake-indexeddb, which uses setImmediate/setTimeout(0)) run. */
async function settle( rounds = 20 ) {
	for ( let i = 0; i < rounds; i++ ) {
		await new Promise( ( r ) => setTimeout( r, 0 ) );
	}
}

let idb: IDBFactory;

beforeEach( () => {
	idb = new IDBFactory();
} );

afterEach( () => {
	vi.useRealTimers();
} );

describe( 'outbox', () => {
	it( 'makes v4 uuids and backs off 1, 2, 4 … 30 s', () => {
		expect( uuid() ).toMatch( /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/ );
		expect( [ 0, 1, 2, 5, 9 ].map( backoff ) ).toEqual( [ 1000, 2000, 4000, 30000, 30000 ] );
	} );

	it( 'sends entries in the order they were made, one game at a time, and drops them when acknowledged', async () => {
		const sent: string[][] = [];
		let release: () => void = () => {};
		const send = vi.fn<Send>( async ( _g, entries ) => {
			sent.push( entries.map( ( e ) => String( e.player_id ) ) );
			if ( sent.length === 1 ) {
				await new Promise<void>( ( r ) => ( release = r ) );
			}
			return ok( entries );
		} );
		const box = createOutbox( { send, idb } );
		box.enqueue( 101, goal( 1 ) );
		await settle();
		box.enqueue( 101, goal( 2 ) );
		box.enqueue( 101, goal( 3 ) );
		await settle();
		expect( sent ).toEqual( [ [ '51' ] ] ); // still waiting on the first answer
		release();
		await settle();
		expect( sent ).toEqual( [ [ '51' ], [ '52', '53' ] ] );
		expect( box.getState().items ).toEqual( [] );
		expect( box.getState().sync[ 101 ] ).toBe( 'idle' );
	} );

	it( 'sends at most 50 per request', async () => {
		const sizes: number[] = [];
		let online = false;
		const send = vi.fn<Send>( async ( _g, entries ) => {
			if ( ! online ) {
				throw new ApiError( 0, 'offline', 'x' );
			}
			sizes.push( entries.length );
			return ok( entries );
		} );
		const box = createOutbox( { send, idb: null } );
		for ( let i = 0; i < 60; i++ ) {
			box.enqueue( 7, goal( i ) );
		}
		await settle();
		online = true;
		await box.flush( 7, true );
		expect( sizes ).toEqual( [ BATCH, 10 ] );
	} );

	it( 'treats a duplicate as acknowledged and keeps the same uid on a resend', async () => {
		const uids: string[] = [];
		let fail = true;
		const send = vi.fn<Send>( async ( _g, entries ) => {
			uids.push( entries[ 0 ].uid );
			if ( fail ) {
				fail = false;
				throw new ApiError( 503, 'http_error', 'Busy' );
			}
			return ok( entries, 'duplicate' );
		} );
		vi.useFakeTimers( { toFake: [ 'setTimeout', 'clearTimeout', 'Date' ] } );
		const box = createOutbox( { send, idb: null } );
		box.enqueue( 101, goal( 1 ) );
		await vi.advanceTimersByTimeAsync( 0 );
		expect( box.getState().sync[ 101 ] ).toBe( 'retrying' );
		await vi.advanceTimersByTimeAsync( 1000 );
		expect( uids ).toHaveLength( 2 );
		expect( uids[ 0 ] ).toBe( uids[ 1 ] );
		expect( box.getState().items ).toEqual( [] );
	} );

	it( 'moves a rejected entry to the rejected list with its reason and never resends it', async () => {
		const send = vi.fn<Send>( async ( _g, entries ) => ( {
			results: entries.map( ( e ) => ( e.player_id === 52 ? { uid: e.uid, status: 'rejected' as const, reason: 'player_not_on_team' } : { uid: e.uid, status: 'created' as const } ) ),
			live: null,
		} ) );
		const box = createOutbox( { send, idb } );
		box.enqueue( 101, goal( 1 ) );
		box.enqueue( 101, goal( 2 ) );
		await settle();
		await box.flushAll( true );
		expect( box.getState().items ).toEqual( [] );
		expect( box.getState().rejected ).toMatchObject( [ { gameId: 101, reason: 'player_not_on_team', entry: { player_id: 52 } } ] );
		const calls = send.mock.calls.length;
		await box.flushAll( true );
		expect( send.mock.calls.length ).toBe( calls );
		box.dismiss( box.getState().rejected[ 0 ].entry.uid );
		expect( box.getState().rejected ).toEqual( [] );
	} );

	it( 'rejects the batch on a final game (409 sppa_finalized) instead of retrying', async () => {
		const box = createOutbox( { send: async () => Promise.reject( new ApiError( 409, 'sppa_finalized', 'Final' ) ), idb: null } );
		box.enqueue( 101, goal( 1 ) );
		await settle();
		expect( box.getState().items ).toEqual( [] );
		expect( box.getState().rejected[ 0 ].reason ).toBe( 'finalized' );
	} );

	it( 'parks a batch the server refuses as a whole (400) instead of resending it forever', async () => {
		const send = vi.fn<Send>( async () => Promise.reject( new ApiError( 400, 'rest_invalid_param', 'Invalid parameter(s): entries' ) ) );
		const box = createOutbox( { send, idb: null } );
		box.enqueue( 101, goal( 1 ) );
		box.enqueue( 101, goal( 2 ) );
		await settle();
		await box.flushAll( true );
		expect( box.getState().items ).toEqual( [] );
		expect( box.getState().rejected.map( ( r ) => r.reason ) ).toEqual( [ 'rest_invalid_param', 'rest_invalid_param' ] );
		expect( send ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'retries a busy game (409 sppa_log_locked) with growing waits', async () => {
		vi.useFakeTimers( { toFake: [ 'setTimeout', 'clearTimeout', 'Date' ] } );
		const send = vi.fn<Send>( async () => Promise.reject( new ApiError( 409, 'sppa_log_locked', 'Busy' ) ) );
		const box = createOutbox( { send, idb: null } );
		box.enqueue( 101, goal( 1 ) );
		await vi.advanceTimersByTimeAsync( 0 );
		expect( send ).toHaveBeenCalledTimes( 1 );
		await vi.advanceTimersByTimeAsync( 999 );
		expect( send ).toHaveBeenCalledTimes( 1 );
		await vi.advanceTimersByTimeAsync( 1 );
		expect( send ).toHaveBeenCalledTimes( 2 );
		await vi.advanceTimersByTimeAsync( 2000 );
		expect( send ).toHaveBeenCalledTimes( 3 );
		expect( box.getState().items ).toHaveLength( 1 );
	} );

	it( 'stops on a lost session without dropping anything, and tries again when nudged', async () => {
		let signedIn = false;
		const send = vi.fn<Send>( async ( _g, entries ) => {
			if ( ! signedIn ) {
				throw new ApiError( 401, 'signed_out', 'Signed out' );
			}
			return ok( entries );
		} );
		const box = createOutbox( { send, idb: null } );
		box.enqueue( 101, goal( 1 ) );
		await settle();
		expect( box.getState().sync[ 101 ] ).toBe( 'signed_out' );
		await box.flushAll(); // the timer does not hammer a signed-out session
		expect( send ).toHaveBeenCalledTimes( 1 );
		expect( box.getState().items ).toHaveLength( 1 );
		signedIn = true;
		await box.flushAll( true );
		expect( box.getState().items ).toEqual( [] );
	} );

	it( 'holds entries while offline and sends them when the browser comes back online', async () => {
		let online = false;
		const send = vi.fn<Send>( async ( _g, entries ) => {
			if ( ! online ) {
				throw new ApiError( 0, 'offline', 'No connection.' );
			}
			return ok( entries );
		} );
		const box = createOutbox( { send, idb } );
		const stop = box.start();
		box.enqueue( 101, goal( 1 ) );
		box.enqueue( 101, goal( 2 ) );
		await settle();
		expect( box.getState().sync[ 101 ] ).toBe( 'offline' );
		expect( box.getState().items ).toHaveLength( 2 );
		online = true;
		window.dispatchEvent( new Event( 'online' ) );
		await settle();
		expect( box.getState().items ).toEqual( [] );
		expect( send.mock.calls.at( -1 )?.[ 1 ].map( ( e ) => e.player_id ) ).toEqual( [ 51, 52 ] );
		stop();
	} );

	it( 'keeps waiting and rejected entries across a reload', async () => {
		const offline = async () => Promise.reject( new ApiError( 0, 'offline', 'x' ) );
		const first = createOutbox( { send: offline, idb } );
		const a = first.enqueue( 101, goal( 1 ) );
		const b = first.enqueue( 101, goal( 2 ) );
		await settle();

		const send = vi.fn<Send>( async ( _g, entries ) => ok( entries ) );
		const second = createOutbox( { send: offline, idb } );
		await second.ready;
		expect( second.getState().items.map( ( i ) => i.entry.uid ) ).toEqual( [ a, b ] );
		expect( second.getState().memoryOnly ).toBe( false );

		const third = createOutbox( { send, idb } );
		await third.ready;
		await third.flushAll( true );
		expect( send.mock.calls[ 0 ][ 1 ].map( ( e ) => e.uid ) ).toEqual( [ a, b ] );
		await settle();
		const fourth = createOutbox( { send, idb } );
		await fourth.ready;
		expect( fourth.getState().items ).toEqual( [] );
	} );

	it( 'falls back to memory with a warning flag when IndexedDB will not open', async () => {
		const broken = { open: () => { throw new Error( 'denied' ); } } as unknown as IDBFactory;
		const box = createOutbox( { send: async ( _g, e ) => ok( e ), idb: broken } );
		box.enqueue( 101, goal( 1 ) );
		await settle();
		expect( box.getState().memoryOnly ).toBe( true );
		expect( box.getState().items ).toEqual( [] );
	} );

	it( 'takes back an entry that has not been sent yet', async () => {
		const box = createOutbox( { send: async () => Promise.reject( new ApiError( 0, 'offline', 'x' ) ), idb: null } );
		const uid = box.enqueue( 101, goal( 1 ) );
		await settle();
		expect( box.discard( uid ) ).toBe( true );
		expect( box.getState().items ).toEqual( [] );
	} );
} );
