import { describe, expect, it } from 'vitest';
import { hrefFor, matchRoute } from './router';

describe( 'matchRoute', () => {
	it( 'matches every route and extracts params', () => {
		expect( matchRoute( '/bench/', '/bench/' ) ).toMatchObject( { path: '/', params: {} } );
		expect( matchRoute( '/bench/schedule', '/bench/' ) ).toMatchObject( { path: '/schedule', params: {} } );
		expect( matchRoute( '/bench/games/42', '/bench/' ) ).toMatchObject( { path: '/games/:id', params: { id: '42' } } );
		expect( matchRoute( '/bench/team/7', '/bench/' ) ).toMatchObject( { path: '/team/:id', params: { id: '7' } } );
		expect( matchRoute( '/bench/spares', '/bench/' ) ).toMatchObject( { path: '/spares', params: {} } );
		expect( matchRoute( '/bench/me', '/bench/' ) ).toMatchObject( { path: '/me', params: {} } );
		expect( matchRoute( '/bench/tonight', '/bench/' ) ).toMatchObject( { path: '/tonight', params: {} } );
	} );

	it( 'treats the bare base, a missing trailing slash and a trailing slash alike', () => {
		expect( matchRoute( '/bench', '/bench/' ).path ).toBe( '/' );
		expect( matchRoute( '/bench/schedule/', '/bench/' ).path ).toBe( '/schedule' );
	} );

	it( 'reports the concrete app-relative pathname', () => {
		expect( matchRoute( '/bench/games/42', '/bench/' ).pathname ).toBe( '/games/42' );
	} );

	it( 'falls back to the home route for an unknown path', () => {
		expect( matchRoute( '/bench/nope', '/bench/' ) ).toMatchObject( { path: '/', params: {}, pathname: '/' } );
		expect( matchRoute( '/bench/games', '/bench/' ).path ).toBe( '/' );
		expect( matchRoute( '/bench/games/42/extra', '/bench/' ).path ).toBe( '/' );
	} );

	it( 'decodes params', () => {
		expect( matchRoute( '/bench/games/4%32', '/bench/' ).params ).toEqual( { id: '42' } );
	} );
} );

describe( 'hrefFor', () => {
	it( 'puts an app path under the base', () => {
		expect( hrefFor( '/games/42', '/bench/' ) ).toBe( '/bench/games/42' );
		expect( hrefFor( '/', '/bench/' ) ).toBe( '/bench/' );
		expect( hrefFor( 'schedule', '/bench/' ) ).toBe( '/bench/schedule' );
	} );
} );
