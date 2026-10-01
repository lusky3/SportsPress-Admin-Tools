import { useState, useEffect, useRef } from '@wordpress/element';
import { searchPlayers } from '../lib/api';

// Labelled player search for forms (the header search navigates away; this one
// selects). Same plain result-button pattern as the header search: no combobox
// roles, so Tab + Enter/Space work natively. The server floor is 3 characters.
const MIN_CHARS = 3;
const DEBOUNCE_MS = 300;

// Module-level counter: stable, unique ids per instance without randomness
// during render.
let pickerCount = 0;

// Debounced, cancel-safe search. Below the minimum length it is idle.
function usePlayerSearch( term ) {
	const [ state, setState ] = useState( { results: [], loading: false, failed: false } );

	useEffect( () => {
		if ( term.length < MIN_CHARS ) {
			setState( { results: [], loading: false, failed: false } );
			return undefined;
		}
		let cancelled = false;
		setState( ( prev ) => ( { ...prev, loading: true, failed: false } ) );
		const timer = setTimeout( () => {
			searchPlayers( term ).then( ( found ) => {
				if ( ! cancelled ) setState( { results: found, loading: false, failed: false } );
			} ).catch( () => {
				if ( ! cancelled ) setState( { results: [], loading: false, failed: true } );
			} );
		}, DEBOUNCE_MS );
		return () => {
			cancelled = true;
			clearTimeout( timer );
		};
	}, [ term ] );

	return state;
}

function statusText( term, { results, loading, failed } ) {
	if ( term.length < MIN_CHARS ) return `Type at least ${ MIN_CHARS } letters`;
	if ( loading ) return 'Searching…';
	if ( failed ) return 'Search failed — try again';
	if ( results.length === 0 ) return 'No players found';
	return results.length === 1 ? '1 player found' : `${ results.length } players found`;
}

function usePickerId( id ) {
	const idRef = useRef( null );
	if ( idRef.current === null ) {
		pickerCount += 1;
		idRef.current = id || `splm-player-picker-${ pickerCount }`;
	}
	return idRef.current;
}

export default function PlayerPicker( { onSelect, label = 'Find a player', autoFocus = false, id } ) {
	const inputId = usePickerId( id );
	const statusId = `${ inputId }-status`;
	const inputRef = useRef( null );

	const [ query, setQuery ] = useState( '' );
	const term = query.trim();
	const search = usePlayerSearch( term );
	const { results, loading } = search;
	const status = statusText( term, search );

	// Focus on mount when asked (the autoFocus attribute is avoided on purpose).
	useEffect( () => {
		if ( autoFocus ) inputRef.current?.focus();
	}, [ autoFocus ] );

	return (
		<div className="splm-player-picker">
			<label htmlFor={ inputId }>{ label }</label>
			<input
				id={ inputId }
				ref={ inputRef }
				type="search"
				className="splm-player-picker__input"
				value={ query }
				onChange={ ( e ) => setQuery( e.target.value ) }
				aria-describedby={ statusId }
				autoComplete="off"
			/>
			<p id={ statusId } className="splm-player-picker__status" aria-live="polite">{ status }</p>
			{ ! loading && results.length > 0 && (
				<ul className="splm-player-picker__results">
					{ results.map( ( p ) => (
						<li key={ p.id }>
							<button
								type="button"
								onClick={ () => onSelect( { id: p.id, name: p.name, team_name: p.team_name || '' } ) }
							>
								<strong>{ p.name }</strong>
								{ p.team_name && <span> — { p.team_name }</span> }
								{ p.number && <span> #{ p.number }</span> }
							</button>
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
}
