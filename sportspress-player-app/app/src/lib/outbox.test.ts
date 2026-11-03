// @vitest-environment jsdom
import { IDBDatabase, IDBFactory } from 'fake-indexeddb';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '../api';
import type { EntryInput, KeeperEntry, PostLogResult } from '../types';
import { BATCH, TIMEOUT_MS, backoff, createOutbox, uuid } from './outbox';
import type { VoidResult } from './outbox';

type Send = ( gameId: number, entries: EntryInput[], signal: AbortSignal ) => Promise<PostLogResult>;
type SendVoid = ( gameId: number, uid: string, signal: AbortSignal ) => Promise<VoidResult>;

const goal = ( n: number ): Omit<EntryInput, 'uid'> => ( { type: 'goal', team_id: 12, period: 1, player_id: 50 + n } );
const ok = ( entries: EntryInput[], status: 'created' | 'duplicate' = 'created' ): PostLogResult => ( { results: entries.map( ( e ) => ( { uid: e.uid, status } ) ), live: null } );
const offline = () => Promise.reject( new ApiError( 0, 'offline', 'No connection.' ) );
const voided = async ( _g: number, uid: string ): Promise<VoidResult> => ( { entry: { uid, voided: true } as KeeperEntry, live: null } );

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
	vi.restoreAllMocks();
	vi.unstubAllGlobals();
} );

