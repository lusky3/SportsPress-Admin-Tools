import { countLabel } from '../lib/games';
import type { Counts } from '../types';

/** "No goalie yet", "1 goalie in", "2 goalies in"; nothing when the count is not known (list rows). */
export function goalieLabel( goalies?: number ): string {
	if ( goalies === undefined ) {
		return '';
	}
	return goalies === 0 ? 'No goalie yet' : `${ goalies } goalie${ goalies === 1 ? '' : 's' } in`;
}

/**
 * Who is coming, as a bar (decoration) and the same thing in words:
 * "11 in · 2 out · 4 no reply" and the goalie line. The words carry the meaning; the bar is a glance.
 */
export function CountBar( { counts }: { counts: Counts } ) {
	const none = counts.none ?? 0;
	const total = counts.in + counts.maybe + counts.out + none || 1;
	const pct = ( n: number ) => `${ ( n / total ) * 100 }%`;
	const goalies = goalieLabel( counts.goalies_in );
	return (
		<div className="countbar">
			<div className="countbar__track" aria-hidden="true">
				<span className="countbar__in" style={ { width: pct( counts.in ) } } />
				<span className="countbar__maybe" style={ { width: pct( counts.maybe ) } } />
				<span className="countbar__out" style={ { width: pct( counts.out ) } } />
			</div>
			<p className="countbar__text">
				<span>{ countLabel( counts ) }</span>{ ' ' }
				{ goalies ? <span className={ counts.goalies_in === 0 ? 'countbar__goalie is-none' : 'countbar__goalie' }>{ goalies }</span> : null }
			</p>
		</div>
	);
}
