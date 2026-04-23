import apiFetch from '@wordpress/api-fetch';

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

export function rolloverPreview(fromSeason, toSeason) {
  return apiFetch({ path: '/splm/v1/season/rollover-preview', method: 'POST', data: { from_season: fromSeason, to_season: toSeason } });
}

export function rolloverExecute(fromSeason, toSeason, playerIds) {
  return apiFetch({ path: '/splm/v1/season/rollover-execute', method: 'POST', data: { from_season: fromSeason, to_season: toSeason, player_ids: playerIds } });
}

// spsg/v1 — Schedule Generator
export const spsg = {
	listConfigs: () => apiFetch( { path: '/spsg/v1/configs' } ),
	getConfig: ( id ) => apiFetch( { path: `/spsg/v1/configs/${ id }` } ),
	createConfig: ( data ) => apiFetch( { path: '/spsg/v1/configs', method: 'POST', data } ),
	updateConfig: ( id, data ) => apiFetch( { path: `/spsg/v1/configs/${ id }`, method: 'PUT', data } ),
	deleteConfig: ( id ) => apiFetch( { path: `/spsg/v1/configs/${ id }`, method: 'DELETE' } ),
	cloneConfig: ( id, name ) => apiFetch( { path: `/spsg/v1/configs/${ id }/clone`, method: 'POST', data: { name } } ),
	validateConfig: ( id ) => apiFetch( { path: `/spsg/v1/configs/${ id }/validate`, method: 'POST' } ),
	getLeagues: () => apiFetch( { path: '/spsg/v1/sportspress/leagues' } ),
	getVenues: () => apiFetch( { path: '/spsg/v1/sportspress/venues' } ),
	getSeasons: () => apiFetch( { path: '/spsg/v1/sportspress/seasons' } ),
	generate: ( configId ) => apiFetch( { path: '/spsg/v1/generate', method: 'POST', data: { config_id: configId } } ),
	progress: () => apiFetch( { path: '/spsg/v1/generate/progress' } ),
	cancel: () => apiFetch( { path: '/spsg/v1/generate/cancel', method: 'POST' } ),
	getPlaceholders: ( configId ) => apiFetch( { path: `/spsg/v1/configs/${ configId }/placeholders` } ),
	replacePlaceholder: ( id, replacementId, del = false ) =>
		apiFetch( { path: `/spsg/v1/placeholders/${ id }/replace`, method: 'POST', data: { replacement_id: replacementId, delete: del } } ),
	getHistory: ( id ) => apiFetch( { path: `/spsg/v1/configs/${ id }/history` } ),
	clearHistory: ( id ) => apiFetch( { path: `/spsg/v1/configs/${ id }/history/clear`, method: 'DELETE' } ),
	listPresets: () => apiFetch( { path: '/spsg/v1/presets' } ),
	getPreset: ( name ) => apiFetch( { path: `/spsg/v1/presets/${ name }` } ),
	getLeagueTeams: ( leagueId ) => apiFetch( { path: `/spsg/v1/sportspress/leagues/${ leagueId }/teams` } ),
	exportXlsx: ( scheduleId, configId, style = 'detailed' ) => apiFetch( { path: '/spsg/v1/export/xlsx', method: 'POST', data: { schedule_id: scheduleId, config_id: configId, style } } ),
	publish: ( scheduleId, seasonId, leagueId, offset = 0, limit = 50, opts = {} ) =>
		apiFetch( { path: '/spsg/v1/publish', method: 'POST', data: { schedule_id: scheduleId, season_id: seasonId, league_id: leagueId, offset, limit, ...opts } } ),
	getDistributionSettings: () => apiFetch( { path: '/spsg/v1/settings/distribution' } ),
	parseVenueCsv: ( formData ) => apiFetch( { path: '/spsg/v1/venue-csv/parse', method: 'POST', body: formData } ),
	applyVenueCsv: ( schedules, venueMapping, configId ) => apiFetch( { path: '/spsg/v1/venue-csv/apply', method: 'POST', data: { schedules, venue_mapping: venueMapping, config_id: configId } } ),
};