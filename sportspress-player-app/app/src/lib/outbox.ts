import { useMemo, useSyncExternalStore } from 'react';
import { ApiError, apiSend } from '../api';
import type { EntryInput, PostLogResult } from '../types';

/*
 * The timekeeper's outbox. Every log entry is written to the phone first (IndexedDB, or memory
 * with a visible warning when IndexedDB is unavailable), shown at once, then sent in order, per
 * game, in batches of up to 50. The server answers each entry on its own: created and duplicate
 * mean "acknowledged" (dropped here), rejected means "never" (moved to a visible list, not retried).
 * A connection problem, a 5xx or a busy game (409 sppa_log_locked) is retried with backoff; a lost
 * session (401/403) stops sending without dropping anything until the next nudge.
 * Nudges: a new entry, the `online` event, the tab becoming visible, and a 20 s timer while anything waits.
 * Entry UIDs make every resend safe.
 */

export interface Queued {
	gameId: number;
	/** Send order across reloads. */
	seq: number;
	entry: EntryInput;
}

export interface Rejected {
	gameId: number;
	entry: EntryInput;
	/** The server's reason code, e.g. 'player_not_on_team', or the whole-request error ('finalized'). */
	reason: string;
}

/** idle: nothing to do or all sent · sending · retrying (server busy or failing) · offline · signed_out (stopped). */
export type SyncState = 'idle' | 'sending' | 'retrying' | 'offline' | 'signed_out';

export interface OutboxState {
	items: Queued[];
	rejected: Rejected[];
	sync: Record<number, SyncState>;
	/** IndexedDB is not available: entries live in memory only and are lost on reload. */
	memoryOnly: boolean;
}

export const BATCH = 50;
const TICK_MS = 20_000;
const BACKOFF_MAX_MS = 30_000;

/** 1 s, 2 s, 4 s … capped at 30 s. */
export function backoff( attempt: number ): number {
	return Math.min( 1000 * 2 ** attempt, BACKOFF_MAX_MS );
}

/** A v4 UUID; crypto.randomUUID needs a secure context, getRandomValues does not. */
export function uuid(): string {
	const c = globalThis.crypto;
	if ( typeof c.randomUUID === 'function' ) {
		return c.randomUUID();
	}
	const b = c.getRandomValues( new Uint8Array( 16 ) );
	b[ 6 ] = ( b[ 6 ] & 0x0f ) | 0x40;
	b[ 8 ] = ( b[ 8 ] & 0x3f ) | 0x80;
	const h = [ ...b ].map( ( x ) => x.toString( 16 ).padStart( 2, '0' ) ).join( '' );
	return `${ h.slice( 0, 8 ) }-${ h.slice( 8, 12 ) }-${ h.slice( 12, 16 ) }-${ h.slice( 16, 20 ) }-${ h.slice( 20 ) }`;
}

type Kind = 'queue' | 'rejected';

/** IndexedDB, two stores keyed by uid. Every call may throw; the outbox catches. */
function openDb( idb: IDBFactory, name: string ): Promise<IDBDatabase> {
	return new Promise( ( resolve, reject ) => {
		const req = idb.open( name, 1 );
		req.onupgradeneeded = () => {
			req.result.createObjectStore( 'queue' );
			req.result.createObjectStore( 'rejected' );
		};
		req.onsuccess = () => resolve( req.result );
		req.onerror = () => reject( req.error );
		req.onblocked = () => reject( new Error( 'blocked' ) );
	} );
}

function done( tx: IDBTransaction ): Promise<void> {
	return new Promise( ( resolve, reject ) => {
		tx.oncomplete = () => resolve();
		tx.onerror = tx.onabort = () => reject( tx.error );
	} );
}

function all<T>( db: IDBDatabase, kind: Kind ): Promise<T[]> {
	return new Promise( ( resolve, reject ) => {
		const req = db.transaction( kind ).objectStore( kind ).getAll();
		req.onsuccess = () => resolve( req.result as T[] );
		req.onerror = () => reject( req.error );
	} );
}

