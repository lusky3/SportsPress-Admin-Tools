import { describe, expect, it } from 'vitest';
import type { RosterPlayer } from '../types';
import { deriveScore, entryLine, matchRoster, normalizeClock } from './live';
import type { ScoreEntry } from './live';

const H = 12;
const A = 7;
const g = ( team: number, period: number, voided = false ): ScoreEntry => ( { type: 'goal', team_id: team, period, voided } );

describe( 'deriveScore (mirror of SPPA_Game_Log::derive)', () => {
	it( 'matches the contract example: a home goal, an away jersey goal, a fighting major and a voided period marker → 1–1, period 2, tie', () => {
		const entries: ScoreEntry[] = [
			{ type: 'period', period: 2, voided: true },
			{ type: 'goal', team_id: A, period: 2 }, // by jersey 88: counts for the team
			{ type: 'penalty', team_id: A, period: 1 },
			{ type: 'goal', team_id: H, period: 1 },
		];
		expect( deriveScore( entries, H, A ) ).toEqual( { home: 1, away: 1, period: 2, decided_in: 'tie' } );
	} );

	it( 'ignores voided goals', () => {
		expect( deriveScore( [ g( H, 1 ), g( H, 2, true ), g( A, 3 ) ], H, A ) ).toMatchObject( { home: 1, away: 1, period: 3 } );
	} );

	it( 'calls a game level after three and won in OT an overtime win', () => {
		expect( deriveScore( [ g( H, 1 ), g( A, 2 ), g( A, 4 ) ], H, A ) ).toEqual( { home: 1, away: 2, period: 4, decided_in: 'overtime' } );
	} );

	it( 'gives the shootout winner exactly one goal when level after OT', () => {
		expect( deriveScore( [ g( H, 1 ), g( A, 3 ), g( H, 5 ), g( H, 5 ), g( A, 5 ) ], H, A ) ).toEqual( { home: 2, away: 1, period: 5, decided_in: 'shootout' } );
	} );

	it( 'ignores shootout goals in a game that is not level', () => {
		expect( deriveScore( [ g( H, 1 ), g( A, 5 ), g( A, 5 ) ], H, A ) ).toEqual( { home: 1, away: 0, period: 5, decided_in: 'regulation' } );
	} );

	it( 'is a tie with an even shootout or no goals, and starts in period 1', () => {
		expect( deriveScore( [ g( H, 5 ), g( A, 5 ) ], H, A ) ).toMatchObject( { home: 0, away: 0, decided_in: 'tie' } );
		expect( deriveScore( [], H, A ) ).toEqual( { home: 0, away: 0, period: 1, decided_in: 'tie' } );
	} );
} );

const roster: RosterPlayer[] = [
	{ player_id: 50, name: 'Pat Skater', number: '9', position: 'Forward', spare: false },
	{ player_id: 51, name: 'Kit Knight', number: '91', position: 'Forward', spare: false },
	{ player_id: 52, name: 'Sam Teammate', number: '31', position: 'Goalie', spare: false },
	{ player_id: 53, name: 'No Number', number: '', position: '', spare: false },
	{ player_id: 54, name: 'Twin One', number: '4', position: '', spare: false },
	{ player_id: 55, name: 'Twin Spare', number: '4', position: '', spare: true },
];

describe( 'matchRoster (number pad)', () => {
	it( 'shows everyone before a digit is typed', () => {
		expect( matchRoster( roster, '' ).matches ).toHaveLength( 6 );
	} );

	it( 'matches an exact number and keeps longer numbers starting with it as candidates', () => {
		const m = matchRoster( roster, '9' );
		expect( m.exact?.player_id ).toBe( 50 );
		expect( m.matches.map( ( p ) => p.player_id ) ).toEqual( [ 50, 51 ] );
	} );

	it( 'has no exact match for a number not on the list', () => {
		const m = matchRoster( roster, '88' );
		expect( m.exact ).toBeUndefined();
		expect( m.matches ).toEqual( [] );
	} );

	it( 'asks for a pick when two players share a number', () => {
		const m = matchRoster( roster, '4' );
		expect( m.exact ).toBeUndefined();
		expect( m.matches.map( ( p ) => p.player_id ) ).toEqual( [ 54, 55 ] );
	} );
} );

describe( 'normalizeClock', () => {
	it( 'accepts mm:ss and m:ss, adds the colon to bare digits, and refuses impossible times', () => {
		expect( normalizeClock( '12:41' ) ).toBe( '12:41' );
		expect( normalizeClock( '1241' ) ).toBe( '12:41' );
		expect( normalizeClock( '941' ) ).toBe( '9:41' );
		expect( normalizeClock( ' ' ) ).toBe( '' );
		expect( normalizeClock( '12:61' ) ).toBeNull();
		expect( normalizeClock( '60:00' ) ).toBeNull();
		expect( normalizeClock( 'abc' ) ).toBeNull();
	} );
} );

describe( 'entryLine', () => {
	const numbers = new Map( [ [ 17, '17' ], [ 4, '4' ], [ 30, '30' ] ] );
	const base = { period: 2, clock: '12:41', player_id: 17, player_name: 'Sam Lee', jersey: null, assist1_id: 4, assist1_name: 'Ana', assist2_id: 30, assist2_name: 'Cy', minutes: null, offense: null };
	it( 'writes a goal the way the sheet shows it', () => {
		expect( entryLine( { ...base, type: 'goal' }, numbers ) ).toBe( 'P2 · 12:41 · Goal — #17 Sam Lee (A: #4, #30)' );
	} );
	it( 'writes a jersey-only penalty and a period marker', () => {
		expect( entryLine( { ...base, type: 'penalty', clock: null, player_id: null, player_name: null, jersey: '88', assist1_id: null, assist2_id: null, minutes: 5, offense: 'fighting' } ) ).toBe( 'P2 · Penalty — #88 · Fighting 5 min' );
		expect( entryLine( { ...base, type: 'period', period: 4, clock: null } ) ).toBe( 'OT · Overtime started' );
	} );
} );
