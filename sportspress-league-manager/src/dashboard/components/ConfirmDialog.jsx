import { useEffect, useRef } from '@wordpress/element';
import useFocusTrap from './useFocusTrap';
import useUid from './useUid';

// In-page yes/no dialog (replaces window.confirm). Cancel is first in the tab
// order and takes focus on open, so a stray Enter never confirms a destructive
// action. Escape cancels. `onCancel` must be a useCallback-memoised function
// (see useFocusTrap).
export default function ConfirmDialog( { message, confirmLabel, danger = false, onConfirm, onCancel } ) {
	const titleId = useUid( 'splm-confirm' );
	const trapRef = useFocusTrap( onCancel );
	const cancelRef = useRef( null );

	// Declared after useFocusTrap so it runs after the trap's move-in focus.
	useEffect( () => {
		cancelRef.current?.focus();
	}, [] );

	return (
		<div className="splm-modal-overlay">
			<div
				ref={ trapRef }
				className="splm-modal"
				role="dialog"
				aria-modal="true"
				aria-labelledby={ titleId }
				tabIndex={ -1 }
			>
				<p id={ titleId }>{ message }</p>
				<div className="splm-modal__actions">
					<button ref={ cancelRef } type="button" className="splm-btn" onClick={ onCancel }>
						Cancel
					</button>
					<button
						type="button"
						className={ `splm-btn ${ danger ? 'splm-btn--danger' : 'splm-btn--primary' }` }
						onClick={ onConfirm }
					>
						{ confirmLabel }
					</button>
				</div>
			</div>
		</div>
	);
}
