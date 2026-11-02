// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { call, error, fakeApi, game, me } from '../test/fake';
import type { SparesPayload } from '../types';
import { invalidate } from '../useResource';

vi.mock( '../lib/offline', () => ( { clearOfflineData: vi.fn( async () => {} ), signOut: vi.fn( async () => {} ) } ) );

import { SignedOut } from '../components/Problem';
import { clearOfflineData, signOut } from '../lib/offline';
import { Me } from './Me';
import { Spares } from './Spares';
import { Team } from './Team';
import { Tonight } from './Tonight';

async function settle() {
	for ( let i = 0; i < 5; i++ ) {
		await act( async () => {
			await Promise.resolve();
		} );
	}
}

beforeEach( () => {
	vi.useFakeTimers( { toFake: [ 'Date' ] } );
	vi.setSystemTime( new Date( '2026-11-04T12:00:00-05:00' ) );
	vi.mocked( clearOfflineData ).mockClear();
	vi.mocked( signOut ).mockClear();
} );

afterEach( () => {
	cleanup();
	vi.useRealTimers();
	vi.unstubAllGlobals();
	delete window.SPBN_BOOT;
} );

function spares( over: Partial<SparesPayload> = {} ): SparesPayload {
	return {
		prefs: { enabled: true, positions: [ 'goalie' ] },
		open_calls: [ { ...call( { is_creator: false, can_cancel: false } ), game: game( { team: { id: 12, name: 'Ducks', logo: '' } } ) } ],
		my_accepted: [],
		my_team_calls: [],
		...over,
	};
}

