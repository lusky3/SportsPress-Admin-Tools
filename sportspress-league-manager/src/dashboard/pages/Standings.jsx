import { useState, useEffect } from '@wordpress/element';
import HelpLink from '../components/HelpLink';
import { fetchStandings, generateStandings } from '../lib/api';

function StandingsTable( { table } ) {
	return (
		<div className="splm-standings__division">
			<h4>{ table.table_name || table.division || 'Standings' }</h4>
			<div className="splm-table-wrapper">
				<table className="splm-table">
					<thead>
						<tr>
							<th scope="col">#</th>
							<th scope="col">Team</th>
							<th scope="col">GP</th>
							<th scope="col">W</th>
							<th scope="col">L</th>
							<th scope="col">T</th>
							<th scope="col">OT</th>
							<th scope="col">GF</th>
							<th scope="col">GA</th>
							<th scope="col">DIFF</th>
							<th scope="col">Pts</th>
						</tr>
					</thead>
					<tbody>
						{ table.standings.map( ( row, i ) => (
							<tr key={ row.team_id }>
								<td>{ i + 1 }</td>
								<td className="splm-table__team">
									{ row.team_url
										? <a className="splm-table__team-link" href={ row.team_url } target="_blank" rel="noopener noreferrer">{ row.team }</a>
										: row.team }
								</td>
								<td>{ row.gp }</td>
								<td>{ row.w }</td>
								<td>{ row.l }</td>
								<td>{ row.t }</td>
								<td>{ row.ot }</td>
								<td>{ row.gf }</td>
								<td>{ row.ga }</td>
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
			loadStandings( { cancelled: false } );
		} catch ( err ) {
			setError( err?.message || 'Failed to generate' );
		}
		setGenerating( false );
	};

	if ( loading ) {
		return <div className="splm-loading">Loading standings...</div>;
	}

	const regular = tables.filter( ( t ) => ! t.is_playoff );
	const playoff = tables.filter( ( t ) => t.is_playoff );

	return (
		<div className="splm-standings">
			<h2>Standings <HelpLink topic="standings" /></h2>
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

			{ tables.length === 0 && <p className="splm-empty">No standings data available.</p> }

			{ regular.length > 0 && (
				<section className="splm-standings__group">
					{ playoff.length > 0 && <h3>Regular Season</h3> }
					{ regular.map( ( t ) => <StandingsTable key={ t.table_id } table={ t } /> ) }
				</section>
			) }

			{ playoff.length > 0 && (
				<section className="splm-standings__group">
					<h3>Playoffs</h3>
					{ playoff.map( ( t ) => <StandingsTable key={ t.table_id } table={ t } /> ) }
				</section>
			) }
		</div>
	);
}
