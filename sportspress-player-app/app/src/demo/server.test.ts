import { describe, expect, it } from 'vitest';
import type { GameDetail, GameLog, KeeperNight, Me, Paged, Game, PostLogResult, SparesPayload } from '../types';
import { roster } from './league';
import { createDemoServer } from './server';
import type { Persona } from './server';

const NOW = new Date( '2026-11-04T15:00:00Z' ); // Wednesday morning in Toronto

function server( persona: Persona ) {
	const s = createDemoServer( { persona, now: NOW, latency: 0 } );
	const call = async <T>( method: string, path: string, body?: unknown ) => {
		const res = await s.fetch( `/demo-api${ path }`, { method, body: body === undefined ? undefined : JSON.stringify( body ) } );
		return { status: res.status, body: ( await res.json() ) as T };
	};
	return { ...s, call };
}

describe( 'demo server', () => {
	it( 'puts tonight at 8 in, 2 out, 1 maybe, 4 no reply, with no goalie', async () => {
		const s = server( 'player' );
		const { body } = await s.call<GameDetail>( 'GET', `/games/${ s.tonight }` );
		expect( body.availability.counts ).toMatchObject( { in: 8, out: 2, maybe: 1, none: 4, goalies_in: 0 } );
		expect( body.start ).toMatch( /T21:45:00-05:00$/ );
	} );

	it( 'moves the counts when the player answers', async () => {
		const s = server( 'player' );
		const put = await s.call<{ counts: { in: number; none: number } }>( 'PUT', `/games/${ s.tonight }/availability`, { status: 'in' } );
		expect( put.status ).toBe( 200 );
		expect( put.body.counts ).toMatchObject( { in: 9, none: 3 } );
		const list = await s.call<Paged<Game>>( 'GET', '/me/games' );
		const tonight = list.body.data.find( ( g ) => g.id === s.tonight );
		expect( tonight?.my_availability ).toBe( 'in' );
		expect( tonight?.counts.in ).toBe( 9 );
	} );

	it( 'fills a call when the spare accepts, and counts the spare in', async () => {
		const s = server( 'spare' );
		const before = await s.call<SparesPayload>( 'GET', '/spares' );
		const goalieCall = before.body.open_calls.find( ( c ) => c.position === 'goalie' );
		expect( goalieCall?.game.team.name ).toBe( 'Ducks' );

		const took = await s.call<{ call: { status: string; accepted_by_me: boolean } }>( 'POST', `/spare-calls/${ goalieCall?.id }/accept` );
		expect( took.body.call ).toMatchObject( { status: 'filled', accepted_by_me: true } );

		const after = await s.call<SparesPayload>( 'GET', '/spares' );
		expect( after.body.open_calls.find( ( c ) => c.id === goalieCall?.id ) ).toBeUndefined();
		expect( after.body.my_accepted.map( ( c ) => c.id ) ).toContain( goalieCall?.id );

		const again = await s.call<{ code: string }>( 'POST', `/spare-calls/${ goalieCall?.id }/accept` );
		expect( again.status ).toBe( 409 );
		expect( again.body.code ).toBe( 'sppa_full' );

		const game = await s.call<GameDetail>( 'GET', `/games/${ s.tonight }` );
		expect( game.body ).toMatchObject( { as_spare: true } );
		expect( game.body.availability.counts ).toMatchObject( { in: 9, goalies_in: 1, spares: 1 } );
	} );

	it( 'refuses a call to a player who has not opted in', async () => {
		const s = server( 'player' );
		const res = await s.call<{ data: { reason: string } }>( 'POST', '/spare-calls/2/accept' );
		expect( res.status ).toBe( 403 );
		expect( res.body.data.reason ).toBe( 'not_opted_in' );
	} );

	it( 'posts and cancels a team call', async () => {
		const s = server( 'player' );
		const made = await s.call<{ call: { id: number; can_cancel: boolean } }>( 'POST', `/games/${ s.tonight }/spare-calls`, { team_id: 1, position: 'skater', needed: 1, note: 'Light jerseys' } );
		expect( made.body.call.can_cancel ).toBe( true );
		const game = await s.call<GameDetail>( 'GET', `/games/${ s.tonight }` );
		expect( game.body.spare_calls ).toHaveLength( 1 );
		const gone = await s.call<{ call: { status: string } }>( 'POST', `/spare-calls/${ made.body.call.id }/cancel` );
		expect( gone.body.call.status ).toBe( 'cancelled' );
	} );

	it( 'keeps the night board to organizers and gives them four games', async () => {
		expect( ( await server( 'player' ).call( 'GET', '/ops/night?date=2026-11-04' ) ).status ).toBe( 403 );
		const s = server( 'organizer' );
		const { body } = await s.call<{ data: Array<{ sides: unknown[] }>; date: string }>( 'GET', '/ops/night?date=2026-11-04' );
		expect( body.data ).toHaveLength( 4 );
		expect( body.date ).toBe( '2026-11-04' );
		expect( ( await s.call<Me>( 'GET', '/me' ) ).body.is_organizer ).toBe( true );
	} );

	it( 'gives the schedule played, postponed and changed games', async () => {
		const { body } = await server( 'player' ).call<Paged<Game>>( 'GET', '/me/games' );
		expect( body.data.filter( ( g ) => g.result ).map( ( g ) => g.result?.outcome ).sort() ).toEqual( [ 'L', 'T', 'W', 'W' ] );
		expect( body.data.some( ( g ) => g.status === 'postponed' ) ).toBe( true );
		expect( body.data.some( ( g ) => g.changed_recently ) ).toBe( true );
	} );

	it( 'has fifteen distinct, numbered players on every team', () => {
		const names = new Set<string>();
		for ( let t = 1; t <= 7; t++ ) {
			const numbers = roster( t ).map( ( p ) => p.number );
			expect( new Set( numbers ).size ).toBe( 15 );
			roster( t ).forEach( ( p ) => names.add( p.name ) );
		}
		expect( names.size ).toBe( 105 );
	} );
} );

