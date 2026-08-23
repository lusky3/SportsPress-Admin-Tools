import { useState, useEffect } from '@wordpress/element';
import { fetchAudit, applyAuditFix } from '../lib/api';

const ICONS = { error: '❌', warning: '⚠️', info: 'ℹ️' };

// Season-configuration problems that the dashboard can repair itself, unlike
// the checks below them which only point at the record to edit by hand.
export default function SeasonAudit( { season } ) {
	const [ report, setReport ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( '' );
	const [ result, setResult ] = useState( '' );
	const [ reload, setReload ] = useState( 0 );

	const adminUrl = window.splmDashboard?.adminUrl || '/wp-admin/';
	const editUrl = ( id ) => `${ adminUrl }post.php?post=${ id }&action=edit`;

	useEffect( () => {
		if ( ! season ) return undefined;
		let cancelled = false;
		fetchAudit( season )
			.then( ( data ) => {
				if ( cancelled ) return;
				setReport( data );
			} )
			.catch( ( err ) => {
				if ( cancelled ) return;
				setError( err?.message || 'Failed to run the season audit' );
			} );
		return () => { cancelled = true; };
	}, [ season, reload ] );

	const runFix = ( check ) => {
		setBusy( check.key );
		setResult( '' );
		setError( '' );
		applyAuditFix( season, check.key )
			.then( ( res ) => {
				setBusy( '' );
				setResult( `${ check.label }: repaired ${ res.fixed } record${ res.fixed === 1 ? '' : 's' }.` );
				setReload( ( n ) => n + 1 );
			} )
			.catch( ( err ) => {
				setBusy( '' );
				setError( err?.message || 'Could not apply the fix' );
			} );
	};

	if ( ! season || ! report ) return null;

	const found = ( report.checks || [] ).filter( ( c ) => c.count > 0 );

	return (
		<section className="splm-audit">
			<h3>Season configuration</h3>
			<p className="splm-muted">
				Problems that make current-season data look wrong even though the games,
				teams and players are correct. These can be repaired from here.
			</p>

			{ error && <div className="splm-alert splm-alert--warning" role="alert">{ error }</div> }
			{ result && <div className="splm-alert" role="status">{ result }</div> }

			{ found.length === 0 ? (
				<p className="splm-empty">Season configuration looks correct. ✓</p>
			) : (
				found.map( ( check ) => (
					<div key={ check.key } className={ `splm-health-alert splm-health-alert--${ check.severity }` }>
						<div className="splm-health-alert__head">
							<span className="splm-health-alert__icon" aria-hidden="true">{ ICONS[ check.severity ] }</span>
							<span className="splm-health-alert__message">{ check.label }</span>
							<span className="splm-health-alert__count">{ check.count } affected</span>
						</div>
						<p className="splm-health-alert__fix">{ check.problem }</p>
						<details className="splm-health-alert__details">
							<summary>Show { check.count } affected { check.applies_to }</summary>
							<ul className="splm-health-alert__list">
								{ check.items.map( ( it ) => (
									<li key={ it.id }>
										<a href={ editUrl( it.id ) } target="_blank" rel="noopener noreferrer">
											{ it.title } ↗
										</a>
										{ it.detail && <span className="splm-muted"> — { it.detail }</span> }
									</li>
								) ) }
							</ul>
						</details>
						<button
							type="button"
							className="splm-btn splm-btn--primary"
							onClick={ () => runFix( check ) }
							disabled={ busy === check.key }
						>
							{ busy === check.key
								? 'Repairing…'
								: `${ check.fix_label } — fix all ${ check.count }` }
						</button>
					</div>
				) )
			) }
		</section>
	);
}
