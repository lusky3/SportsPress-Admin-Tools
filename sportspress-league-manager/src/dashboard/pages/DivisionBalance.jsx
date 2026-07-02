import { useState, useEffect } from '@wordpress/element';
import { fetchDivisionBalance } from '../lib/api';

export default function DivisionBalance( { season } ) {
	const [ data, setData ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		let cancelled = false;
		setLoading( true );
		fetchDivisionBalance( season ).then( ( d ) => {
			if ( ! cancelled ) { setData( d ); setLoading( false ); }
		} ).catch( ( err ) => {
			if ( ! cancelled ) { setError( err?.message || 'Failed to load' ); setLoading( false ); }
		} );
		return () => { cancelled = true; };
	}, [ season ] );

	if ( loading ) return <div className="splm-loading">Loading division balance...</div>;

	return (
		<div className="splm-division-balance">
			<h2>Division Balance</h2>
			{ error && <div className="splm-alert splm-alert--warning" role="alert">{ error }</div> }
			{ data.length === 0 ? (
				<p className="splm-empty">No divisions with rated players found.</p>
			) : (
				<div className="splm-grid">
					{ data.map( ( div ) => (
						<section key={ div.division.id } className="splm-card">
							<h3>{ div.division.name }</h3>
							<div className="splm-summary-stats">
								<div className="splm-summary-stats__item">
									<span className="splm-summary-stats__value">{ div.teams }</span>
									<span className="splm-summary-stats__label">Teams</span>
								</div>
								<div className="splm-summary-stats__item">
									<span className="splm-summary-stats__value">{ div.rated }/{ div.players }</span>
									<span className="splm-summary-stats__label">Rated</span>
								</div>
								<div className="splm-summary-stats__item">
									<span className="splm-summary-stats__value">{ div.skill_avg }</span>
									<span className="splm-summary-stats__label">Avg Skill</span>
								</div>
								<div className="splm-summary-stats__item">
									<span className="splm-summary-stats__value">{ div.skill_min }–{ div.skill_max }</span>
									<span className="splm-summary-stats__label">Range</span>
								</div>
							</div>
							<div className="splm-skill-dist">
								{ Object.entries( div.distribution ).map( ( [ level, count ] ) => (
									<div key={ level } className="splm-skill-dist__bar" title={ `Level ${ level }: ${ count } players` }>
										<span className="splm-skill-dist__label">{ level }</span>
										<div className="splm-skill-dist__fill" style={ { width: `${ div.rated ? ( count / div.rated ) * 100 : 0 }%` } } />
										<span className="splm-skill-dist__count">{ count }</span>
									</div>
								) ) }
							</div>
						</section>
					) ) }
				</div>
			) }
		</div>
	);
}
