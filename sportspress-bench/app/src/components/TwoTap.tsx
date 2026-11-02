import { useEffect, useState } from 'react';

interface TwoTapProps {
	label: string;
	/** What the button says once armed, e.g. "Tap again to cancel". */
	confirm: string;
	onConfirm: () => void;
	disabled?: boolean;
	className?: string;
}

/** A button that needs a second tap within a few seconds. Used instead of confirm() for anything undoable only by hand. */
export function TwoTap( { label, confirm, onConfirm, disabled, className = 'btn-quiet' }: TwoTapProps ) {
	const [ armed, setArmed ] = useState( false );

	useEffect( () => {
		if ( ! armed ) {
			return;
		}
		const t = window.setTimeout( () => setArmed( false ), 5000 );
		return () => window.clearTimeout( t );
	}, [ armed ] );

	return (
		<>
			<button
				type="button"
				className={ armed ? `${ className } is-armed` : className }
				disabled={ disabled }
				onClick={ () => {
					if ( armed ) {
						setArmed( false );
						onConfirm();
					} else {
						setArmed( true );
					}
				} }
				onBlur={ () => setArmed( false ) }
			>
				{ armed ? confirm : label }
			</button>
			{ /* A focused button's new label is not always read out; this says it. */ }
			<span className="sr-only" role="status" aria-live="polite">
				{ armed ? confirm : '' }
			</span>
		</>
	);
}
