import { useState, useEffect } from '@wordpress/element';
import HelpLink from '../components/HelpLink';
import { fetchLeaders } from '../lib/api';

const STAT_LABELS = { p: 'Points', g: 'Goals', a: 'Assists', pim: 'Penalty Minutes' };
const STAT_ORDER = [ 'p', 'g', 'a', 'pim' ];

function Board( { statKey, rows } ) {
	if ( ! rows || rows.length === 0 ) return null;
	return (
		<section className="splm-card">
			<h3>{ STAT_LABELS[ statKey ] }</h3>
			<div className="splm-table-wrapper">
				<table className="splm-table">
					<thead>
						<tr>
							<th scope="col">#</th>
							<th scope="col">Player</th>
							<th scope="col">Team</th>
							<th scope="col">GP</th>
							<th scope="col">{ STAT_LABELS[ statKey ] }</th>
						</tr>
					</thead>
					<tbody>
						{ rows.map( ( row, i ) => (
							<tr key={ row.player_id }>
								<td>{ i + 1 }</td>
								<td>{ row.player }</td>
								<td>{ row.team }</td>
								<td>{ row.gp }</td>
								<td className="splm-table__pts">{ row.value }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
		</section>
	);
}

export default function Leaders( { season } ) {
	const [ data, setData ] = useState( null );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ division, setDivision ] = useState( 0 );
	const [ windowWeeks, setWindowWeeks ] = useState( 0 );
	const [ includePlayoffs, setIncludePlayoffs ] = useState( false );

	useEffect( () => {
		if ( ! season ) return undefined;
		let cancelled = false;
		setLoading( true );
		setError( '' );
		fetchLeaders( season, { windowWeeks, includePlayoffs } )
			.then( ( d ) => {
				if ( cancelled ) return;
				setData( d );
				setLoading( false );
			} )
			.catch( ( err ) => {
				if ( cancelled ) return;
				setError( err?.message || 'Failed to load leaders' );
				setLoading( false );
			} );
		return () => { cancelled = true; };
	}, [ season, windowWeeks, includePlayoffs ] );

	if ( ! season ) {
		return (
			<div className="splm-leaders">
				<h2>Leaders <HelpLink topic="leaders" /></h2>
				<p className="splm-empty">Select a season to see leaders.</p>
			</div>
		);
	}

	const divisions = data?.divisions || [];
	const active = division
		? divisions.find( ( d ) => d.id === division )?.leaders
		: data?.overall;

	return (
		<div className="splm-leaders">
			<h2>Leaders{ data ? ` — ${ data.season.name }` : '' } <HelpLink topic="leaders" /></h2>
			{ error && <div className="splm-alert splm-alert--warning" role="alert">{ error }</div> }

			<section className="splm-card">
				<label htmlFor="splm-leaders-division">Division</label>
				<select
					id="splm-leaders-division"
					value={ division }
					onChange={ ( e ) => setDivision( Number( e.target.value ) ) }
				>
					<option value={ 0 }>All divisions</option>
					{ divisions.map( ( d ) => (
						<option key={ d.id } value={ d.id }>{ d.name }</option>
					) ) }
				</select>

				<label htmlFor="splm-leaders-window">Range</label>
				<select
					id="splm-leaders-window"
					value={ windowWeeks }
					onChange={ ( e ) => setWindowWeeks( Number( e.target.value ) ) }
				>
					<option value={ 0 }>Full season</option>
					<option value={ 4 }>Last 4 weeks</option>
					<option value={ 8 }>Last 8 weeks</option>
				</select>

				<label className="splm-checkbox">
					<input
						type="checkbox"
						checked={ includePlayoffs }
						onChange={ () => setIncludePlayoffs( ( v ) => ! v ) }
					/>
					Include playoff games
				</label>
			</section>

			{ loading && <div className="splm-loading">Loading leaders...</div> }

			{ ! loading && active && STAT_ORDER.every( ( k ) => ! active[ k ]?.length ) && (
				<section className="splm-card">
					<p className="splm-muted">No player statistics recorded for this selection yet. Leaders appear once game player-stats are entered.</p>
				</section>
			) }

			{ ! loading && active && STAT_ORDER.map( ( k ) => (
				<Board key={ k } statKey={ k } rows={ active[ k ] } />
			) ) }
		</div>
	);
}
