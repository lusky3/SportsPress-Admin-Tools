import { useRef, useEffect } from '@wordpress/element';

// UX-9: focus trap + focus move-in / restore-on-close for modal dialogs.
// Returns a ref to attach to the dialog container.
//
// NOTE: the effect depends on the identity of the `onClose` argument, so callers
// must pass a `useCallback`-memoised function — an inline arrow re-runs the
// effect on every render, which re-moves focus and restores it to the trigger.
export default function useFocusTrap( onClose ) {
	const ref = useRef( null );
	useEffect( () => {
		const node = ref.current;
		if ( ! node ) return undefined;
		const previouslyFocused = document.activeElement;
		const selector = 'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])';
		const focusables = () => Array.from( node.querySelectorAll( selector ) ).filter( ( el ) => el.offsetParent !== null || el === document.activeElement );
		// Move focus into the dialog.
		const first = focusables()[ 0 ];
		( first || node ).focus();

		const onKeyDown = ( e ) => {
			if ( e.key === 'Escape' ) { onClose(); return; }
			if ( e.key !== 'Tab' ) return;
			const els = focusables();
			if ( ! els.length ) { e.preventDefault(); return; }
			const firstEl = els[ 0 ];
			const lastEl = els[ els.length - 1 ];
			if ( e.shiftKey && document.activeElement === firstEl ) {
				e.preventDefault();
				lastEl.focus();
			} else if ( ! e.shiftKey && document.activeElement === lastEl ) {
				e.preventDefault();
				firstEl.focus();
			}
		};
		node.addEventListener( 'keydown', onKeyDown );
		return () => {
			node.removeEventListener( 'keydown', onKeyDown );
			// Restore focus to the trigger.
			if ( previouslyFocused && typeof previouslyFocused.focus === 'function' ) {
				previouslyFocused.focus();
			}
		};
	}, [ onClose ] );
	return ref;
}
