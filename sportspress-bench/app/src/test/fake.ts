/* Shared fixtures for screen tests: a boot object, a fetch stub routed by path, and API shapes. */
import { vi } from 'vitest';
import { _resetApiForTests } from '../api';
import type { Game, GameDetail, Me, SpareCall } from '../types';
import { _resetResourcesForTests } from '../useResource';

export const BOOT = {
	restRoot: 'https://example.test/wp-json/spbn/v1/',
	nonce: 'nonce1234',
	ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php',
	loginUrl: 'https://example.test/wp-login.php',
	logoutUrl: 'https://example.test/wp-login.php?action=logout',
	accountUrl: 'https://example.test/my-account/',
	helpUrl: 'https://help.example.test/',
	appBase: '/bench/',
	siteName: 'ARL',
};

export interface Sent {
	method: string;
	path: string;
	body: unknown;
}

type Handler = ( sent: Sent ) => { status?: number; body: unknown } | undefined;

/** Install a fetch stub. `handler` answers by method and path (relative to the REST root, e.g. 'GET /me'). */
export function fakeApi( handler: Handler ): Sent[] {
	const sent: Sent[] = [];
	window.SPBN_BOOT = { ...BOOT };
	_resetApiForTests();
	_resetResourcesForTests();
	vi.stubGlobal(
		'fetch',
		vi.fn( async ( url: string, init: RequestInit = {} ) => {
			const s: Sent = {
				method: init.method ?? 'GET',
				path: '/' + url.slice( BOOT.restRoot.length ),
				body: typeof init.body === 'string' ? JSON.parse( init.body ) : undefined,
			};
			sent.push( s );
			const answer = handler( s ) ?? { status: 404, body: { code: 'spbn_not_found', message: 'Not found.', data: { status: 404 } } };
			return new Response( JSON.stringify( answer.body ), { status: answer.status ?? 200, headers: { 'Content-Type': 'application/json' } } );
		} )
	);
	return sent;
}

export function error( status: number, code: string, message = 'Nope.', reason?: string ) {
	return { status, body: { code, message, data: { status, reason } } };
}

export function me( over: Partial<Me> = {} ): Me {
	return {
		user: { id: 5, name: 'Sam Lee' },
		linked: true,
		players: [ { id: 50, name: 'Sam Lee', number: '17', positions: [ 'Forward' ] } ],
		teams: [ { id: 7, name: 'Ducks', logo: '', division: 'Tuesday C' } ],
		season: { id: 3, name: 'Fall 2026' },
		is_organizer: false,
		spare_prefs: { enabled: false, positions: [] },
		ical_url: 'https://example.test/bench/ical/abc',
		...over,
	};
}

export function game( over: Partial<Game> = {} ): Game {
	return {
		id: 101,
		start: '2026-11-04T21:45:00-05:00',
		status: 'ok',
		venue: { name: 'Black', rink: 'Wave Twin Rinks', address: '1179 Northside Rd, Burlington, ON', map_url: 'https://maps.example.test/?q=wave' },
		team: { id: 7, name: 'Ducks', logo: '' },
		opponent: { id: 8, name: 'Knights of Ni', logo: '' },
		is_home: true,
		result: null,
		changed_recently: false,
		my_availability: null,
		counts: { in: 6, out: 2, maybe: 1 },
		as_spare: false,
		...over,
	};
}

export function call( over: Partial<SpareCall> = {} ): SpareCall {
	return {
		id: 900,
		event_id: 101,
		team_id: 7,
		position: 'goalie',
		needed: 1,
		note: '',
		status: 'open',
		accepted: 0,
		created_at: '2026-11-03T10:00:00-05:00',
		is_creator: true,
		accepted_by_me: false,
		can_cancel: true,
		...over,
	};
}

export function detail( over: Partial<GameDetail> = {} ): GameDetail {
	return {
		...game(),
		availability: {
			team_id: 7,
			mine: { player_id: 50, status: null, note: '' },
			editable: true,
			counts: { in: 6, out: 2, maybe: 1, none: 5, goalies_in: 0, spares: 0 },
			players: [
				{ player_id: 50, name: 'Sam Lee', number: '17', positions: [ 'Forward' ], position: 'Forward', status: 'none', note: '' },
				{ player_id: 51, name: 'Ana Ruiz', number: '4', positions: [ 'Defence' ], position: 'Defence', status: 'in', note: '' },
				{ player_id: 52, name: 'Bo Chen', number: '', positions: [ 'Forward' ], position: 'Forward', status: 'out', note: 'Away for work' },
				{ player_id: 53, name: 'Cy Diaz', number: '30', positions: [ 'Goalie' ], position: 'Goalie', status: 'maybe', note: '' },
				{ player_id: 99, name: 'Dee Spare', number: '', positions: [ 'Goalie' ], position: 'Goalie', status: 'in', note: '', spare: true },
			],
		},
		opponent_availability: null,
		spare_calls: [],
		...over,
	};
}
