import { useId, useState } from 'react';
import type { FormEvent } from 'react';
import { apiSend } from '../api';
import { errorText } from '../lib/errors';
import { relativeDay, timeLabel } from '../lib/dates';
import { callLabel, venueShort } from '../lib/games';
import type { Game, Position, SpareCall, SpareCallWithGame } from '../types';
import { TwoTap } from './TwoTap';

/** "Tonight 9:45 PM · Wave Twin Rinks · Black" for a call shown away from its game. */
export function GameLine( { game }: { game: Game } ) {
	const where = venueShort( game.venue );
	return (
		<p className="call__when">
			{ relativeDay( game.start, new Date() ) } { game.status === 'tbd' ? 'time TBD' : timeLabel( game.start ) }
			{ where ? ` · ${ where }` : '' }
		</p>
	);
}

const NOTE_MAX = 140;

/** "Open · 1 of 2 found", "Filled", "Cancelled". */
export function callStatus( call: Pick<SpareCall, 'status' | 'accepted' | 'needed'> ): string {
	if ( call.status === 'filled' ) {
		return call.needed === 1 ? 'Filled. Your spare is in.' : `Filled. All ${ call.needed } spares are in.`;
	}
	if ( call.status === 'cancelled' ) {
		return 'Cancelled';
	}
	return call.accepted > 0 ? `${ call.accepted } of ${ call.needed } found so far` : 'Waiting for a spare';
}

interface CallListProps {
	calls: SpareCall[];
	/** The call came back cancelled from the server. */
	onCancelled: ( call: SpareCall ) => void;
}

/** The team's calls for a game, each with a two-tap cancel when this person may cancel it. */
export function CallList( { calls, onCancelled }: CallListProps ) {
	const [ busy, setBusy ] = useState( 0 );
	const [ error, setError ] = useState( '' );

	const cancel = async ( call: SpareCall ) => {
		setBusy( call.id );
		setError( '' );
		try {
			const res = await apiSend<{ call: SpareCall }>( 'POST', `/spare-calls/${ call.id }/cancel` );
			onCancelled( res.call );
		} catch ( e ) {
			setError( errorText( e, 'That call could not be cancelled. Try again.' ) );
		} finally {
			setBusy( 0 );
		}
	};

	if ( ! calls.length ) {
		return null;
	}
	return (
		<div>
			<ul className="calls">
				{ calls.map( ( call ) => (
					<li key={ call.id } className={ `call call--${ call.status }` }>
						<div>
							{ 'game' in call ? <GameLine game={ ( call as SpareCallWithGame ).game } /> : null }
							<p className="call__what">{ callLabel( call ) }</p>
							<p className="call__status">{ callStatus( call ) }</p>
							{ call.note ? <p className="call__note">“{ call.note }”</p> : null }
						</div>
						{ call.can_cancel && call.status !== 'cancelled' ? (
							<TwoTap label="Cancel call" confirm="Tap again to cancel" disabled={ busy === call.id } onConfirm={ () => void cancel( call ) } />
						) : null }
					</li>
				) ) }
			</ul>
			{ error ? (
				<p className="inline-error" role="alert">
					{ error }
				</p>
			) : null }
		</div>
	);
}

interface CallFormProps {
	gameId: number;
	teamId: number;
	onPosted: ( call: SpareCall ) => void;
	onClose: () => void;
}

/** Ask the spare pool for a skater or a goalie: position, how many (1 to 3), an optional note. */
export function CallForm( { gameId, teamId, onPosted, onClose }: CallFormProps ) {
	const id = useId();
	const [ position, setPosition ] = useState<Position>( 'skater' );
	const [ needed, setNeeded ] = useState( 1 );
	const [ note, setNote ] = useState( '' );
	const [ sending, setSending ] = useState( false );
	const [ error, setError ] = useState( '' );

	const submit = async ( event: FormEvent ) => {
		event.preventDefault();
		setSending( true );
		setError( '' );
		try {
			const res = await apiSend<{ call: SpareCall }>( 'POST', `/games/${ gameId }/spare-calls`, { team_id: teamId, position, needed, note: note.trim() } );
			onPosted( res.call );
		} catch ( e ) {
			setError( errorText( e, 'The call didn’t go out. Try again.', { spbn_closed: 'This game is over or called off, so it can’t take a spare call.' } ) );
			setSending( false );
		}
	};

	return (
		<form className="call-form" onSubmit={ submit }>
			<fieldset className="seg">
				<legend>Who do you need?</legend>
				{ ( [ 'skater', 'goalie' ] as const ).map( ( p ) => (
					<label key={ p } className="seg__opt">
						<input type="radio" name={ `${ id }-pos` } value={ p } checked={ position === p } onChange={ () => setPosition( p ) } />
						<span>{ p === 'skater' ? 'Skater' : 'Goalie' }</span>
					</label>
				) ) }
			</fieldset>
			<div className="field">
				<label htmlFor={ `${ id }-n` }>How many?</label>
				<select id={ `${ id }-n` } value={ needed } onChange={ ( e ) => setNeeded( Number( e.target.value ) ) }>
					<option value={ 1 }>1</option>
					<option value={ 2 }>2</option>
					<option value={ 3 }>3</option>
				</select>
			</div>
			<div className="field">
				<label htmlFor={ `${ id }-note` }>Note for the spare (optional)</label>
				<textarea id={ `${ id }-note` } rows={ 2 } maxLength={ NOTE_MAX } value={ note } placeholder="Light jerseys tonight" onChange={ ( e ) => setNote( e.target.value ) } aria-describedby={ `${ id }-left` } />
				<p className="field__hint" id={ `${ id }-left` }>
					{ NOTE_MAX - note.length } characters left
				</p>
			</div>
			<p className="field__hint">We email players who said they’re happy to spare and are free that night.</p>
			{ error ? (
				<p className="inline-error" role="alert">
					{ error }
				</p>
			) : null }
			<div className="actions">
				<button type="submit" className="btn-primary" disabled={ sending }>
					<span>{ sending ? 'Posting…' : 'Post the call' }</span>
				</button>
				<button type="button" className="btn-link" onClick={ onClose }>
					Never mind
				</button>
			</div>
		</form>
	);
}
