import { onSignedOut } from '../api';
import { boot, bootUserId } from '../boot';
import { forgetData } from '../useResource';
import { clearOfflineData } from './offline';

/*
 * Whose saved replies these are.
 *
 * The service worker keeps the last good answer to each GET in one cache keyed by URL (sppa-api),
 * and the page shell in another (sppa-shell). The app's own Sign out clears both, but a player who
 * signs out through WordPress or My Account never runs it, and the next person on that phone could
 * be shown the last person's /me, calendar link and answers whenever the network fails or is slow.
 *
 * So the app remembers which user it last ran for (localStorage `sppa_uid`) and clears both caches
 * and its in-memory copies when that changes: on start (the shell's userId), when /me names
 * someone else, and when the server says the session is gone. The timekeeper outbox is never
 * touched here; its entries carry their owner instead (lib/outbox.ts).
 *
 * The limit: a phone signed out outside the app keeps the last user's saved replies until the
 * app is next opened online. Offline, the worker serves the old shell, which still names the old
 * user, so nothing here can tell that anyone signed out.
 */

const KEY = 'sppa_uid';
const demo = import.meta.env.VITE_DEMO === '1';

/** Stands in for localStorage when it is blocked: good for this page only. */
let memory: number | undefined;
/** The user /me last named on this page. */
let current: number | undefined;
const listeners = new Set<() => void>();

function stored(): number | undefined {
	try {
		const value = localStorage.getItem( KEY );
		return value === null ? memory : Number( value );
	} catch {
		return memory;
	}
}

function store( id: number ): void {
	memory = id;
	try {
		localStorage.setItem( KEY, String( id ) );
	} catch {
		// Blocked storage: the page remembers; a reload clears the saved replies again, which is safe.
	}
}

/** The signed-in user as far as this page knows: /me's answer once seen, before that the shell's. 0 = signed out or unknown. */
export function currentUser(): number {
	return current ?? bootUserId();
}

/** The last user this phone ran for, even while signed out now (0 when nobody is known). */
export function lastKnownUser(): number {
	const id = current ?? stored();
	return id && id > 0 ? id : 0;
}

/*
 * Another account under this page. The server refuses a write whose `owner` is not the signed-in
 * user (409 sppa_wrong_user): someone signed in as someone else in another tab, and the cookie is
 * theirs now. Nothing more is sent from this page; a reload reads the shell's userId again.
 */
let switched = false;
const switchListeners = new Set<() => void>();

/** The server said another account is signed in: stop sending until the page is reloaded. */
export function markSwitched(): void {
	if ( ! switched ) {
		switched = true;
		for ( const listener of [ ...switchListeners ] ) {
			listener();
		}
	}
}

export function isSwitched(): boolean {
	return switched;
}

export function onSwitched( listener: () => void ): () => void {
	switchListeners.add( listener );
	return () => {
		switchListeners.delete( listener );
	};
}

/** Listen for the current user changing (another keeper signed in). Returns the unsubscribe function. */
export function onUserChange( listener: () => void ): () => void {
	listeners.add( listener );
	return () => {
		listeners.delete( listener );
	};
}

async function forget( reload: boolean ): Promise<void> {
	if ( demo ) {
		return; // the demo has no worker and no real accounts
	}
	forgetData( reload );
	await clearOfflineData();
}

/**
 * On start, before the first request: clear what was saved for someone else. An older shell that
 * names no user is left alone (/me decides), so an offline start never throws away the only copy.
 */
export async function checkBootUser(): Promise<void> {
	const id = boot().userId;
	if ( typeof id !== 'number' ) {
		return;
	}
	if ( stored() !== id ) {
		store( id );
		await forget( false );
	}
}

/** /me answered for `id`: if that is not who the saved replies belong to, clear them and load this user's. */
export function seenUser( id: number ): void {
	const was = currentUser();
	current = id;
	if ( stored() !== id ) {
		store( id );
		void forget( true );
	}
	if ( was !== id ) {
		for ( const listener of [ ...listeners ] ) {
			listener();
		}
	}
}

// A lost session (signed out elsewhere, or expired) drops the saved replies. What this page holds
// in memory stays, so a keeper mid-game keeps the sheet and its "sign in to send" prompt.
onSignedOut( () => {
	if ( ! demo ) {
		void clearOfflineData();
	}
} );

/** Test helper: forget the current user, and set the in-page fallback to `last` (the user this phone last ran for). */
export function _resetAccountForTests( last?: number ): void {
	memory = last;
	current = undefined;
	switched = false;
}
