import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { createPortal } from 'react-dom';

const FOCUSABLE = 'button:not([disabled]), [href], input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])';

/** Keep Tab inside `box`: from the last control to the first, and back. */
function trapTab( e: KeyboardEvent, box: HTMLElement, head: HTMLElement | null ): void {
	const items = [ ...box.querySelectorAll<HTMLElement>( FOCUSABLE ) ];
	const first = items[ 0 ];
	const last = items[ items.length - 1 ];
	if ( e.shiftKey && ( document.activeElement === first || document.activeElement === head ) ) {
		e.preventDefault();
		last?.focus();
	} else if ( ! e.shiftKey && document.activeElement === last ) {
		e.preventDefault();
		first?.focus();
	}
}

/**
 * The bottom sheet: a modal dialog pinned to the thumb end of the screen. Everything behind it is
 * inert, Tab stays inside, Escape or Cancel closes it (never a stray tap outside), and focus goes
 * back to the button that opened it.
 */
export function Sheet( { title, onClose, onBack, busy, children }: { title: string; onClose: () => void; onBack?: () => void; busy?: boolean; children: ReactNode } ) {
	const head = useRef<HTMLHeadingElement>( null );
	const box = useRef<HTMLDivElement>( null );
	const close = useRef( onClose );
	close.current = onClose;
	// Whatever had focus when the sheet first rendered (the button that opened it).
	const [ opener ] = useState( () => document.activeElement as HTMLElement | null );

	useEffect( () => {
		head.current?.focus();
	}, [ title ] );

	useEffect( () => {
		const back = box.current?.parentElement;
		const behind = [ ...document.body.children ].filter( ( el ) => el !== back && ! el.hasAttribute( 'inert' ) );
		behind.forEach( ( el ) => el.setAttribute( 'inert', '' ) );
		const key = ( e: KeyboardEvent ) => {
			if ( e.key === 'Escape' ) {
				close.current();
			} else if ( e.key === 'Tab' && box.current ) {
				trapTab( e, box.current, head.current );
			}
		};
		window.addEventListener( 'keydown', key );
		return () => {
			window.removeEventListener( 'keydown', key );
			behind.forEach( ( el ) => el.removeAttribute( 'inert' ) );
			if ( opener?.isConnected ) {
				opener.focus();
			}
		};
	}, [ opener ] );

	return createPortal(
		<div className="sheet-back">
			<div ref={ box } className="sheet" role="dialog" aria-modal="true" aria-labelledby="sheet-h" aria-busy={ busy || undefined }>
				<div className="sheet__bar">
					{ onBack ? (
						<button type="button" className="sheet__nav" onClick={ onBack }>
							‹ Back
						</button>
					) : (
						<span />
					) }
					<h2 id="sheet-h" ref={ head } tabIndex={ -1 }>
						{ title }
					</h2>
					<button type="button" className="sheet__nav" onClick={ onClose }>
						Cancel
					</button>
				</div>
				<div className="sheet__body">{ children }</div>
			</div>
		</div>,
		document.body
	);
}
