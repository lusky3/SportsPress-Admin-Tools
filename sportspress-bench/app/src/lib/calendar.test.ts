import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Game } from '../types';
import { gameIcsHref, googleUrl, webcalUrl } from './calendar';
import { addDays, leagueDate, longDate } from './dates';

describe( 'calendar links', () => {
	it( 'turns the https feed into a webcal link, with or without an .ics suffix', () => {
		expect( webcalUrl( 'https://example.test/bench/ical/abc' ) ).toBe( 'webcal://example.test/bench/ical/abc' );
		expect( webcalUrl( 'https://example.test/bench/ical/abc.ics' ) ).toBe( 'webcal://example.test/bench/ical/abc.ics' );
		expect( webcalUrl( 'http://example.test/bench/ical/abc' ) ).toBe( 'webcal://example.test/bench/ical/abc' );
	} );

	it( 'builds the Google add-by-URL link from the webcal form', () => {
		expect( googleUrl( 'https://example.test/bench/ical/abc' ) ).toBe( 'https://calendar.google.com/calendar/r?cid=webcal%3A%2F%2Fexample.test%2Fbench%2Fical%2Fabc' );
	} );

	const game: Game = {
		id: 101,
		start: '2026-11-04T21:45:00-05:00',
		status: 'ok',
		venue: { name: 'Black', rink: 'Wave Twin Rinks', address: '1179 Northside Rd, Burlington', map_url: '' },
		team: { id: 7, name: 'Ducks', logo: '' },
		opponent: { id: 8, name: 'Knights; of Ni', logo: '' },
		is_home: false,
		result: null,
		changed_recently: false,
		my_availability: null,
		counts: { in: 0, out: 0, maybe: 0 },
		as_spare: false,
	};
	const icsOf = ( href: string ) => decodeURIComponent( href.replace( 'data:text/calendar;charset=utf-8,', '' ) );

	beforeEach( () => {
		// The app may be opened on another host name than the site's; the UID follows the site (REST root), like the PHP feed.
		vi.stubGlobal( 'window', { SPBN_BOOT: { restRoot: 'https://arl.example/wp-json/spbn/v1/' }, location: { href: 'https://www.arl.example/bench/games/101' } } );
	} );

	afterEach( () => {
		vi.unstubAllGlobals();
	} );

	it( 'writes a one-game .ics with the feed UID, UTC times and escaped text', () => {
		const ics = icsOf( gameIcsHref( game ) );
		expect( ics ).toContain( 'UID:spbn-101-7@arl.example\r\n' );
		expect( ics ).toContain( 'DTSTART:20261105T024500Z\r\n' );
		expect( ics ).toContain( 'DTEND:20261105T040000Z\r\n' );
		expect( ics ).toContain( 'SUMMARY:Ducks at Knights\\; of Ni\r\n' );
		expect( ics ).toContain( 'LOCATION:Wave Twin Rinks · Black pad\\, 1179 Northside Rd\\, Burlington\r\n' );
	} );

	it( 'reads the host from a relative REST root too', () => {
		vi.stubGlobal( 'window', { SPBN_BOOT: { restRoot: '/wp-json/spbn/v1/' }, location: { href: 'https://arl.example/bench/' } } );
		expect( icsOf( gameIcsHref( game ) ) ).toContain( 'UID:spbn-101-7@arl.example\r\n' );
	} );

	it( 'folds lines longer than 75 octets without splitting a character', () => {
		const long = { ...game, venue: { ...game.venue, address: 'Unit 4 · Upper Level, 1179 Northside Road, Burlington, Ontario L7M 1H5, Canada · Rink entrance at the back' } };
		const ics = icsOf( gameIcsHref( long ) );
		const lines = ics.split( '\r\n' );
		const bytes = ( s: string ) => new TextEncoder().encode( s ).length;
		expect( Math.max( ...lines.map( bytes ) ) ).toBeLessThanOrEqual( 75 );
		expect( lines.some( ( l ) => l.startsWith( ' ' ) ) ).toBe( true );
		const unfolded = ics.replace( /\r\n /g, '' );
		expect( unfolded ).toContain( 'LOCATION:Wave Twin Rinks · Black pad\\, Unit 4 · Upper Level\\, 1179 Northside Road\\, Burlington\\, Ontario L7M 1H5\\, Canada · Rink entrance at the back\r\n' );
		expect( ics ).not.toContain( '�' );
	} );
} );

describe( 'league dates', () => {
	it( 'gives the calendar date in the league timezone', () => {
		expect( leagueDate( new Date( '2026-11-05T03:00:00Z' ) ) ).toBe( '2026-11-04' );
	} );

	it( 'steps a date by whole days, across months', () => {
		expect( addDays( '2026-10-31', 1 ) ).toBe( '2026-11-01' );
		expect( addDays( '2026-11-01', -1 ) ).toBe( '2026-10-31' );
	} );

	it( 'says a date in words', () => {
		expect( longDate( '2026-11-04' ) ).toBe( 'Wednesday, November 4' );
	} );
} );
