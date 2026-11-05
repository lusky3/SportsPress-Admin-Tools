import { boot } from './boot';
import type { WpErrorBody } from './types';

/** Thrown for every failed request. `status` is 0 when there was no response at all. */
export class ApiError extends Error {
	readonly status: number;
	readonly code: string;
	/** `data.reason` from the WP_Error, e.g. 'busy' on a 403 sppa_not_eligible. */
	readonly reason: string;
	/** The WP_Error's `data`, e.g. `{ result: { home, away } }` on a 409 sppa_result_exists. */
	readonly data: Record<string, unknown>;

	constructor( status: number, code: string, message: string, reason = '', data: Record<string, unknown> = {} ) {
		super( message );
		this.name = 'ApiError';
		this.status = status;
		this.code = code;
		this.reason = reason;
		this.data = data;
	}
}

/*
 * Offline signal. The service worker marks a response it served from its cache
 * with `X-SPPA-From-Cache`, and a browser that says it has no connection makes
 * everything it shows a saved copy. Screens show OfflineBanner while this is true.
 */

type OfflineListener = ( offline: boolean ) => void;

let offline = false;
const listeners = new Set<OfflineListener>();

function setOffline( value: boolean ): void {
	if ( value === offline ) {
		return;
	}
	offline = value;
	for ( const listener of [ ...listeners ] ) {
		listener( value );
	}
}

export function isOffline(): boolean {
	return offline;
}

/** Listen for the app going offline (true) or getting fresh data again (false). Returns the unsubscribe function. */
export function onOffline( callback: OfflineListener ): () => void {
	listeners.add( callback );
	return () => {
		listeners.delete( callback );
	};
}

if ( typeof window !== 'undefined' && typeof window.addEventListener === 'function' ) {
	window.addEventListener( 'offline', () => setOffline( true ) );
	window.addEventListener( 'online', () => setOffline( false ) );
}

function browserSaysOffline(): boolean {
	return typeof navigator !== 'undefined' && navigator.onLine === false;
}

/*
 * Nonce. The one in SPPA_BOOT expires (WordPress nonces last 12-24 hours) while
 * an installed app can stay open for days. On a 401, or a 403 that says the nonce
 * is bad, fetch a new one from admin-ajax and retry the request once; a second
 * failure means the session is gone and the screen should ask the person to sign in.
 */

let nonce: string | undefined;
let refreshing: Promise<string> | null = null;

function currentNonce(): string {
	return nonce ?? boot().nonce;
}

function signedOut(): ApiError {
	return new ApiError( 401, 'signed_out', 'Your session has ended. Sign in again to carry on.' );
}

function needsNonce( error: unknown ): boolean {
	return error instanceof ApiError && ( error.status === 401 || ( error.status === 403 && error.code === 'rest_cookie_invalid_nonce' ) );
}

async function fetchNonce(): Promise<string> {
	let response: Response;
	try {
		response = await fetch( `${ boot().ajaxUrl }?action=rest-nonce`, { credentials: 'same-origin', cache: 'no-store' } );
	} catch {
		throw new ApiError( 0, 'offline', 'No connection.' );
	}
	const body = response.ok ? ( await response.text() ).trim() : '';
	// A signed-out visitor gets "0" (or an error status); a real nonce is a short alphanumeric string.
	if ( ! /^[A-Za-z0-9]{6,}$/.test( body ) ) {
		throw signedOut();
	}
	nonce = body;
	return body;
}

/** Get a fresh REST nonce. Requests that expire together share one trip. */
export function refreshNonce(): Promise<string> {
	refreshing ??= fetchNonce().finally( () => {
		refreshing = null;
	} );
	return refreshing;
}

/*
 * Requests.
 */

function urlFor( path: string ): string {
	const root = boot().restRoot;
	let relative = path.replace( /^\/+/, '' );
	if ( root.includes( '?' ) ) {
		// Plain permalinks: the route lives in ?rest_route=, so the query joins with &.
		relative = relative.replace( '?', '&' );
	}
	return root + relative;
}

async function readError( response: Response ): Promise<ApiError> {
	let body: WpErrorBody = {};
	try {
		body = ( await response.json() ) as WpErrorBody;
	} catch {
		// Not JSON (a proxy error page): fall back to the status.
	}
	return new ApiError( response.status, body.code || 'http_error', body.message || response.statusText || 'The request failed.', body.data?.reason || '', body.data ?? {} );
}

