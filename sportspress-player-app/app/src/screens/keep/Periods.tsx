import { useRef } from 'react';
import type { KeyboardEvent as ReactKeyboardEvent } from 'react';
import { periodLabel } from '../../lib/live';

/** The period control: a radio group (arrow keys move and choose, one tab stop). */
export function Periods( { value, onChange }: { value: number; onChange: ( p: number ) => void } ) {
	const group = useRef<HTMLDivElement>( null );
	const keys = ( e: ReactKeyboardEvent ) => {
		const step = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1, Home: -9, End: 9 }[ e.key ];
		if ( ! step ) {
			return;
		}
		e.preventDefault();
		const next = Math.min( 5, Math.max( 1, value + step ) );
		onChange( next );
		group.current?.querySelectorAll<HTMLElement>( '[role=radio]' )[ next - 1 ]?.focus();
	};
	return (
		<div ref={ group } className="periods" role="radiogroup" aria-label="Period" onKeyDown={ keys }>
			{ [ 1, 2, 3, 4, 5 ].map( ( p ) => (
				<button key={ p } type="button" role="radio" aria-checked={ p === value } tabIndex={ p === value ? 0 : -1 } className="periods__opt" onClick={ () => onChange( p ) }>
					{ periodLabel( p ).replace( /^P/, '' ) }
				</button>
			) ) }
		</div>
	);
}