describe( 'Spares', () => {
	it( 'lists an open call in plain words and says someone beat you to it on a 409', async () => {
		let taken = false;
		const sent = fakeApi( ( s ) => {
			if ( s.path === '/spares' ) {
				// Once someone else has it, the server no longer offers it.
				return { body: taken ? spares( { open_calls: [] } ) : spares() };
			}
			if ( s.path === '/spare-calls/900/accept' ) {
				taken = true;
				return error( 409, 'spbn_full', 'That spare call is already full.' );
			}
		} );
		render( <Spares me={ me() } /> );
		await settle();
		const item = screen.getByText( 'Ducks need a goalie' ).closest( 'li' ) as HTMLElement;
		expect( item.textContent ).toContain( 'Tonight' );
		expect( item.textContent ).toContain( '9:45 PM' );
		expect( item.textContent ).toContain( 'Wave Twin Rinks · Black' );
		await act( async () => {
			fireEvent.click( within( item ).getByRole( 'button', { name: /i.ll play/i } ) );
		} );
		await settle();
		expect( sent.some( ( s ) => s.method === 'POST' && s.path === '/spare-calls/900/accept' ) ).toBe( true );
		expect( sent.filter( ( s ) => s.method === 'GET' && s.path === '/spares' ) ).toHaveLength( 2 );
		expect( screen.getByRole( 'alert' ).textContent ).toMatch( /someone beat you to it/i );
		expect( screen.queryByText( 'Ducks need a goalie' ) ).toBeNull();
	} );

	it( 'clears an earlier success message when a later action fails', async () => {
		fakeApi( ( s ) => {
			if ( s.path === '/spares' ) {
				return {
					body: spares( {
						open_calls: [
							{ ...call( { id: 901, is_creator: false, can_cancel: false } ), game: game( { team: { id: 12, name: 'Ducks', logo: '' } } ) },
							{ ...call( { id: 902, is_creator: false, can_cancel: false } ), game: game( { id: 105, team: { id: 13, name: 'Geese', logo: '' } } ) },
						],
					} ),
				};
			}
			if ( s.path === '/spare-calls/901/accept' ) {
				return { body: { success: true, call: call( { id: 901 } ) } };
			}
			if ( s.path === '/spare-calls/902/accept' ) {
				return error( 403, 'spbn_not_eligible', 'x', 'busy' );
			}
		} );
		render( <Spares me={ me() } /> );
		await settle();
		const [ first, second ] = screen.getAllByRole( 'button', { name: /i.ll play/i } );
		await act( async () => {
			fireEvent.click( first );
		} );
		await settle();
		expect( screen.getByText( /you.re in for ducks/i ) ).toBeTruthy();
		await act( async () => {
			fireEvent.click( second );
		} );
		await settle();
		expect( screen.queryByText( /you.re in for/i ) ).toBeNull();
		expect( screen.getByRole( 'alert' ).textContent ).toMatch( /too close/i );
	} );

	it( 'shows the server’s prefs again when they change elsewhere', async () => {
		let prefs = { enabled: false, positions: [] as string[] };
		fakeApi( ( s ) => ( s.path === '/spares' ? { body: spares( { prefs: prefs as SparesPayload['prefs'], open_calls: [] } ) } : undefined ) );
		render( <Spares me={ me() } /> );
		await settle();
		expect( screen.getByRole( 'switch' ).getAttribute( 'aria-checked' ) ).toBe( 'false' );
		prefs = { enabled: true, positions: [ 'goalie' ] };
		await act( async () => {
			invalidate( '/spares' );
		} );
		await settle();
		expect( screen.getByRole( 'switch' ).getAttribute( 'aria-checked' ) ).toBe( 'true' );
		expect( ( screen.getByRole( 'checkbox', { name: 'Goalie' } ) as HTMLInputElement ).checked ).toBe( true );
	} );

	it( 'explains a refusal in plain language, not as a sign-in problem', async () => {
		fakeApi( ( s ) => {
			if ( s.path === '/spares' ) {
				return { body: spares() };
			}
			if ( s.path === '/spare-calls/900/accept' ) {
				return error( 403, 'spbn_not_eligible', 'You have a game of your own too close to this one.', 'busy' );
			}
		} );
		render( <Spares me={ me() } /> );
		await settle();
		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: /i.ll play/i } ) );
		} );
		await settle();
		expect( screen.getByRole( 'alert' ).textContent ).toBe( 'You have a game of your own too close to this one.' );
		expect( screen.queryByText( /sign in/i ) ).toBeNull();
	} );

	it( 'turns sparing on with skater picked, and rolls back with a message when the save fails', async () => {
		let fail = false;
		let stored: SparesPayload['prefs'] = { enabled: false, positions: [] };
		const sent = fakeApi( ( s ) => {
			if ( s.path === '/spares' ) {
				return { body: spares( { prefs: stored, open_calls: [] } ) };
			}
			if ( s.path === '/spares/prefs' ) {
				if ( fail ) {
					return error( 500, 'http_error', 'Oops' );
				}
				stored = s.body as SparesPayload['prefs'];
				return { body: { success: true, ...stored } };
			}
		} );
		render( <Spares me={ me() } /> );
		await settle();
		const toggle = screen.getByRole( 'switch', { name: /happy to spare/i } );
		expect( toggle.getAttribute( 'aria-checked' ) ).toBe( 'false' );
		await act( async () => {
			fireEvent.click( toggle );
		} );
		await settle();
		expect( sent.find( ( s ) => s.method === 'PUT' )?.body ).toEqual( { enabled: true, positions: [ 'skater' ] } );
		expect( screen.getByRole( 'switch' ).getAttribute( 'aria-checked' ) ).toBe( 'true' );
		expect( ( screen.getByRole( 'checkbox', { name: 'Skater' } ) as HTMLInputElement ).checked ).toBe( true );

		fail = true;
		await act( async () => {
			fireEvent.click( screen.getByRole( 'checkbox', { name: 'Goalie' } ) );
		} );
		await settle();
		expect( ( screen.getByRole( 'checkbox', { name: 'Goalie' } ) as HTMLInputElement ).checked ).toBe( false );
		expect( screen.getByRole( 'alert' ).textContent ).toMatch( /didn.t save/i );
	} );

	it( 'withdraws from an accepted call on the second tap', async () => {
		const sent = fakeApi( ( s ) => {
			if ( s.path === '/spares' ) {
				return { body: spares( { open_calls: [], my_accepted: [ { ...call( { accepted_by_me: true, is_creator: false, can_cancel: false } ), game: game(), can_withdraw: true } ] } ) };
			}
			if ( s.path === '/spare-calls/900/withdraw' ) {
				return { body: { success: true, call: call() } };
			}
		} );
		render( <Spares me={ me() } /> );
		await settle();
		fireEvent.click( screen.getByRole( 'button', { name: 'Withdraw' } ) );
		expect( sent.some( ( s ) => s.method === 'POST' ) ).toBe( false );
		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: /tap again/i } ) );
		} );
		await settle();
		expect( sent.filter( ( s ) => s.method === 'POST' ).map( ( s ) => s.path ) ).toEqual( [ '/spare-calls/900/withdraw' ] );
	} );
} );

