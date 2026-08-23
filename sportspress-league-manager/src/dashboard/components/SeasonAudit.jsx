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
		// Clear the previous season's findings first: leaving them on screen
		// would show one season's numbers under another season's heading.
		setReport( null );
		setError( '' );
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
		// Every other bulk action in this dashboard confirms first, and this one
		// rewrites league records.
		const seasonName = report?.season?.name || 'this season';
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( `Repair ${ check.count } record(s) for ${ seasonName }?\n\n${ check.fix_label }.` ) ) {
			return;
		}
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

	if ( ! season ) return null;

	// A failed initial load leaves report null; showing nothing would hide the
	// failure entirely, so the error is rendered on its own.
	if ( ! report ) {
		return error ? (
			<section className="splm-audit">
				<h3>Season configuration</h3>
				<div className="splm-alert splm-alert--warning" role="alert">{ error }</div>
			</section>
		) : null;
	}

	const found = ( report.checks || [] ).filter( ( c ) => c.count > 0 );

	return (
		<section className="splm-audit">
			<h3>Season configuration — { report.season?.name }</h3>
			<p className="splm-muted">
				Problems that make { report.season?.name } data look wrong even though the
				games, teams and players are correct. These can be repaired from here.
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
							<summary>
								Show { check.items.length } of { check.count } affected { check.applies_to }
							</summary>
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
						{ check.capped && (
							<p className="splm-muted">
								More than { check.items.length } records match; repairing will fix
								every one, then re-run this check to see what remains.
							</p>
						) }
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
