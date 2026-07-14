import apiFetch from '@wordpress/api-fetch';

// All list endpoints across splm/v1 + spsg/v1 conform to the
// { data, total, page, total_pages } shape documented in
// docs/rest-api-conventions.md. Helper unwraps to a plain array.
// Single-resource and aggregate endpoints are returned as-is.
const unwrapList = ( res ) => Array.isArray( res?.data ) ? res.data : [];

export function fetchGames( params = {} ) {
	const query = new URLSearchParams( params ).toString();
	return apiFetch( { path: `/splm/v1/games${ query ? '?' + query : '' }` } )
		.then( unwrapList );
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
	return apiFetch( { path: `/splm/v1/standings${ query }` } ).then( unwrapList );
}

export function fetchTeams( season ) {
	const params = season ? '?season=' + season : '';
	return apiFetch( { path: '/splm/v1/teams' + params } ).then( unwrapList );
}

export function fetchTeamsWithDivisions() {
	return apiFetch( { path: '/splm/v1/teams/with-divisions' } ).then( ( res ) => Array.isArray( res?.teams ) ? res.teams : [] );
}

export function fetchRosterDetails( teamId, seasonId ) {
	return apiFetch( { path: `/splm/v1/rosters/details?team=${ teamId }&season=${ seasonId }` } ).then( unwrapList );
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
	return apiFetch( { path: `/splm/v1/notes?player=${ playerId }` } ).then( unwrapList );
}

// Batch note counts for a roster, so rows can show a "has notes" indicator
// without one request per player. Returns { player_id: count }.
export function fetchNoteCounts( playerIds ) {
	const ids = ( playerIds || [] ).filter( Boolean ).join( ',' );
	if ( ! ids ) return Promise.resolve( {} );
	return apiFetch( { path: `/splm/v1/notes/counts?player_ids=${ ids }` } )
		.then( ( res ) => ( res && res.counts ) ? res.counts : {} )
		.catch( () => ( {} ) );
}

export function addNote( playerId, content ) {
	return apiFetch( {
		path: '/splm/v1/notes',
		method: 'POST',
		data: { player_id: playerId, content },
	} );
}

export function fetchPayments( season, { page = 1, perPage = 200 } = {} ) {
	// Paginated list endpoint. Returns the full { data, total, page, total_pages }
	// envelope so the Payments page can render its pager. The page calls
	// `res.data`, `res.total`, and `res.total_pages` directly.
	const params = new URLSearchParams( { per_page: String( perPage ), page: String( page ) } );
	if ( season ) params.set( 'season', season );
	return apiFetch( { path: `/splm/v1/payments?${ params }` } );
}

export function fetchHealth() {
	// /health is an aggregate report — returns the object as-is, not a list.
	return apiFetch( { path: '/splm/v1/health' } );
}

export function fetchGamePlayers( gameId ) {
	// /games/{id}/players is a single-game aggregate ({performances, teams}).
	return apiFetch( { path: `/splm/v1/games/${ gameId }/players` } );
}

export function saveGamePlayers( gameId, stats ) {
	return apiFetch( {
		path: `/splm/v1/games/${ gameId }/players`,
		method: 'POST',
		data: { stats },
	} );
}

export function rolloverPreview( fromSeason, toSeason ) {
	return apiFetch( { path: '/splm/v1/season/rollover-preview', method: 'POST', data: { from_season: fromSeason, to_season: toSeason } } );
}

export function rolloverExecute( fromSeason, toSeason, playerIds ) {
	return apiFetch( { path: '/splm/v1/season/rollover-execute', method: 'POST', data: { from_season: fromSeason, to_season: toSeason, player_ids: playerIds } } );
}

