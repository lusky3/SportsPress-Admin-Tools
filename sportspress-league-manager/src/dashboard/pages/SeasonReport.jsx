import { useState, useEffect } from '@wordpress/element';
import HelpLink from '../components/HelpLink';
import { fetchSeasonSummary } from '../lib/api';

// Human labels for the stat-leader keys the report endpoint returns.
const STAT_LABELS = { p: 'Points', g: 'Goals', a: 'Assists', pim: 'Penalty Minutes' };
const statLabel = ( key ) => STAT_LABELS[ key ] || key.toUpperCase();

export default function SeasonReport( { season } ) {
	const [ report, setReport ] = useState( null );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		if ( ! season ) return undefined;
		let cancelled = false;
		setLoading( true );
		setError( '' );
		fetchSeasonSummary( season )
			.then( ( d ) => {
				if ( cancelled ) return;
				setReport( d );
				setLoading( false );
			} )
			.catch( ( err ) => {
				if ( cancelled ) return;
				setError( err?.message || 'Failed to load report' );
				setLoading( false );
			} );
		return () => { cancelled = true; };
	}, [ season ] );

	if ( ! season ) return <div className="splm-season-report"><h2>Season Report <HelpLink topic="season-report" /></h2><p className="splm-empty">Select a season to generate a report.</p></div>;
	if ( loading ) return <div className="splm-loading">Generating report...</div>;

	const reg = report?.registration;
	const leaderEntries = report ? Object.entries( report.leaders || {} ).filter( ( [ , players ] ) => players.length > 0 ) : [];

	return (
		<div className="splm-season-report">
			<h2>Season Report{ report ? ` — ${ report.season.name }` : '' } <HelpLink topic="season-report" /></h2>
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
