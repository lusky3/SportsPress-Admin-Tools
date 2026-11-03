import { useState } from 'react';
import { addDays, leagueDate, longDate } from '../lib/dates';

/** A day that starts at today, for a stepper. */
export function useDay() {
	const today = leagueDate( new Date() );
	const [ date, setDate ] = useState( today );
	return { today, date, setDate };
}

/** "‹ Tonight ›" with the long date under it, and "Back to today" once you have stepped away. */
export function DayStepper( { today, date, setDate }: ReturnType<typeof useDay> ) {
	const shift = Math.round( ( Date.parse( date ) - Date.parse( today ) ) / 86_400_000 );
	const title = { [ -1 ]: 'Last night', 0: 'Tonight', 1: 'Tomorrow' }[ shift ] ?? longDate( date );
	return (
		<>
			<header className="night-head">
				<button type="button" className="stepper" aria-label="Previous day" onClick={ () => setDate( addDays( date, -1 ) ) }>
					‹
				</button>
				<div className="night-head__title">
					<h1>{ title }</h1>
					<p className="muted">{ longDate( date ) }</p>
				</div>
				<button type="button" className="stepper" aria-label="Next day" onClick={ () => setDate( addDays( date, 1 ) ) }>
					›
				</button>
			</header>
			{ shift !== 0 ? (
				<button type="button" className="btn-link" onClick={ () => setDate( today ) }>
					Back to today
				</button>
			) : null }
		</>
	);
}
