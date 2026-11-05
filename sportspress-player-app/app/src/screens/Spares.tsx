import { useEffect, useState } from 'react';
import { ApiError, apiSend } from '../api';
import { EmptyState } from '../components/EmptyState';
import { Problem, Skeleton } from '../components/Problem';
import { CallList, GameLine } from '../components/SpareCalls';
import { TwoTap } from '../components/TwoTap';
import { errorText } from '../lib/errors';
import type { Me, Position, SparePrefs, SparesPayload, SpareCallWithGame } from '../types';
import { invalidate, useResource } from '../useResource';

const PATH = '/spares';

/** "Ducks need a goalie", "Ducks need 2 skaters". */
function needLine( call: SpareCallWithGame ): string {
	const who = call.needed === 1 ? `a ${ call.position }` : `${ call.needed } ${ call.position }s`;
	return `${ call.game.team.name || 'A team' } need${ call.game.team.name ? '' : 's' } ${ who }`;
}

/** The "I'm happy to spare" switch and the positions. Saves on every change; goes back and says so if a save fails. */
function Prefs( { initial }: { initial: SparePrefs } ) {
	const [ prefs, setPrefs ] = useState( initial );
	const [ error, setError ] = useState( '' );

	// Follow the server when its copy changes (a refresh, or a change made on another device).
	const serverKey = `${ initial.enabled }:${ initial.positions.join( ',' ) }`;
	useEffect( () => {
		setPrefs( initial ); // keyed on the value, not the object, so a refetch of the same prefs is a no-op
	}, [ serverKey ] );

	const save = async ( next: SparePrefs ) => {
		const before = prefs;
		setPrefs( next );
		setError( '' );
		try {
			await apiSend( 'PUT', '/spares/prefs', next );
			invalidate( PATH );
			invalidate( '/me' );
		} catch ( e ) {
			setPrefs( before );
			setError( `That didn’t save. ${ e instanceof ApiError && e.code === 'offline' ? 'Check your signal and try again.' : 'Try again.' }` );
		}
	};

	const flip = ( p: Position ) => {
		const positions = prefs.positions.includes( p ) ? prefs.positions.filter( ( x ) => x !== p ) : [ ...prefs.positions, p ];
		void save( { ...prefs, positions } );
	};

	return (
		<section className="card prefs" aria-labelledby="prefs-h">
			<div className="prefs__row">
				<h2 id="prefs-h" className="prefs__label">
					I’m happy to spare
				</h2>
				<button
					type="button"
					role="switch"
					className="switch-btn"
					aria-checked={ prefs.enabled }
					aria-labelledby="prefs-h"
					onClick={ () => void save( { enabled: ! prefs.enabled, positions: ! prefs.enabled && ! prefs.positions.length ? [ 'skater' ] : prefs.positions } ) }
				>
					<span className="switch-btn__knob" />
				</button>
			</div>
			<p className="muted">When a team is short we email you, and you decide if you go. No pressure, ever.</p>
			{ prefs.enabled ? (
				<fieldset className="checks">
					<legend>I can play</legend>
					{ ( [ 'skater', 'goalie' ] as const ).map( ( p ) => (
						<label key={ p } className="check">
							<input type="checkbox" checked={ prefs.positions.includes( p ) } onChange={ () => flip( p ) } />
							<span>{ p === 'skater' ? 'Skater' : 'Goalie' }</span>
						</label>
					) ) }
				</fieldset>
			) : null }
			{ error ? (
				<p className="inline-error" role="alert">
					{ error }
				</p>
			) : null }
		</section>
	);
}

