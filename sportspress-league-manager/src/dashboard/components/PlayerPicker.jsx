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

export default function PlayerPicker( { onSelect, label = 'Find a player', autoFocus = false, id } ) {
	const idRef = useRef( null );
	if ( idRef.current === null ) {
		pickerCount += 1;
		idRef.current = id || `splm-player-picker-${ pickerCount }`;
	}
	const inputId = idRef.current;
	const statusId = `${ inputId }-status`;

	const [ query, setQuery ] = useState( '' );
	const [ results, setResults ] = useState( [] );
	const [ loading, setLoading ] = useState( false );
	const [ failed, setFailed ] = useState( false );

	const term = query.trim();

	useEffect( () => {
		if ( term.length < MIN_CHARS ) {
			setResults( [] );
			setLoading( false );
			setFailed( false );
			return undefined;
		}
		let cancelled = false;
		setLoading( true );
		setFailed( false );
		const timer = setTimeout( () => {
			searchPlayers( term ).then( ( found ) => {
				if ( cancelled ) return;
				setResults( found );
				setLoading( false );
			} ).catch( () => {
				if ( cancelled ) return;
				setResults( [] );
				setFailed( true );
				setLoading( false );
			} );
		}, DEBOUNCE_MS );
		return () => {
			cancelled = true;
			clearTimeout( timer );
		};
	}, [ term ] );

	let status;
	if ( term.length < MIN_CHARS ) {
		status = `Type at least ${ MIN_CHARS } letters`;
	} else if ( loading ) {
		status = 'Searching…';
	} else if ( failed ) {
		status = 'Search failed — try again';
	} else if ( results.length === 0 ) {
		status = 'No players found';
	} else {
		status = results.length === 1 ? '1 player found' : `${ results.length } players found`;
	}

	return (
		<div className="splm-player-picker">
			<label htmlFor={ inputId }>{ label }</label>
			<input
				id={ inputId }
				type="search"
				className="splm-player-picker__input"
				value={ query }
				onChange={ ( e ) => setQuery( e.target.value ) }
				aria-describedby={ statusId }
				autoComplete="off"
				autoFocus={ autoFocus }
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