export interface OutboxOptions {
	send?: ( gameId: number, entries: EntryInput[] ) => Promise<PostLogResult>;
	/** Pass null to force memory only. Defaults to the browser's IndexedDB. */
	idb?: IDBFactory | null;
	dbName?: string;
}

type AckListener = ( gameId: number, result: PostLogResult ) => void;

export function createOutbox( options: OutboxOptions = {} ) {
	const send = options.send ?? ( ( gameId: number, entries: EntryInput[] ) => apiSend<PostLogResult>( 'POST', `/games/${ gameId }/log`, { entries } ) );
	const idb = options.idb === undefined ? ( typeof indexedDB === 'undefined' ? null : indexedDB ) : options.idb;

	// `idb: null` asks for memory on purpose (the demo): no warning. A browser without IndexedDB gets one.
	let state: OutboxState = { items: [], rejected: [], sync: {}, memoryOnly: ! idb && options.idb !== null };
	let seq = 0;
	const listeners = new Set<() => void>();
	const ackListeners = new Set<AckListener>();
	const running = new Set<number>();
	/** uids in a request right now: they cannot be discarded until it answers. */
	const inFlight = new Set<string>();
	const attempts = new Map<number, number>();
	const retryAt = new Map<number, number>();
	const timers = new Map<number, ReturnType<typeof setTimeout>>();
	/** Stopped for good (see start()). */
	let halted = false;

	function set( patch: Partial<OutboxState> ): void {
		state = { ...state, ...patch };
		for ( const l of [ ...listeners ] ) {
			l();
		}
	}

	function setSync( gameId: number, s: SyncState ): void {
		if ( state.sync[ gameId ] !== s ) {
			set( { sync: { ...state.sync, [ gameId ]: s } } );
		}
	}

	/* Storage. */

	let db: Promise<IDBDatabase | null> = Promise.resolve( null );
	if ( idb ) {
		db = openDb( idb, options.dbName ?? 'sppa-outbox' ).catch( () => {
			set( { memoryOnly: true } );
			return null;
		} );
	}

	async function write( kind: Kind, key: string, value: unknown ): Promise<void> {
		try {
			const d = await db;
			if ( ! d ) {
				return;
			}
			const tx = d.transaction( kind, 'readwrite' );
			if ( value === undefined ) {
				tx.objectStore( kind ).delete( key );
			} else {
				tx.objectStore( kind ).put( value, key );
			}
			await done( tx );
		} catch {
			set( { memoryOnly: true } );
		}
	}

	/** Load what an earlier page left behind. Entries added meanwhile are kept, after the loaded ones. */
	const ready = ( async () => {
		try {
			const d = await db;
			if ( ! d ) {
				return;
			}
			const [ items, rejected ] = await Promise.all( [ all<Queued>( d, 'queue' ), all<Rejected>( d, 'rejected' ) ] );
			const known = new Set( state.items.map( ( i ) => i.entry.uid ) );
			const loaded = items.filter( ( i ) => ! known.has( i.entry.uid ) ).sort( ( a, b ) => a.seq - b.seq );
			const base = Math.max( 0, ...items.map( ( i ) => i.seq + 1 ) );
			const local = state.items.map( ( i, n ) => ( { ...i, seq: base + n } ) );
			seq = base + local.length;
			set( { items: [ ...loaded, ...local ], rejected: [ ...rejected, ...state.rejected ] } );
			local.forEach( ( i ) => void write( 'queue', i.entry.uid, i ) );
		} catch {
			set( { memoryOnly: true } );
		}
	} )();

	/* Sending. */

	const pendingFor = ( gameId: number ) => state.items.filter( ( i ) => i.gameId === gameId );

	function schedule( gameId: number, delay: number ): void {
		clearTimeout( timers.get( gameId ) );
		retryAt.set( gameId, Date.now() + delay );
		timers.set(
			gameId,
			setTimeout( () => {
				timers.delete( gameId );
				void flush( gameId );
			}, delay )
		);
	}

	function drop( uids: Set<string>, reasons: Map<string, string> ): void {
		const gone = state.items.filter( ( i ) => uids.has( i.entry.uid ) );
		const rejected = gone.filter( ( i ) => reasons.has( i.entry.uid ) ).map( ( i ) => ( { gameId: i.gameId, entry: i.entry, reason: reasons.get( i.entry.uid ) as string } ) );
		set( { items: state.items.filter( ( i ) => ! uids.has( i.entry.uid ) ), rejected: [ ...state.rejected, ...rejected ] } );
		for ( const r of rejected ) {
			void write( 'rejected', r.entry.uid, r );
		}
		for ( const i of gone ) {
			void write( 'queue', i.entry.uid, undefined );
		}
	}

	/**
	 * Send a game's queue in order until it is empty or something stops it.
	 * `nudge` (new entry, online, visible) skips any backoff wait; the timer does not.
	 */
	async function flush( gameId: number, nudge = false ): Promise<void> {
		await ready;
		if ( halted || running.has( gameId ) || ( ! nudge && ( state.sync[ gameId ] === 'signed_out' || ( retryAt.get( gameId ) ?? 0 ) > Date.now() ) ) ) {
			return;
		}
		running.add( gameId );
		clearTimeout( timers.get( gameId ) );
		retryAt.delete( gameId );
		try {
			for ( let batch = pendingFor( gameId ).slice( 0, BATCH ); batch.length; batch = pendingFor( gameId ).slice( 0, BATCH ) ) {
				setSync( gameId, 'sending' );
				batch.forEach( ( i ) => inFlight.add( i.entry.uid ) );
				let result: PostLogResult;
				try {
					result = await send( gameId, batch.map( ( i ) => i.entry ) );
				} catch ( error ) {
					const e = error instanceof ApiError ? error : new ApiError( 0, 'offline', 'No connection.' );
					if ( e.code === 'signed_out' || e.status === 401 || e.status === 403 ) {
						setSync( gameId, 'signed_out' );
						return;
					}
					const transient = e.status === 0 || e.status >= 500 || e.code === 'sppa_log_locked' || e.code === 'http_error' || e.code === 'bad_response';
					if ( transient ) {
						const n = attempts.get( gameId ) ?? 0;
						attempts.set( gameId, n + 1 );
						setSync( gameId, e.status === 0 ? 'offline' : 'retrying' );
						schedule( gameId, backoff( n ) );
						return;
					}
					// The whole request was refused for good (finalized, called off, unknown game): say so, never resend.
					const reason = e.code.replace( /^sppa_/, '' );
					drop( new Set( batch.map( ( i ) => i.entry.uid ) ), new Map( batch.map( ( i ) => [ i.entry.uid, reason ] ) ) );
					continue;
				} finally {
					batch.forEach( ( i ) => inFlight.delete( i.entry.uid ) );
				}

				attempts.delete( gameId );
				const answered = new Set<string>();
				const reasons = new Map<string, string>();
				for ( const r of result.results ?? [] ) {
					answered.add( r.uid );
					if ( r.status === 'rejected' ) {
						reasons.set( r.uid, r.reason || 'rejected' );
					}
				}
				const progress = batch.some( ( i ) => answered.has( i.entry.uid ) );
				drop( answered, reasons );
				for ( const l of [ ...ackListeners ] ) {
					l( gameId, result );
				}
				if ( ! progress ) {
					// An answer that names none of what we sent: try again later rather than spin.
					setSync( gameId, 'retrying' );
					schedule( gameId, backoff( 0 ) );
					return;
				}
			}
			setSync( gameId, 'idle' );
		} finally {
			running.delete( gameId );
		}
	}

	function games(): number[] {
		return [ ...new Set( state.items.map( ( i ) => i.gameId ) ) ];
	}

	/** Try every game with something waiting. */
	function flushAll( nudge = false ): Promise<void> {
		return Promise.all( games().map( ( g ) => flush( g, nudge ) ) ).then( () => undefined );
	}

	/** Save an entry on the phone (stamping a uid if it has none) and start sending. Returns the uid. */
	function enqueue( gameId: number, entry: Omit<EntryInput, 'uid'> & { uid?: string } ): string {
		const full = { ...entry, uid: entry.uid || uuid() } as EntryInput;
		const item: Queued = { gameId, seq: seq++, entry: full };
		set( { items: [ ...state.items, item ] } );
		void write( 'queue', full.uid, item ).then( () => flush( gameId, true ) );
		return full.uid;
	}

	/** Take back an entry that has not been sent. False when it is being sent right now (void it once it lands). */
	function discard( uid: string ): boolean {
		if ( inFlight.has( uid ) ) {
			return false;
		}
		const had = state.items.some( ( i ) => i.entry.uid === uid );
		if ( had ) {
			drop( new Set( [ uid ] ), new Map() );
		}
		return had;
	}

	/** Clear a rejected entry from the list once the keeper has seen it. */
	function dismiss( uid: string ): void {
		set( { rejected: state.rejected.filter( ( r ) => r.entry.uid !== uid ) } );
		void write( 'rejected', uid, undefined );
	}

	/** Listen to the browser: online, visible, and a 20 s tick while anything waits. Returns a stop function. */
	function start(): () => void {
		const nudge = () => void flushAll( true );
		const visible = () => {
			if ( document.visibilityState === 'visible' ) {
				nudge();
			}
		};
		window.addEventListener( 'online', nudge );
		document.addEventListener( 'visibilitychange', visible );
		const tick = setInterval( () => {
			if ( state.items.length ) {
				void flushAll();
			}
		}, TICK_MS );
		void flushAll( true );
		return () => {
			halted = true;
			timers.forEach( ( t ) => clearTimeout( t ) );
			window.removeEventListener( 'online', nudge );
			document.removeEventListener( 'visibilitychange', visible );
			clearInterval( tick );
		};
	}

	return {
		ready,
		enqueue,
		discard,
		dismiss,
		flush,
		flushAll,
		start,
		getState: () => state,
		subscribe( l: () => void ) {
			listeners.add( l );
			return () => {
				listeners.delete( l );
			};
		},
		/** Called with each successful POST's answer (to merge acknowledged entries into the screen). */
		onAck( l: AckListener ) {
			ackListeners.add( l );
			return () => {
				ackListeners.delete( l );
			};
		},
	};
}