interface Raw<T> {
	data: T;
	response: Response;
}

/*
 * Demo seam. The demo build (npm run build:demo) sets VITE_DEMO and swaps the network for an
 * in-memory server. In a normal build the constant is undefined, so this all folds away.
 */
let transport: typeof fetch | undefined;

/** Demo only: answer every request with `send` instead of the network. */
export function setTransport( send: typeof fetch | undefined ): void {
	transport = send;
}

async function once<T>( method: string, path: string, body: unknown, nonceValue: string, signal?: AbortSignal ): Promise<Raw<T>> {
	const headers: Record<string, string> = { Accept: 'application/json', 'X-WP-Nonce': nonceValue };
	const init: RequestInit = { method, credentials: 'same-origin', headers, signal };
	if ( body !== undefined ) {
		headers[ 'Content-Type' ] = 'application/json';
		init.body = JSON.stringify( body );
	}

	let response: Response;
	try {
		response = await ( import.meta.env.VITE_DEMO === '1' && transport ? transport : fetch )( urlFor( path ), init );
	} catch {
		throw new ApiError( 0, 'offline', 'No connection.' );
	}
	if ( ! response.ok ) {
		throw await readError( response );
	}
	const text = await response.text();
	try {
		return { data: ( text === '' ? undefined : JSON.parse( text ) ) as T, response };
	} catch {
		// A 200 that is not JSON: a captive portal, a maintenance page, a PHP notice before the body.
		throw new ApiError( response.status, 'bad_response', 'The server sent something we could not read.' );
	}
}

const sessionLost = new Set<() => void>();

/** Listen for the server saying the session is gone (signed_out after the nonce retry). Returns the unsubscribe function. */
export function onSignedOut( callback: () => void ): () => void {
	sessionLost.add( callback );
	return () => {
		sessionLost.delete( callback );
	};
}

async function request<T>( method: string, path: string, body?: unknown, signal?: AbortSignal ): Promise<Raw<T>> {
	try {
		return await requestOnce<T>( method, path, body, signal );
	} catch ( error ) {
		if ( error instanceof ApiError && error.code === 'signed_out' ) {
			for ( const listener of [ ...sessionLost ] ) {
				listener();
			}
		}
		throw error;
	}
}

async function requestOnce<T>( method: string, path: string, body?: unknown, signal?: AbortSignal ): Promise<Raw<T>> {
	const used = currentNonce();
	try {
		return await once<T>( method, path, body, used, signal );
	} catch ( error ) {
		if ( ! needsNonce( error ) ) {
			throw error;
		}
	}

	// Another request may already have fetched a new nonce while this one was in flight.
	if ( currentNonce() === used ) {
		await refreshNonce();
	}
	try {
		return await once<T>( method, path, body, currentNonce(), signal );
	} catch ( error ) {
		throw needsNonce( error ) ? signedOut() : error;
	}
}

export interface ApiResult<T> {
	data: T;
	/** When the server produced the response (its Date header), for "saved at 7:42 PM". */
	savedAt: Date | undefined;
	/** True when this is a saved copy: served by the service worker, or the browser is offline. */
	fromCache: boolean;
}

/** GET with the extras a screen needs to say how old its data is. */
export async function apiGetWithMeta<T>( path: string ): Promise<ApiResult<T>> {
	const { data, response } = await request<T>( 'GET', path );
	const header = response.headers.get( 'Date' );
	const date = header ? new Date( header ) : undefined;
	const fromCache = response.headers.has( 'X-SPPA-From-Cache' ) || browserSaysOffline();
	setOffline( fromCache );
	return { data, savedAt: date && ! Number.isNaN( date.getTime() ) ? date : undefined, fromCache };
}

/** GET `path` (relative to the REST root, e.g. '/me') and return the JSON. */
export async function apiGet<T>( path: string ): Promise<T> {
	return ( await apiGetWithMeta<T>( path ) ).data;
}

/** POST or PUT a JSON body to `path` and return the JSON. An aborted `signal` fails like no connection (status 0). */
export async function apiSend<T>( method: 'POST' | 'PUT', path: string, body?: unknown, signal?: AbortSignal ): Promise<T> {
	return ( await request<T>( method, path, body, signal ) ).data;
}

/** Test helper: forget the nonce, any refresh in flight, and the offline state. */
export function _resetApiForTests(): void {
	nonce = undefined;
	refreshing = null;
	offline = false;
	listeners.clear();
}
