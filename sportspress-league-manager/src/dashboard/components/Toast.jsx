/**
 * In-app toast (UI-13) — replaces blocking window.alert for success/info
 * feedback. Announces via role=status / aria-live=polite and auto-dismisses.
 */
import { useEffect } from '@wordpress/element';

export default function Toast( { message, type = 'success', onDismiss, duration = 4000 } ) {
	useEffect( () => {
		if ( ! message ) return undefined;
		const t = setTimeout( () => onDismiss?.(), duration );
		return () => clearTimeout( t );
	}, [ message, duration, onDismiss ] );

	if ( ! message ) return null;

	return (
		<div
			className={ `splm-toast${ type === 'error' ? ' splm-toast--error' : '' }` }
			role="status"
			aria-live="polite"
		>
			<span>{ message }</span>
			<button
				type="button"
				className="splm-toast__close"
				onClick={ () => onDismiss?.() }
				aria-label="Dismiss notification"
			>
				✕
			</button>
		</div>
	);
}
