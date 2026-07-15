import { useState, useEffect } from '@wordpress/element';
import HelpLink from '../components/HelpLink';
import { fetchDivisionBalance } from '../lib/api';

export default function DivisionBalance( { season } ) {
	const [ data, setData ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	// { divisionName, level, players:[{id,name,team}] } | null
	const [ modal, setModal ] = useState( null );

	const adminUrl = window.splmDashboard?.adminUrl || '/wp-admin/';
	const editUrl = ( id ) => `${ adminUrl }post.php?post=${ id }&action=edit`;

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

	// Close the modal on Escape.
	useEffect( () => {
		if ( ! modal ) return undefined;
		const onKey = ( e ) => { if ( e.key === 'Escape' ) setModal( null ); };
		document.addEventListener( 'keydown', onKey );
		return () => document.removeEventListener( 'keydown', onKey );
	}, [ modal ] );

	if ( loading ) return <div className="splm-loading">Loading division balance...</div>;

	return (
		<div className="splm-division-balance">
			<h2>Division Balance <HelpLink topic="div-balance" /></h2>
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
								{ Object.entries( div.distribution ).map( ( [ level, count ] ) => {
									const players = div.players_by_level?.[ level ] || [];
									const clickable = count > 0 && players.length > 0;
									return (
										<button
											key={ level }
											type="button"
											className={ `splm-skill-dist__bar${ clickable ? ' splm-skill-dist__bar--clickable' : '' }` }
											disabled={ ! clickable }
											onClick={ () => clickable && setModal( { divisionName: div.division.name, level, players } ) }
											title={ clickable ? `Level ${ level }: ${ count } player(s) — click to view` : `Level ${ level }: ${ count } players` }
											aria-label={ `Level ${ level }, ${ count } players${ clickable ? ' — view list' : '' }` }
										>
											<span className="splm-skill-dist__label">{ level }</span>
											<span className="splm-skill-dist__fill" style={ { width: `${ div.rated ? ( count / div.rated ) * 100 : 0 }%` } } />
											<span className="splm-skill-dist__count">{ count }</span>
										</button>
									);
								} ) }
							</div>
						</section>
					) ) }
				</div>
			) }

			{ modal && (
				<div className="splm-modal-overlay" onClick={ () => setModal( null ) }>
					<div className="splm-modal" role="dialog" aria-modal="true" aria-label={ `${ modal.divisionName }, skill level ${ modal.level }` } onClick={ ( e ) => e.stopPropagation() }>
						<h3>{ modal.divisionName } — Skill level { modal.level } <span className="splm-muted">({ modal.players.length })</span></h3>
						<ul className="splm-player-list">
							{ modal.players.map( ( p ) => (
								<li key={ p.id }>
									<a href={ editUrl( p.id ) } target="_blank" rel="noopener noreferrer">{ p.name }</a>
									{ p.team ? <span className="splm-muted"> — { p.team }</span> : null }
								</li>
							) ) }
						</ul>
						<div className="splm-modal__actions">
							<button type="button" className="splm-btn" onClick={ () => setModal( null ) }>Close</button>
						</div>
					</div>
				</div>
			) }
		</div>
	);
}
