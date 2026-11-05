// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { call, detail, error, fakeApi, game, me } from '../test/fake';
import type { Sent } from '../test/fake';
import { Game } from './Game';
import { Next } from './Next';
import { Schedule } from './Schedule';

const list = ( games: unknown[] ) => ( { body: { data: games, total: games.length, page: 1, total_pages: 1 } } );

/** Let fetches and the renders after them settle. */
async function settle() {
	for ( let i = 0; i < 5; i++ ) {
		await act( async () => {
			await Promise.resolve();
		} );
	}
}

beforeEach( () => {
	vi.useFakeTimers( { toFake: [ 'Date' ] } );
	vi.setSystemTime( new Date( '2026-11-04T12:00:00-05:00' ) ); // Wednesday noon
} );

afterEach( () => {
	cleanup();
	vi.useRealTimers();
	vi.unstubAllGlobals();
	delete window.SPPA_BOOT;
} );

describe( 'Next', () => {
	it( 'shows the friendly card with My Account and help when the account has no player', async () => {
		fakeApi( () => undefined );
		render( <Next me={ me( { linked: false, players: [], teams: [], ical_url: '' } ) } /> );
		expect( screen.getByText( "We couldn't match your account to a player yet." ) ).toBeTruthy();
		expect( screen.getByRole( 'link', { name: 'Go to My Account' } ).getAttribute( 'href' ) ).toBe( 'https://example.test/my-account/' );
		expect( screen.getByRole( 'link', { name: 'Get help' } ).getAttribute( 'href' ) ).toBe( 'https://help.example.test/' );
	} );

	it( 'puts the next game in the hero: day, time, rink and pad, address, directions, opponent, counts', async () => {
		fakeApi( ( s ) => {
			if ( s.path === '/me/games' ) {
				return list( [ game( { id: 100, start: '2026-11-01T20:00:00-05:00', result: { for: 3, against: 1, outcome: 'W' } } ), game(), game( { id: 102, start: '2026-11-11T20:30:00-05:00' } ) ] );
			}
			if ( s.path === '/games/101' ) {
				return { body: detail() };
			}
		} );
		render( <Next me={ me() } /> );
		await settle();
		const hero = screen.getByRole( 'region', { name: /next game/i } );
		expect( hero.textContent ).toContain( 'Tonight' );
		expect( hero.textContent ).toContain( '9:45 PM' );
		expect( hero.textContent ).toContain( 'Wave Twin Rinks' );
		expect( hero.textContent ).toContain( 'Black pad' );
		expect( hero.textContent ).toContain( '1179 Northside Rd, Burlington, ON' );
		const directions = within( hero ).getByRole( 'link', { name: /directions/i } );
		expect( directions.getAttribute( 'href' ) ).toBe( 'https://maps.example.test/?q=wave' );
		expect( directions.getAttribute( 'target' ) ).toBe( '_blank' );
		expect( directions.getAttribute( 'rel' ) ).toContain( 'noopener' );
		expect( hero.textContent ).toContain( 'Knights of Ni' );
		expect( hero.textContent ).toContain( '6 in · 2 out · 1 maybe · 5 no reply' );
		expect( hero.textContent ).toMatch( /no goalie yet/i );
		// The game after it, compact.
		expect( screen.getByText( 'Wed, Nov 11' ) ).toBeTruthy();
	} );

	it( 'falls back to the venue name when there is no rink, and leaves out directions without a map link', async () => {
		fakeApi( ( s ) => {
			if ( s.path === '/me/games' ) {
				return list( [ game( { venue: { name: 'Appleby 1', rink: '', address: '', map_url: '' } } ) ] );
			}
			if ( s.path === '/games/101' ) {
				return { body: detail( { venue: { name: 'Appleby 1', rink: '', address: '', map_url: '' } } ) };
			}
		} );
		render( <Next me={ me() } /> );
		await settle();
		const hero = screen.getByRole( 'region', { name: /next game/i } );
		expect( hero.textContent ).toContain( 'Appleby 1' );
		expect( hero.textContent ).not.toContain( 'pad' );
		expect( within( hero ).queryByRole( 'link', { name: /directions/i } ) ).toBeNull();
	} );

	it( 'saves an answer for the player the game screen named', async () => {
		const sent = fakeApi( ( s ) => {
			if ( s.path === '/me/games' ) {
				return list( [ game() ] );
			}
			if ( s.path === '/games/101' && s.method === 'GET' ) {
				return { body: detail() };
			}
			if ( s.path === '/games/101/availability' ) {
				return { body: { success: true, availability: {}, counts: { in: 6, out: 3, maybe: 1, none: 4, goalies_in: 0, spares: 0 } } };
			}
		} );
		render( <Next me={ me() } /> );
		await settle();
		await act( async () => {
			fireEvent.click( screen.getByRole( 'radio', { name: 'Out' } ) );
		} );
		await settle();
		const put = sent.find( ( s ) => s.method === 'PUT' ) as Sent;
		expect( put.path ).toBe( '/games/101/availability' );
		expect( put.body ).toEqual( { status: 'out', player_id: 50 } );
		expect( screen.getByRole( 'region', { name: /next game/i } ).textContent ).toContain( '6 in · 3 out · 1 maybe · 4 no reply' );
	} );

	it( 'offers a spare call when short, posts it, and shows the call', async () => {
		const sent = fakeApi( ( s ) => {
			if ( s.path === '/me/games' ) {
				return list( [ game() ] );
			}
			if ( s.path === '/games/101' ) {
				return { body: detail() };
			}
			if ( s.path === '/games/101/spare-calls' ) {
				return { status: 201, body: { success: true, call: call( { needed: 2, note: 'Bring white' } ) } };
			}
		} );
		render( <Next me={ me() } /> );
		await settle();
		fireEvent.click( screen.getByRole( 'button', { name: /short\? call a spare/i } ) );
		fireEvent.click( screen.getByRole( 'radio', { name: 'Goalie' } ) );
		fireEvent.change( screen.getByLabelText( /how many/i ), { target: { value: '2' } } );
		fireEvent.change( screen.getByLabelText( /note/i ), { target: { value: 'Bring white' } } );
		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: /post the call/i } ) );
		} );
		await settle();
		const post = sent.find( ( s ) => s.method === 'POST' ) as Sent;
		expect( post.path ).toBe( '/games/101/spare-calls' );
		expect( post.body ).toEqual( { team_id: 7, position: 'goalie', needed: 2, note: 'Bring white' } );
		expect( screen.getByText( /looking for 2 goalies/i ) ).toBeTruthy();
	} );

	it( 'cancels a call only on a second tap', async () => {
		const sent = fakeApi( ( s ) => {
			if ( s.path === '/me/games' ) {
				return list( [ game() ] );
			}
			if ( s.path === '/games/101' ) {
				return { body: detail( { spare_calls: [ call() ] } ) };
			}
			if ( s.path === '/spare-calls/900/cancel' ) {
				return { body: { success: true, call: call( { status: 'cancelled' } ) } };
			}
		} );
		render( <Next me={ me() } /> );
		await settle();
		fireEvent.click( screen.getByRole( 'button', { name: 'Cancel call' } ) );
		expect( sent.some( ( s ) => s.method === 'POST' ) ).toBe( false );
		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: /tap again to cancel/i } ) );
		} );
		await settle();
		expect( sent.filter( ( s ) => s.method === 'POST' ).map( ( s ) => s.path ) ).toEqual( [ '/spare-calls/900/cancel' ] );
		expect( screen.queryByText( /looking for/i ) ).toBeNull();
	} );

	it( 'shows a filled call as found, with no Cancel', async () => {
		fakeApi( ( s ) => {
			if ( s.path === '/me/games' ) {
				return list( [ game() ] );
			}
			if ( s.path === '/games/101' ) {
				return { body: detail( { spare_calls: [ call( { status: 'filled', accepted: 1 } ) ] } ) };
			}
		} );
		render( <Next me={ me() } /> );
		await settle();
		expect( screen.getByText( 'Spare found' ) ).toBeTruthy();
		expect( screen.queryByText( /looking for/i ) ).toBeNull();
		expect( screen.queryByRole( 'button', { name: 'Cancel call' } ) ).toBeNull();
	} );

	it( 'flags a changed game and says when there is nothing coming up', async () => {
		fakeApi( ( s ) => ( s.path === '/me/games' ? list( [ game( { changed_recently: true } ) ] ) : s.path === '/games/101' ? { body: detail( { changed_recently: true } ) } : undefined ) );
		const { unmount } = render( <Next me={ me() } /> );
		await settle();
		expect( screen.getByRole( 'region', { name: /next game/i } ).textContent ).toContain( 'Changed' );
		unmount();

		fakeApi( ( s ) => ( s.path === '/me/games' ? list( [ game( { status: 'cancelled' } ) ] ) : undefined ) );
		render( <Next me={ me() } /> );
		await settle();
		expect( screen.getByText( /no games coming up/i ) ).toBeTruthy();
	} );

	it( 'skips a called-off game for "After that"', async () => {
		fakeApi( ( s ) =>
			s.path === '/me/games'
				? list( [
						game(),
						game( { id: 102, start: '2026-11-09T20:30:00-05:00', status: 'postponed', opponent: { id: 20, name: 'Called Off FC', logo: '' } } ),
						game( { id: 103, start: '2026-11-11T20:30:00-05:00', opponent: { id: 21, name: 'Pucks Deluxe', logo: '' } } ),
				  ] )
				: s.path === '/games/101'
				? { body: detail() }
				: undefined
		);
		render( <Next me={ me() } /> );
		await settle();
		const after = screen.getByRole( 'region', { name: /after that/i } );
		expect( after.textContent ).toContain( 'Pucks Deluxe' );
		expect( after.textContent ).not.toContain( 'Called Off FC' );
	} );
} );

