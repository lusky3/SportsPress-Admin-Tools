import { apiSend } from '../api';
import type { AvailabilityResult, AvailabilityStatus, GameDetail, SpareCall } from '../types';
import { getCached, invalidate, setCached, useResource } from '../useResource';

/** Patch the cached game detail (if any) with what the server just confirmed. */
function patch( path: string, change: ( d: GameDetail ) => GameDetail ): void {
	const current = getCached<GameDetail>( path );
	if ( current ) {
		setCached( path, change( current ) );
	}
}

/**
 * A game's detail plus the writes a game screen makes: answer, post a spare call, drop a cancelled one.
 * Each write updates the cached detail from the server's reply, then marks the lists that show it stale.
 * Pass id 0 to load nothing.
 */
export function useGame( id: number ) {
	const path = `/games/${ id }`;
	const resource = useResource<GameDetail>( id > 0 ? path : '' );

	const answer = async ( status: AvailabilityStatus ) => {
		const mine = getCached<GameDetail>( path )?.availability.mine;
		const res = await apiSend<AvailabilityResult>( 'PUT', `${ path }/availability`, mine ? { status, player_id: mine.player_id } : { status } );
		patch( path, ( d ) => ( {
			...d,
			my_availability: status,
			counts: { in: res.counts.in, out: res.counts.out, maybe: res.counts.maybe },
			availability: {
				...d.availability,
				mine: d.availability.mine && { ...d.availability.mine, status },
				counts: { ...d.availability.counts, ...res.counts },
				players: d.availability.players.map( ( p ) => ( p.player_id === d.availability.mine?.player_id && ! p.spare ? { ...p, status } : p ) ),
			},
		} ) );
		invalidate( '/me/games' );
	};

	const addCall = ( call: SpareCall ) => {
		patch( path, ( d ) => ( { ...d, spare_calls: [ ...d.spare_calls, call ] } ) );
		invalidate( '/spares' );
	};

	const dropCall = ( call: SpareCall ) => {
		patch( path, ( d ) => ( { ...d, spare_calls: d.spare_calls.filter( ( c ) => c.id !== call.id ) } ) );
		invalidate( '/spares' );
	};

	return { ...resource, answer, addCall, dropCall };
}
