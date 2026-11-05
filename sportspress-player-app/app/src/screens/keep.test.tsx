// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { App } from '../App';
import { createOutbox, mergeIntoLog, setOutbox } from '../lib/outbox';
import { fakeApi, me } from '../test/fake';
import type { Sent } from '../test/fake';
import type { EntryInput, GameLog, KeeperEntry, KeeperGame } from '../types';
import { KeepGame, NumberPad } from './KeepGame';
import { Keep } from './Keep';
import { Tonight } from './Tonight';

async function settle( rounds = 8 ) {
	for ( let i = 0; i < rounds; i++ ) {
		await act( async () => {
			await Promise.resolve();
		} );
	}
}

const roster = ( team: number ) => [
	{ player_id: team * 10 + 1, name: team === 12 ? 'Sam Lee' : 'Kit Knight', number: '17', position: 'Forward', spare: false },
	{ player_id: team * 10 + 2, name: team === 12 ? 'Ana Ruiz' : 'Olive Opp', number: '4', position: 'Defence', spare: false },
	{ player_id: team * 10 + 3, name: team === 12 ? 'Cy Diaz' : 'Hawk Spare', number: '30', position: 'Goalie', spare: team !== 12 },
];

function keeperGame( over: Partial<KeeperGame> = {} ): KeeperGame {
	return {
		id: 101,
		start: '2026-11-04T12:05:00-05:00',
		status: 'ok',
		venue: { name: 'Black', rink: 'Wave Twin Rinks', address: '', map_url: '' },
		home: { id: 12, name: 'Ducks', logo: '', roster: roster( 12 ) },
		away: { id: 7, name: 'Kings', logo: '', roster: roster( 7 ) },
		live: null,
		needs_review_count: 0,
		has_result: false,
		result: null,
		...over,
	};
}

function entry( over: Partial<KeeperEntry> = {} ): KeeperEntry {
	return {
		uid: 'aaaaaaaa-0000-4000-8000-000000000001',
		type: 'goal',
		team_id: 12,
		side: 'home',
		period: 1,
		clock: '12:01',
		player_id: 121,
		player_name: 'Sam Lee',
		jersey: null,
		not_on_list: false,
		assist1_id: 122,
		assist1_name: 'Ana Ruiz',
		assist2_id: null,
		assist2_name: null,
		minutes: null,
		offense: null,
		flags: [],
		recorded_at: '2026-11-04T17:10:00Z',
		text: null,
		needs_review: false,
		recorded_by: { id: 5, name: 'Sam Lee' },
		voided: false,
		voided_by: null,
		voided_at: null,
		can_void: true,
		...over,
	};
}

function log( over: Partial<GameLog> = {} ): GameLog {
	return {
		game: keeperGame(),
		status: 'live',
		score: { home: 1, away: 0 },
		period: 1,
		decided_in: 'regulation',
		totals: { goals: 1, penalties: 0, needs_review: 0, unattributed_goals: 0, pim: { home: 0, away: 0 }, shootout: { home: 0, away: 0 }, players: [] },
		finalized: null,
		can_reopen: false,
		needs_finish: false,
		entries: [ entry() ],
		...over,
	};
}

/** Answer POST /log like the server: every entry created, with its stored copy. */
function created( s: Sent ) {
	const entries = ( s.body as { entries: EntryInput[] } ).entries;
	return {
		body: {
			results: entries.map( ( e ) => ( { uid: e.uid, status: 'created', entry: entry( { ...e, uid: e.uid, player_name: 'Kit Knight', can_void: true } as Partial<KeeperEntry> ) } ) ),
			live: { status: 'live', score: { home: 1, away: 1 }, period: 1 },
		},
	};
}

let stop: () => void = () => {};

beforeEach( () => {
	vi.useFakeTimers( { toFake: [ 'Date' ] } );
	vi.setSystemTime( new Date( '2026-11-04T12:00:00-05:00' ) );
	const box = createOutbox( { idb: null } );
	setOutbox( box );
	stop = box.start();
} );

afterEach( () => {
	cleanup();
	stop();
	setOutbox();
	vi.useRealTimers();
	vi.unstubAllGlobals();
	delete window.SPPA_BOOT;
} );

