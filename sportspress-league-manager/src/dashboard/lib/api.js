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

export function fetchTeams( season ) {
	const params = season ? '?season=' + season : '';
	return apiFetch( { path: '/splm/v1/teams' + params } );
}

export function fetchRoster( teamId ) {
	return apiFetch( { path: `/splm/v1/rosters?team=${ teamId }` } );
}

export function fetchRosterDetails( teamId, seasonId ) {
	return apiFetch( { path: `/splm/v1/rosters/details?team=${ teamId }&season=${ seasonId }` } );
}

export function setCaptain( playerId, teamId, isCaptain ) {
	return apiFetch( { path: '/splm/v1/rosters/set-captain', method: 'POST', data: { player_id: playerId, team_id: teamId, is_captain: isCaptain } } );
}

export function updatePlayerMetadata( playerId, field, value ) {
	return apiFetch( { path: '/splm/v1/rosters/update-metadata', method: 'POST', data: { player_id: playerId, field, value } } );
}

export function importRoster( teamId, seasonId, players ) {
	return apiFetch( { path: '/splm/v1/rosters/import', method: 'POST', data: { team_id: teamId, season_id: seasonId, players } } );
}

export function movePlayer( playerId, fromTeam, toTeam ) {
	return apiFetch( {
		path: '/splm/v1/rosters/move',
		method: 'POST',
		data: { player_id: playerId, from_team: fromTeam, to_team: toTeam },
	} );
}

export function updatePlayer( playerId, field, value ) {
	return apiFetch( {
		path: '/splm/v1/rosters/update-player',
		method: 'POST',
		data: { player_id: playerId, field, value },
	} );
}

export function removePlayer( playerId, teamId ) {
	return apiFetch( {
		path: '/splm/v1/rosters/remove-player',
		method: 'POST',
		data: { player_id: playerId, team_id: teamId },
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

export function fetchGamePlayers( gameId ) {
	return apiFetch( { path: `/splm/v1/games/${ gameId }/players` } );
}

export function saveGamePlayers( gameId, stats ) {
	return apiFetch( {
		path: `/splm/v1/games/${ gameId }/players`,
		method: 'POST',
		data: { stats },
	} );
}

export function fetchScheduleConfig() {
	return apiFetch( { path: '/splm/v1/schedule/config' } );
}

export function generateSchedule( config ) {
	return apiFetch( { path: '/splm/v1/schedule/generate', method: 'POST', data: config } );
}

export function publishSchedule( games, seasonId, leagueId ) {
	return apiFetch( { path: '/splm/v1/schedule/publish', method: 'POST', data: { games, season_id: seasonId, league_id: leagueId } } );
}
