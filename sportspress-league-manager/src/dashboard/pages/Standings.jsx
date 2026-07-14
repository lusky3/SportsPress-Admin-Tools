import { useState, useEffect } from '@wordpress/element';
import { fetchStandings, fetchTeams, generateStandings } from '../lib/api';

export default function Standings( { season } ) {
	const [ tables, setTables ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ genModal, setGenModal ] = useState( false );
	const [ genLeague, setGenLeague ] = useState( '' );
	const [ generating, setGenerating ] = useState( false );

	const config = window.splmDashboard || {};
	const leagues = config.leagues || [];

	// L3: load with cancel guard so a stale response from a previous season
	// doesn't overwrite state after the user has navigated.
	const loadStandings = ( cancelledRef ) => {
		setLoading( true );
		fetchStandings( null, season ).then( ( data ) => {
			if ( cancelledRef && cancelledRef.cancelled ) return;
			if ( Array.isArray( data ) && data.length > 0 && data[ 0 ].standings ) {
				setTables( data );
			} else if ( Array.isArray( data ) && data.length > 0 ) {
				setTables( [ { table_id: 0, table_name: 'Standings', standings: data } ] );
			} else {
				setTables( [] );
			}
			setLoading( false );
		} ).catch( ( err ) => {
			if ( cancelledRef && cancelledRef.cancelled ) return;
			setError( err?.message || 'Failed to load standings' );
			setLoading( false );
		} );
	};

	useEffect( () => {
		const ref = { cancelled: false };
		loadStandings( ref );
		return () => { ref.cancelled = true; };
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ season ] );

	const handleGenerate = async () => {
		if ( ! genLeague || ! season ) return;
		setGenerating( true );
		try {
			await generateStandings( genLeague, season );
			setGenModal( false );
			// UI-16: pass a fresh guard ref so loadStandings's cancel check has a
			// defined object (it dereferences cancelledRef.cancelled).
			loadStandings( { cancelled: false } );
		} catch ( err ) {
			setError( err?.message || 'Failed to generate' );
		}
		setGenerating( false );
	};

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
			{ season && (
				<button className="splm-btn" onClick={ () => setGenModal( ! genModal ) }>
					Generate Standings Table
				</button>
			) }
			{ genModal && (
				<div className="splm-card" style={ { marginTop: '1rem' } }>
					<select className="splm-select" value={ genLeague } onChange={ ( e ) => setGenLeague( e.target.value ) } aria-label="Select division">
						<option value="">Select division...</option>
						{ leagues.map( ( l ) => <option key={ l.id } value={ l.id }>{ l.name }</option> ) }
					</select>
					<button className="splm-btn splm-btn--primary" onClick={ handleGenerate } disabled={ ! genLeague || generating } style={ { marginLeft: '0.5rem' } }>
						{ generating ? 'Creating...' : 'Create Table' }
					</button>
				</div>
			) }
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
