import type { ReactNode } from 'react';
import { SavedBeforeSignIn, unsentForGameText } from '../../components/UnsentElsewhere';
import { isCalledOff } from '../../lib/games';
import type { Pending } from '../../lib/outbox';
import type { GameLog, Me } from '../../types';
import type { Resource } from '../../useResource';
import { FinalBar, ScoreBoard } from './Board';
import { EntryFlow } from './EntryFlow';
import { FinishSheet } from './FinishSheet';
import { LogList } from './LogList';
import { Periods } from './Periods';
import { Rejections } from './Rejections';
import { buildRows, scoreOf, shownScore } from './rows';
import { SignedOutNotice, SyncBadge, finishBlockText } from './status';
import { useEntryActions } from './useEntryActions';
import type { EntryFlowState } from './useEntryActions';
import { useFinishFlow } from './useFinishFlow';

/** Which sheet is open over the game: an entry, or Finish. */
export type Flow = EntryFlowState | { kind: 'finish' };

export interface Names {
	n: Map<number, string>;
	numbers: Map<number, string>;
}

interface Props {
	gameId: number;
	path: string;
	log: Resource<GameLog>;
	d: GameLog;
	me: Me;
	out: Pending;
	names: Names;
	/** Another keeper's entries for this game that this session will not send. */
	elsewhere: number;
	back: ReactNode;
	flow: Flow | undefined;
	setFlow: ( flow: Flow | undefined ) => void;
	chosen: number | undefined;
	setChosen: ( period: number ) => void;
	note: string;
	setNote: ( note: string ) => void;
	say: string;
	announce: ( text: string ) => void;
}

/** Keepers may finish from 15 minutes before the start (the server says 409 sppa_not_started before that); organizers any time. */
const tooEarly = ( me: Me, d: GameLog ) => ! me.is_organizer && Date.parse( d.game.start ) - Date.now() > 15 * 60_000;

/** The sheet for a loaded game: score, period, Goal and Penalty, refusals, the log, Finish. */
export function GameSheet( p: Props ) {
	const { gameId, path, log, d, me, out, names, elsewhere, flow, setFlow, note, setNote, announce } = p;
	const { home, away } = d.game;
	const waiting = out.pending.length;
	const rows = buildRows( out.pending, d.entries, names.n );
	const derived = scoreOf( rows, d );
	const reached = Math.max( derived.period, d.period );
	const period = p.chosen ?? Math.min( reached, 5 );
	const final = d.status === 'final';
	const locked = final || isCalledOff( d.game );
	const signedOut = out.sync === 'signed_out' && waiting > 0;
	const finishBlock = finishBlockText( out.rejected.length, waiting );
	const teamName = ( t: number ) => [ home, away ].find( ( x ) => x.id === t )?.name ?? '';

	const finishing = useFinishFlow( { gameId, path, d, log, me, isOpen: flow?.kind === 'finish', setOpen: ( open ) => setFlow( open ? { kind: 'finish' } : undefined ), setNote, announce } );
	const act = useEntryActions( { gameId, path, sync: out.sync, reached, openEntry: setFlow, setChosen: p.setChosen, setNote, announce } );
	const entry = flow && flow.kind !== 'finish' ? flow : undefined;

	return (
		<section className="screen keep">
			<div className="keep__top">
				{ p.back }
				<SyncBadge waiting={ waiting } sync={ out.sync } signedOut={ signedOut } />
			</div>
			<h1 className="sr-only">
				Game sheet: { home.name } vs { away.name }
			</h1>
			<p className="sr-only" role="status" aria-live="polite">
				{ p.say }
			</p>
			{ signedOut ? <SignedOutNotice /> : null }

			<ScoreBoard home={ home } away={ away } score={ shownScore( derived, d, waiting ) } final={ final } period={ period } />
			{ final ? (
				<FinalBar
					d={ d }
					finishBlock={ finishBlock }
					onFinishAgain={ finishing.open }
					onReopen={ () => {
						void act.reopen();
					} }
				/>
			) : null }

			{ elsewhere ? (
				<p className="notice" role="status">
					{ unsentForGameText( elsewhere ) }
				</p>
			) : null }
			<SavedBeforeSignIn name={ me.user.name } />
			{ out.memoryOnly ? <p className="notice">This phone isn’t saving entries to its storage. Keep this page open until everything syncs.</p> : null }
			<Rejections rejected={ out.rejected } names={ names.n } numbers={ names.numbers } onDismiss={ act.box.dismiss } onRetry={ act.box.retry } onEnterAgain={ act.enterAgain } />

			{ locked ? null : (
				<>
					<Periods value={ period } onChange={ act.choosePeriod } />
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

			<LogList
				rows={ rows }
				numbers={ names.numbers }
				teamName={ teamName }
				locked={ locked }
				onVoid={ ( r ) => {
					void act.voidRow( r );
				} }
			/>

			{ locked ? null : (
				<button type="button" className="btn-quiet btn-block finish-btn" disabled={ Boolean( finishBlock ) || tooEarly( me, d ) } onClick={ finishing.open }>
					{ finishBlock || ( tooEarly( me, d ) ? 'Opens 15 min before puck drop' : 'Finish game' ) }
				</button>
			) }

			{ entry ? <EntryFlow key={ entry.replaces ?? entry.kind } kind={ entry.kind } period={ period } home={ home } away={ away } initial={ entry.initial } onSave={ ( e ) => act.record( e, entry.replaces ) } onClose={ () => setFlow( undefined ) } /> : null }
			{ flow?.kind === 'finish' ? <FinishSheet d={ d } flow={ finishing } finishBlock={ finishBlock } elsewhere={ elsewhere } note={ note } /> : null }
		</section>
	);
}
