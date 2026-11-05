import { TwoTap } from '../../components/TwoTap';
import { entryLine } from '../../lib/live';
import { rowMeta } from './rows';
import type { Row } from './rows';

interface Props {
	rows: Row[];
	numbers: Map<number, string>;
	teamName: ( teamId: number ) => string;
	/** Final or called off: no Void or Undo. */
	locked: boolean;
	onVoid: ( r: Row ) => void;
}

function LogRow( { r, numbers, teamName, locked, onVoid }: Omit<Props, 'rows'> & { r: Row } ) {
	const struck = Boolean( r.voided || r.undoing );
	const line = entryLine( r, numbers );
	return (
		<li className={ `log__row${ r.waiting ? ' is-waiting' : '' }${ struck ? ' is-voided' : '' }` }>
			<div className="log__text">
				<p className="log__line">{ struck ? <s>{ line }</s> : line }</p>
				<p className="log__meta">{ rowMeta( r, teamName( r.team_id ) ) }</p>
			</div>
			{ struck || locked ? null : (
				<TwoTap
					label={ r.waiting ? 'Undo' : 'Void' }
					confirm="Tap again"
					disabled={ ! r.can_void }
					onConfirm={ () => {
						onVoid( r );
					} }
				/>
			) }
		</li>
	);
}

/** The game's log, newest first, with Undo (waiting here) or Void (stored) on each line. */
export function LogList( { rows, ...rest }: Props ) {
	return (
		<section className="log" aria-labelledby="log-h">
			<h2 id="log-h" className="kicker">
				Log
			</h2>
			{ rows.length ? (
				<ol className="log__list">
					{ rows.map( ( r ) => (
						<LogRow key={ r.uid } r={ r } { ...rest } />
					) ) }
				</ol>
			) : (
				<p className="muted">Nothing logged yet. Tap Goal or Penalty when it happens.</p>
			) }
		</section>
	);
}
