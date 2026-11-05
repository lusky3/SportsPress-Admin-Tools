import { useMemo, useSyncExternalStore } from 'react';
import { ApiError, apiSend } from '../api';
import type { EntryInput, GameLog, KeeperEntry, LiveSummary, PostLogResult } from '../types';
import { getCached, patchCached } from '../useResource';

/*
 * The timekeeper's outbox. The rule it keeps: a goal a timekeeper tapped is never silently lost,
 * duplicated or misattributed.
 *
 * - An entry is written to the phone (IndexedDB) before it counts as saved; until then it shows as
 *   "saving". If IndexedDB is missing or a write fails, it stays in memory and a warning shows.
 * - Entries are sent in order, per game, in batches of up to 50, each request cut off after 15 s.
 *   The server answers each entry: created/duplicate = acknowledged (dropped here, and merged into
 *   the cached game log even if the sheet is closed); rejected = parked with its reason until the
 *   keeper enters it again or discards it.
 * - A request refused as a whole for good (PERMANENT) parks the batch, resendable with "Try again".
 *   A lost session (401, or a 403 other than sppa_forbidden) stops sending until the next nudge.
 *   Everything else (no connection, timeout, 408, 429, 5xx, a busy or unknown 409) is retried with backoff.
 * - Undo of an entry never sent removes it. Undo of one that was ever sent (the server may have it)
 *   becomes a void: it waits for the acknowledgement, then POSTs /void.
 * Nudges: a new entry, `online`, the tab becoming visible, and a 20 s tick while anything waits.
 */

export interface Queued {
	gameId: number;
	/** Send order across reloads. */
	seq: number;
	entry: EntryInput;
	/** Not yet written to the phone's storage. */
	saving?: boolean;
	/** Was in a request at least once: the server may have stored it. */
	attempted?: boolean;
	/** The keeper undid it: void it once the server has acknowledged it. */
	undo?: boolean;
	/** Acknowledged by the server (kept only while its undo is pending). */
	acked?: boolean;
}

export interface Rejected {
	gameId: number;
	entry: EntryInput;
	/** The server's reason code ('player_not_on_team'), or the whole-request error without its prefix ('not_found'). */
	reason: string;
	/** The whole request was refused (not this entry): sending it again may work. */
	whole?: boolean;
}

/** idle · sending · retrying (server busy or failing) · offline (no answer) · signed_out (stopped). */
export type SyncState = 'idle' | 'sending' | 'retrying' | 'offline' | 'signed_out';

export interface OutboxState {
	items: Queued[];
	rejected: Rejected[];
	sync: Record<number, SyncState>;
	/** Entries are not reaching the phone's storage: they live in memory and a reload would lose them. */
	memoryOnly: boolean;
}

export const BATCH = 50;
export const TIMEOUT_MS = 15_000;
/** IndexedDB that has not answered in this long is treated as unavailable (some private modes never answer). */
export const STORAGE_TIMEOUT_MS = 3_000;
const TICK_MS = 20_000;
const BACKOFF_MAX_MS = 30_000;

/** Whole-request refusals that resending as-is will not fix. Everything else is retried. */
export const PERMANENT = new Set( [ 'rest_invalid_param', 'rest_missing_callback_param', 'sppa_finalized', 'sppa_called_off', 'sppa_no_teams', 'sppa_not_found', 'sppa_forbidden' ] );

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

function all<T>( db: IDBDatabase, kind: Kind ): Promise<T[]> {
	return new Promise( ( resolve, reject ) => {
		const req = db.transaction( kind ).objectStore( kind ).getAll();
		req.onsuccess = () => resolve( req.result as T[] );
		req.onerror = () => reject( req.error );
	} );
}

/** `p`, or a rejection once `ms` pass without an answer. */
function within<T>( p: Promise<T>, ms: number ): Promise<T> {
	return new Promise( ( resolve, reject ) => {
		const timer = setTimeout( () => reject( new Error( 'timeout' ) ), ms );
		p.then(
			( v ) => {
				clearTimeout( timer );
				resolve( v );
			},
			( e ) => {
				clearTimeout( timer );
				reject( e );
			}
		);
	} );
}

/** Run a request with a 15 s limit: past it, abort and fail as "no connection". */
function timed<T>( run: ( signal: AbortSignal ) => Promise<T> ): Promise<T> {
	const ctrl = new AbortController();
	return new Promise( ( resolve, reject ) => {
		const timer = setTimeout( () => {
			ctrl.abort();
			reject( new ApiError( 0, 'timeout', 'The request took too long.' ) );
		}, TIMEOUT_MS );
		run( ctrl.signal ).then(
			( v ) => {
				clearTimeout( timer );
				resolve( v );
			},
			( e ) => {
				clearTimeout( timer );
				reject( e );
			}
		);
	} );
}