describe( 'demo server: timekeeper', () => {
	it( 'makes the timekeeper an unlinked keeper and lists today’s games with a half-played one', async () => {
		const s = server( 'keeper' );
		const me = ( await s.call<Me>( 'GET', '/me' ) ).body;
		expect( me ).toMatchObject( { linked: false, teams: [], is_timekeeper: true, is_organizer: false } );
		const night = ( await s.call<KeeperNight>( 'GET', '/keep/night?date=2026-11-04' ) ).body;
		expect( night.data ).toHaveLength( 4 );
		expect( night.data[ 0 ].live ).toEqual( { status: 'live', score: { home: 2, away: 1 }, period: 2 } );
		expect( night.data[ 0 ].needs_review_count ).toBe( 2 );
		expect( night.data[ 0 ].home.roster ).toHaveLength( 15 );
		expect( ( await server( 'player' ).call( 'GET', '/keep/night' ) ).status ).toBe( 403 );
	} );

	it( 'logs, answers duplicates and rejections per entry, voids and finishes', async () => {
		const s = server( 'keeper' );
		const id = s.liveGame;
		const log = ( await s.call<GameLog>( 'GET', `/games/${ id }/log` ) ).body;
		const scorer = log.game.away.roster[ 0 ];
		const goal = { uid: 'demo-0000-0001', type: 'goal', team_id: log.game.away.id, period: 2, player_id: scorer.player_id };
		const bad = { uid: 'demo-0000-0002', type: 'goal', team_id: log.game.away.id, period: 2, player_id: log.game.home.roster[ 0 ].player_id };
		const post = await s.call<PostLogResult>( 'POST', `/games/${ id }/log`, { entries: [ goal, bad ] } );
		expect( post.body.results.map( ( r ) => [ r.status, r.reason ] ) ).toEqual( [ [ 'created', undefined ], [ 'rejected', 'player_not_on_team' ] ] );
		expect( post.body.live?.score ).toEqual( { home: 2, away: 2 } );
		const again = await s.call<PostLogResult>( 'POST', `/games/${ id }/log`, { entries: [ goal ] } );
		expect( again.body.results[ 0 ].status ).toBe( 'duplicate' );

		// Someone else's seeded entry cannot be voided by the keeper; their own can.
		const others = log.entries.find( ( e ) => e.recorded_by?.name === 'Sam Okafor' );
		expect( others?.can_void ).toBe( false );
		const voided = await s.call<{ live: { score: { away: number } } }>( 'POST', `/games/${ id }/log/demo-0000-0001/void` );
		expect( voided.body.live.score.away ).toBe( 1 );

		const done = await s.call<{ score: { home: number; away: number } }>( 'POST', `/games/${ id }/finish`, {} );
		expect( done.body.score ).toEqual( { home: 2, away: 1 } );
		const late = await s.call<{ code: string }>( 'POST', `/games/${ id }/log`, { entries: [ { ...goal, uid: 'demo-0000-0003' } ] } );
		expect( late.body.code ).toBe( 'sppa_finalized' );
		const live = ( await s.call<{ status: string }>( 'GET', `/games/${ id }/live` ) ).body;
		expect( live.status ).toBe( 'final' );
	} );

	it( 'asks before replacing a result someone else entered', async () => {
		const s = server( 'keeper' );
		const first = await s.call<{ code: string; data: { result: unknown } }>( 'POST', `/games/${ s.scoredGame }/finish`, {} );
		expect( first.status ).toBe( 409 );
		expect( first.body.data.result ).toEqual( { home: 3, away: 3 } );
		expect( ( await s.call( 'POST', `/games/${ s.scoredGame }/finish`, { overwrite: true } ) ).status ).toBe( 200 );
	} );

	it( 'fails every request like fetch does while offline is simulated', async () => {
		const s = server( 'keeper' );
		s.setOffline( true );
		await expect( s.fetch( '/demo-api/me' ) ).rejects.toThrow( TypeError );
		s.setOffline( false );
		expect( ( await s.fetch( '/demo-api/me' ) ).status ).toBe( 200 );
	} );
} );