describe( 'role gating', () => {
	const keeperApi = () =>
		fakeApi( ( s ) => {
			if ( s.path === '/me' ) {
				return { body: me( { linked: false, players: [], teams: [], ical_url: '', is_timekeeper: true } ) };
			}
			if ( s.path.startsWith( '/keep/night' ) ) {
				return { body: { data: [], total: 0, page: 1, total_pages: 1, date: '2026-11-04' } };
			}
		} );

	it( 'gives a timekeeper with no player the tab bar (Score · Me) instead of the unlinked card', async () => {
		keeperApi();
		render( <App /> );
		await settle( 15 );
		const nav = screen.getByRole( 'navigation', { name: 'Main' } );
		expect( within( nav ).getAllByRole( 'link' ).map( ( a ) => a.textContent ) ).toEqual( [ 'Score', 'Me' ] );
		expect( screen.queryByText( "We couldn't match your account to a player yet." ) ).toBeNull();
		expect( screen.getByText( 'No games to keep on this day.' ) ).toBeTruthy();
	} );

	it.each( [ '/schedule', '/spares', '/team/7', '/games/101' ] )( 'takes a timekeeper with no player from a %s link to their games', async ( to ) => {
		const sent = keeperApi();
		window.history.pushState( null, '', `/my-arl${ to }` );
		render( <App /> );
		await settle( 15 );
		expect( screen.getByText( 'No games to keep on this day.' ) ).toBeTruthy();
		expect( sent.map( ( s ) => s.path ).filter( ( p ) => ! p.startsWith( '/keep' ) && p !== '/me' ) ).toEqual( [] );
		window.history.pushState( null, '', '/' );
	} );

	it( 'still shows the unlinked card, with no tabs, to an unlinked player who is not a keeper', async () => {
		fakeApi( ( s ) => ( s.path === '/me' ? { body: me( { linked: false, players: [], teams: [], ical_url: '', is_timekeeper: false } ) } : undefined ) );
		render( <App /> );
		await settle();
		expect( screen.getByText( "We couldn't match your account to a player yet." ) ).toBeTruthy();
		expect( screen.queryByRole( 'navigation' ) ).toBeNull();
	} );

	it( 'adds Score for organizers and keepers, not for players', async () => {
		fakeApi( ( s ) => ( s.path === '/me' ? { body: me( { is_organizer: true, is_timekeeper: true } ) } : s.path === '/me/games' ? { body: { data: [], total: 0, page: 1, total_pages: 1 } } : undefined ) );
		render( <App /> );
		await settle();
		expect( within( screen.getByRole( 'navigation' ) ).getByRole( 'link', { name: 'Score' } ) ).toBeTruthy();
		cleanup();
		fakeApi( ( s ) => ( s.path === '/me' ? { body: me() } : s.path === '/me/games' ? { body: { data: [], total: 0, page: 1, total_pages: 1 } } : undefined ) );
		render( <App /> );
		await settle();
		expect( within( screen.getByRole( 'navigation' ) ).queryByRole( 'link', { name: 'Score' } ) ).toBeNull();
	} );
} );

describe( 'Keep list', () => {
	it( 'shows each game with its state: not started, live with the period, final, review', async () => {
		fakeApi( () => ( {
			body: {
				data: [
					keeperGame(),
					keeperGame( { id: 102, live: { status: 'live', score: { home: 2, away: 1 }, period: 2 }, needs_review_count: 1 } ),
					keeperGame( { id: 103, live: { status: 'final', score: { home: 4, away: 3 }, period: 3 } } ),
				],
				total: 3,
				page: 1,
				total_pages: 1,
				date: '2026-11-04',
			},
		} ) );
		render( <Keep /> );
		await settle();
		const links = screen.getAllByRole( 'link' );
		expect( links.map( ( a ) => a.getAttribute( 'href' ) ) ).toEqual( [ '/my-arl/keep/101', '/my-arl/keep/102', '/my-arl/keep/103' ] );
		expect( links[ 0 ].textContent ).toContain( 'Not started' );
		expect( links[ 1 ].textContent ).toContain( 'Live 2–1 · P2' );
		expect( links[ 1 ].textContent ).toContain( 'Needs review (1)' );
		expect( links[ 2 ].textContent ).toContain( 'Final 4–3' );
	} );
} );