describe( 'outbox: sending', () => {
	it( 'makes v4 uuids and backs off 1, 2, 4 … 30 s', () => {
		expect( uuid() ).toMatch( /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/ );
		expect( [ 0, 1, 2, 5, 9 ].map( backoff ) ).toEqual( [ 1000, 2000, 4000, 30000, 30000 ] );
	} );

	it( 'sends entries in the order they were made, one request at a time per game, and drops them when acknowledged', async () => {
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
		expect( sent ).toEqual( [ [ '51' ] ] );
		release();
		await settle();
		expect( sent ).toEqual( [ [ '51' ], [ '52', '53' ] ] );
		expect( box.getState().items ).toEqual( [] );
		expect( box.getState().sync[ 101 ] ).toBe( 'idle' );
	} );

	it( 'sends one request when two nudges arrive together', async () => {
		let online = false;
		const send = vi.fn<Send>( async ( _g, entries ) => ( online ? ok( entries ) : offline() ) );
		const box = createOutbox( { send, idb: null } );
		box.enqueue( 101, goal( 1 ) );
		await settle();
		send.mockClear();
		online = true;
		await Promise.all( [ box.flushAll( true ), box.flushAll( true ) ] );
		expect( send ).toHaveBeenCalledTimes( 1 );
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

	it.each( [
		[ 429, 'http_error' ],
		[ 408, 'http_error' ],
		[ 409, 'sppa_something_new' ],
		[ 409, 'sppa_log_locked' ],
		[ 500, 'internal_server_error' ],
	] )( 'retries a %i %s with growing waits', async ( status, code ) => {
		vi.useFakeTimers( { toFake: [ 'setTimeout', 'clearTimeout', 'Date' ] } );
		const send = vi.fn<Send>( async () => Promise.reject( new ApiError( status, code, 'x' ) ) );
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
		expect( box.getState().rejected ).toEqual( [] );
	} );

	it( 'gives up on a hung request after 15 s, aborts it and tries again', async () => {
		vi.useFakeTimers( { toFake: [ 'setTimeout', 'clearTimeout', 'Date' ] } );
		const signals: AbortSignal[] = [];
		const send = vi.fn<Send>( ( _g, entries, signal ) => {
			signals.push( signal );
			return signals.length === 1 ? new Promise<PostLogResult>( () => {} ) : Promise.resolve( ok( entries ) );
		} );
		const box = createOutbox( { send, idb: null } );
		box.enqueue( 101, goal( 1 ) );
		await vi.advanceTimersByTimeAsync( TIMEOUT_MS - 1 );
		expect( box.getState().sync[ 101 ] ).toBe( 'sending' );
		await vi.advanceTimersByTimeAsync( 1 );
		expect( signals[ 0 ].aborted ).toBe( true );
		expect( box.getState().sync[ 101 ] ).toBe( 'offline' );
		await vi.advanceTimersByTimeAsync( 1000 );
		expect( send ).toHaveBeenCalledTimes( 2 );
		expect( box.getState().items ).toEqual( [] );
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
		await box.flushAll();
		expect( send ).toHaveBeenCalledTimes( 1 );
		expect( box.getState().items ).toHaveLength( 1 );
		signedIn = true;
		await box.flushAll( true );
		expect( box.getState().items ).toEqual( [] );
	} );

	it( 'holds entries while offline and sends them when the browser comes back online', async () => {
		let online = false;
		const send = vi.fn<Send>( async ( _g, entries ) => ( online ? ok( entries ) : offline() ) );
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
} );

describe( 'outbox: refusals', () => {
	it( 'parks an entry the server rejects, with its reason, and never resends it', async () => {
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
		expect( box.getState().rejected[ 0 ].whole ).toBeUndefined();
		const calls = send.mock.calls.length;
		await box.flushAll( true );
		expect( send.mock.calls.length ).toBe( calls );
		box.dismiss( box.getState().rejected[ 0 ].entry.uid );
		expect( box.getState().rejected ).toEqual( [] );
	} );

	it.each( [ 'rest_invalid_param', 'rest_missing_callback_param', 'sppa_finalized', 'sppa_called_off', 'sppa_no_teams', 'sppa_not_found' ] )( 'parks the whole batch on %s, and Try again sends the same entries again', async ( code ) => {
		let refuse = true;
		const send = vi.fn<Send>( async ( _g, entries ) => ( refuse ? Promise.reject( new ApiError( code.startsWith( 'rest' ) ? 400 : 409, code, 'x' ) ) : ok( entries ) ) );
		const box = createOutbox( { send, idb: null } );
		const a = box.enqueue( 101, goal( 1 ) );
		await settle();
		expect( box.getState().items ).toEqual( [] );
		expect( box.getState().rejected ).toMatchObject( [ { reason: code.replace( /^sppa_/, '' ), whole: true } ] );
		expect( send ).toHaveBeenCalledTimes( 1 );
		refuse = false;
		box.retry( a );
		await settle();
		expect( send.mock.calls[ 1 ][ 1 ][ 0 ].uid ).toBe( a );
		expect( box.getState().rejected ).toEqual( [] );
		expect( box.getState().items ).toEqual( [] );
	} );
} );

describe( 'outbox: undo', () => {
	it( 'removes an entry that was never sent, without telling the server', async () => {
		const send = vi.fn<Send>( async ( _g, e ) => ok( e ) );
		const sendVoid = vi.fn<SendVoid>( voided );
		const box = createOutbox( { send, sendVoid, idb: null } );
		const uid = box.enqueue( 101, goal( 1 ) );
		expect( box.discard( uid ) ).toBe( 'removed' );
		await settle();
		expect( box.getState().items ).toEqual( [] );
		expect( send ).not.toHaveBeenCalled();
		expect( sendVoid ).not.toHaveBeenCalled();
	} );

	it( 'voids an entry whose send failed once acknowledged, instead of forgetting it (the server may have it)', async () => {
		let online = false;
		const send = vi.fn<Send>( async ( _g, entries ) => ( online ? ok( entries, 'duplicate' ) : offline() ) );
		const sendVoid = vi.fn<SendVoid>( voided );
		const box = createOutbox( { send, sendVoid, idb } );
		const uid = box.enqueue( 101, goal( 1 ) );
		await settle();
		expect( box.discard( uid ) ).toBe( 'undoing' );
		expect( box.getState().items[ 0 ] ).toMatchObject( { undo: true, attempted: true } );
		online = true;
		await box.flushAll( true );
		await settle();
		expect( sendVoid ).toHaveBeenCalledWith( 101, uid, expect.anything() );
		expect( box.getState().items ).toEqual( [] );
	} );

	it( 'voids an entry undone while its request is in flight, after the answer', async () => {
		let release: () => void = () => {};
		const send = vi.fn<Send>( async ( _g, entries ) => {
			await new Promise<void>( ( r ) => ( release = r ) );
			return ok( entries );
		} );
		const sendVoid = vi.fn<SendVoid>( voided );
		const box = createOutbox( { send, sendVoid, idb: null } );
		const uid = box.enqueue( 101, goal( 1 ) );
		await settle();
		expect( box.discard( uid ) ).toBe( 'undoing' );
		expect( sendVoid ).not.toHaveBeenCalled();
		release();
		await settle();
		expect( sendVoid ).toHaveBeenCalledTimes( 1 );
		expect( box.getState().items ).toEqual( [] );
	} );

	it( 'drops an undone entry the server rejected, with no void and nothing parked', async () => {
		let online = false;
		const send = vi.fn<Send>( async ( _g, entries ) => ( online ? { results: entries.map( ( e ) => ( { uid: e.uid, status: 'rejected' as const, reason: 'invalid_clock' } ) ), live: null } : offline() ) );
		const sendVoid = vi.fn<SendVoid>( voided );
		const box = createOutbox( { send, sendVoid, idb: null } );
		const uid = box.enqueue( 101, goal( 1 ) );
		await settle();
		box.discard( uid );
		online = true;
		await box.flushAll( true );
		expect( sendVoid ).not.toHaveBeenCalled();
		expect( box.getState().items ).toEqual( [] );
		expect( box.getState().rejected ).toEqual( [] );
	} );
} );

describe( 'outbox: storage', () => {
	it( 'shows an entry as saving until it is in storage', async () => {
		const box = createOutbox( { send: offline, idb } );
		const uid = box.enqueue( 101, goal( 1 ) );
		expect( box.getState().items[ 0 ] ).toMatchObject( { saving: true } );
		await settle();
		expect( box.getState().items.find( ( i ) => i.entry.uid === uid )?.saving ).toBe( false );
	} );

	it( 'keeps an entry in memory, warns, and still sends it when the storage write fails', async () => {
		const real = IDBDatabase.prototype.transaction;
		vi.spyOn( IDBDatabase.prototype, 'transaction' ).mockImplementation( function ( this: IDBDatabase, ...args: Parameters<IDBDatabase[ 'transaction' ]> ) {
			if ( args[ 1 ] === 'readwrite' ) {
				throw new DOMException( 'Quota exceeded', 'QuotaExceededError' );
			}
			return real.apply( this, args );
		} );
		const send = vi.fn<Send>( async ( _g, e ) => ok( e ) );
		const box = createOutbox( { send, idb } );
		box.enqueue( 101, goal( 1 ) );
		await settle();
		expect( box.getState().memoryOnly ).toBe( true );
		expect( send ).toHaveBeenCalledTimes( 1 );
		expect( box.getState().items ).toEqual( [] );
	} );

	it( 'keeps waiting and undone entries across a reload', async () => {
		const first = createOutbox( { send: offline, idb } );
		const a = first.enqueue( 101, goal( 1 ) );
		const b = first.enqueue( 101, goal( 2 ) );
		await settle();
		first.discard( b );
		await settle();

		const second = createOutbox( { send: offline, idb } );
		await second.ready;
		expect( second.getState().items.map( ( i ) => [ i.entry.uid, Boolean( i.undo ) ] ) ).toEqual( [
			[ a, false ],
			[ b, true ],
		] );
		expect( second.getState().memoryOnly ).toBe( false );

		const send = vi.fn<Send>( async ( _g, entries ) => ok( entries ) );
		const sendVoid = vi.fn<SendVoid>( voided );
		const third = createOutbox( { send, sendVoid, idb } );
		await third.ready;
		await third.flushAll( true );
		expect( send.mock.calls[ 0 ][ 1 ].map( ( e ) => e.uid ) ).toEqual( [ a, b ] );
		expect( sendVoid ).toHaveBeenCalledWith( 101, b, expect.anything() );
		await settle();
		const fourth = createOutbox( { send, idb } );
		await fourth.ready;
		expect( fourth.getState().items ).toEqual( [] );
	} );

	it( 'falls back to memory with a warning flag when IndexedDB will not open', async () => {
		const broken = {
			open: () => {
				throw new Error( 'denied' );
			},
		} as unknown as IDBFactory;
		const box = createOutbox( { send: async ( _g, e ) => ok( e ), idb: broken } );
		box.enqueue( 101, goal( 1 ) );
		await settle();
		expect( box.getState().memoryOnly ).toBe( true );
		expect( box.getState().items ).toEqual( [] );
	} );

	it( 'asks the browser once to keep its storage', async () => {
		const persist = vi.fn( async () => true );
		vi.stubGlobal( 'navigator', { ...navigator, storage: { persist } } );
		const box = createOutbox( { send: offline, idb: null } );
		box.enqueue( 101, goal( 1 ) );
		box.enqueue( 101, goal( 2 ) );
		expect( persist ).toHaveBeenCalledTimes( 1 );
	} );
} );
