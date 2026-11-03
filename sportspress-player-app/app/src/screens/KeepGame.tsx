import { useEffect, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { ApiError, apiSend } from '../api';
import { Crest } from '../components/Crest';
import { Problem, Skeleton } from '../components/Problem';
import { TwoTap } from '../components/TwoTap';
import { errorText } from '../lib/errors';
import { isCalledOff } from '../lib/games';
import { FLAGS, OFFENSES, byNumber, deriveScore, entryLine, matchRoster, normalizeClock, offense, periodLabel } from '../lib/live';
import { outbox, usePending } from '../lib/outbox';
import { Link } from '../router';
import type { EntryInput, GameLog, GoalFlag, KeeperEntry, LiveSummary, Me, OffenseKey, RosterPlayer, SavedResult, Score, TeamSide } from '../types';
import { getCached, invalidate, setCached, useResource } from '../useResource';

/*
 * The timekeeper's game sheet: score, period, Goal and Penalty, the log, Finish.
 * Every entry goes to the outbox first and shows at once as "waiting"; the server's
 * copy replaces it when it is acknowledged. See lib/outbox.ts and lib/live.ts.
 */

type Row = KeeperEntry & { waiting?: boolean };

/** Rejection reasons that read badly as plain words; the rest are shown as "player not on team". */
const REASONS: Record<string, string> = {
	finalized: 'the game was already finished',
};

/** A pending entry drawn like a stored one. */
function asRow( e: EntryInput, names: Map<number, string> ): Row {
	const name = ( id?: number ) => ( id ? names.get( id ) ?? null : null );
	const o = offense( e.offense );
	// Absent ids stay undefined, which every reader treats like the server's null.
	return {
		...e,
		team_id: e.team_id ?? 0,
		player_name: name( e.player_id ),
		assist1_name: name( e.assist1_id ),
		assist2_name: name( e.assist2_id ),
		not_on_list: ! e.player_id && Boolean( e.jersey ),
		minutes: e.minutes ?? o?.minutes ?? null,
		flags: e.flags ?? [],
		needs_review: Boolean( o?.review ),
		can_void: true,
		waiting: true,
	} as unknown as Row;
}

/** The bottom sheet: a dialog pinned to the thumb end of the screen. Only Cancel or Escape closes it, never a stray tap. */
function Sheet( { title, onClose, onBack, children }: { title: string; onClose: () => void; onBack?: () => void; children: ReactNode } ) {
	const head = useRef<HTMLHeadingElement>( null );
	useEffect( () => {
		head.current?.focus();
	}, [ title ] );
	useEffect( () => {
		const key = ( e: KeyboardEvent ) => e.key === 'Escape' && onClose();
		window.addEventListener( 'keydown', key );
		return () => window.removeEventListener( 'keydown', key );
	}, [ onClose ] );
	return (
		<div className="sheet-back">
			<div className="sheet" role="dialog" aria-modal="true" aria-labelledby="sheet-h">
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
		</div>
	);
}

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

/** Goal or penalty, one step at a time: team → (offense) → player → details → save. */
function EntryFlow( { kind, period, home, away, onSave, onClose }: { kind: 'goal' | 'penalty'; period: number; home: TeamSide; away: TeamSide; onSave: ( e: Omit<EntryInput, 'uid'> ) => void; onClose: () => void } ) {
	const [ step, setStep ] = useState<Step>( 'team' );
	const [ team, setTeam ] = useState<TeamSide>();
	const [ pick, setPick ] = useState<Pick>( {} );
	const [ a1, setA1 ] = useState<number>();
	const [ a2, setA2 ] = useState<number>();
	const [ flags, setFlags ] = useState<GoalFlag[]>( [] );
	const [ off, setOff ] = useState<OffenseKey>();
	const [ minutes, setMinutes ] = useState( 2 );
	const [ clock, setClock ] = useState( '' );
	const so = period === 5;
	const goal = kind === 'goal';

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
							setStep( goal ? 'player' : 'offense' );
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
						<span className="offense__min">{ o.minutes } min{ o.review ? ' · review' : '' }</span>
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

/** One game's sheet. */
export function KeepGame( { id, me }: { id: string; me: Me } ) {
	const gameId = Number( id ) || 0;
	const path = `/games/${ gameId }/log`;
	const log = useResource<GameLog>( gameId ? path : '' );
	const box = outbox();
	const out = usePending( gameId );
	const [ flow, setFlow ] = useState<'goal' | 'penalty' | 'finish'>();
	const [ chosen, setChosen ] = useState<number>();
	const [ say, setSay ] = useState( '' );
	const [ note, setNote ] = useState( '' );
	const [ exists, setExists ] = useState<SavedResult>();
	const [ busy, setBusy ] = useState( false );
	const announce = ( text: string ) => setSay( ( s ) => ( s === text ? text + ' ' : text ) );

	// Fold acknowledged entries into the cached log; the server's score comes with them.
	useEffect(
		() =>
			box.onAck( ( g, res ) => {
				const cur = getCached<GameLog>( path );
				if ( g !== gameId || ! cur ) {
					return;
				}
				const fresh = res.results.flatMap( ( r ) => ( r.entry && ! cur.entries.some( ( e ) => e.uid === r.uid ) ? [ r.entry ] : [] ) );
				setCached( path, { ...cur, entries: [ ...fresh.reverse(), ...cur.entries ], ...( res.live ?? {} ) } );
			} ),
		[ box, gameId, path ]
	);

	// "Synced" once the queue empties; "Waiting to sync" when it gets stuck.
	const waiting = out.pending.length;
	const before = useRef( waiting );
	useEffect( () => {
		if ( before.current > 0 && waiting === 0 ) {
			announce( 'Synced' );
		}
		before.current = waiting;
	}, [ waiting ] );
	useEffect( () => {
		if ( waiting && ( out.sync === 'offline' || out.sync === 'retrying' ) ) {
			announce( 'Waiting to sync' );
		}
	}, [ out.sync, waiting ] );

	const d = log.data && log.data.game.id === gameId ? log.data : undefined;
	const names = useMemo( () => {
		const n = new Map<number, string>();
		const numbers = new Map<number, string>();
		for ( const p of d ? [ ...d.game.home.roster, ...d.game.away.roster ] : [] ) {
			n.set( p.player_id, p.name );
			numbers.set( p.player_id, p.number );
		}
		return { n, numbers };
	}, [ d ] );

	if ( ! d ) {
		return (
			<section className="screen">
				<Link to="/keep" className="back">
					<span aria-hidden="true">‹</span> Keep time
				</Link>
				{ log.loading ? <Skeleton rows={ 5 } /> : <Problem error={ log.error } reload={ log.reload } missing="We couldn’t find that game." forbidden="Keeping time is for timekeepers and organizers." /> }
			</section>
		);
	}

	const { home, away } = d.game;
	const rows: Row[] = [ ...out.pending.map( ( e ) => asRow( e, names.n ) ).reverse(), ...d.entries.filter( ( e ) => ! out.pending.some( ( p ) => p.uid === e.uid ) ) ];
	const derived = deriveScore( rows, home.id, away.id );
	const score = waiting ? derived : d.score;
	const period = chosen ?? Math.min( Math.max( derived.period, d.period ), 5 );
	const final = d.status === 'final';
	const locked = final || isCalledOff( d.game );
	const active = rows.filter( ( r ) => ! r.voided );
	const goals = active.filter( ( r ) => r.type === 'goal' && r.period < 5 ).length;
	const pens = active.filter( ( r ) => r.type === 'penalty' );
	const pim = pens.reduce( ( s, r ) => s + ( r.minutes ?? 0 ), 0 );
	// Keepers may finish from 15 minutes before the start (the server says 409 sppa_not_started before that); organizers any time.
	const early = ! me.is_organizer && Date.parse( d.game.start ) - Date.now() > 15 * 60_000;
	const side = ( n: number | null ) => ( n === null ? '?' : n );
	const teamName = ( t: number ) => ( t === home.id ? home.name : t === away.id ? away.name : '' );

	const badge = ! waiting ? ( out.sync === 'offline' ? 'Offline' : 'All saved' ) : out.sync === 'offline' ? `Offline · ${ waiting } waiting` : `${ waiting } waiting to sync`;

	const record = ( e: Omit<EntryInput, 'uid'> ) => {
		box.enqueue( gameId, e );
		setFlow( undefined );
		const what = e.type === 'goal' ? 'Goal recorded' : e.type === 'penalty' ? 'Penalty recorded' : `${ periodLabel( e.period ) } started`;
		announce( out.sync === 'offline' ? `${ what }. Waiting to sync` : what );
	};

	const choosePeriod = ( p: number ) => {
		setChosen( p );
		if ( p > Math.max( derived.period, d.period ) ) {
			record( { type: 'period', period: p } );
		}
	};

	const voidRow = async ( r: Row ) => {
		setNote( '' );
		if ( r.waiting ) {
			if ( box.discard( r.uid ) ) {
				announce( 'Removed' );
			} else {
				setNote( 'That one is being sent right now. Try again in a moment.' );
			}
			return;
		}
		try {
			const res = await apiSend<{ entry: KeeperEntry; live: NonNullable<LiveSummary> }>( 'POST', `${ path }/${ encodeURIComponent( r.uid ) }/void` );
			const cur = getCached<GameLog>( path );
			if ( cur ) {
				setCached( path, { ...cur, ...res.live, entries: cur.entries.map( ( e ) => ( e.uid === r.uid ? res.entry : e ) ) } );
			}
			announce( 'Voided' );
		} catch ( e ) {
			setNote( errorText( e, 'That didn’t void. Try again.', { sppa_log_locked: 'Someone else is saving to this game. Try again in a moment.' } ) );
		}
	};

	const finish = async ( overwrite: boolean ) => {
		setBusy( true );
		setNote( '' );
		try {
			const res = await apiSend<{ score: Score }>( 'POST', `/games/${ gameId }/finish`, { overwrite } );
			setCached( path, { ...d, status: 'final', score: res.score, can_reopen: me.is_organizer, needs_finish: false } );
			invalidate( path );
			invalidate( '/keep/night' );
			invalidate( '/ops/night' );
			setFlow( undefined );
			setExists( undefined );
			window.scrollTo?.( 0, 0 ); // show the Final banner
			announce( `Game finished, ${ home.name } ${ res.score.home }, ${ away.name } ${ res.score.away }` );
		} catch ( e ) {
			if ( e instanceof ApiError && e.code === 'sppa_result_exists' ) {
				setExists( ( e.data.result as SavedResult ) ?? { home: null, away: null } );
			} else {
				setNote( errorText( e, 'The game could not be finished. Try again.', { sppa_log_locked: 'Someone else is saving to this game. Try again in a moment.', sppa_not_started: 'This game hasn’t started yet. Finish opens 15 minutes before puck drop.' } ) );
			}
		} finally {
			setBusy( false );
		}
	};

	const reopen = async () => {
		try {
			await apiSend( 'POST', `/games/${ gameId }/reopen` );
			invalidate( path );
			announce( 'Game reopened' );
		} catch ( e ) {
			setNote( errorText( e ) );
		}
	};

	return (
		<section className="screen keep">
			<div className="keep__top">
				<Link to="/keep" className="back">
					<span aria-hidden="true">‹</span> Keep time
				</Link>
				<span className={ `sync sync--${ waiting ? ( out.sync === 'offline' ? 'off' : 'wait' ) : 'ok' }` }>{ badge }</span>
			</div>
			<h1 className="sr-only">
				Game sheet: { home.name } vs { away.name }
			</h1>
			<p className="sr-only" role="status" aria-live="polite">
				{ say }
			</p>

			<div className={ final ? 'board-score is-final' : 'board-score' } aria-label={ `${ home.name } ${ score.home }, ${ away.name } ${ score.away }, ${ final ? 'final' : periodLabel( period ) }` } role="group">
				{ [ home, away ].map( ( t, i ) => (
					<div key={ t.id } className="board-score__team">
						<span className="board-score__name">{ t.name }</span>
						<span className="board-score__n" aria-hidden="true">
							{ i ? score.away : score.home }
						</span>
						<span className="board-score__side" aria-hidden="true">
							{ i ? 'Away' : 'Home' }
						</span>
					</div>
				) ) }
				<span className="board-score__mid" aria-hidden="true">
					{ final ? 'Final' : periodLabel( period ) }
				</span>
			</div>

			{ final ? (
				<div className="final-bar">
					<p>
						<strong>Final.</strong> This sheet is closed{ d.finalized?.by ? ` by ${ d.finalized.by.name }` : '' }.
					</p>
					{ d.can_reopen ? <TwoTap label="Reopen" confirm="Tap again to reopen" onConfirm={ () => void reopen() } /> : null }
					{ d.needs_finish ? (
						<>
							<p>The log changed since this game was finished. Finish again to update the result.</p>
							<button type="button" className="btn-quiet" disabled={ waiting > 0 } onClick={ () => setFlow( 'finish' ) }>
								Finish again
							</button>
						</>
					) : null }
				</div>
			) : null }

			{ out.memoryOnly ? <p className="notice">This phone can’t store entries offline. Keep this page open until everything syncs.</p> : null }
			{ out.rejected.map( ( r ) => (
				<div key={ r.entry.uid } className="notice rejected">
					<p>
						Not saved: { entryLine( asRow( r.entry, names.n ), names.numbers ) } ({ REASONS[ r.reason ] ?? r.reason.replace( /_/g, ' ' ) }).
					</p>
					<button type="button" className="btn-quiet" onClick={ () => box.dismiss( r.entry.uid ) }>
						OK
					</button>
				</div>
			) ) }

			{ locked ? null : (
				<>
					<div className="periods" role="radiogroup" aria-label="Period">
						{ [ 1, 2, 3, 4, 5 ].map( ( p ) => (
							<button key={ p } type="button" role="radio" aria-checked={ p === period } className="periods__opt" onClick={ () => choosePeriod( p ) }>
								{ periodLabel( p ).replace( /^P/, '' ) }
							</button>
						) ) }
					</div>
					<div className="big-actions">
						<button type="button" className="big-btn big-btn--goal" onClick={ () => setFlow( 'goal' ) }>
							<span>Goal</span>
						</button>
						<button type="button" className="big-btn" onClick={ () => setFlow( 'penalty' ) }>
							<span>Penalty</span>
						</button>
					</div>
				</>
			) }
			{ note ? (
				<p className="inline-error" role="alert">
					{ note }
				</p>
			) : null }

			<section className="log" aria-labelledby="log-h">
				<h2 id="log-h" className="kicker">
					Log
				</h2>
				{ rows.length ? (
					<ol className="log__list">
						{ rows.map( ( r ) => (
							<li key={ r.uid } className={ `log__row${ r.waiting ? ' is-waiting' : '' }${ r.voided ? ' is-voided' : '' }` }>
								<div className="log__text">
									<p className="log__line">{ r.voided ? <s>{ entryLine( r, names.numbers ) }</s> : entryLine( r, names.numbers ) }</p>
									<p className="log__meta">
										{ [
											teamName( r.team_id ),
											r.flags.map( ( f ) => f.toUpperCase() ).join( ' ' ),
											r.not_on_list ? 'not on the list' : '',
											r.needs_review ? 'needs review' : '',
											r.waiting ? 'waiting to sync' : '',
											r.voided ? `voided${ r.voided_by ? ` by ${ r.voided_by.name }` : '' }` : '',
										]
											.filter( Boolean )
											.join( ' · ' ) }
									</p>
								</div>
								{ r.voided || locked ? null : <TwoTap label={ r.waiting ? 'Undo' : 'Void' } confirm="Tap again" disabled={ ! r.can_void } onConfirm={ () => void voidRow( r ) } /> }
							</li>
						) ) }
					</ol>
				) : (
					<p className="muted">Nothing logged yet. Tap Goal or Penalty when it happens.</p>
				) }
			</section>

			{ locked ? null : (
				<button type="button" className="btn-quiet btn-block finish-btn" disabled={ waiting > 0 || early } onClick={ () => setFlow( 'finish' ) }>
					{ waiting ? 'Waiting to sync…' : early ? 'Opens 15 min before puck drop' : 'Finish game' }
				</button>
			) }

			{ flow === 'goal' || flow === 'penalty' ? <EntryFlow kind={ flow } period={ period } home={ home } away={ away } onSave={ record } onClose={ () => setFlow( undefined ) } /> : null }
			{ flow === 'finish' ? (
				<Sheet
					title={ exists ? 'Replace the result?' : 'Finish this game?' }
					onClose={ () => {
						setFlow( undefined );
						setExists( undefined );
					} }
				>
					<div className="details">
						{ exists ? (
							<p className="finish__q">
								{ d.finalized ? 'This game’s result or stats were changed after you finished. Replace them?' : `This game already has a result (${ side( exists.home ) }–${ side( exists.away ) }). Replace it?` }
							</p>
						) : (
							<>
								<p className="finish__score">
									{ home.name } { score.home } – { away.name } { score.away }
								</p>
								<p className="muted">
									{ goals } goal{ goals === 1 ? '' : 's' } · { pens.length } penalt{ pens.length === 1 ? 'y' : 'ies' } · { pim } PIM
								</p>
								<p>{ active.length ? 'This writes the score and stats to the league site.' : 'Nothing has been logged, so this finishes 0–0.' }</p>
							</>
						) }
						{ note ? <p className="inline-error">{ note }</p> : null }
						<button type="button" className="btn-primary btn-block" disabled={ busy } onClick={ () => void finish( Boolean( exists ) ) }>
							<span>{ exists ? 'Replace' : 'Finish game' }</span>
						</button>
					</div>
				</Sheet>
			) : null }
		</section>
	);
}
