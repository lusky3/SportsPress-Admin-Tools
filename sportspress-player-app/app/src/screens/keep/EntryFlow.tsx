import { useState } from 'react';
import type { ReactNode } from 'react';
import { Crest } from '../../components/Crest';
import { FLAGS, OFFENSES, byNumber, matchRoster, normalizeClock, offense } from '../../lib/live';
import type { EntryInput, GoalFlag, OffenseKey, RosterPlayer, TeamSide } from '../../types';
import { Sheet } from './Sheet';

interface Pick {
	player?: RosterPlayer;
	jersey?: string;
}

/** Jersey number pad: digits match the roster as you type; a number not on the list is allowed and marked. */
export function NumberPad( { roster, onPick }: { roster: RosterPlayer[]; onPick: ( pick: Pick ) => void } ) {
	const [ typed, setTyped ] = useState( '' );
	const { exact, matches } = matchRoster( byNumber( roster ), typed );
	const unknown = typed !== '' && ! matches.some( ( p ) => p.number === typed );
	const shared = typed !== '' && ! exact && ! unknown;
	const key = ( k: string ) => setTyped( ( t ) => ( k === '⌫' ? t.slice( 0, -1 ) : t.length < 3 ? t + k : t ) );
	return (
		<div className="pad">
			<p className={ unknown && ! matches.length ? 'pad__show is-unknown' : 'pad__show' } aria-live="polite">
				<span className="pad__num">{ typed ? `#${ typed }` : '#' }</span>
				<span className="pad__who">{ exact ? exact.name : unknown ? ( matches.length ? 'Keep typing, or tap a name' : 'Not on the list' ) : shared ? 'Two share this number: tap one' : 'Type the number or tap a name' }</span>
			</p>
			<ul className="pad__chips" aria-label="Players">
				{ matches.map( ( p ) => (
					<li key={ p.player_id }>
						<button type="button" className={ p === exact ? 'pchip is-match' : 'pchip' } onClick={ () => onPick( { player: p } ) }>
							<b>{ p.number || '–' }</b> { p.name }
							{ p.spare ? <span className="pchip__tag">Spare</span> : null }
						</button>
					</li>
				) ) }
			</ul>
			<div className="pad__keys">
				{ [ '1', '2', '3', '4', '5', '6', '7', '8', '9', '⌫', '0' ].map( ( k ) => (
					<button key={ k } type="button" className="pad__key" aria-label={ k === '⌫' ? 'Delete' : undefined } onClick={ () => key( k ) }>
						{ k }
					</button>
				) ) }
				<button type="button" className="pad__key pad__ok" aria-label={ typed ? `Use number ${ typed }` : 'Use number' } disabled={ ! typed || shared } onClick={ () => onPick( exact ? { player: exact } : { jersey: typed } ) }>
					✓
				</button>
			</div>
		</div>
	);
}

/** A one-line strip of player chips with a "none" choice first. */
function Picker( { label, none, roster, value, onChange }: { label: string; none: string; roster: RosterPlayer[]; value?: number; onChange: ( id?: number ) => void } ) {
	return (
		<fieldset className="picker">
			<legend>{ label }</legend>
			<div className="picker__strip">
				<button type="button" className="pchip" aria-pressed={ ! value } onClick={ () => onChange( undefined ) }>
					{ none }
				</button>
				{ roster.map( ( p ) => (
					<button key={ p.player_id } type="button" className="pchip" aria-pressed={ value === p.player_id } onClick={ () => onChange( p.player_id ) }>
						<b>{ p.number || '–' }</b> { p.name }
					</button>
				) ) }
			</div>
		</fieldset>
	);
}

type Step = 'team' | 'offense' | 'player' | 'details';

interface FlowProps {
	kind: 'goal' | 'penalty';
	period: number;
	home: TeamSide;
	away: TeamSide;
	/** An entry the server refused, to fix and send again: every field it had that still fits is filled in. */
	initial?: EntryInput;
	onSave: ( e: Omit<EntryInput, 'uid'> ) => void;
	onClose: () => void;
}