describe( 'Me', () => {
	it( 'offers the calendar as webcal, Google and a copyable link', async () => {
		fakeApi( () => undefined );
		render( <Me me={ me() } /> );
		expect( screen.getByRole( 'link', { name: /subscribe/i } ).getAttribute( 'href' ) ).toBe( 'webcal://example.test/bench/ical/abc' );
		expect( screen.getByRole( 'link', { name: /google/i } ).getAttribute( 'href' ) ).toBe( 'https://calendar.google.com/calendar/r?cid=webcal%3A%2F%2Fexample.test%2Fbench%2Fical%2Fabc' );
		expect( ( screen.getByLabelText( /calendar link/i ) as HTMLInputElement ).value ).toBe( 'https://example.test/bench/ical/abc' );
	} );

	it( 'keeps an .ics feed URL working too', () => {
		fakeApi( () => undefined );
		render( <Me me={ me( { ical_url: 'https://example.test/bench/ical/abc.ics' } ) } /> );
		expect( screen.getByRole( 'link', { name: /subscribe/i } ).getAttribute( 'href' ) ).toBe( 'webcal://example.test/bench/ical/abc.ics' );
	} );

	describe( 'Copy', () => {
		const setClipboard = ( value: unknown ) => Object.defineProperty( navigator, 'clipboard', { value, configurable: true } );
		afterEach( () => setClipboard( undefined ) );

		it( 'copies the feed URL and says so', async () => {
			const writeText = vi.fn( async () => {} );
			setClipboard( { writeText } );
			fakeApi( () => undefined );
			render( <Me me={ me() } /> );
			await act( async () => {
				fireEvent.click( screen.getByRole( 'button', { name: 'Copy' } ) );
			} );
			expect( writeText ).toHaveBeenCalledWith( 'https://example.test/bench/ical/abc' );
			expect( screen.getByText( 'Copied.' ) ).toBeTruthy();
		} );

		it.each( [
			[ 'refused', () => ( { writeText: vi.fn( async () => Promise.reject( new Error( 'NotAllowedError' ) ) ) } ) ],
			[ 'missing', () => undefined ],
		] )( 'selects the link for a manual copy when the clipboard is %s', async ( _label, clipboard ) => {
			setClipboard( clipboard() );
			fakeApi( () => undefined );
			render( <Me me={ me() } /> );
			await act( async () => {
				fireEvent.click( screen.getByRole( 'button', { name: 'Copy' } ) );
			} );
			const field = screen.getByLabelText( /calendar link/i ) as HTMLInputElement;
			expect( document.activeElement ).toBe( field );
			expect( [ field.selectionStart, field.selectionEnd ] ).toEqual( [ 0, field.value.length ] );
			expect( screen.getByText( /^Selected/ ) ).toBeTruthy();
		} );
	} );

	it( 'follows a new feed URL when /me changes', () => {
		fakeApi( () => undefined );
		const { rerender } = render( <Me me={ me() } /> );
		rerender( <Me me={ me( { ical_url: 'https://example.test/bench/ical/new1' } ) } /> );
		expect( screen.getByRole( 'link', { name: /subscribe/i } ).getAttribute( 'href' ) ).toBe( 'webcal://example.test/bench/ical/new1' );
	} );

	it( 'resets the link only on a second tap and shows the new one', async () => {
		const sent = fakeApi( ( s ) => ( s.path === '/me/ical-token/rotate' ? { body: { success: true, ical_url: 'https://example.test/bench/ical/xyz' } } : undefined ) );
		render( <Me me={ me() } /> );
		fireEvent.click( screen.getByRole( 'button', { name: 'Reset link' } ) );
		expect( sent ).toHaveLength( 0 );
		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: /tap again/i } ) );
		} );
		await settle();
		expect( sent.map( ( s ) => `${ s.method } ${ s.path }` ) ).toEqual( [ 'POST /me/ical-token/rotate' ] );
		expect( screen.getByRole( 'link', { name: /subscribe/i } ).getAttribute( 'href' ) ).toBe( 'webcal://example.test/bench/ical/xyz' );
	} );

	it( 'signs out through the helper that clears the saved copies', async () => {
		fakeApi( () => undefined );
		render( <Me me={ me() } /> );
		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Sign out' } ) );
		} );
		expect( signOut ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'shows name, number and teams', () => {
		fakeApi( () => undefined );
		render( <Me me={ me() } /> );
		expect( screen.getByRole( 'heading', { name: 'Sam Lee' } ) ).toBeTruthy();
		expect( screen.getByText( '#17' ) ).toBeTruthy();
		expect( screen.getByRole( 'link', { name: /ducks/i } ).getAttribute( 'href' ) ).toBe( '/bench/team/7' );
	} );
} );