describe( 'Schedule', () => {
	it( 'groups by month, links each row, hides counts on past rows and strikes called-off ones', async () => {
		fakeApi( ( s ) =>
			s.path === '/me/games'
				? list( [
						game( { id: 90, start: '2026-10-28T20:00:00-04:00', result: { for: 4, against: 2, outcome: 'W' }, counts: { in: 0, out: 0, maybe: 0 } } ),
						game( { id: 100, start: '2026-11-01T20:00:00-05:00', status: 'postponed', counts: { in: 0, out: 0, maybe: 0 } } ),
						game( { my_availability: 'in', changed_recently: true } ),
				  ] )
				: undefined
		);
		render( <Schedule me={ me() } /> );
		await settle();
		expect( screen.getByRole( 'heading', { name: 'October 2026' } ) ).toBeTruthy();
		expect( screen.getByRole( 'heading', { name: 'November 2026' } ) ).toBeTruthy();
		const rows = screen.getAllByRole( 'link' );
		expect( rows.map( ( a ) => a.getAttribute( 'href' ) ) ).toEqual( [ '/my-arl/games/90', '/my-arl/games/100', '/my-arl/games/101' ] );
		expect( rows[ 0 ].textContent ).toContain( 'W 4–2' );
		expect( rows[ 0 ].textContent ).not.toMatch( /\d+ in/ );
		expect( rows[ 1 ].textContent ).toContain( 'Postponed' );
		expect( rows[ 1 ].querySelector( 's, .is-off' ) ).not.toBeNull();
		expect( rows[ 2 ].textContent ).toContain( 'Changed' );
		expect( rows[ 2 ].textContent ).toContain( '6 in' );
		expect( rows[ 2 ].textContent ).toContain( 'You’re in' );
	} );
} );

describe( 'Game', () => {
	it( 'lists teammates by answer with numbers, spares tagged and notes shown', async () => {
		fakeApi( ( s ) => ( s.path === '/games/101' ? { body: detail() } : undefined ) );
		render( <Game id="101" me={ me() } /> );
		await settle();
		const group = ( name: RegExp ) => screen.getByRole( 'region', { name } );
		expect( group( /^in/i ).textContent ).toContain( 'Ana Ruiz' );
		expect( group( /^in/i ).textContent ).toContain( '#4' );
		expect( group( /^in/i ).textContent ).toContain( 'Spare' );
		expect( group( /^maybe/i ).textContent ).toContain( 'Cy Diaz' );
		expect( group( /^out/i ).textContent ).toContain( 'Away for work' );
		expect( group( /^no reply/i ).textContent ).toContain( 'Sam Lee' );
	} );

	it( 'says plainly when the game is not one of yours', async () => {
		fakeApi( ( s ) => ( s.path === '/games/101' ? error( 403, 'sppa_forbidden', 'You do not have access to that.' ) : undefined ) );
		render( <Game id="101" me={ me() } /> );
		await settle();
		expect( screen.getByText( /isn.t one of your games/i ) ).toBeTruthy();
	} );
} );
