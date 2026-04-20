import { useState, useEffect } from '@wordpress/element';
import { fetchStandings } from '../lib/api';

export default function Standings() {
	const [ standings, setStandings ] = useState( [] );
	const [ loading, setLoading ] = useState( true );

	useEffect( () => {
		fetchStandings().then( ( data ) => {
			setStandings( data );
			setLoading( false );
		} ).catch( () => setLoading( false ) );
	}, [] );

	if ( loading ) {
		return <div className="splm-loading">Loading standings...</div>;
	}

	if ( standings.length === 0 ) {
		return (
			<div className="splm-standings">
				<h2>Standings</h2>
				<p className="splm-empty">No standings data available.</p>
			</div>
		);
	}

	return (
		<div className="splm-standings">
			<h2>Standings</h2>
			<div className="splm-table-wrapper">
				<table className="splm-table">
					<thead>
						<tr>
							<th>#</th>
							<th>Team</th>
							<th>GP</th>
							<th>W</th>
							<th>L</th>
							<th>D</th>
							<th>Pts</th>
						</tr>
					</thead>
					<tbody>
						{ standings.map( ( row, i ) => (
							<tr key={ row.team_id }>
								<td>{ i + 1 }</td>
								<td className="splm-table__team">{ row.team }</td>
								<td>{ row.p }</td>
								<td>{ row.w }</td>
								<td>{ row.l }</td>
								<td>{ row.d }</td>
								<td className="splm-table__pts">{ row.pts }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
		</div>
	);
}
