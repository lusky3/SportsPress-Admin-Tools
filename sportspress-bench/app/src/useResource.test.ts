import { describe, expect, it } from 'vitest';
import { getCached, invalidate, setCached } from './useResource';

describe( 'resource cache', () => {
	it( 'returns what was cached for a path', () => {
		setCached( '/me', { linked: true } );
		expect( getCached( '/me' ) ).toEqual( { linked: true } );
		expect( getCached( '/spares' ) ).toBeUndefined();
	} );

	it( 'invalidates by prefix and leaves other paths alone', () => {
		setCached( '/me', 1 );
		setCached( '/me/games', 2 );
		setCached( '/games/9', 3 );
		invalidate( '/me' );
		expect( getCached( '/me' ) ).toBeUndefined();
		expect( getCached( '/me/games' ) ).toBeUndefined();
		expect( getCached( '/games/9' ) ).toBe( 3 );
	} );

	it( 'invalidates everything with no prefix', () => {
		setCached( '/games/9', 3 );
		invalidate();
		expect( getCached( '/games/9' ) ).toBeUndefined();
	} );
} );
