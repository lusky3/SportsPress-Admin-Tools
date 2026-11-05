// @vitest-environment jsdom
import { act, cleanup, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { detail, fakeApi, game, me } from '../test/fake';
import type { LiveView } from '../types';
import { Game } from './Game';
import { Next } from './Next';

async function settle() {
	for ( let i = 0; i < 6; i++ ) {
		await act( async () => {
			await Promise.resolve();
		} );
	}
}

function live( over: Partial<LiveView> = {} ): LiveView {
	return {
		event_id: 101,
		status: 'live',
		score: { home: 2, away: 1 },
		period: 2,
		home: { id: 7, name: 'Ducks', logo: '' },
		away: { id: 8, name: 'Knights of Ni', logo: '' },
		entries: [
			{ uid: 'u1', type: 'goal', team_id: 8, side: 'away', period: 2, clock: null, player_id: null, player_name: null, jersey: '88', not_on_list: true, assist1_id: null, assist1_name: null, assist2_id: null, assist2_name: null, minutes: null, offense: null, flags: [], recorded_at: '' },
		],
		...over,
	};
}

let hidden = false;

beforeEach( () => {
	vi.useFakeTimers( { toFake: [ 'Date', 'setInterval', 'clearInterval' ] } );
	vi.setSystemTime( new Date( '2026-11-04T22:10:00-05:00' ) ); // 25 minutes into the 9:45 game
	hidden = false;
	Object.defineProperty( document, 'visibilityState', { configurable: true, get: () => ( hidden ? 'hidden' : 'visible' ) } );
} );

afterEach( () => {
	cleanup();
	vi.useRealTimers();
	vi.unstubAllGlobals();
	delete window.SPPA_BOOT;
} );

const tick = async ( ms: number ) => {
	await act( async () => {
		vi.advanceTimersByTime( ms );
	} );
	await settle();
};

describe( 'live score for players', () => {
	it( 'shows a Live card and polls every 15 s while live and visible, pauses when hidden, and stops at final', async () => {
		let answer = live();
		const sent = fakeApi( ( s ) => ( s.path === '/games/101' ? { body: detail() } : s.path === '/games/101/live' ? { body: answer } : undefined ) );
		const polls = () => sent.filter( ( s ) => s.path === '/games/101/live' ).length;
		render( <Game id="101" me={ me() } /> );
		await settle();
		const card = screen.getByRole( 'region', { name: /Live/ } );
		expect( card.textContent ).toContain( 'Ducks 2' );
		expect( card.textContent ).toContain( 'P2' );
		expect( card.textContent ).toContain( 'P2 · Goal — #88' );
		expect( polls() ).toBe( 1 );

		await tick( 15_000 );
		expect( polls() ).toBe( 2 );

		hidden = true;
		await act( async () => {
			document.dispatchEvent( new Event( 'visibilitychange' ) );
		} );
		await tick( 45_000 );
		expect( polls() ).toBe( 2 );

		hidden = false;
		answer = live( { status: 'final', score: { home: 4, away: 3 } } );
		await act( async () => {
			document.dispatchEvent( new Event( 'visibilitychange' ) );
		} );
		await settle();
		expect( polls() ).toBe( 3 ); // back in view: refresh at once
		expect( screen.getByRole( 'region', { name: /Final/ } ).textContent ).toContain( '4' );
		await tick( 60_000 );
		expect( polls() ).toBe( 3 ); // final: no more polling
	} );

	it( 'checks every 60 s while nothing is logged yet, inside the game window', async () => {
		const sent = fakeApi( ( s ) => ( s.path === '/games/101' ? { body: detail() } : s.path === '/games/101/live' ? { body: live( { status: 'none', score: { home: 0, away: 0 }, period: 1, entries: [] } ) } : undefined ) );
		const polls = () => sent.filter( ( s ) => s.path === '/games/101/live' ).length;
		render( <Game id="101" me={ me() } /> );
		await settle();
		expect( screen.queryByRole( 'region', { name: /Live/ } ) ).toBeNull();
		await tick( 15_000 );
		expect( polls() ).toBe( 1 );
		await tick( 45_000 );
		expect( polls() ).toBe( 2 );
	} );

	it( 'shows nothing when the live score is not the viewer’s to see (403)', async () => {
		fakeApi( ( s ) => ( s.path === '/games/101' ? { body: detail() } : s.path === '/games/101/live' ? { status: 403, body: { code: 'sppa_forbidden', message: 'No.', data: { status: 403 } } } : undefined ) );
		render( <Game id="101" me={ me() } /> );
		await settle();
		expect( screen.getByRole( 'region', { name: 'Game' } ) ).toBeTruthy();
		expect( screen.queryByRole( 'region', { name: /Live|Final/ } ) ).toBeNull();
	} );

	it( 'does not let an old game someone forgot to finish hide the real next game', async () => {
		fakeApi( ( s ) => {
			if ( s.path === '/me/games' ) {
				const stale = game( { id: 90, start: '2026-11-01T21:45:00-05:00', live: { status: 'live', score: { home: 2, away: 1 }, period: 2 } } );
				return { body: { data: [ stale, game( { id: 102, start: '2026-11-05T20:30:00-05:00' } ) ], total: 2, page: 1, total_pages: 1 } };
			}
			if ( s.path === '/games/102' ) {
				return { body: detail( { id: 102, start: '2026-11-05T20:30:00-05:00' } ) };
			}
		} );
		render( <Next me={ me() } /> );
		await settle();
		expect( screen.queryByRole( 'region', { name: 'Playing now' } ) ).toBeNull();
		expect( screen.getByRole( 'region', { name: 'Next game' } ).textContent ).toContain( 'Tomorrow' );
	} );

	it( 'does not ask for a live score for a game days away', async () => {
		vi.setSystemTime( new Date( '2026-11-01T12:00:00-05:00' ) );
		const sent = fakeApi( ( s ) => ( s.path === '/games/101' ? { body: detail() } : undefined ) );
		render( <Game id="101" me={ me() } /> );
		await settle();
		expect( sent.some( ( s ) => s.path.endsWith( '/live' ) ) ).toBe( false );
	} );

	it( 'puts the game being played in the Next hero with its live score, from the viewer’s side', async () => {
		fakeApi( ( s ) => {
			if ( s.path === '/me/games' ) {
				const g = game( { is_home: false, live: { status: 'live', score: { home: 2, away: 1 }, period: 2 } } );
				return { body: { data: [ g, game( { id: 102, start: '2026-11-11T20:30:00-05:00' } ) ], total: 2, page: 1, total_pages: 1 } };
			}
			if ( s.path === '/games/101' ) {
				return { body: detail( { is_home: false } ) };
			}
			if ( s.path === '/games/101/live' ) {
				return { body: live() };
			}
		} );
		render( <Next me={ me() } /> );
		await settle();
		const hero = screen.getByRole( 'region', { name: 'Playing now' } );
		expect( hero.textContent ).toContain( 'Live 1–2 · P2' );
	} );
} );
