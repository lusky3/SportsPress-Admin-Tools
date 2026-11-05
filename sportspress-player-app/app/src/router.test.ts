import { describe, expect, it } from 'vitest';
import { hrefFor, matchRoute } from './router';

describe( 'matchRoute', () => {
	it( 'matches every route and extracts params', () => {
		expect( matchRoute( '/my-arl/', '/my-arl/' ) ).toMatchObject( { path: '/', params: {} } );
		expect( matchRoute( '/my-arl/schedule', '/my-arl/' ) ).toMatchObject( { path: '/schedule', params: {} } );
		expect( matchRoute( '/my-arl/games/42', '/my-arl/' ) ).toMatchObject( { path: '/games/:id', params: { id: '42' } } );
		expect( matchRoute( '/my-arl/team/7', '/my-arl/' ) ).toMatchObject( { path: '/team/:id', params: { id: '7' } } );
		expect( matchRoute( '/my-arl/spares', '/my-arl/' ) ).toMatchObject( { path: '/spares', params: {} } );
		expect( matchRoute( '/my-arl/me', '/my-arl/' ) ).toMatchObject( { path: '/me', params: {} } );
		expect( matchRoute( '/my-arl/tonight', '/my-arl/' ) ).toMatchObject( { path: '/tonight', params: {} } );
	} );

	it( 'treats the bare base, a missing trailing slash and a trailing slash alike', () => {
		expect( matchRoute( '/my-arl', '/my-arl/' ).path ).toBe( '/' );
		expect( matchRoute( '/my-arl/schedule/', '/my-arl/' ).path ).toBe( '/schedule' );
	} );

	it( 'reports the concrete app-relative pathname', () => {
		expect( matchRoute( '/my-arl/games/42', '/my-arl/' ).pathname ).toBe( '/games/42' );
	} );

	it( 'falls back to the home route for an unknown path', () => {
		expect( matchRoute( '/my-arl/nope', '/my-arl/' ) ).toMatchObject( { path: '/', params: {}, pathname: '/' } );
		expect( matchRoute( '/my-arl/games', '/my-arl/' ).path ).toBe( '/' );
		expect( matchRoute( '/my-arl/games/42/extra', '/my-arl/' ).path ).toBe( '/' );
	} );

	it( 'decodes params', () => {
		expect( matchRoute( '/my-arl/games/4%32', '/my-arl/' ).params ).toEqual( { id: '42' } );
	} );
} );

describe( 'hrefFor', () => {
	it( 'puts an app path under the base', () => {
		expect( hrefFor( '/games/42', '/my-arl/' ) ).toBe( '/my-arl/games/42' );
		expect( hrefFor( '/', '/my-arl/' ) ).toBe( '/my-arl/' );
		expect( hrefFor( 'schedule', '/my-arl/' ) ).toBe( '/my-arl/schedule' );
	} );
} );
