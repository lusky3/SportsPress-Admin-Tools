import { useState, useEffect } from '@wordpress/element';
import { fetchTeams, compareTeams } from '../lib/api';

export default function TeamComparison( { season } ) {
	const [ teams, setTeams ] = useState( [] );
	const [ teamA, setTeamA ] = useState( '' );
	const [ teamB, setTeamB ] = useState( '' );
	const [ result, setResult ] = useState( null );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		fetchTeams( season ).then( setTeams ).catch( () => {} );
	}, [ season ] );

	const handleCompare = () => {
		if ( ! teamA || ! teamB || teamA === teamB ) return;
		setLoading( true );
		setError( '' );
		compareTeams( teamA, teamB, season ).then( ( d ) => {
			setResult( d );
			setLoading( false );
		} ).catch( ( err ) => {
			setError( err?.message || 'Failed to compare' );
			setLoading( false );
		} );
	};

	return (
		<div className="splm-team-compare">
			<h2>Team Comparison</h2>
			{ error && <div className="splm-alert splm-alert--warning" role="alert">{ error }</div> }

			<div className="splm-team-compare__selectors">
				<select className="splm-select" value={ teamA } onChange={ ( e ) => setTeamA( e.target.value ) } aria-label="Select team A">
					<option value="">Select team A...</option>
					{ teams.map( ( t ) => <option key={ t.id } value={ t.id }>{ t.name }</option> ) }
				</select>
				<span className="splm-team-compare__vs">vs</span>
				<select className="splm-select" value={ teamB } onChange={ ( e ) => setTeamB( e.target.value ) } aria-label="Select team B">
					<option value="">Select team B...</option>
					{ teams.map( ( t ) => <option key={ t.id } value={ t.id }>{ t.name }</option> ) }
				</select>
				<button className="splm-btn splm-btn--primary" onClick={ handleCompare } disabled={ ! teamA || ! teamB || teamA === teamB || loading }>
					{ loading ? 'Comparing...' : 'Compare' }
				</button>
			</div>

			{ result && (
				<div className="splm-grid">
					<section className="splm-card">
						<h3>Head to Head</h3>
						<div className="splm-summary-stats">
							<div className="splm-summary-stats__item splm-summary-stats__item--green">
								<span className="splm-summary-stats__value">{ result.head_to_head.a_wins }</span>
								<span className="splm-summary-stats__label">{ result.team_a.name } wins</span>
							</div>
							<div className="splm-summary-stats__item">
								<span className="splm-summary-stats__value">{ result.head_to_head.draws }</span>
								<span className="splm-summary-stats__label">Draws</span>
							</div>
							<div className="splm-summary-stats__item splm-summary-stats__item--red">
								<span className="splm-summary-stats__value">{ result.head_to_head.b_wins }</span>
								<span className="splm-summary-stats__label">{ result.team_b.name } wins</span>
							</div>
						</div>
					</section>

					<section className="splm-card">
						<h3>Roster Comparison</h3>
						<div className="splm-table-wrapper">
							<table className="splm-table">
								<thead><tr><th></th><th>{ result.team_a.name }</th><th>{ result.team_b.name }</th></tr></thead>
								<tbody>
									<tr><td>Players</td><td>{ result.team_a.players }</td><td>{ result.team_b.players }</td></tr>
									<tr><td>Avg Skill</td><td>{ result.team_a.avg_skill }</td><td>{ result.team_b.avg_skill }</td></tr>
									{ result.stat_keys.map( ( sk ) => (
										<tr key={ sk.key }>
											<td>{ sk.label }</td>
											<td>{ result.team_a.stats[ sk.key ] || 0 }</td>
											<td>{ result.team_b.stats[ sk.key ] || 0 }</td>
										</tr>
									) ) }
								</tbody>
							</table>
						</div>
					</section>
				</div>
			) }
		</div>
	);
}
