import { useState, useEffect } from '@wordpress/element';
import { fetchStandings } from '../lib/api';

export default function Standings( { season } ) {
	const [ tables, setTables ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		let cancelled = false;
		setLoading( true );
		fetchStandings( null, season ).then( ( data ) => {
			if ( cancelled ) return;
			// Handle both old flat array and new multi-table format
			if ( Array.isArray( data ) && data.length > 0 && data[ 0 ].standings ) {
				setTables( data );
			} else if ( Array.isArray( data ) && data.length > 0 ) {
				setTables( [ { table_id: 0, table_name: 'Standings', standings: data } ] );
			} else {
				setTables( [] );
			}
			setLoading( false );
		} ).catch( ( err ) => {
			if ( cancelled ) return;
			setError( err?.message || 'Failed to load standings' );
			setLoading( false );
		} );
		return () => { cancelled = true; };
	}, [ season ] );

	if ( loading ) {
		return <div className="splm-loading">Loading standings...</div>;
	}

	if ( tables.length === 0 ) {
		return (
			<div className="splm-standings">
				<h2>Standings</h2>
				{ error && <div className="splm-alert splm-alert--warning" role="alert">{ error }</div> }
				<p className="splm-empty">No standings data available.</p>
			</div>
		);
	}

	return (
		<div className="splm-standings">
			<h2>Standings</h2>
			{ error && <div className="splm-alert splm-alert--warning" role="alert">{ error }</div> }
			{ tables.map( ( table ) => (
				<div key={ table.table_id } className="splm-standings__division">
					{ tables.length > 1 && <h3>{ table.table_name }</h3> }
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
								{ table.standings.map( ( row, i ) => (
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
			) ) }
		</div>
	);
}