export interface VoidResult {
	entry: KeeperEntry;
	live: LiveSummary;
}

export interface OutboxOptions {
	send?: ( gameId: number, entries: EntryInput[], signal: AbortSignal ) => Promise<PostLogResult>;
	sendVoid?: ( gameId: number, uid: string, signal: AbortSignal ) => Promise<VoidResult>;
	/** Pass null for memory on purpose (the demo, no warning). Defaults to the browser's IndexedDB. */
	idb?: IDBFactory | null;
	dbName?: string;
}

type AckListener = ( gameId: number, entries: KeeperEntry[], live: LiveSummary ) => void;

export function createOutbox( options: OutboxOptions = {} ) {
	const send = options.send ?? ( ( g: number, entries: EntryInput[], signal: AbortSignal ) => apiSend<PostLogResult>( 'POST', `/games/${ g }/log`, { entries }, signal ) );
	const sendVoid = options.sendVoid ?? ( ( g: number, uid: string, signal: AbortSignal ) => apiSend<VoidResult>( 'POST', `/games/${ g }/log/${ encodeURIComponent( uid ) }/void`, undefined, signal ) );
	const idb = options.idb === undefined ? ( typeof indexedDB === 'undefined' ? null : indexedDB ) : options.idb;

	let state: OutboxState = { items: [], rejected: [], sync: {}, memoryOnly: ! idb && options.idb !== null };
	let seq = 0;
	let askedPersist = false;
	let halted = false;
	const listeners = new Set<() => void>();
	const ackListeners = new Set<AckListener>();
	const running = new Set<number>();
	const attempts = new Map<number, number>();
	const retryAt = new Map<number, number>();
	const timers = new Map<number, ReturnType<typeof setTimeout>>();

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
		// A storage that never answers must not hold entries hostage: after 3 s, carry on in memory (with the warning).
		db = within( openDb( idb, options.dbName ?? 'sppa-outbox' ), STORAGE_TIMEOUT_MS ).catch( () => {
			set( { memoryOnly: true } );
			return null;
		} );
	}

	/** Write (or delete, with undefined) one record. False when it did not reach storage. */
	async function write( kind: Kind, key: string, value: unknown ): Promise<boolean> {
		try {
			const d = await db;
			if ( ! d ) {
				return ! idb;
			}
			const tx = d.transaction( kind, 'readwrite' );
			if ( value === undefined ) {
				tx.objectStore( kind ).delete( key );
			} else {
				tx.objectStore( kind ).put( value, key );
			}
			await within(
				new Promise<void>( ( resolve, reject ) => {
					tx.oncomplete = () => resolve();
					tx.onerror = tx.onabort = () => reject( tx.error );
				} ),
				STORAGE_TIMEOUT_MS
			);
			return true;
		} catch {
			set( { memoryOnly: true } );
			return false;
		}
	}

	/** Change one queued item, here and in storage. */
	function update( uid: string, patch: Partial<Queued> ): void {
		let changed: Queued | undefined;
		set( { items: state.items.map( ( i ) => ( i.entry.uid === uid ? ( changed = { ...i, ...patch } ) : i ) ) } );
		if ( changed ) {
			const { saving: _saving, ...stored } = changed;
			void write( 'queue', uid, stored );
		}
	}

	/** Load what an earlier page left behind. Entries added meanwhile are kept, after the loaded ones. */
	const ready = ( async () => {
		try {
			const d = await db;
			if ( ! d ) {
				return;
			}
			const [ items, rejected ] = await within( Promise.all( [ all<Queued>( d, 'queue' ), all<Rejected>( d, 'rejected' ) ] ), STORAGE_TIMEOUT_MS );
			const known = new Set( state.items.map( ( i ) => i.entry.uid ) );
			const loaded = items.filter( ( i ) => ! known.has( i.entry.uid ) ).sort( ( a, b ) => a.seq - b.seq );
			const base = Math.max( 0, ...items.map( ( i ) => i.seq + 1 ) );
			const local = state.items.map( ( i, n ) => ( { ...i, seq: base + n } ) );
			seq = base + local.length;
			set( { items: [ ...loaded, ...local ], rejected: [ ...rejected, ...state.rejected ] } );
		} catch {
			set( { memoryOnly: true } );
		}
	} )();

	/* Sending. */

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

	function remove( uids: Set<string> ): void {
		const gone = state.items.filter( ( i ) => uids.has( i.entry.uid ) );
		set( { items: state.items.filter( ( i ) => ! uids.has( i.entry.uid ) ) } );
		gone.forEach( ( i ) => void write( 'queue', i.entry.uid, undefined ) );
	}

	function park( items: Queued[], reason: string, whole: boolean ): void {
		// Read each item as it is now: one the keeper undid (even while the request was out) is dropped,
		// never parked, so "Try again" can never send a goal that was taken back.
		const now = items.map( ( i ) => state.items.find( ( x ) => x.entry.uid === i.entry.uid ) ?? i );
		remove( new Set( items.map( ( i ) => i.entry.uid ) ) );
		const parked = now.filter( ( i ) => ! i.undo && ! i.acked ).map( ( i ) => ( { gameId: i.gameId, entry: i.entry, reason, ...( whole ? { whole } : {} ) } ) );
		set( { rejected: [ ...state.rejected, ...parked ] } );
		parked.forEach( ( r ) => void write( 'rejected', r.entry.uid, r ) );
	}

	/** Apply a POST's per-entry answers. True when at least one entry of the batch was answered. */
	function answer( batch: Queued[], res: PostLogResult ): boolean {
		const byUid = new Map( ( res.results ?? [] ).map( ( r ) => [ r.uid, r ] ) );
		const done = new Set<string>();
		for ( const sent of batch ) {
			const r = byUid.get( sent.entry.uid );
			// The keeper may have pressed Undo while the request was out: read the item as it is now.
			const item = state.items.find( ( i ) => i.entry.uid === sent.entry.uid ) ?? sent;
			if ( ! r ) {
				continue;
			}
			if ( r.status === 'rejected' ) {
				if ( item.undo ) {
					done.add( item.entry.uid ); // never stored, and the keeper undid it anyway
				} else {
					park( [ item ], r.reason || 'rejected', false );
				}
			} else if ( item.undo ) {
				update( item.entry.uid, { acked: true } );
			} else {
				done.add( item.entry.uid );
			}
		}
		remove( done );
		const stored = ( res.results ?? [] ).flatMap( ( r ) => ( r.entry ? [ r.entry ] : [] ) );
		for ( const l of [ ...ackListeners ] ) {
			l( batch[ 0 ].gameId, stored, res.live );
		}
		return batch.some( ( i ) => byUid.has( i.entry.uid ) );
	}

	/**
	 * Send a game's queue (entries, then voids of undone entries) until it is empty or something stops it.
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
			for ( ;; ) {
				const mine = state.items.filter( ( i ) => i.gameId === gameId );
				const batch = mine.filter( ( i ) => ! i.saving && ! i.acked ).slice( 0, BATCH );
				const undo = mine.find( ( i ) => i.acked && i.undo );
				if ( ! batch.length && ! undo ) {
					break;
				}
				setSync( gameId, 'sending' );
				try {
					if ( batch.length ) {
						batch.filter( ( i ) => ! i.attempted ).forEach( ( i ) => update( i.entry.uid, { attempted: true } ) );
						const res = await timed( ( signal ) =>
							send(
								gameId,
								batch.map( ( i ) => i.entry ),
								signal
							)
						);
						if ( ! answer( batch, res ) ) {
							throw new ApiError( 502, 'bad_response', 'The answer named none of the entries.' );
						}
					} else if ( undo ) {
						const res = await timed( ( signal ) => sendVoid( gameId, undo.entry.uid, signal ) );
						remove( new Set( [ undo.entry.uid ] ) );
						for ( const l of [ ...ackListeners ] ) {
							l( gameId, [ res.entry ], res.live );
						}
					}
					attempts.delete( gameId );
				} catch ( error ) {
					const e = error instanceof ApiError ? error : new ApiError( 0, 'offline', 'No connection.' );
					if ( e.code === 'signed_out' || e.status === 401 || ( e.status === 403 && e.code !== 'sppa_forbidden' ) ) {
						setSync( gameId, 'signed_out' );
						return;
					}
					if ( PERMANENT.has( e.code ) ) {
						const reason = e.code.replace( /^sppa_/, '' );
						if ( batch.length ) {
							park( batch, reason, true );
						} else if ( undo ) {
							// The server will not void it (the game is final, say): it stays in the log; tell the keeper.
							remove( new Set( [ undo.entry.uid ] ) );
							set( { rejected: [ ...state.rejected, { gameId, entry: undo.entry, reason: `void_${ reason }` } ] } );
						}
						continue;
					}
					const n = attempts.get( gameId ) ?? 0;
					attempts.set( gameId, n + 1 );
					setSync( gameId, e.status === 0 ? 'offline' : 'retrying' );
					schedule( gameId, backoff( n ) );
					return;
				}
			}
			setSync( gameId, 'idle' );
		} finally {
			running.delete( gameId );
		}
	}

	/** Try every game with something waiting, once what an earlier page stored has been loaded. */
	async function flushAll( nudge = false ): Promise<void> {
		await ready;
		const games = new Set( state.items.map( ( i ) => i.gameId ) );
		await Promise.all( [ ...games ].map( ( g ) => flush( g, nudge ) ) );
	}

	/**
	 * Save an entry (stamping a uid if it has none): it shows as "saving" until it is in the phone's
	 * storage, then sending starts. Returns the uid at once and the save as a promise.
	 */
	function enqueue( gameId: number, entry: Omit<EntryInput, 'uid'> & { uid?: string } ): string {
		const full = { ...entry, uid: entry.uid || uuid() } as EntryInput;
		const item: Queued = { gameId, seq: seq++, entry: full };
		set( { items: [ ...state.items, { ...item, saving: true } ] } );
		if ( ! askedPersist ) {
			askedPersist = true;
			try {
				void navigator.storage?.persist?.().catch( () => undefined );
			} catch {
				// Not offered here; the outbox works without it.
			}
		}
		void write( 'queue', full.uid, item ).then( () => {
			set( { items: state.items.map( ( i ) => ( i.entry.uid === full.uid ? { ...i, saving: false } : i ) ) } );
			return flush( gameId, true );
		} );
		return full.uid;
	}

	/**
	 * Undo an entry that has not been acknowledged. Never sent: it is removed ('removed').
	 * Sent at least once (the server may have it): it is voided once acknowledged ('undoing').
	 */
	function discard( uid: string ): 'removed' | 'undoing' | 'unknown' {
		const item = state.items.find( ( i ) => i.entry.uid === uid );
		if ( ! item ) {
			return 'unknown';
		}
		// Entries are marked attempted before a request leaves, so one that is not was never sent.
		if ( ! item.attempted ) {
			remove( new Set( [ uid ] ) );
			return 'removed';
		}
		update( uid, { undo: true } );
		void flush( item.gameId, true );
		return 'undoing';
	}

	/** Forget a parked entry (the keeper discarded it or entered it again). */
	function dismiss( uid: string ): void {
		set( { rejected: state.rejected.filter( ( r ) => r.entry.uid !== uid ) } );
		void write( 'rejected', uid, undefined );
	}

	/** Put a parked entry back in the queue as it was (same uid), after a whole-request refusal. */
	function retry( uid: string ): void {
		const r = state.rejected.find( ( x ) => x.entry.uid === uid );
		if ( r ) {
			dismiss( uid );
			const item: Queued = { gameId: r.gameId, seq: seq++, entry: r.entry, attempted: true };
			set( { items: [ ...state.items, item ] } );
			void write( 'queue', uid, item ).then( () => flush( r.gameId, true ) );
		}
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
		retry,
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
		/** Called with the stored entries the server returned (acknowledged or voided) and the game's live summary. */
		onAck( l: AckListener ) {
			ackListeners.add( l );
			return () => {
				ackListeners.delete( l );
			};
		},
	};
}