describe( 'SignedOut', () => {
	it( 'drops the saved copies when it shows', () => {
		fakeApi( () => undefined );
		render( <SignedOut /> );
		expect( clearOfflineData ).toHaveBeenCalledTimes( 1 );
		expect( screen.getByRole( 'link', { name: 'Sign in' } ).getAttribute( 'href' ) ).toBe( 'https://example.test/wp-login.php' );
	} );
} );

describe( 'Team', () => {
	const roster = {
		id: 7,
		name: 'Ducks',
		logo: '',
		division: 'Tuesday C',
		record: { w: 3, l: 2, t: 1 },
		roster: [
			{ player_id: 51, name: 'Ana Ruiz', number: '4', positions: [ 'Defence' ], is_captain: true, is_me: false },
			{ player_id: 50, name: 'Sam Lee', number: '17', positions: [ 'Forward' ], is_captain: false, is_me: true },
		],
	};

	it( 'shows the record, a captain badge and highlights you', async () => {
		fakeApi( ( s ) => ( s.path === '/teams/7' ? { body: roster } : undefined ) );
		render( <Team id="7" me={ me() } /> );
		await settle();
		expect( screen.getByRole( 'heading', { level: 1, name: 'Ducks' } ) ).toBeTruthy();
		expect( screen.getByText( '3–2–1' ) ).toBeTruthy();
		const ana = screen.getByText( 'Ana Ruiz' ).closest( 'li' ) as HTMLElement;
		expect( within( ana ).getByText( 'Captain', { exact: false } ) ).toBeTruthy();
		const sam = screen.getByText( 'Sam Lee' ).closest( 'li' ) as HTMLElement;
		expect( sam.className ).toContain( 'is-me' );
		expect( sam.textContent ).toContain( 'You' );
	} );

	it( 'switches between teams when you are on more than one', async () => {
		fakeApi( ( s ) => ( s.path === '/teams/7' ? { body: roster } : undefined ) );
		render( <Team id="7" me={ me( { teams: [ { id: 7, name: 'Ducks', logo: '', division: '' }, { id: 9, name: 'Geese', logo: '', division: '' } ] } ) } /> );
		await settle();
		const nav = screen.getByRole( 'navigation', { name: /your teams/i } );
		const links = within( nav ).getAllByRole( 'link' );
		expect( links.map( ( a ) => [ a.textContent, a.getAttribute( 'aria-current' ) ] ) ).toEqual( [
			[ 'Ducks', 'page' ],
			[ 'Geese', null ],
		] );
	} );
} );

describe( 'Tonight', () => {
	const night = ( date: string ) => ( {
		date,
		data: [
			{
				...game( { result: { for: 2, against: 2, outcome: 'T' } } ),
				sides: [
					{ team: { id: 7, name: 'Ducks', logo: '' }, counts: { in: 11, out: 2, maybe: 0, none: 4, goalies_in: 1, spares: 0 }, goalies_in: 1 },
					{ team: { id: 8, name: 'Knights of Ni', logo: '' }, counts: { in: 8, out: 1, maybe: 1, none: 6, goalies_in: 0, spares: 1 }, goalies_in: 0 },
				],
				open_calls: [ call() ],
				has_result: true,
			},
		],
		total: 1,
		page: 1,
		total_pages: 1,
	} );

	it( 'loads today, steps a day forward, and shows both sides with goalies, calls and the result', async () => {
		const sent = fakeApi( ( s ) => ( s.path.startsWith( '/ops/night' ) ? { body: night( s.path.slice( -10 ) ) } : undefined ) );
		render( <Tonight /> );
		await settle();
		expect( sent[ 0 ].path ).toBe( '/ops/night?date=2026-11-04' );
		const item = screen.getByText( 'Knights of Ni' ).closest( 'li' ) as HTMLElement;
		expect( item.textContent ).toContain( '11 in · 2 out · 4 no reply' );
		expect( item.textContent ).toContain( '1 goalie' );
		expect( item.textContent ).toContain( 'No goalie' );
		expect( item.textContent ).toContain( 'Looking for 1 goalie' );
		expect( item.textContent ).toContain( 'Score in' );
		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: /next day/i } ) );
		} );
		await settle();
		expect( sent[ sent.length - 1 ].path ).toBe( '/ops/night?date=2026-11-05' );
		expect( screen.getByRole( 'heading', { level: 1 } ).textContent ).toContain( 'Tomorrow' );
	} );
} );
