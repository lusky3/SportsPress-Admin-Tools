import apiFetch from '@wordpress/api-fetch';

const config = window.splmDashboard || {};

export function fetchGames( params = {} ) {
	const query = new URLSearchParams( params ).toString();
	return apiFetch( { path: `/splm/v1/games${ query ? '?' + query : '' }` } );
}

export function updateScore( gameId, homeScore, awayScore ) {
	return apiFetch( {
		path: `/splm/v1/games/${ gameId }/score`,
		method: 'POST',
		data: { home_score: homeScore, away_score: awayScore },
	} );
}

export function rescheduleGame( gameId, { date, time, reason, notify = true } ) {
	return apiFetch( {
		path: `/splm/v1/games/${ gameId }/reschedule`,
		method: 'POST',
		data: { date, time, reason, notify },
	} );
}

export function cancelGame( gameId, { reason, notify = true } ) {
	return apiFetch( {
		path: `/splm/v1/games/${ gameId }/cancel`,
		method: 'POST',
		data: { reason, notify },
	} );
}

export function fetchStandings( tableId ) {
	const params = tableId ? `?table_id=${ tableId }` : '';
	return apiFetch( { path: `/splm/v1/standings${ params }` } );
}
