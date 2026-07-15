import { useState, useEffect } from '@wordpress/element';
import { fetchSeasonSummary, fetchStandings } from '../lib/api';

// Human labels for the stat-leader keys the report endpoint returns.
const STAT_LABELS = { p: 'Points', g: 'Goals', a: 'Assists', pim: 'Penalty Minutes', gaa: 'Goals Against Avg' };
const statLabel = ( key ) => STAT_LABELS[ key ] || key.toUpperCase();

function StandingsTable( { table } ) {
	const rows = table.standings || [];
	if ( rows.length === 0 ) return null;
	return (
		<div className="splm-standings__division">
			<h4>{ table.table_name || table.division || 'Standings' }</h4>
			<div className="splm-table-wrapper">
				<table className="splm-table">
					<thead>
						<tr>
							<th scope="col">#</th><th scope="col">Team</th><th scope="col">GP</th><th scope="col">W</th>
							<th scope="col">L</th><th scope="col">T</th><th scope="col">OT</th><th scope="col">GF</th>
							<th scope="col">GA</th><th scope="col">DIFF</th><th scope="col">Pts</th>
						</tr>
					</thead>
					<tbody>
						{ rows.map( ( row, i ) => (
							<tr key={ row.team_id }>
								<td>{ i + 1 }</td>
								<td className="splm-table__team">
									{ row.team_url
										? <a className="splm-table__team-link" href={ row.team_url } target="_blank" rel="noopener noreferrer">{ row.team }</a>
										: row.team }
								</td>
								<td>{ row.gp }</td><td>{ row.w }</td><td>{ row.l }</td><td>{ row.t }</td><td>{ row.ot }</td>
								<td>{ row.gf }</td><td>{ row.ga }</td>
								<td>{ row.diff > 0 ? `+${ row.diff }` : row.diff }</td>
								<td className="splm-table__pts">{ row.pts }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
		</div>
	);
}

export default function SeasonReport( { season } ) {
	const [ report, setReport ] = useState( null );
	const [ standings, setStandings ] = useState( [] );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		if ( ! season ) return undefined;
		let cancelled = false;
		setLoading( true );
		setError( '' );
		Promise.all( [ fetchSeasonSummary( season ), fetchStandings( null, season ).catch( () => [] ) ] )
			.then( ( [ d, s ] ) => {
				if ( cancelled ) return;
				setReport( d );
				setStandings( Array.isArray( s ) ? s : [] );
				setLoading( false );
			} )
			.catch( ( err ) => {
				if ( cancelled ) return;
				setError( err?.message || 'Failed to load report' );
				setLoading( false );
			} );
		return () => { cancelled = true; };
	}, [ season ] );

	if ( ! season ) return <div className="splm-season-report"><h2>Season Report</h2><p className="splm-empty">Select a season to generate a report.</p></div>;
	if ( loading ) return <div className="splm-loading">Generating report...</div>;

	const reg = report?.registration;
	const regularTables = standings.filter( ( t ) => ! t.is_playoff );
	const playoffTables = standings.filter( ( t ) => t.is_playoff );
	const leaderEntries = report ? Object.entries( report.leaders || {} ).filter( ( [ , players ] ) => players.length > 0 ) : [];

	return (
		<div className="splm-season-report">
			<h2>Season Report{ report ? ` — ${ report.season.name }` : '' }</h2>
			{ error && <div className="splm-alert splm-alert--warning" role="alert">{ error }</div> }

			{ report && (
				<>
					{ /* Games + registration at-a-glance */ }
					<section className="splm-card">
						<h3>Games</h3>
						<div className="splm-summary-stats">
							<div className="splm-summary-stats__item"><span className="splm-summary-stats__value">{ report.games.scheduled }</span><span className="splm-summary-stats__label">Scheduled</span></div>
							<div className="splm-summary-stats__item splm-summary-stats__item--green"><span className="splm-summary-stats__value">{ report.games.played }</span><span className="splm-summary-stats__label">Played</span></div>
							<div className="splm-summary-stats__item splm-summary-stats__item--yellow"><span className="splm-summary-stats__value">{ report.games.remaining }</span><span className="splm-summary-stats__label">Remaining</span></div>
							<div className="splm-summary-stats__item splm-summary-stats__item--red"><span className="splm-summary-stats__value">{ report.games.cancelled }</span><span className="splm-summary-stats__label">Cancelled</span></div>
						</div>
					</section>

					{ reg && (
						<section className="splm-card">
							<h3>Registration &amp; Payments</h3>
							<div className="splm-summary-stats">
								<div className="splm-summary-stats__item"><span className="splm-summary-stats__value">{ reg.roster }</span><span className="splm-summary-stats__label">Roster players</span></div>
								<div className="splm-summary-stats__item splm-summary-stats__item--green"><span className="splm-summary-stats__value">{ reg.registered }</span><span className="splm-summary-stats__label">Registered</span></div>
								<div className="splm-summary-stats__item splm-summary-stats__item--green"><span className="splm-summary-stats__value">{ reg.paid }</span><span className="splm-summary-stats__label">Paid</span></div>
							</div>
							<p className="splm-muted">“Registered” = has a registration record for this season; “Paid” = its linked order is completed. Registered players not yet on a roster are counted in the season totals but not under a division below.</p>
						</section>
					) }

					{ /* Per-division summary */ }
					{ report.divisions.length > 0 && (
						<section className="splm-card">
							<h3>By Division</h3>
							<div className="splm-table-wrapper">
								<table className="splm-table">
									<thead>
										<tr>
											<th scope="col">Division</th><th scope="col">Teams</th><th scope="col">Played</th>
											<th scope="col">Remaining</th><th scope="col">Complete</th>
											<th scope="col">Roster</th><th scope="col">Registered</th><th scope="col">Paid</th>
										</tr>
									</thead>
									<tbody>
										{ report.divisions.map( ( d ) => {
											const pct = d.scheduled > 0 ? Math.round( ( d.played / d.scheduled ) * 100 ) : 0;
											return (
												<tr key={ d.name }>
													<td>{ d.name }</td>
													<td>{ d.teams }</td>
													<td>{ d.played } / { d.scheduled }</td>
													<td>{ d.remaining }</td>
													<td>{ pct }%</td>
													<td>{ d.roster }</td>
													<td>{ d.registered }</td>
													<td>{ d.paid }</td>
												</tr>
											);
										} ) }
									</tbody>
								</table>
							</div>
						</section>
					) }

					{ /* Full standings */ }
					{ regularTables.length > 0 && (
						<section className="splm-card">
							<h3>Standings</h3>
							{ regularTables.map( ( t, i ) => <StandingsTable key={ t.table_id || t.division || i } table={ t } /> ) }
						</section>
					) }
					{ playoffTables.length > 0 && (
						<section className="splm-card">
							<h3>Playoff Standings</h3>
							{ playoffTables.map( ( t, i ) => <StandingsTable key={ t.table_id || t.division || i } table={ t } /> ) }
						</section>
					) }

					{ /* Leaders */ }
					{ leaderEntries.length > 0 ? (
						leaderEntries.map( ( [ key, players ] ) => (
							<section key={ key } className="splm-card">
								<h3>{ statLabel( key ) } Leaders</h3>
								<div className="splm-table-wrapper">
									<table className="splm-table">
										<thead><tr><th scope="col">#</th><th scope="col">Player</th><th scope="col">Team</th><th scope="col">{ statLabel( key ) }</th></tr></thead>
										<tbody>
											{ players.map( ( p, i ) => (
												<tr key={ i }>
													<td>{ i + 1 }</td>
													<td>{ p.player }</td>
													<td>{ p.team }</td>
													<td className="splm-table__pts">{ p.value }</td>
												</tr>
											) ) }
										</tbody>
									</table>
								</div>
							</section>
						) )
					) : (
						<section className="splm-card">
							<h3>Stat Leaders</h3>
							<p className="splm-muted">No player statistics recorded for this season yet. Leaders appear once game player-stats are entered.</p>
						</section>
					) }
				</>
			) }
		</div>
	);
}
