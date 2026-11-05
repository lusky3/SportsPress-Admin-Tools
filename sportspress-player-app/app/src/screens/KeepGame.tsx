import { useEffect, useMemo, useRef, useState } from 'react';
import type { KeyboardEvent as ReactKeyboardEvent, ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { ApiError, apiSend } from '../api';
import { boot } from '../boot';
import { Crest } from '../components/Crest';
import { Problem, Skeleton } from '../components/Problem';
import { TwoTap } from '../components/TwoTap';
import { UnsentElsewhere } from '../components/UnsentElsewhere';
import { errorText } from '../lib/errors';
import { isCalledOff } from '../lib/games';
import { FLAGS, OFFENSES, byNumber, deriveScore, entryLine, matchRoster, normalizeClock, offense, periodLabel } from '../lib/live';
import { mergeIntoLog, outbox, usePending } from '../lib/outbox';
import type { Queued, Rejected, VoidResult } from '../lib/outbox';
import { Link } from '../router';
import type { EntryInput, GameLog, GoalFlag, Me, OffenseKey, RosterPlayer, SavedResult, Score, TeamSide } from '../types';
import { invalidate, refresh, setCached, useResource } from '../useResource';
import type { KeeperEntry } from '../types';

/*
 * The timekeeper's game sheet: score, period, Goal and Penalty, the log, Finish.
 * Every entry goes to the outbox first (lib/outbox.ts) and shows at once ("saving", then
 * "waiting to sync"); the server's copy replaces it when acknowledged. Finish only runs on a
 * complete log: nothing waiting, nothing the server refused.
 */

type Row = KeeperEntry & { waiting?: boolean; saving?: boolean; undoing?: boolean };

/** Every refusal the server can give, in words a volunteer can act on. */
const REASONS: Record<string, string> = {
	invalid_uid: 'That entry wasn’t valid',
	invalid_type: 'That entry wasn’t valid',
	rest_invalid_param: 'That entry wasn’t valid',
	rest_missing_callback_param: 'That entry wasn’t valid',
	invalid_period: 'The period wasn’t valid',
	invalid_clock: 'The clock time wasn’t valid',
	text_too_long: 'The note was too long',
	text_required: 'The note was empty',
	invalid_flags: 'The PP, SH or EN marks weren’t valid',
	team_not_on_game: 'That team isn’t playing in this game',
	team_not_allowed: 'A period marker can’t have a team',
	player_required: 'No player or number was picked',
	player_not_on_team: 'That player isn’t on the team’s list',
	invalid_jersey: 'The jersey number wasn’t valid',
	assist_not_on_team: 'An assist isn’t on the team’s list',
	assist_is_scorer: 'The scorer can’t also get the assist',
	assists_same: 'Both assists were the same player',
	no_assists_in_shootout: 'Shootout goals don’t get assists',
	invalid_offense: 'The penalty type wasn’t valid',
	invalid_minutes: 'The penalty minutes weren’t valid',
	finalized: 'The game was already finished',
	called_off: 'The game is postponed or cancelled',
	no_teams: 'This game doesn’t have two teams yet',
	not_found: 'That game or team wasn’t found',
	forbidden: 'You can’t change that entry',
};

export function reasonText( reason: string ): string {
	return REASONS[ reason.replace( /^void_/, '' ) ] ?? 'The server didn’t accept it';
}

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

const FOCUSABLE = 'button:not([disabled]), [href], input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])';

/**
 * The bottom sheet: a modal dialog pinned to the thumb end of the screen. Everything behind it is
 * inert, Tab stays inside, Escape or Cancel closes it (never a stray tap outside), and focus goes
 * back to the button that opened it.
 */
function Sheet( { title, onClose, onBack, busy, children }: { title: string; onClose: () => void; onBack?: () => void; busy?: boolean; children: ReactNode } ) {
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
				const items = [ ...box.current.querySelectorAll<HTMLElement>( FOCUSABLE ) ];
				const first = items[ 0 ];
				const last = items[ items.length - 1 ];
				if ( e.shiftKey && ( document.activeElement === first || document.activeElement === head.current ) ) {
					e.preventDefault();
					last?.focus();
				} else if ( ! e.shiftKey && document.activeElement === last ) {
					e.preventDefault();
					first?.focus();
				}
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
function EntryFlow( { kind, period: now, home, away, initial, onSave, onClose }: FlowProps ) {
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

/** The period control: a radio group (arrow keys move and choose, one tab stop). */
function Periods( { value, onChange }: { value: number; onChange: ( p: number ) => void } ) {
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

type Flow = { kind: 'goal' | 'penalty'; initial?: EntryInput; replaces?: string } | { kind: 'finish' };

/** What the log says, to notice another keeper's change while the Finish sheet is open. */
const signature = ( d: GameLog ) => d.entries.map( ( e ) => e.uid + ( e.voided ? '-' : '' ) ).join();

const plural = ( n: number, one: string, many: string ) => ( n === 1 ? one : many );

/** How often an open sheet fetches the full log, and the longest it waits after failures. */
export const REFRESH_MS = 10_000;
const REFRESH_MAX_MS = 60_000;

/**
 * While the sheet is on screen, fetch the full log every 10 s, so another keeper's entries show up
 * here (acknowledgements only carry this phone's own). Asks at once when the tab comes back or the
 * connection returns; sleeps while hidden or offline; one request at a time; after a failure waits
 * 20 s, then 40 s, up to a minute. The fetched log replaces the cached one; this phone's waiting
 * entries are drawn over it by uid (see rows), so none is dropped or counted twice.
 * An empty `path` (no log yet, or a final game) asks for nothing.
 */
function useLogRefresh( path: string ): void {
	useEffect( () => {
		if ( ! path ) {
			return;
		}
		let timer: ReturnType<typeof setTimeout> | undefined;
		let failures = 0;
		let busy = false;
		let stopped = false;
		const awake = () => document.visibilityState !== 'hidden' && navigator.onLine !== false;
		const run = async () => {
			clearTimeout( timer );
			if ( stopped || busy || ! awake() ) {
				return; // asleep: showing the tab again, or the connection coming back, wakes it
			}
			busy = true;
			const fresh = await refresh( path );
			busy = false;
			if ( ! stopped ) {
				failures = fresh ? 0 : failures + 1;
				timer = setTimeout( run, Math.min( REFRESH_MS * 2 ** failures, REFRESH_MAX_MS ) );
			}
		};
		const wake = () => void run();
		document.addEventListener( 'visibilitychange', wake );
		window.addEventListener( 'online', wake );
		timer = setTimeout( run, REFRESH_MS );
		return () => {
			stopped = true;
			clearTimeout( timer );
			document.removeEventListener( 'visibilitychange', wake );
			window.removeEventListener( 'online', wake );
		};
	}, [ path ] );
}

/** One game's sheet. */
export function KeepGame( { id, me }: { id: string; me: Me } ) {
	const gameId = Number( id ) || 0;
	const path = `/games/${ gameId }/log`;
	const log = useResource<GameLog>( gameId ? path : '' );
	const box = outbox();
	const out = usePending( gameId );
	const [ flow, setFlow ] = useState<Flow>();
	const [ chosen, setChosen ] = useState<number>();
	const [ say, setSay ] = useState( '' );
	const [ note, setNote ] = useState( '' );
	const [ exists, setExists ] = useState<SavedResult>();
	const [ busy, setBusy ] = useState( false );
	const [ seen, setSeen ] = useState( '' );
	const announce = ( text: string ) => setSay( ( s ) => ( s === text ? text + ' ' : text ) );

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
	useLogRefresh( d && d.status !== 'final' ? path : '' );
	const names = useMemo( () => {
		const n = new Map<number, string>();
		const numbers = new Map<number, string>();
		for ( const p of d ? [ ...d.game.home.roster, ...d.game.away.roster ] : [] ) {
			n.set( p.player_id, p.name );
			numbers.set( p.player_id, p.number );
		}
		return { n, numbers };
	}, [ d ] );

	const back = (
		<Link to="/keep" className="back">
			<span aria-hidden="true">‹</span> Games
		</Link>
	);

	if ( ! d ) {
		return (
			<section className="screen">
				{ back }
				{ log.loading ? <Skeleton rows={ 5 } /> : <Problem error={ log.error } reload={ log.reload } missing="We couldn’t find that game." forbidden="Scoring games is for timekeepers and organizers." /> }
			</section>
		);
	}

	const { home, away } = d.game;
	const queued = new Map<string, Queued>( out.pending.map( ( q ) => [ q.entry.uid, q ] ) );
	const rows: Row[] = [
		...out.pending
			.filter( ( q ) => ! q.acked )
			.map( ( q ) => ( { ...asRow( q.entry, names.n ), saving: q.saving, undoing: q.undo } ) )
			.reverse(),
		...d.entries.filter( ( e ) => ! queued.get( e.uid ) || queued.get( e.uid )?.acked ).map( ( e ) => ( queued.get( e.uid )?.undo ? { ...e, undoing: true } : e ) ),
	];
	// Undone entries no longer count; the server's score stands once nothing waits.
	const derived = deriveScore(
		rows.map( ( r ) => ( r.undoing ? { ...r, voided: true } : r ) ),
		home.id,
		away.id
	);
	const score = waiting ? derived : d.score;
	const period = chosen ?? Math.min( Math.max( derived.period, d.period ), 5 );
	const final = d.status === 'final';
	const locked = final || isCalledOff( d.game );
	const parked = out.rejected.length;
	const signedOut = out.sync === 'signed_out' && waiting > 0;
	// Keepers may finish from 15 minutes before the start (the server says 409 sppa_not_started before that); organizers any time.
	const early = ! me.is_organizer && Date.parse( d.game.start ) - Date.now() > 15 * 60_000;
	const side = ( n: number | null ) => ( n === null ? '?' : n );
	const teamName = ( t: number ) => ( t === home.id ? home.name : t === away.id ? away.name : '' );
	const finishBlock = parked ? `${ parked } ${ plural( parked, 'entry wasn’t', 'entries weren’t' ) } saved — fix ${ plural( parked, 'it', 'them' ) } first` : waiting ? 'Waiting to sync…' : '';

	let badge = 'All saved';
	if ( signedOut ) {
		badge = `Sign in again to send ${ waiting } ${ plural( waiting, 'entry', 'entries' ) }`;
	} else if ( waiting ) {
		badge = out.sync === 'offline' ? `Offline · ${ waiting } waiting` : `${ waiting } waiting to sync`;
	} else if ( out.sync === 'offline' ) {
		badge = 'Offline';
	}

	const record = ( e: Omit<EntryInput, 'uid'>, replaces?: string ) => {
		box.enqueue( gameId, e );
		if ( replaces ) {
			box.dismiss( replaces );
		}
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

	const enterAgain = ( r: Rejected ) => {
		if ( r.entry.type === 'goal' || r.entry.type === 'penalty' ) {
			setFlow( { kind: r.entry.type, initial: r.entry, replaces: r.entry.uid } );
		} else {
			const { uid: _uid, ...rest } = r.entry;
			record( rest, r.entry.uid );
		}
	};

	const voidRow = async ( r: Row ) => {
		setNote( '' );
		if ( r.waiting ) {
			announce( box.discard( r.uid ) === 'undoing' ? 'Undoing' : 'Removed' );
			return;
		}
		try {
			const res = await apiSend<VoidResult>( 'POST', `${ path }/${ encodeURIComponent( r.uid ) }/void` );
			mergeIntoLog( gameId, [ res.entry ], res.live );
			announce( 'Voided' );
		} catch ( e ) {
			setNote( errorText( e, 'That didn’t void. Try again.', { sppa_log_locked: 'Someone else is saving to this game. Try again in a moment.' } ) );
		}
	};

	const openFinish = () => {
		setNote( '' );
		setSeen( signature( d ) );
		setFlow( { kind: 'finish' } );
		log.reload(); // finish from the server's log, not the copy on screen
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

	const t = d.totals;
	const checking = flow?.kind === 'finish' && log.loading;
	const changed = flow?.kind === 'finish' && ! log.loading && seen !== signature( d );

	return (
		<section className="screen keep">
			<div className="keep__top">
				{ back }
				<span className={ `sync sync--${ signedOut || ( waiting && out.sync === 'offline' ) ? 'off' : waiting ? 'wait' : 'ok' }` }>{ badge }</span>
			</div>
			<h1 className="sr-only">
				Game sheet: { home.name } vs { away.name }
			</h1>
			<p className="sr-only" role="status" aria-live="polite">
				{ say }
			</p>
			{ signedOut ? (
				<p className="notice rejected">
					<span>Your session ended. Nothing is lost: sign in and these entries send.</span>
					<a className="btn-quiet" href={ boot().loginUrl }>
						Sign in
					</a>
				</p>
			) : null }

			<div className={ final ? 'board-score is-final' : 'board-score' } aria-label={ `${ home.name } ${ score.home }, ${ away.name } ${ score.away }, ${ final ? 'final' : periodLabel( period ) }` } role="group">
				{ [ home, away ].map( ( team, i ) => (
					<div key={ team.id } className="board-score__team">
						<span className="board-score__name">{ team.name }</span>
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
					<Link to="/keep">Back to games</Link>
					{ d.can_reopen ? <TwoTap label="Reopen" confirm="Tap again to reopen" onConfirm={ () => void reopen() } /> : null }
					{ d.needs_finish ? (
						<>
							<p>The log changed since this game was finished. Finish again to update the result.</p>
							<button type="button" className="btn-quiet" disabled={ Boolean( finishBlock ) } onClick={ openFinish }>
								{ finishBlock || 'Finish again' }
							</button>
						</>
					) : null }
				</div>
			) : null }

			<UnsentElsewhere />
			{ out.memoryOnly ? <p className="notice">This phone isn’t saving entries to its storage. Keep this page open until everything syncs.</p> : null }
			{ out.rejected.map( ( r ) => (
				<div key={ r.entry.uid } className="notice rejected">
					<p>
						{ r.reason.startsWith( 'void_' ) ? 'Couldn’t undo' : 'Not saved' }: { entryLine( asRow( r.entry, names.n ), names.numbers ) }. { reasonText( r.reason ) }.
					</p>
					<div className="rejected__actions">
						{ r.reason.startsWith( 'void_' ) ? (
							<button type="button" className="btn-quiet" onClick={ () => box.dismiss( r.entry.uid ) }>
								OK
							</button>
						) : (
							<>
								{ r.whole ? (
									<button type="button" className="btn-quiet" onClick={ () => box.retry( r.entry.uid ) }>
										Try again
									</button>
								) : (
									<button type="button" className="btn-quiet" onClick={ () => enterAgain( r ) }>
										Enter it again
									</button>
								) }
								<TwoTap label="Discard" confirm="Tap again to discard" onConfirm={ () => box.dismiss( r.entry.uid ) } />
							</>
						) }
					</div>
				</div>
			) ) }

			{ locked ? null : (
				<>
					<Periods value={ period } onChange={ choosePeriod } />
					<div className="big-actions">
						<button type="button" className="big-btn big-btn--goal" onClick={ () => setFlow( { kind: 'goal' } ) }>
							<span>Goal</span>
						</button>
						<button type="button" className="big-btn" onClick={ () => setFlow( { kind: 'penalty' } ) }>
							<span>Penalty</span>
						</button>
					</div>
				</>
			) }
			{ note && flow?.kind !== 'finish' ? (
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
							<li key={ r.uid } className={ `log__row${ r.waiting ? ' is-waiting' : '' }${ r.voided || r.undoing ? ' is-voided' : '' }` }>
								<div className="log__text">
									<p className="log__line">{ r.voided || r.undoing ? <s>{ entryLine( r, names.numbers ) }</s> : entryLine( r, names.numbers ) }</p>
									<p className="log__meta">
										{ [
											teamName( r.team_id ),
											r.flags.map( ( f ) => f.toUpperCase() ).join( ' ' ),
											r.not_on_list ? 'not on the list' : '',
											r.needs_review ? 'needs review' : '',
											r.undoing ? 'undoing…' : r.saving ? 'saving…' : r.waiting ? 'waiting to sync' : '',
											r.voided ? `voided${ r.voided_by ? ` by ${ r.voided_by.name }` : '' }` : '',
										]
											.filter( Boolean )
											.join( ' · ' ) }
									</p>
								</div>
								{ r.voided || r.undoing || locked ? null : <TwoTap label={ r.waiting ? 'Undo' : 'Void' } confirm="Tap again" disabled={ ! r.can_void } onConfirm={ () => void voidRow( r ) } /> }
							</li>
						) ) }
					</ol>
				) : (
					<p className="muted">Nothing logged yet. Tap Goal or Penalty when it happens.</p>
				) }
			</section>

			{ locked ? null : (
				<button type="button" className="btn-quiet btn-block finish-btn" disabled={ Boolean( finishBlock ) || early } onClick={ openFinish }>
					{ finishBlock || ( early ? 'Opens 15 min before puck drop' : 'Finish game' ) }
				</button>
			) }

			{ flow && flow.kind !== 'finish' ? <EntryFlow key={ flow.replaces ?? flow.kind } kind={ flow.kind } period={ period } home={ home } away={ away } initial={ flow.initial } onSave={ ( e ) => record( e, flow.replaces ) } onClose={ () => setFlow( undefined ) } /> : null }
			{ flow?.kind === 'finish' ? (
				<Sheet
					title={ exists ? 'Replace the result?' : 'Finish this game?' }
					busy={ checking || busy }
					onClose={ () => {
						setFlow( undefined );
						setExists( undefined );
						setNote( '' );
					} }
				>
					<div className="details">
						{ exists ? (
							<p className="finish__q">{ d.finalized ? 'This game’s result or stats were changed after you finished. Replace them?' : `This game already has a result (${ side( exists.home ) }–${ side( exists.away ) }). Replace it?` }</p>
						) : (
							<>
								<p className="finish__score">
									{ home.name } { d.score.home } – { away.name } { d.score.away }
								</p>
								<p className="muted">{ checking ? 'Checking the latest log…' : `${ t.goals } ${ plural( t.goals, 'goal', 'goals' ) } · ${ t.penalties } ${ plural( t.penalties, 'penalty', 'penalties' ) } · ${ t.pim.home + t.pim.away } PIM` }</p>
								{ changed ? <p className="notice">Another keeper changed this log since you opened it. Check the score above before you finish.</p> : null }
								<p>{ d.entries.some( ( e ) => ! e.voided ) ? 'This writes the score and stats to the league site.' : 'Nothing has been logged, so this finishes 0–0.' }</p>
							</>
						) }
						{ note ? <p className="inline-error">{ note }</p> : null }
						<button type="button" className="btn-primary btn-block" disabled={ busy || checking || Boolean( finishBlock ) } aria-busy={ busy || undefined } onClick={ () => void finish( Boolean( exists ) ) }>
							<span>{ exists ? 'Replace' : 'Finish game' }</span>
						</button>
					</div>
				</Sheet>
			) : null }
		</section>
	);
}
