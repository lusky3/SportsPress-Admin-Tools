import { useState, useEffect } from '@wordpress/element';
import { fetchSeasonSummary } from '../lib/api';

export default function SeasonReport( { season } ) {
	const [ report, setReport ] = useState( null );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		if ( ! season ) return;
		setLoading( true );
		fetchSeasonSummary( season ).then( ( d ) => {
			setReport( d );
			setLoading( false );
		} ).catch( ( err ) => {
			setError( err?.message || 'Failed to load report' );
			setLoading( false );
		} );
	}, [ season ] );

	if ( ! season ) return <div className="splm-season-report"><h2>Season Report</h2><p className="splm-empty">Select a season to generate a report.</p></div>;
	if ( loading ) return <div className="splm-loading">Generating report...</div>;

	return (
		<div className="splm-season-report">
			<h2>Season Report{ report ? ` — ${ report.season.name }` : '' }</h2>
			{ error && <div className="splm-alert splm-alert--warning" role="alert">{ error }</div> }

			{ report && (
				<>
					<section className="splm-card">
						<h3>Games</h3>
						<div className="splm-summary-stats">
							<div className="splm-summary-stats__item">
								<span className="splm-summary-stats__value">{ report.games.scheduled }</span>
								<span className="splm-summary-stats__label">Scheduled</span>
							</div>
							<div className="splm-summary-stats__item splm-summary-stats__item--green">
								<span className="splm-summary-stats__value">{ report.games.played }</span>
								<span className="splm-summary-stats__label">Played</span>
							</div>
							<div className="splm-summary-stats__item splm-summary-stats__item--red">
								<span className="splm-summary-stats__value">{ report.games.cancelled }</span>
								<span className="splm-summary-stats__label">Cancelled</span>
							</div>
							<div className="splm-summary-stats__item splm-summary-stats__item--yellow">
								<span className="splm-summary-stats__value">{ report.games.remaining }</span>
								<span className="splm-summary-stats__label">Remaining</span>
							</div>
						</div>
					</section>

					{ report.divisions.length > 0 && (
						<section className="splm-card">
							<h3>Standings Tables</h3>
							<ul className="splm-game-list">
								{ report.divisions.map( ( d ) => (
									<li key={ d.table_id } className="splm-game-list__item">{ d.name }</li>
								) ) }
							</ul>
						</section>
					) }

					{ Object.entries( report.leaders ).map( ( [ key, players ] ) => (
						players.length > 0 && (
							<section key={ key } className="splm-card">
								<h3>{ key.toUpperCase() } Leaders</h3>
								<div className="splm-table-wrapper">
									<table className="splm-table">
										<thead><tr><th>#</th><th>Player</th><th>Team</th><th>{ key.toUpperCase() }</th></tr></thead>
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
						)
					) ) }
				</>
			) }
		</div>
	);
}
