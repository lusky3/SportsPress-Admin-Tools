// @vitest-environment jsdom
import { act, cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { fakeApi, fakeStorage, me } from './test/fake';

/** Counts loads of the keeper chunk: a player must never download it. */
const loaded = vi.hoisted( () => ( { keep: 0 } ) );
vi.mock( './screens/KeepGame', () => {
	loaded.keep++;
	return { KeepGame: () => <p>sheet</p> };
} );
vi.mock( './screens/Keep', () => {
	loaded.keep++;
	return { Keep: () => <p>keeper list</p> };
} );

import { App } from './App';

async function settle() {
	for ( let i = 0; i < 10; i++ ) {
		await act( async () => {
			await Promise.resolve();
		} );
	}
}

afterEach( () => {
	cleanup();
	vi.unstubAllGlobals();
	delete window.SPPA_BOOT;
	window.history.pushState( null, '', '/' );
} );

describe( 'App routing', () => {
	it( 'sends a player who follows a /keep/101 link to their home screen, without loading the scorekeeping chunk', async () => {
		fakeApi( ( s ) => ( s.path === '/me' ? { body: me() } : s.path === '/me/games' ? { body: { data: [], total: 0, page: 1, total_pages: 1 } } : undefined ) );
		window.history.pushState( null, '', '/my-arl/keep/101' );
		render( <App /> );
		await settle();
		expect( screen.getByText( 'No games coming up. Your next one will show up here.' ) ).toBeTruthy();
		expect( screen.queryByText( 'sheet' ) ).toBeNull();
		expect( loaded.keep ).toBe( 0 );
	} );

	it( 'loads the sheet for a timekeeper on the same link', async () => {
		fakeApi( ( s ) => ( s.path === '/me' ? { body: me( { is_timekeeper: true } ) } : undefined ) );
		window.history.pushState( null, '', '/my-arl/keep/101' );
		render( <App /> );
		await settle();
		expect( await screen.findByText( 'sheet' ) ).toBeTruthy();
	} );
} );

describe( 'App: another account', () => {
	it( 'clears the saved replies when /me names a different user than the one this phone last ran for', async () => {
		const deleted: string[] = [];
		fakeStorage();
		const sent = fakeApi( ( s ) => ( s.path === '/me' ? { body: me( { user: { id: 8, name: 'Kit' } } ) } : s.path === '/me/games' ? { body: { data: [], total: 0, page: 1, total_pages: 1 } } : undefined ) );
		vi.stubGlobal( 'caches', {
			keys: () => Promise.resolve( [ 'sppa-shell', 'sppa-api' ] ),
			delete: ( name: string ) => Promise.resolve( deleted.push( name ) > 0 ),
		} );
		render( <App /> );
		await settle();
		expect( deleted ).toEqual( [ 'sppa-shell', 'sppa-api' ] );
		expect( localStorage.getItem( 'sppa_uid' ) ).toBe( '8' );
		expect( sent.filter( ( s ) => s.path === '/me' ) ).toHaveLength( 2 ); // this user's /me, fetched again
		expect( screen.getByText( 'No games coming up. Your next one will show up here.' ) ).toBeTruthy();
	} );
} );