export type Outbox = ReturnType<typeof createOutbox>;

/**
 * Fold what the server returned into the cached /games/{id}/log, whether or not the sheet is open,
 * so coming back to it never shows the score from before (and never invites entering a goal twice).
 */
export function mergeIntoLog( gameId: number, entries: KeeperEntry[], live: LiveSummary ): void {
	const path = `/games/${ gameId }/log`;
	const cur = getCached<GameLog>( path );
	if ( ! cur ) {
		return;
	}
	const fresh = new Map( entries.map( ( e ) => [ e.uid, e ] ) );
	const kept = cur.entries.map( ( e ) => fresh.get( e.uid ) ?? e );
	const added = entries.filter( ( e ) => ! cur.entries.some( ( c ) => c.uid === e.uid ) ).reverse();
	// patch, not replace: a reload in flight (the Finish sheet's) must still land with the server's totals.
	patchCached( path, { ...cur, ...( live ?? {} ), entries: [ ...added, ...kept ] } );
}

let shared: Outbox | undefined;
let stopShared: ( () => void ) | undefined;

/** The app's one outbox, started on first use. The demo keeps it in memory, so a reload starts over. */
export function outbox(): Outbox {
	if ( ! shared ) {
		shared = createOutbox( import.meta.env.VITE_DEMO === '1' ? { idb: null } : {} );
		shared.onAck( mergeIntoLog );
		stopShared = shared.start();
	}
	return shared;
}

/** Use `box` as the shared outbox (tests; it gets the log merge), or drop the shared one so the next use starts afresh (demo persona switch). */
export function setOutbox( box?: Outbox ): void {
	stopShared?.();
	stopShared = undefined;
	shared = box;
	box?.onAck( mergeIntoLog );
}

export interface Pending {
	/** Not yet acknowledged (or acknowledged and being undone), oldest first. */
	pending: Queued[];
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
			pending: state.items.filter( ( i ) => i.gameId === gameId ),
			rejected: state.rejected.filter( ( r ) => r.gameId === gameId ),
			sync: state.sync[ gameId ] ?? 'idle',
			memoryOnly: state.memoryOnly,
		} ),
		[ state, gameId ]
	);
}
