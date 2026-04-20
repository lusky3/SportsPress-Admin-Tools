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

export function fetchStandings( tableId, season ) {
	const params = [];
	if ( tableId ) params.push( 'table_id=' + tableId );
	if ( season ) params.push( 'season=' + season );
	const query = params.length ? '?' + params.join( '&' ) : '';
	return apiFetch( { path: `/splm/v1/standings${ query }` } );
}

export function fetchTeams() {
	return apiFetch( { path: '/splm/v1/teams' } );
}

export function fetchRoster( teamId ) {
	return apiFetch( { path: `/splm/v1/rosters?team=${ teamId }` } );
}

export function movePlayer( playerId, fromTeam, toTeam ) {
	return apiFetch( {
		path: '/splm/v1/rosters/move',
		method: 'POST',
		data: { player_id: playerId, from_team: fromTeam, to_team: toTeam },
	} );
}

export function fetchNotes( playerId ) {
	return apiFetch( { path: `/splm/v1/notes?player=${ playerId }` } );
}

export function addNote( playerId, content ) {
	return apiFetch( {
		path: '/splm/v1/notes',
		method: 'POST',
		data: { player_id: playerId, content },
	} );
}

export function fetchPayments( season ) {
	const params = season ? '?season=' + season : '';
	return apiFetch( { path: '/splm/v1/payments' + params } );
}

export function fetchHealth() {
	return apiFetch( { path: '/splm/v1/health' } );
}

export function fetchSeasons() {
	return apiFetch( { path: '/splm/v1/seasons' } );
}
