import { describe, expect, it } from 'vitest';
import type { Game } from '../types';
import { callLabel, countLabel, groupByMonth, isShort, nextGame, venueLine, venueShort } from './games';

function game( id: number, start: string, extra: Partial<Game> = {} ): Game {
	return {
		id,
		start,
		status: 'ok',
		venue: { name: 'Black', rink: 'Twin Rinks', address: '', map_url: '' },
		team: { id: 12, name: 'Ducks', logo: '' },
		opponent: { id: 7, name: 'Wolves', logo: '' },
		is_home: true,
		result: null,
		changed_recently: false,
		my_availability: null,
		counts: { in: 0, out: 0, maybe: 0 },
		as_spare: false,
		...extra,
	};
}

const now = new Date( '2026-11-04T12:00:00-05:00' );

describe( 'groupByMonth', () => {
	it( 'keeps game order and labels each month', () => {
		const games = [
			game( 1, '2026-11-04T21:45:00-05:00' ),
			game( 2, '2026-11-25T20:00:00-05:00' ),
			game( 3, '2026-12-02T20:00:00-05:00' ),
		];
		const groups = groupByMonth( games );
		expect( groups.map( ( g ) => g.key ) ).toEqual( [ '2026-11', '2026-12' ] );
		expect( groups.map( ( g ) => g.label ) ).toEqual( [ 'November 2026', 'December 2026' ] );
		expect( groups[ 0 ].games.map( ( g ) => g.id ) ).toEqual( [ 1, 2 ] );
		expect( groups[ 1 ].games.map( ( g ) => g.id ) ).toEqual( [ 3 ] );
	} );

	it( 'is empty for no games', () => {
		expect( groupByMonth( [] ) ).toEqual( [] );
	} );

	it( 'groups a game with no time yet apart from the months', () => {
		const groups = groupByMonth( [ game( 1, '2026-11-04T21:45:00-05:00' ), game( 2, '', { status: 'tbd' } ) ] );
		expect( groups.map( ( g ) => g.key ) ).toEqual( [ '2026-11', 'tbd' ] );
	} );
} );

describe( 'nextGame', () => {
	it( 'is the first upcoming game', () => {
		const games = [ game( 1, '2026-11-01T20:00:00-05:00' ), game( 2, '2026-11-05T20:00:00-05:00' ), game( 3, '2026-11-12T20:00:00-05:00' ) ];
		expect( nextGame( games, now )?.id ).toBe( 2 );
	} );

	it( 'skips postponed and cancelled games', () => {
		const games = [
			game( 1, '2026-11-05T20:00:00-05:00', { status: 'postponed' } ),
			game( 2, '2026-11-06T20:00:00-05:00', { status: 'cancelled' } ),
			game( 3, '2026-11-12T20:00:00-05:00' ),
		];
		expect( nextGame( games, now )?.id ).toBe( 3 );
	} );

	it( 'is undefined when nothing is left', () => {
		expect( nextGame( [ game( 1, '2026-11-01T20:00:00-05:00' ) ], now ) ).toBeUndefined();
		expect( nextGame( [], now ) ).toBeUndefined();
	} );

	it( 'ignores a game with no time yet', () => {
		expect( nextGame( [ game( 1, '', { status: 'tbd' } ), game( 2, '2026-11-12T20:00:00-05:00' ) ], now )?.id ).toBe( 2 );
	} );

	it( 'takes the earliest even when the list is not sorted', () => {
		const games = [ game( 1, '2026-11-12T20:00:00-05:00' ), game( 2, '2026-11-05T20:00:00-05:00' ) ];
		expect( nextGame( games, now )?.id ).toBe( 2 );
	} );
} );