export function createSeason( seasonName, leagueId, { createCalendars = false, createRosters = false, createPlayoffs = false, teamIds = [], newTeams = [], newTeamDivisions = [], divisionAssignments = {} } = {} ) {
	const data = { season_name: seasonName, league_id: leagueId, create_calendars: createCalendars, create_rosters: createRosters, create_playoffs: createPlayoffs };
	if ( teamIds.length ) data.team_ids = teamIds;
	if ( newTeams.length ) {
		data.new_teams = newTeams;
		// new_team_divisions is index-aligned with new_teams: the sp_league term id
		// each new team should be assigned to. Coerced to integers.
		data.new_team_divisions = newTeamDivisions.map( ( id ) => Number( id ) );
	}
	if ( Object.keys( divisionAssignments ).length ) data.division_assignments = divisionAssignments;
	return apiFetch( { path: '/splm/v1/season/create', method: 'POST', data } );
}

// spsg/v1 — Schedule Generator. List endpoints unwrap .data; single-resource
// and write endpoints return their response as-is.
export const spsg = {
	listConfigs: () => apiFetch( { path: '/spsg/v1/configs' } ).then( unwrapList ),
	getConfig: ( id ) => apiFetch( { path: `/spsg/v1/configs/${ id }` } ),
	createConfig: ( data ) => apiFetch( { path: '/spsg/v1/configs', method: 'POST', data } ),
	updateConfig: ( id, data ) => apiFetch( { path: `/spsg/v1/configs/${ id }`, method: 'PUT', data } ),
	deleteConfig: ( id ) => apiFetch( { path: `/spsg/v1/configs/${ id }`, method: 'DELETE' } ),
	cloneConfig: ( id, name ) => apiFetch( { path: `/spsg/v1/configs/${ id }/clone`, method: 'POST', data: { name } } ),
	validateConfig: ( id ) => apiFetch( { path: `/spsg/v1/configs/${ id }/validate`, method: 'POST' } ),
	getLeagues: () => apiFetch( { path: '/spsg/v1/sportspress/leagues' } ).then( unwrapList ),
	getVenues: () => apiFetch( { path: '/spsg/v1/sportspress/venues' } ).then( unwrapList ),
	getSeasons: () => apiFetch( { path: '/spsg/v1/sportspress/seasons' } ).then( unwrapList ),
	generate: ( configId ) => apiFetch( { path: '/spsg/v1/generate', method: 'POST', data: { config_id: configId } } ),
	progress: () => apiFetch( { path: '/spsg/v1/generate/progress' } ),
	cancel: () => apiFetch( { path: '/spsg/v1/generate/cancel', method: 'POST' } ),
	getPlaceholders: ( configId ) => apiFetch( { path: `/spsg/v1/configs/${ configId }/placeholders` } ).then( unwrapList ),
	replacePlaceholder: ( id, replacementId, del = false ) =>
		apiFetch( { path: `/spsg/v1/placeholders/${ id }/replace`, method: 'POST', data: { replacement_id: replacementId, delete: del } } ),
	getHistory: ( id ) => apiFetch( { path: `/spsg/v1/configs/${ id }/history` } ).then( unwrapList ),
	clearHistory: ( id ) => apiFetch( { path: `/spsg/v1/configs/${ id }/history/clear`, method: 'DELETE' } ),
	listPresets: () => apiFetch( { path: '/spsg/v1/presets' } ).then( unwrapList ),
	getPreset: ( name ) => apiFetch( { path: `/spsg/v1/presets/${ name }` } ),
	getLeagueTeams: ( leagueId ) => apiFetch( { path: `/spsg/v1/sportspress/leagues/${ leagueId }/teams` } ).then( unwrapList ),
	exportXlsx: ( scheduleId, configId, style = 'detailed' ) => apiFetch( { path: '/spsg/v1/export/xlsx', method: 'POST', data: { schedule_id: scheduleId, config_id: configId, style } } ),
	publish: ( scheduleId, seasonId, leagueId, offset = 0, limit = 50, opts = {} ) =>
		apiFetch( { path: '/spsg/v1/publish', method: 'POST', data: { schedule_id: scheduleId, season_id: seasonId, league_id: leagueId, offset, limit, ...opts } } ),
	getDistributionSettings: () => apiFetch( { path: '/spsg/v1/settings/distribution' } ),
	parseVenueCsv: ( formData ) => apiFetch( { path: '/spsg/v1/venue-csv/parse', method: 'POST', body: formData } ),
	applyVenueCsv: ( schedules, venueMapping, configId ) => apiFetch( { path: '/spsg/v1/venue-csv/apply', method: 'POST', data: { schedules, venue_mapping: venueMapping, config_id: configId } } ),
};