export type Outbox = ReturnType<typeof createOutbox>;

let shared: Outbox | undefined;
let stopShared: ( () => void ) | undefined;

/** The app's one outbox, started on first use. The demo keeps it in memory, so a reload starts over. */
export function outbox(): Outbox {
	if ( ! shared ) {
		shared = createOutbox( import.meta.env.VITE_DEMO === '1' ? { idb: null } : {} );
		stopShared = shared.start();
	}
	return shared;
}

/** Use `box` as the shared outbox (tests), or drop the shared one so the next use starts afresh (demo persona switch). */
export function setOutbox( box?: Outbox ): void {
	stopShared?.();
	stopShared = undefined;
	shared = box;
}

export interface Pending {
	/** Waiting to be sent, oldest first. */
	pending: EntryInput[];
	rejected: Rejected[];
	sync: SyncState;
	memoryOnly: boolean;
}

/** One game's slice of the outbox, kept in step with it. */
export function usePending( gameId: number ): Pending {
	const box = outbox();
	const state = useSyncExternalStore( box.subscribe, box.getState, box.getState );
	return useMemo(
		() => ( {
			pending: state.items.filter( ( i ) => i.gameId === gameId ).map( ( i ) => i.entry ),
			rejected: state.rejected.filter( ( r ) => r.gameId === gameId ),
			sync: state.sync[ gameId ] ?? 'idle',
			memoryOnly: state.memoryOnly,
		} ),
		[ state, gameId ]
	);
}
