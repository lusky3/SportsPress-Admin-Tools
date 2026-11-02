import type { AvailabilityStatus, Game, GameResult } from '../types';

type Tone = 'plain' | 'alert' | 'ice' | 'ghost';

export function StatusChip( { label, tone = 'plain' }: { label: string; tone?: Tone } ) {
	return <span className={ `chip chip--${ tone }` }>{ label }</span>;
}

/** The flags a game carries: called off, changed, time not set, playing as a spare. */
export function GameChips( { game }: { game: Pick<Game, 'status' | 'changed_recently' | 'as_spare'> } ) {
	return (
		<>
			{ game.status === 'postponed' ? <StatusChip label="Postponed" tone="alert" /> : null }
			{ game.status === 'cancelled' ? <StatusChip label="Cancelled" tone="alert" /> : null }
			{ game.status === 'tbd' ? <StatusChip label="Time TBD" /> : null }
			{ game.changed_recently ? <StatusChip label="Changed" tone="alert" /> : null }
			{ game.as_spare ? <StatusChip label="Spare" tone="ice" /> : null }
		</>
	);
}

const ANSWER: Record<AvailabilityStatus, string> = { in: 'You’re in', out: 'You’re out', maybe: 'Maybe' };

/** The viewer's answer on a schedule row; a gentle dashed "Not answered" when there is none. */
export function AnswerChip( { value }: { value: AvailabilityStatus | null } ) {
	return value ? <StatusChip label={ ANSWER[ value ] } tone={ value === 'in' ? 'ice' : 'plain' } /> : <StatusChip label="Not answered" tone="ghost" />;
}

const OUTCOME = { W: 'Win', L: 'Loss', T: 'Tie' };

/** "W 4–2", read out as "Win 4 to 2". */
export function ResultPill( { result }: { result: GameResult } ) {
	return (
		<span className={ `pill pill--${ result.outcome }` }>
			<span aria-hidden="true">
				{ result.outcome } { result.for }–{ result.against }
			</span>
			<span className="sr-only">
				{ OUTCOME[ result.outcome ] } { result.for } to { result.against }
			</span>
		</span>
	);
}