// --- Dashboard gap features ---

export function searchPlayers( query ) {
	return apiFetch( { path: `/splm/v1/players/search?q=${ encodeURIComponent( query ) }` } ).then( unwrapList );
}

export function fetchActivity( limit = 20 ) {
	return apiFetch( { path: `/splm/v1/activity?limit=${ limit }` } ).then( unwrapList );
}

export function batchUpdateScores( scores ) {
	return apiFetch( { path: '/splm/v1/scores/batch', method: 'POST', data: { scores } } );
}

export function saveUserPreferences( prefs ) {
	return apiFetch( { path: '/splm/v1/user/preferences', method: 'POST', data: prefs } );
}

export function calculateSkills( leagueId, seasonId ) {
	return apiFetch( { path: '/splm/v1/skills/calculate', method: 'POST', data: { league_id: leagueId, season_id: seasonId } } );
}

export function generateStandings( leagueId, seasonId ) {
	return apiFetch( { path: '/splm/v1/standings/generate', method: 'POST', data: { league_id: leagueId, season_id: seasonId } } );
}

export function fetchDivisionBalance( seasonId ) {
	const params = seasonId ? `?season=${ seasonId }` : '';
	return apiFetch( { path: `/splm/v1/divisions/balance${ params }` } ).then( unwrapList );
}

export function compareTeams( teamA, teamB, seasonId ) {
	// /teams/compare is a single comparison aggregate, not a list.
	const params = new URLSearchParams( { team_a: teamA, team_b: teamB } );
	if ( seasonId ) params.set( 'season', seasonId );
	return apiFetch( { path: `/splm/v1/teams/compare?${ params }` } );
}

export function fetchSeasonSummary( seasonId ) {
	// /reports/season-summary is an aggregate report, not a list.
	return apiFetch( { path: `/splm/v1/reports/season-summary?season=${ seasonId }` } );
}

export function bulkUploadRoster( file ) {
	const formData = new FormData();
	formData.append( 'file', file );
	return apiFetch( { path: '/splm/v1/rosters/bulk-upload', method: 'POST', body: formData } );
}

export function bulkProcessRoster( teams, seasonId, action, template ) {
	return apiFetch( { path: '/splm/v1/rosters/bulk-process', method: 'POST', data: { teams, season_id: seasonId, action, list_name_template: template } } );
}

export function importGamesPreview( file ) {
	const formData = new FormData();
	formData.append( 'file', file );
	return apiFetch( { path: '/splm/v1/games/import-preview', method: 'POST', body: formData } );
}

export function importGames( games, seasonId ) {
	return apiFetch( { path: '/splm/v1/games/import', method: 'POST', data: { games, season_id: seasonId } } );
}

// spss/v1 — Score Sheets. The queue is a { data, total } list (unwrapped);
// single-sheet and confirm endpoints return their response as-is.
export function fetchSheets( status = '' ) {
	const params = status ? '?status=' + encodeURIComponent( status ) : '';
	return apiFetch( { path: `/spss/v1/sheets${ params }` } ).then( unwrapList );
}

export function fetchSheet( id ) {
	return apiFetch( { path: `/spss/v1/sheets/${ id }` } );
}

export function fetchScoreSheetEvents( season = '' ) {
	const params = season ? '?season=' + encodeURIComponent( season ) : '';
	return apiFetch( { path: `/spss/v1/events${ params }` } ).then( unwrapList );
}

export function uploadSheet( { image_b64, ext, event_id } = {} ) {
	const data = { image_b64 };
	if ( ext ) data.ext = ext;
	if ( event_id ) data.event_id = event_id;
	return apiFetch( { path: '/spss/v1/sheets', method: 'POST', data } );
}

export function confirmSheet( id, payload ) {
	return apiFetch( { path: `/spss/v1/sheets/${ id }/confirm`, method: 'POST', data: payload } );
}
