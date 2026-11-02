import { describe, expect, it } from 'vitest';
import { monthKey, monthLabel, relativeDay, timeLabel } from './dates';

const now = new Date( '2026-11-04T12:00:00-05:00' ); // Wednesday noon, Toronto

describe( 'relativeDay', () => {
	it( 'says Tonight for a game later the same day from 5 PM', () => {
		expect( relativeDay( '2026-11-04T21:45:00-05:00', now ) ).toBe( 'Tonight' );
		expect( relativeDay( '2026-11-04T17:00:00-05:00', now ) ).toBe( 'Tonight' );
	} );

	it( 'says Today for a same-day game before 5 PM', () => {
		expect( relativeDay( '2026-11-04T16:59:00-05:00', now ) ).toBe( 'Today' );
	} );

	it( 'says Tomorrow for the next calendar day', () => {
		expect( relativeDay( '2026-11-05T20:30:00-05:00', now ) ).toBe( 'Tomorrow' );
	} );

	it( 'names the weekday two to six days out', () => {
		expect( relativeDay( '2026-11-06T20:30:00-05:00', now ) ).toBe( 'Friday' );
		expect( relativeDay( '2026-11-10T20:30:00-05:00', now ) ).toBe( 'Tuesday' );
	} );

	it( 'uses a date from a week out and for the past', () => {
		expect( relativeDay( '2026-11-11T20:30:00-05:00', now ) ).toBe( 'Wed, Nov 11' );
		expect( relativeDay( '2026-11-03T20:30:00-05:00', now ) ).toBe( 'Tue, Nov 3' );
	} );

	it( 'reads the day in the league timezone, not the device one', () => {
		// 02:00 UTC on Nov 5 is 9 PM on Nov 4 in Toronto.
		expect( relativeDay( '2026-11-05T02:00:00Z', now ) ).toBe( 'Tonight' );
		// 23:30 Toronto time on Nov 4 is already Nov 5 in UTC; still the same day here.
		expect( relativeDay( '2026-11-04T23:30:00-05:00', new Date( '2026-11-05T04:30:00Z' ), 'America/Toronto' ) ).toBe( 'Tonight' );
	} );

	it( 'honours a clock change between now and the game', () => {
		// DST ends Nov 1 2026; a game on Nov 8 is 4 days away on the calendar.
		expect( relativeDay( '2026-11-08T20:00:00-05:00', new Date( '2026-11-04T12:00:00-05:00' ) ) ).toBe( 'Sunday' );
	} );

	it( 'says Tomorrow across the night the clocks go back', () => {
		// 11 PM EDT on Oct 31; the game is 8 PM EST on Nov 1, 22 hours later but the next calendar day.
		expect( relativeDay( '2026-11-01T20:00:00-05:00', new Date( '2026-10-31T23:00:00-04:00' ) ) ).toBe( 'Tomorrow' );
	} );

	it( 'does not throw on a game with no time yet', () => {
		expect( relativeDay( '', now ) ).toBe( 'TBD' );
	} );
} );

describe( 'timeLabel', () => {
	it( 'formats the local time with a plain space', () => {
		expect( timeLabel( '2026-11-04T21:45:00-05:00' ) ).toBe( '9:45 PM' );
		expect( timeLabel( '2026-11-04T08:05:00-05:00' ) ).toBe( '8:05 AM' );
	} );

	it( 'converts to the given timezone', () => {
		expect( timeLabel( '2026-11-05T02:45:00Z', 'America/Toronto' ) ).toBe( '9:45 PM' );
		expect( timeLabel( '2026-11-05T02:45:00Z', 'Europe/London' ) ).toBe( '2:45 AM' );
	} );
} );

describe( 'monthKey and monthLabel', () => {
	it( 'keys by year and month in the league timezone', () => {
		expect( monthKey( '2026-11-04T21:45:00-05:00' ) ).toBe( '2026-11' );
		// 02:00 UTC on Dec 1 is still Nov 30 in Toronto.
		expect( monthKey( '2026-12-01T02:00:00Z' ) ).toBe( '2026-11' );
	} );

	it( 'labels a key with the month name and year', () => {
		expect( monthLabel( '2026-11' ) ).toBe( 'November 2026' );
		expect( monthLabel( '2027-01' ) ).toBe( 'January 2027' );
	} );
} );