/** Goal or penalty, one step at a time: team → (offense) → player → details → save. */
export function EntryFlow( { kind, period: now, home, away, initial, onSave, onClose }: FlowProps ) {
	const period = initial?.period ?? now;
	const first = [ home, away ].find( ( t ) => t.id === initial?.team_id );
	const on = ( id?: number ) => ( id ? first?.roster.find( ( p ) => p.player_id === id ) : undefined );
	const firstPick: Pick = on( initial?.player_id ) ? { player: on( initial?.player_id ) } : initial?.jersey ? { jersey: initial.jersey } : {};
	const firstOff = offense( initial?.offense );
	const [ team, setTeam ] = useState<TeamSide | undefined>( first );
	const [ pick, setPick ] = useState<Pick>( firstPick );
	const [ a1, setA1 ] = useState<number | undefined>( on( initial?.assist1_id )?.player_id );
	const [ a2, setA2 ] = useState<number | undefined>( on( initial?.assist2_id )?.player_id );
	const [ flags, setFlags ] = useState<GoalFlag[]>( initial?.flags ?? [] );
	const [ off, setOff ] = useState<OffenseKey | undefined>( firstOff?.key );
	const [ minutes, setMinutes ] = useState( initial?.minutes && initial.minutes <= 20 ? initial.minutes : firstOff?.minutes ?? 2 );
	const [ clock, setClock ] = useState( initial?.clock ?? '' );
	const goal = kind === 'goal';
	const so = period === 5;
	const [ step, setStep ] = useState<Step>( ! first ? 'team' : ! goal && ! firstOff ? 'offense' : firstPick.player || firstPick.jersey ? ( so ? 'player' : 'details' ) : 'player' );

	const save = ( p: Pick = pick ) => {
		const c = normalizeClock( clock );
		onSave( {
			type: kind,
			period,
			team_id: team?.id,
			...( p.player ? { player_id: p.player.player_id } : { jersey: p.jersey } ),
			...( c ? { clock: c } : {} ),
			...( goal ? { ...( a1 ? { assist1_id: a1 } : {} ), ...( a2 ? { assist2_id: a2 } : {} ), ...( flags.length ? { flags } : {} ) } : { offense: off, minutes } ),
		} );
	};

	const back = { team: undefined, offense: 'team', player: goal ? 'team' : 'offense', details: 'player' }[ step ] as Step | undefined;
	const what = goal ? ( so ? 'Shootout goal' : 'Goal' ) : 'Penalty';
	const title = { team: `${ what }: which team?`, offense: 'Penalty: what for?', player: goal ? 'Who scored?' : 'Who took it?', details: goal ? 'Assists and details' : 'Minutes and time' }[ step ];
	const clockBad = normalizeClock( clock ) === null;
	const others = ( team?.roster ?? [] ).filter( ( p ) => p.player_id !== pick.player?.player_id );

	let body: ReactNode;
	if ( step === 'team' ) {
		body = (
			<div className="pick-team">
				{ [ home, away ].map( ( t, i ) => (
					<button
						key={ t.id }
						type="button"
						className="team-btn"
						onClick={ () => {
							setTeam( t );
							setPick( {} );
							setA1( undefined );
							setA2( undefined );
							setStep( goal || off ? 'player' : 'offense' );
						} }
					>
						<Crest name={ t.name } logo={ t.logo } size={ 40 } />
						<span className="team-btn__name">{ t.name }</span>
						<span className="team-btn__side">{ i ? 'Away' : 'Home' }</span>
					</button>
				) ) }
			</div>
		);
	} else if ( step === 'offense' ) {
		body = (
			<div className="offenses">
				{ OFFENSES.map( ( o ) => (
					<button
						key={ o.key }
						type="button"
						className="offense"
						onClick={ () => {
							setOff( o.key );
							setMinutes( o.minutes );
							setStep( 'player' );
						} }
					>
						<span>{ o.label }</span>
						<span className="offense__min">
							{ o.minutes } min{ o.review ? ' · review' : '' }
						</span>
					</button>
				) ) }
			</div>
		);
	} else if ( step === 'player' ) {
		body = (
			<NumberPad
				roster={ team?.roster ?? [] }
				onPick={ ( p ) => {
					setPick( p );
					if ( so ) {
						save( p ); // a shootout goal has no assists, flags or clock
					} else {
						setStep( 'details' );
					}
				} }
			/>
		);
	} else {
		const who = pick.player ? `#${ pick.player.number || '–' } ${ pick.player.name }` : `#${ pick.jersey } (not on the list)`;
		body = (
			<div className="details">
				<p className="details__who">
					{ team?.name } · { who }
					{ goal ? '' : ` · ${ offense( off )?.label }` }
				</p>
				{ goal ? (
					<>
						<Picker
							label="Assist 1"
							none="Unassisted"
							roster={ others }
							value={ a1 }
							onChange={ ( id ) => {
								setA1( id );
								setA2( undefined );
							} }
						/>
						{ a1 ? <Picker label="Assist 2" none="None" roster={ others.filter( ( p ) => p.player_id !== a1 ) } value={ a2 } onChange={ setA2 } /> : null }
						<fieldset className="picker">
							<legend>Flags</legend>
							<div className="picker__strip">
								{ FLAGS.map( ( f ) => (
									<button key={ f.key } type="button" className="pchip" aria-pressed={ flags.includes( f.key ) } aria-label={ `${ f.label }, ${ f.long }` } onClick={ () => setFlags( ( x ) => ( x.includes( f.key ) ? x.filter( ( y ) => y !== f.key ) : [ ...x, f.key ] ) ) }>
										{ f.label }
									</button>
								) ) }
							</div>
						</fieldset>
					</>
				) : (
					<div className="minutes">
						<span id="min-l">Minutes</span>
						<button type="button" className="stepper" aria-label="Fewer minutes" disabled={ minutes <= 1 } onClick={ () => setMinutes( minutes - 1 ) }>
							−
						</button>
						<output aria-labelledby="min-l" className="minutes__n">
							{ minutes }
						</output>
						<button type="button" className="stepper" aria-label="More minutes" disabled={ minutes >= 20 } onClick={ () => setMinutes( minutes + 1 ) }>
							+
						</button>
					</div>
				) }
				<div className="field">
					<label htmlFor="clock">Clock (optional)</label>
					<input id="clock" inputMode="numeric" autoComplete="off" placeholder="mm:ss" maxLength={ 5 } value={ clock } aria-invalid={ clockBad } aria-describedby="clock-h" onChange={ ( e ) => setClock( e.target.value ) } />
					<p id="clock-h" className={ clockBad ? 'inline-error' : 'field__hint' }>
						{ clockBad ? 'Use minutes and seconds, like 12:41.' : 'Time on the clock, if you have it.' }
					</p>
				</div>
				<button type="button" className="btn-primary btn-block" disabled={ clockBad } onClick={ () => save() }>
					<span>Save { goal ? 'goal' : 'penalty' }</span>
				</button>
			</div>
		);
	}

	return (
		<Sheet title={ title } onClose={ onClose } onBack={ back ? () => setStep( back ) : undefined }>
			{ body }
		</Sheet>
	);
}