describe( 'isShort', () => {
	const at = ( hours: number ) => new Date( now.getTime() + hours * 3600_000 ).toISOString();

	it( 'is true within 72 hours with fewer than 10 in', () => {
		expect( isShort( game( 1, at( 71 ), { counts: { in: 9, out: 2, maybe: 0, goalies_in: 1 } } ), now ) ).toBe( true );
		expect( isShort( game( 1, at( 72 ), { counts: { in: 9, out: 2, maybe: 0, goalies_in: 1 } } ), now ) ).toBe( true );
	} );

	it( 'is false at 73 hours', () => {
		expect( isShort( game( 1, at( 73 ), { counts: { in: 3, out: 2, maybe: 0, goalies_in: 0 } } ), now ) ).toBe( false );
	} );

	it( 'is true with no goalie in even when the count is fine', () => {
		expect( isShort( game( 1, at( 24 ), { counts: { in: 12, out: 2, maybe: 0, goalies_in: 0 } } ), now ) ).toBe( true );
	} );

	it( 'is false with 10 in and a goalie in', () => {
		expect( isShort( game( 1, at( 24 ), { counts: { in: 10, out: 2, maybe: 0, goalies_in: 1 } } ), now ) ).toBe( false );
	} );

	it( 'does not guess a goalie shortage when the goalie count is unknown', () => {
		expect( isShort( game( 1, at( 24 ), { counts: { in: 12, out: 2, maybe: 0 } } ), now ) ).toBe( false );
		expect( isShort( game( 1, at( 24 ), { counts: { in: 8, out: 2, maybe: 0 } } ), now ) ).toBe( true );
	} );

	it( 'is false for a game already under way or called off', () => {
		expect( isShort( game( 1, at( -1 ), { counts: { in: 2, out: 0, maybe: 0, goalies_in: 0 } } ), now ) ).toBe( false );
		expect( isShort( game( 1, at( 24 ), { status: 'postponed', counts: { in: 2, out: 0, maybe: 0, goalies_in: 0 } } ), now ) ).toBe( false );
	} );
} );

describe( 'countLabel', () => {
	it( 'joins in, out and no reply', () => {
		expect( countLabel( { in: 11, out: 2, maybe: 0, none: 4 } ) ).toBe( '11 in · 2 out · 4 no reply' );
	} );

	it( 'adds maybe only when there is one', () => {
		expect( countLabel( { in: 11, out: 2, maybe: 1, none: 3 } ) ).toBe( '11 in · 2 out · 1 maybe · 3 no reply' );
	} );

	it( 'leaves out no reply when the list does not carry it', () => {
		expect( countLabel( { in: 11, out: 2, maybe: 0 } ) ).toBe( '11 in · 2 out' );
	} );
} );

describe( 'venue labels', () => {
	const v = ( name: string, rink: string ) => ( { name, rink, address: '', map_url: '' } );

	it( 'names the rink and the pad, adding "pad" only to a one-word pad name', () => {
		expect( venueLine( v( 'Black', 'Wave Twin Rinks' ) ) ).toBe( 'Wave Twin Rinks · Black pad' );
		expect( venueLine( v( 'Appleby 1', 'Appleby Ice Centre' ) ) ).toBe( 'Appleby Ice Centre · Appleby 1' );
		expect( venueLine( v( 'Appleby 1', '' ) ) ).toBe( 'Appleby 1' );
		expect( venueLine( v( '', 'Wave Twin Rinks' ) ) ).toBe( 'Wave Twin Rinks' );
		expect( venueLine( v( '', '' ) ) ).toBe( '' );
	} );

	it( 'keeps the short form short', () => {
		expect( venueShort( v( 'Black', 'Wave Twin Rinks' ) ) ).toBe( 'Wave Twin Rinks · Black' );
		expect( venueShort( v( 'Rink B', 'Burlington Memorial Arena and Community Centre' ) ) ).toBe( 'Rink B' );
		expect( venueShort( v( 'Appleby 1', '' ) ) ).toBe( 'Appleby 1' );
	} );
} );

describe( 'callLabel', () => {
	it( 'says who the team is looking for', () => {
		expect( callLabel( { position: 'goalie', needed: 1 } ) ).toBe( 'Looking for 1 goalie' );
		expect( callLabel( { position: 'skater', needed: 3 } ) ).toBe( 'Looking for 3 skaters' );
	} );
} );
