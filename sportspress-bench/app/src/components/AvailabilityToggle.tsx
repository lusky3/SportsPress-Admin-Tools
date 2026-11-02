import { useEffect, useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { ApiError } from '../api';
import { errorText } from '../lib/errors';
import type { AvailabilityStatus } from '../types';

const OPTIONS: Array<{ value: AvailabilityStatus; label: string }> = [
	{ value: 'in', label: 'In' },
	{ value: 'out', label: 'Out' },
	{ value: 'maybe', label: 'Maybe' },
];

interface AvailabilityToggleProps {
	/** The saved answer; null when the player has not answered. */
	value: AvailabilityStatus | null;
	/** Save the answer. The toggle shows it at once and goes back if this rejects. */
	onChange: ( value: AvailabilityStatus ) => Promise<unknown> | void;
	disabled?: boolean;
	/** Accessible name for the group. */
	label?: string;
}

/** In / Out / Maybe as one radio group: three 44px segments, arrow keys move and choose, optimistic with rollback. */
export function AvailabilityToggle( { value, onChange, disabled = false, label = 'Are you playing?' }: AvailabilityToggleProps ) {
	const [ shown, setShown ] = useState( value );
	const [ error, setError ] = useState( '' );
	const buttons = useRef<Array<HTMLButtonElement | null>>( [] );
	// The latest choice wins: an older save that fails late must not undo a newer one.
	const attempt = useRef( 0 );

	useEffect( () => {
		setShown( value );
	}, [ value ] );

	const choose = async ( next: AvailabilityStatus ) => {
		if ( disabled || next === shown ) {
			return;
		}
		const before = shown;
		const mine = ++attempt.current;
		setShown( next );
		setError( '' );
		try {
			await onChange( next );
		} catch ( e ) {
			if ( mine === attempt.current ) {
				setShown( before );
				setError(
					e instanceof ApiError && e.code === 'spbn_locked'
						? errorText( e )
						: `That didn’t save. ${ e instanceof ApiError && e.code === 'offline' ? 'Check your signal and try again.' : 'Try again.' }`
				);
			}
		}
	};

	const onKey = ( event: KeyboardEvent, index: number ) => {
		const step = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[ event.key ];
		if ( ! step ) {
			return;
		}
		event.preventDefault();
		const to = ( index + step + OPTIONS.length ) % OPTIONS.length;
		buttons.current[ to ]?.focus();
		void choose( OPTIONS[ to ].value );
	};

	const focusable = Math.max( 0, OPTIONS.findIndex( ( o ) => o.value === shown ) );
	return (
		<div className="toggle-wrap">
			<div className="toggle" role="radiogroup" aria-label={ label } aria-disabled={ disabled || undefined }>
				{ OPTIONS.map( ( option, i ) => (
					<button
						key={ option.value }
						ref={ ( el ) => {
							buttons.current[ i ] = el;
						} }
						type="button"
						role="radio"
						className={ `toggle__opt toggle__opt--${ option.value }` }
						aria-checked={ shown === option.value }
						tabIndex={ i === focusable ? 0 : -1 }
						disabled={ disabled }
						onClick={ () => void choose( option.value ) }
						onKeyDown={ ( e ) => onKey( e, i ) }
					>
						<span>{ option.label }</span>
					</button>
				) ) }
			</div>
			{ error ? (
				<p className="inline-error" role="alert">
					{ error }
				</p>
			) : null }
		</div>
	);
}
