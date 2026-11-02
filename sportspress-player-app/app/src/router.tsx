import { useMemo, useSyncExternalStore } from 'react';
import type { AnchorHTMLAttributes, MouseEvent } from 'react';
import { boot } from './boot';

/*
 * A small History API router. The PHP shell answers every /my-arl/... URL with
 * the same page, so deep links and reloads work; this maps the path to a screen.
 */

export interface Route {
	/** The pattern that matched, e.g. '/games/:id'. Switch on this to pick a screen. */
	path: string;
	params: Record<string, string>;
	/** The concrete path inside the app, e.g. '/games/42'. */
	pathname: string;
}

/** Every screen. Anything else shows the home screen. */
export const ROUTES = [ '/', '/schedule', '/games/:id', '/team/:id', '/spares', '/me', '/tonight' ] as const;

const NAVIGATE_EVENT = 'sppa:navigate';

/** Demo only: the route lives in the #hash, because a sandboxed frame may not allow pushState. */
const HASH = import.meta.env.VITE_DEMO === '1';

function hashPath(): string {
	return window.location.hash.replace( /^#/, '' ) || '/';
}

function decode( segment: string ): string {
	try {
		return decodeURIComponent( segment );
	} catch {
		return segment;
	}
}

function split( path: string ): string[] {
	return path.split( '/' ).filter( Boolean );
}

/** Pure: a browser pathname to a route, given the app's base ('/my-arl/'). */
export function matchRoute( browserPath: string, base: string ): Route {
	const root = '/' + split( base ).join( '/' );
	let inside = browserPath;
	if ( root !== '/' && ( browserPath === root || browserPath.startsWith( root + '/' ) ) ) {
		inside = browserPath.slice( root.length );
	}
	const segments = split( inside );

	for ( const pattern of ROUTES ) {
		const wanted = split( pattern );
		if ( wanted.length !== segments.length ) {
			continue;
		}
		const params: Record<string, string> = {};
		const matches = wanted.every( ( part, i ) => {
			if ( part.startsWith( ':' ) ) {
				params[ part.slice( 1 ) ] = decode( segments[ i ] );
				return true;
			}
			return part === segments[ i ];
		} );
		if ( matches ) {
			return { path: pattern, params, pathname: '/' + segments.map( decode ).join( '/' ) };
		}
	}
	return { path: '/', params: {}, pathname: '/' };
}

/** Pure: an app path ('/games/42') to the URL the browser shows ('/my-arl/games/42'). */
export function hrefFor( to: string, base: string ): string {
	const root = '/' + split( base ).join( '/' );
	const rest = to.replace( /^\/+/, '' );
	if ( root === '/' ) {
		return '/' + rest;
	}
	return rest === '' ? root + '/' : `${ root }/${ rest }`;
}

function subscribe( onChange: () => void ): () => void {
	window.addEventListener( HASH ? 'hashchange' : 'popstate', onChange );
	window.addEventListener( NAVIGATE_EVENT, onChange );
	return () => {
		window.removeEventListener( HASH ? 'hashchange' : 'popstate', onChange );
		window.removeEventListener( NAVIGATE_EVENT, onChange );
	};
}

/** The current route; re-renders on navigate() and on the browser's back and forward. */
export function useRoute(): Route {
	const browserPath = useSyncExternalStore( subscribe, () => ( HASH ? hashPath() : window.location.pathname ), () => '/' );
	return useMemo( () => matchRoute( browserPath, boot().appBase ), [ browserPath ] );
}

/** Go to an app path such as '/games/42'. */
export function navigate( to: string, options: { replace?: boolean } = {} ): void {
	if ( HASH ) {
		if ( hashPath() !== to ) {
			if ( options.replace ) {
				window.location.replace( '#' + to );
			} else {
				window.location.hash = to;
			}
		}
		window.scrollTo( 0, 0 );
		return;
	}
	const href = hrefFor( to, boot().appBase );
	if ( href !== window.location.pathname + window.location.search ) {
		if ( options.replace ) {
			window.history.replaceState( null, '', href );
		} else {
			window.history.pushState( null, '', href );
		}
		window.dispatchEvent( new Event( NAVIGATE_EVENT ) );
	}
	window.scrollTo( 0, 0 );
}

interface LinkProps extends Omit<AnchorHTMLAttributes<HTMLAnchorElement>, 'href'> {
	/** App path, e.g. '/schedule'. */
	to: string;
}

/** An ordinary link that navigates in place; new-tab and modified clicks keep their normal behaviour. */
export function Link( { to, onClick, ...rest }: LinkProps ) {
	const handle = ( event: MouseEvent<HTMLAnchorElement> ) => {
		onClick?.( event );
		if ( event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || rest.target === '_blank' ) {
			return;
		}
		event.preventDefault();
		navigate( to );
	};
	return <a { ...rest } href={ HASH ? '#' + to : hrefFor( to, boot().appBase ) } onClick={ handle } />;
}