/** Calls you could take, the ones you took, and your own team's calls. */
export function Spares( { me }: { me: Me } ) {
	const spares = useResource<SparesPayload>( PATH );
	const [ busy, setBusy ] = useState( 0 );
	const [ errors, setErrors ] = useState<Record<number, string>>( {} );
	const [ done, setDone ] = useState( '' );
	// Shown above the list: the call it is about drops out of the list once refreshed.
	const [ taken, setTaken ] = useState( '' );

	const act = async ( call: SpareCallWithGame, action: 'accept' | 'withdraw' ) => {
		setBusy( call.id );
		setTaken( '' );
		setErrors( ( prev ) => ( { ...prev, [ call.id ]: '' } ) );
		try {
			await apiSend( 'POST', `/spare-calls/${ call.id }/${ action }` );
			setDone( action === 'accept' ? `You’re in for ${ call.game.team.name || 'the team' }. It’s on your schedule now. Thanks for helping out.` : 'Done. Your place is open for someone else.' );
			invalidate( PATH );
			invalidate( '/me/games' );
		} catch ( e ) {
			setDone( '' );
			if ( e instanceof ApiError && ( e.code === 'sppa_full' || e.code === 'sppa_closed' ) ) {
				setTaken( errorText( e ) );
				invalidate( PATH ); // the server no longer offers it
			} else {
				setErrors( ( prev ) => ( { ...prev, [ call.id ]: errorText( e ) } ) );
			}
		} finally {
			setBusy( 0 );
		}
	};

	const data = spares.data;
	let body;
	if ( ! data ) {
		body = spares.loading ? <Skeleton /> : <Problem error={ spares.error } reload={ spares.reload } />;
	} else {
		const enabled = data.prefs.enabled;
		body = (
			<>
				<Prefs initial={ data.prefs } />
				<p className="notice notice--quiet" role="status">
					{ done }
				</p>
				<section aria-labelledby="open-h">
					<h2 id="open-h" className="kicker">
						Teams looking for a spare
					</h2>
					{ taken ? (
						<p className="inline-error" role="alert">
							{ taken }
						</p>
					) : null }
					{ data.open_calls.length ? (
						<ul className="offers">
							{ data.open_calls.map( ( call ) => (
								<li key={ call.id } className="offer">
									<GameLine game={ call.game } />
									<p className="offer__who">{ needLine( call ) }</p>
									{ call.note ? <p className="call__note">“{ call.note }”</p> : null }
									<button type="button" className="btn-primary" disabled={ busy === call.id } onClick={ () => void act( call, 'accept' ) }>
										<span>I’ll play</span>
									</button>
									{ errors[ call.id ] ? (
										<p className="inline-error" role="alert">
											{ errors[ call.id ] }
										</p>
									) : null }
								</li>
							) ) }
						</ul>
					) : (
						<EmptyState message={ enabled || ! me.linked ? 'No team needs a spare right now. We’ll email you when one does.' : 'Turn on sparing to see teams looking for a player.' } />
					) }
				</section>
				{ data.my_accepted.length ? (
					<section aria-labelledby="mine-h">
						<h2 id="mine-h" className="kicker">
							You’re sparing
						</h2>
						<ul className="offers">
							{ data.my_accepted.map( ( call ) => (
								<li key={ call.id } className="offer offer--mine">
									<GameLine game={ call.game } />
									<p className="offer__who">
										For { call.game.team.name } { call.game.is_home ? 'vs' : 'at' } { call.game.opponent.name }
									</p>
									{ call.can_withdraw ? <TwoTap label="Withdraw" confirm="Tap again to give your place back" disabled={ busy === call.id } onConfirm={ () => void act( call, 'withdraw' ) } /> : null }
									{ errors[ call.id ] ? (
										<p className="inline-error" role="alert">
											{ errors[ call.id ] }
										</p>
									) : null }
								</li>
							) ) }
						</ul>
					</section>
				) : null }
				{ data.my_team_calls.length ? (
					<section aria-labelledby="team-h" className="team-calls">
						<h2 id="team-h" className="kicker">
							Your team’s calls
						</h2>
						<CallList calls={ data.my_team_calls } onCancelled={ () => invalidate( PATH ) } />
					</section>
				) : null }
			</>
		);
	}

	return (
		<section className="screen">
			<header className="screen__head">
				<h1>Spares</h1>
			</header>
			{ body }
		</section>
	);
}
