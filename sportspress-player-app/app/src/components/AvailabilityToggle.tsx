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
	const [ busy, setBusy ] = useState( false );
	const buttons = useRef<Array<HTMLButtonElement | null>>( [] );
	/*
	 * Saves go one at a time, so the server sees them in order. A choice made while one is
	 * in flight waits (only the latest waits) and is sent when it settles. `saved` is the last
	 * value the server confirmed; a failure always goes back to it, so the toggle ends on what
	 * the server holds.
	 */
	const saved = useRef( value );
	const inFlight = useRef( false );
	const queued = useRef<AvailabilityStatus | null>( null );

	useEffect( () => {
		saved.current = value;
		if ( ! inFlight.current ) {
			setShown( value );
		}
	}, [ value ] );

	const send = async ( next: AvailabilityStatus ) => {
		inFlight.current = true;
		setBusy( true );
		setError( '' );
		let failure: unknown = null;
		try {
			await onChange( next );
			saved.current = next;
		} catch ( e ) {
			failure = e ?? new Error( 'failed' );
		}
		inFlight.current = false;
		const waiting = queued.current;
		queued.current = null;
		if ( waiting !== null && waiting !== saved.current ) {
			void send( waiting );
			return;
		}
		setBusy( false );
		setShown( saved.current );
		if ( failure ) {
			setError(
				failure instanceof ApiError && failure.code === 'sppa_locked'
					? errorText( failure )
					: `That didn’t save. ${ failure instanceof ApiError && failure.code === 'offline' ? 'Check your signal and try again.' : 'Try again.' }`
			);
		}
	};

	const choose = ( next: AvailabilityStatus ) => {
		if ( disabled || next === shown ) {
			return;
		}
		setShown( next );
		setError( '' );
		if ( inFlight.current ) {
			queued.current = next;
			return;
		}
		void send( next );
	};

	const onKey = ( event: KeyboardEvent, index: number ) => {
		const step = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[ event.key ];
		if ( ! step ) {
			return;
		}
		event.preventDefault();
		const to = ( index + step + OPTIONS.length ) % OPTIONS.length;
		buttons.current[ to ]?.focus();
		choose( OPTIONS[ to ].value );
	};

	const focusable = Math.max( 0, OPTIONS.findIndex( ( o ) => o.value === shown ) );
	return (
		<div className="toggle-wrap">
			<div className="toggle" role="radiogroup" aria-label={ label } aria-disabled={ disabled || undefined } aria-busy={ busy || undefined }>
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
						onClick={ () => choose( option.value ) }
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