describe( 'NumberPad', () => {
	it( 'matches the roster as you type, and marks a number that is not on the list', () => {
		const pick = vi.fn();
		render( <NumberPad roster={ roster( 12 ) } onPick={ pick } /> );
		fireEvent.click( screen.getByRole( 'button', { name: '1' } ) );
		expect( screen.getByText( 'Keep typing, or tap a name' ) ).toBeTruthy();
		fireEvent.click( screen.getByRole( 'button', { name: '7' } ) );
		expect( screen.getByText( 'Sam Lee', { selector: '.pad__who' } ) ).toBeTruthy();
		fireEvent.click( screen.getByRole( 'button', { name: 'Delete' } ) );
		fireEvent.click( screen.getByRole( 'button', { name: '9' } ) );
		expect( screen.getByText( 'Not on the list' ) ).toBeTruthy();
		fireEvent.click( screen.getByRole( 'button', { name: 'Use number 19' } ) );
		expect( pick ).toHaveBeenCalledWith( { jersey: '19' } );
	} );
} );

describe( 'game sheet', () => {
	function api( over: { log?: GameLog | ( () => GameLog ); post?: ( s: Sent ) => unknown; finish?: ( s: Sent ) => unknown } = {} ) {
		return fakeApi( ( s ) => {
			if ( s.method === 'GET' && s.path === '/games/101/log' ) {
				return { body: typeof over.log === 'function' ? over.log() : over.log ?? log() };
			}
			if ( s.method === 'POST' && s.path === '/games/101/log' ) {
				return ( over.post ?? created )( s ) as { body: unknown };
			}
			if ( s.path === '/games/101/finish' && over.finish ) {
				return over.finish( s ) as { body: unknown };
			}
		} );
	}

	const status = () => screen.getAllByRole( 'status' ).map( ( n ) => n.textContent ?? '' ).join( ' | ' );

	it( 'records a goal in a few taps: team, scorer chip, assist, save; it shows at once and then syncs', async () => {
		const sent = api();
		render( <KeepGame id="101" me={ me( { is_timekeeper: true } ) } /> );
		await settle();
		expect( screen.getByRole( 'group', { name: /Ducks 1, Kings 0/ } ) ).toBeTruthy();
		expect( screen.getByText( 'P1 · 12:01 · Goal — #17 Sam Lee (A: #4)' ) ).toBeTruthy();

		fireEvent.click( screen.getByRole( 'button', { name: 'Goal' } ) );
		const dialog = screen.getByRole( 'dialog' );
		fireEvent.click( within( dialog ).getByRole( 'button', { name: /Kings/ } ) );
		fireEvent.click( within( screen.getByRole( 'dialog' ) ).getByRole( 'button', { name: /17 Kit Knight/ } ) );
		fireEvent.click( within( screen.getByRole( 'group', { name: 'Assist 1' } ) ).getByRole( 'button', { name: /4 Olive Opp/ } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'PP, power play' } ) );
		fireEvent.change( screen.getByLabelText( 'Clock (optional)' ), { target: { value: '941' } } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save goal' } ) );

		expect( screen.queryByRole( 'dialog' ) ).toBeNull();
		expect( status() ).toContain( 'Goal recorded' );
		await settle();
		const post = sent.find( ( s ) => s.method === 'POST' );
		expect( ( post?.body as { entries: EntryInput[] } ).entries[ 0 ] ).toMatchObject( { type: 'goal', team_id: 7, period: 1, player_id: 71, assist1_id: 72, flags: [ 'pp' ], clock: '9:41' } );
		expect( screen.getByText( 'All saved' ) ).toBeTruthy();
		expect( screen.getByRole( 'group', { name: /Ducks 1, Kings 1/ } ) ).toBeTruthy();
		expect( status() ).toContain( 'Synced' );
	} );

	it( 'keeps entries waiting while offline, holds Finish, and sends them when the connection comes back', async () => {
		let online = false;
		const sent = api( {
			post: ( s ) => {
				if ( ! online ) {
					throw new TypeError( 'Failed to fetch' );
				}
				return created( s );
			},
		} );
		render( <KeepGame id="101" me={ me( { is_timekeeper: true } ) } /> );
		await settle();
		fireEvent.click( screen.getByRole( 'button', { name: 'Penalty' } ) );
		fireEvent.click( screen.getByRole( 'button', { name: /Kings/ } ) );
		fireEvent.click( screen.getByRole( 'button', { name: /Fighting/ } ) );
		for ( const k of [ '8', '8' ] ) {
			fireEvent.click( screen.getByRole( 'button', { name: k } ) );
		}
		fireEvent.click( screen.getByRole( 'button', { name: 'Use number 88' } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save penalty' } ) );
		await settle();

		expect( screen.getByText( 'Offline · 1 waiting' ) ).toBeTruthy();
		expect( screen.getByText( 'P1 · Penalty — #88 · Fighting 5 min' ) ).toBeTruthy();
		expect( screen.getByText( /not on the list · needs review · waiting to sync/ ) ).toBeTruthy();
		const finish = screen.getByRole( 'button', { name: 'Waiting to sync…' } ) as HTMLButtonElement;
		expect( finish.disabled ).toBe( true );
		expect( status() ).toContain( 'Waiting to sync' );

		online = true;
		await act( async () => {
			window.dispatchEvent( new Event( 'online' ) );
		} );
		await settle();
		expect( screen.getByText( 'All saved' ) ).toBeTruthy();
		expect( ( screen.getByRole( 'button', { name: 'Finish game' } ) as HTMLButtonElement ).disabled ).toBe( false );
		const posts = sent.filter( ( s ) => s.method === 'POST' );
		expect( ( posts.at( -1 )?.body as { entries: EntryInput[] } ).entries[ 0 ] ).toMatchObject( { type: 'penalty', jersey: '88', offense: 'fighting', minutes: 5 } );
	} );

	it( 'shows totals before finishing, and asks before replacing a result someone else entered (3–?)', async () => {
		let final = false;
		const sent = api( {
			log: () => log( final ? { status: 'final' } : {} ),
			finish: ( s ) => {
				if ( ! ( s.body as { overwrite: boolean } ).overwrite ) {
					return { status: 409, body: { code: 'sppa_result_exists', message: 'x', data: { status: 409, result: { home: 3, away: null } } } };
				}
				final = true;
				return { body: { success: true, status: 'final', score: { home: 1, away: 0 } } };
			},
		} );
		render( <KeepGame id="101" me={ me( { is_timekeeper: true } ) } /> );
		await settle();
		fireEvent.click( screen.getByRole( 'button', { name: 'Finish game' } ) );
		const dialog = screen.getByRole( 'dialog' );
		expect( dialog.getAttribute( 'aria-busy' ) ).toBe( 'true' ); // reloading the log first
		await settle();
		expect( dialog.getAttribute( 'aria-busy' ) ).toBeNull();
		expect( dialog.textContent ).toContain( 'Ducks 1 – Kings 0' );
		expect( dialog.textContent ).toContain( '1 goal · 0 penalties · 0 PIM' );
		fireEvent.click( within( dialog ).getByRole( 'button', { name: 'Finish game' } ) );
		await settle();
		expect( screen.getByText( 'This game already has a result (3–?). Replace it?' ) ).toBeTruthy();
		fireEvent.click( screen.getByRole( 'button', { name: 'Replace' } ) );
		await settle();
		expect( sent.filter( ( s ) => s.path === '/games/101/finish' ).map( ( s ) => s.body ) ).toEqual( [ { overwrite: false }, { overwrite: true } ] );
		expect( screen.getByText( 'Final.' ) ).toBeTruthy();
		expect( screen.queryByRole( 'button', { name: 'Goal' } ) ).toBeNull();
	} );

	it( 'words the replace prompt for stats changed after our own Finish', async () => {
		api( {
			log: log( { finalized: { by: { id: 5, name: 'Sam Lee' }, at: '2026-11-04T18:00:00Z', home: 1, away: 0 } } ),
			finish: () => ( { status: 409, body: { code: 'sppa_result_exists', message: 'x', data: { status: 409, result: { home: 1, away: 0 } } } } ),
		} );
		render( <KeepGame id="101" me={ me( { is_timekeeper: true } ) } /> );
		await settle();
		fireEvent.click( screen.getByRole( 'button', { name: 'Finish game' } ) );
		await settle();
		fireEvent.click( within( screen.getByRole( 'dialog' ) ).getByRole( 'button', { name: 'Finish game' } ) );
		await settle();
		expect( screen.getByText( 'This game’s result or stats were changed after you finished. Replace them?' ) ).toBeTruthy();
	} );

	it( 'keeps Finish closed for a keeper until 15 minutes before puck drop; organizers may finish any time', async () => {
		const later = log( { game: keeperGame( { start: '2026-11-04T19:00:00-05:00' } ) } );
		api( { log: later } );
		render( <KeepGame id="101" me={ me( { is_timekeeper: true } ) } /> );
		await settle();
		expect( ( screen.getByRole( 'button', { name: 'Opens 15 min before puck drop' } ) as HTMLButtonElement ).disabled ).toBe( true );
		cleanup();
		api( { log: later } );
		render( <KeepGame id="101" me={ me( { is_organizer: true, is_timekeeper: true } ) } /> );
		await settle();
		expect( ( screen.getByRole( 'button', { name: 'Finish game' } ) as HTMLButtonElement ).disabled ).toBe( false );
	} );

	it( 'shows a final sheet read-only, with Reopen for organizers and Finish again when the log changed', async () => {
		api( { log: log( { status: 'final', can_reopen: true, needs_finish: true } ) } );
		render( <KeepGame id="101" me={ me( { is_organizer: true, is_timekeeper: true } ) } /> );
		await settle();
		expect( screen.getByText( 'Final.' ) ).toBeTruthy();
		expect( screen.queryByRole( 'button', { name: 'Goal' } ) ).toBeNull();
		expect( screen.queryByRole( 'button', { name: 'Void' } ) ).toBeNull();
		expect( screen.getByRole( 'button', { name: 'Reopen' } ) ).toBeTruthy();
		fireEvent.click( screen.getByRole( 'button', { name: 'Finish again' } ) );
		expect( screen.getByRole( 'dialog', { name: 'Finish this game?' } ) ).toBeTruthy();
	} );

	it( 'voids an entry on the second tap and disables Void on other people’s entries', async () => {
		const sent = fakeApi( ( s ) => {
			if ( s.path === '/games/101/log' ) {
				return { body: log( { entries: [ entry( { uid: 'bbbbbbbb-0000-4000-8000-000000000002', can_void: false, recorded_by: { id: 9, name: 'Other' } } ), entry() ] } ) };
			}
			if ( s.path.endsWith( '/void' ) ) {
				return { body: { entry: entry( { voided: true, voided_by: { id: 5, name: 'Sam Lee' }, can_void: false } ), live: { status: 'live', score: { home: 0, away: 0 }, period: 1 } } };
			}
		} );
		render( <KeepGame id="101" me={ me( { is_timekeeper: true } ) } /> );
		await settle();
		const voids = screen.getAllByRole( 'button', { name: 'Void' } ) as HTMLButtonElement[];
		expect( voids[ 0 ].disabled ).toBe( true );
		fireEvent.click( voids[ 1 ] );
		fireEvent.click( screen.getByRole( 'button', { name: 'Tap again' } ) );
		await settle();
		expect( sent.some( ( s ) => s.path === '/games/101/log/aaaaaaaa-0000-4000-8000-000000000001/void' ) ).toBe( true );
		expect( screen.getByText( /voided by Sam Lee/ ) ).toBeTruthy();
		expect( screen.getByRole( 'group', { name: /Ducks 0, Kings 0/ } ) ).toBeTruthy();
	} );
} );

describe( 'Tonight', () => {
	it( 'shows the live or final score and a needs-review count per game', async () => {
		fakeApi( () => ( {
			body: {
				data: [
					{
						id: 101,
						start: '2026-11-04T21:45:00-05:00',
						status: 'ok',
						venue: { name: 'Black', rink: '', address: '', map_url: '' },
						team: { id: 12, name: 'Ducks', logo: '' },
						opponent: { id: 7, name: 'Kings', logo: '' },
						is_home: true,
						result: null,
						changed_recently: false,
						my_availability: null,
						counts: { in: 0, out: 0, maybe: 0 },
						as_spare: false,
						sides: [],
						open_calls: [],
						has_result: false,
						live: { status: 'live', score: { home: 2, away: 1 }, period: 2 },
						needs_review_count: 2,
					},
				],
				total: 1,
				page: 1,
				total_pages: 1,
				date: '2026-11-04',
			},
		} ) );
		render( <Tonight /> );
		await settle();
		expect( screen.getByText( 'Live 2–1 · P2' ) ).toBeTruthy();
		expect( screen.getByText( 'Needs review (2)' ) ).toBeTruthy();
	} );
} );

describe( 'game sheet: nothing tapped is lost, doubled or published half-done', () => {
	const sheet = () => render( <KeepGame id="101" me={ me( { is_timekeeper: true } ) } /> );

	/** Goal for the Kings' #17 (one tap on the chip), then optional clock, then Save. */
	async function kingsGoal( clock = '' ) {
		fireEvent.click( screen.getByRole( 'button', { name: 'Goal' } ) );
		fireEvent.click( within( screen.getByRole( 'dialog' ) ).getByRole( 'button', { name: /Kings/ } ) );
		fireEvent.click( within( screen.getByRole( 'dialog' ) ).getByRole( 'button', { name: /17 Kit Knight/ } ) );
		if ( clock ) {
			fireEvent.change( screen.getByLabelText( 'Clock (optional)' ), { target: { value: clock } } );
		}
		fireEvent.click( screen.getByRole( 'button', { name: 'Save goal' } ) );
		await settle();
	}

	const posts = ( sent: Sent[] ) => sent.filter( ( s ) => s.method === 'POST' && s.path === '/games/101/log' ).map( ( s ) => ( s.body as { entries: EntryInput[] } ).entries );

	it( 'blocks Finish while an entry is refused, says why in plain words, and Enter it again opens it filled in', async () => {
		let refuse = true;
		const sent = fakeApi( ( s ) => {
			if ( s.path === '/games/101/log' && s.method === 'GET' ) {
				return { body: log() };
			}
			if ( s.path === '/games/101/log' ) {
				const entries = ( s.body as { entries: EntryInput[] } ).entries;
				return refuse ? { body: { results: entries.map( ( e ) => ( { uid: e.uid, status: 'rejected', reason: 'invalid_clock' } ) ), live: null } } : created( s );
			}
		} );
		sheet();
		await settle();
		await kingsGoal( '9:41' );
		expect( screen.getByText( /Not saved: P1 · 9:41 · Goal — #17 Kit Knight\. The clock time wasn’t valid\./ ) ).toBeTruthy();
		expect( screen.queryByText( /invalid_clock/ ) ).toBeNull();
		const finish = screen.getByRole( 'button', { name: '1 entry wasn’t saved — fix it first' } ) as HTMLButtonElement;
		expect( finish.disabled ).toBe( true );

		refuse = false;
		fireEvent.click( screen.getByRole( 'button', { name: 'Enter it again' } ) );
		expect( screen.getByRole( 'dialog', { name: 'Assists and details' } ) ).toBeTruthy();
		expect( ( screen.getByLabelText( 'Clock (optional)' ) as HTMLInputElement ).value ).toBe( '9:41' );
		fireEvent.change( screen.getByLabelText( 'Clock (optional)' ), { target: { value: '' } } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save goal' } ) );
		await settle();
		const [ first, again ] = posts( sent );
		expect( again[ 0 ] ).toMatchObject( { type: 'goal', team_id: 7, player_id: 71 } );
		expect( again[ 0 ].uid ).not.toBe( first[ 0 ].uid );
		expect( again[ 0 ].clock ).toBeUndefined();
		expect( screen.queryByText( /Not saved/ ) ).toBeNull();
		expect( ( screen.getByRole( 'button', { name: 'Finish game' } ) as HTMLButtonElement ).disabled ).toBe( false );
	} );

	it( 'lets the keeper discard a refused entry, on the second tap', async () => {
		fakeApi( ( s ) => {
			if ( s.method === 'GET' ) {
				return { body: log() };
			}
			const entries = ( s.body as { entries: EntryInput[] } ).entries;
			return { body: { results: entries.map( ( e ) => ( { uid: e.uid, status: 'rejected', reason: 'player_not_on_team' } ) ), live: null } };
		} );
		sheet();
		await settle();
		await kingsGoal();
		expect( screen.getByText( /That player isn’t on the team’s list/ ) ).toBeTruthy();
		fireEvent.click( screen.getByRole( 'button', { name: 'Discard' } ) );
		expect( screen.getByText( /Not saved/ ) ).toBeTruthy();
		fireEvent.click( screen.getByRole( 'button', { name: 'Tap again to discard' } ) );
		expect( screen.queryByText( /Not saved/ ) ).toBeNull();
	} );

	it( 'offers Try again (same entry) when the whole request was refused', async () => {
		let refuse = true;
		const sent = fakeApi( ( s ) => {
			if ( s.method === 'GET' ) {
				return { body: log() };
			}
			return refuse ? { status: 404, body: { code: 'sppa_not_found', message: 'Not found.', data: { status: 404 } } } : created( s );
		} );
		sheet();
		await settle();
		await kingsGoal();
		expect( screen.getByText( /That game or team wasn’t found/ ) ).toBeTruthy();
		expect( screen.queryByRole( 'button', { name: 'Enter it again' } ) ).toBeNull();
		refuse = false;
		fireEvent.click( screen.getByRole( 'button', { name: 'Try again' } ) );
		await settle();
		const [ first, again ] = posts( sent );
		expect( again[ 0 ].uid ).toBe( first[ 0 ].uid );
		expect( screen.queryByText( /Not saved/ ) ).toBeNull();
	} );

	it( 'turns Undo of an entry that was already sent into a void once it is acknowledged', async () => {
		let online = false;
		const sent = fakeApi( ( s ) => {
			if ( s.method === 'GET' ) {
				return { body: log() };
			}
			if ( ! online ) {
				throw new TypeError( 'Failed to fetch' );
			}
			if ( s.path.endsWith( '/void' ) ) {
				return { body: { entry: entry( { uid: s.path.split( '/' )[ 4 ], voided: true } ), live: { status: 'live', score: { home: 1, away: 0 }, period: 1 } } };
			}
			return created( s );
		} );
		sheet();
		await settle();
		await kingsGoal();
		expect( screen.getByRole( 'group', { name: /Kings 1/ } ) ).toBeTruthy();
		fireEvent.click( screen.getByRole( 'button', { name: 'Undo' } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Tap again' } ) );
		await settle();
		expect( screen.getByText( /undoing…/ ) ).toBeTruthy();
		expect( screen.getByRole( 'group', { name: /Kings 0/ } ) ).toBeTruthy();
		online = true;
		await act( async () => {
			window.dispatchEvent( new Event( 'online' ) );
		} );
		await settle( 15 );
		const uid = posts( sent )[ 0 ][ 0 ].uid;
		expect( sent.some( ( s ) => s.path === `/games/101/log/${ uid }/void` ) ).toBe( true );
		expect( screen.getByText( 'All saved' ) ).toBeTruthy();
	} );

	it( 'keeps an acknowledged goal on the sheet after leaving it before the answer came', async () => {
		let offline = false;
		fakeApi( ( s ) => {
			if ( offline ) {
				throw new TypeError( 'Failed to fetch' );
			}
			return s.method === 'GET' ? { body: log() } : undefined;
		} );
		let answer: ( r: unknown ) => void = () => {};
		const box = createOutbox( {
			idb: null,
			send: () => new Promise( ( r ) => ( answer = r as ( r: unknown ) => void ) ),
		} );
		setOutbox( box );
		sheet();
		await settle();
		await kingsGoal();
		const uid = box.getState().items[ 0 ].entry.uid;
		cleanup(); // the keeper leaves the sheet
		answer( { results: [ { uid, status: 'created', entry: entry( { uid, team_id: 7, side: 'away', player_id: 71, player_name: 'Kit Knight', assist1_id: null, assist1_name: null, clock: null } ) } ], live: { status: 'live', score: { home: 1, away: 1 }, period: 1 } } );
		await settle();
		offline = true;
		sheet(); // back on the sheet, with no connection
		await settle();
		expect( screen.getByText( 'P1 · Goal — #17 Kit Knight' ) ).toBeTruthy();
		expect( screen.getByRole( 'group', { name: /Ducks 1, Kings 1/ } ) ).toBeTruthy();
		expect( screen.getByText( 'All saved' ) ).toBeTruthy();
	} );

	it( 'says how many entries wait for a sign-in, with a link to sign in', async () => {
		fakeApi( ( s ) => ( s.method === 'GET' && s.path === '/games/101/log' ? { body: log() } : { status: 401, body: { code: 'rest_forbidden', message: 'Sorry', data: { status: 401 } } } ) );
		sheet();
		await settle();
		await kingsGoal();
		await settle();
		expect( screen.getByText( 'Sign in again to send 1 entry' ) ).toBeTruthy();
		expect( screen.getByRole( 'link', { name: 'Sign in' } ).getAttribute( 'href' ) ).toBe( 'https://example.test/wp-login.php' );
	} );

	it( 'moves through periods with the arrow keys, one tab stop, and logs the new period', async () => {
		const sent = fakeApi( ( s ) => ( s.method === 'GET' ? { body: log() } : created( s ) ) );
		sheet();
		await settle();
		const group = screen.getByRole( 'radiogroup', { name: 'Period' } );
		const radios = within( group ).getAllByRole( 'radio' );
		expect( radios.map( ( r ) => r.tabIndex ) ).toEqual( [ 0, -1, -1, -1, -1 ] );
		radios[ 0 ].focus();
		fireEvent.keyDown( radios[ 0 ], { key: 'ArrowRight' } );
		await settle();
		expect( radios[ 1 ].getAttribute( 'aria-checked' ) ).toBe( 'true' );
		expect( document.activeElement ).toBe( radios[ 1 ] );
		expect( posts( sent )[ 0 ][ 0 ] ).toMatchObject( { type: 'period', period: 2 } );
	} );

	it( 'opens the entry sheet as a modal: the page behind is inert, Escape closes it and focus goes back', async () => {
		fakeApi( () => ( { body: log() } ) );
		const { container } = sheet();
		await settle();
		const goalButton = screen.getByRole( 'button', { name: 'Goal' } );
		goalButton.focus();
		fireEvent.click( goalButton );
		expect( container.hasAttribute( 'inert' ) ).toBe( true );
		expect( document.activeElement?.id ).toBe( 'sheet-h' );
		fireEvent.keyDown( window, { key: 'Escape' } );
		expect( screen.queryByRole( 'dialog' ) ).toBeNull();
		expect( container.hasAttribute( 'inert' ) ).toBe( false );
		expect( document.activeElement ).toBe( goalButton );
	} );

	it( 'reloads the log before finishing and says when another keeper changed it', async () => {
		let gets = 0;
		fakeApi( ( s ) => {
			if ( s.method === 'GET' ) {
				gets++;
				return { body: gets > 1 ? log( { score: { home: 2, away: 0 }, entries: [ entry( { uid: 'cccccccc-0000-4000-8000-000000000003', recorded_by: { id: 9, name: 'Other' } } ), entry() ], totals: { ...log().totals, goals: 2 } } ) : log() };
			}
		} );
		sheet();
		await settle();
		fireEvent.click( screen.getByRole( 'button', { name: 'Finish game' } ) );
		await settle();
		const dialog = screen.getByRole( 'dialog' );
		expect( dialog.textContent ).toContain( 'Ducks 2 – Kings 0' );
		expect( dialog.textContent ).toContain( '2 goals' );
		expect( dialog.textContent ).toContain( 'Another keeper changed this log since you opened it' );
	} );

	it( 'still takes the Finish totals from the server when an acknowledgement lands during the reload', async () => {
		fakeApi( () => undefined ); // boot and a clean cache
		const answers: Array<( body: GameLog ) => void> = [];
		let gets = 0;
		vi.stubGlobal(
			'fetch',
			vi.fn( ( _url: string, init: RequestInit = {} ) => {
				if ( ( init.method ?? 'GET' ) !== 'GET' ) {
					return Promise.reject( new TypeError( 'unused' ) );
				}
				gets++;
				if ( gets === 1 ) {
					return Promise.resolve( new Response( JSON.stringify( log() ), { status: 200 } ) );
				}
				return new Promise( ( resolve ) => answers.push( ( body ) => resolve( new Response( JSON.stringify( body ), { status: 200 } ) ) ) );
			} )
		);
		sheet();
		await settle();
		fireEvent.click( screen.getByRole( 'button', { name: 'Finish game' } ) );
		await settle();
		const dialog = screen.getByRole( 'dialog' );
		expect( dialog.getAttribute( 'aria-busy' ) ).toBe( 'true' );

		// Another of this phone's entries is acknowledged while the reload is out.
		await act( async () => {
			mergeIntoLog( 101, [ entry( { uid: 'dddddddd-0000-4000-8000-000000000004', team_id: 7, side: 'away', player_id: 71, player_name: 'Kit Knight' } ) ], { status: 'live', score: { home: 1, away: 1 }, period: 1 } );
		} );
		expect( dialog.getAttribute( 'aria-busy' ) ).toBe( 'true' ); // still waiting for the server
		expect( gets ).toBe( 3 ); // and asked again, after the acknowledgement

		const server = log( { score: { home: 1, away: 1 }, totals: { ...log().totals, goals: 2, penalties: 1, pim: { home: 0, away: 2 } } } );
		await act( async () => {
			answers.forEach( ( a ) => a( server ) );
		} );
		await settle();
		expect( dialog.getAttribute( 'aria-busy' ) ).toBeNull();
		expect( dialog.textContent ).toContain( 'Ducks 1 – Kings 1' );
		expect( dialog.textContent ).toContain( '2 goals · 1 penalty · 2 PIM' );
	} );
} );
