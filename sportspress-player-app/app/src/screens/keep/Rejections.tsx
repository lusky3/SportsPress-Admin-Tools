import { TwoTap } from '../../components/TwoTap';
import { entryLine } from '../../lib/live';
import type { Rejected } from '../../lib/outbox';
import { asRow, reasonText } from './rows';

interface Props {
	rejected: Rejected[];
	names: Map<number, string>;
	numbers: Map<number, string>;
	onDismiss: ( uid: string ) => void;
	onRetry: ( uid: string ) => void;
	onEnterAgain: ( r: Rejected ) => void;
}

function Actions( { r, onDismiss, onRetry, onEnterAgain }: Pick<Props, 'onDismiss' | 'onRetry' | 'onEnterAgain'> & { r: Rejected } ) {
	const uid = r.entry.uid;
	if ( r.reason.startsWith( 'void_' ) ) {
		return (
			<button type="button" className="btn-quiet" onClick={ () => onDismiss( uid ) }>
				OK
			</button>
		);
	}
	return (
		<>
			{ r.whole ? (
				<button type="button" className="btn-quiet" onClick={ () => onRetry( uid ) }>
					Try again
				</button>
			) : (
				<button type="button" className="btn-quiet" onClick={ () => onEnterAgain( r ) }>
					Enter it again
				</button>
			) }
			<TwoTap label="Discard" confirm="Tap again to discard" onConfirm={ () => onDismiss( uid ) } />
		</>
	);
}

/** Entries the server refused (or would not undo), each with what the keeper can do about it. */
export function Rejections( { rejected, names, numbers, ...actions }: Props ) {
	return (
		<>
			{ rejected.map( ( r ) => (
				<div key={ r.entry.uid } className="notice rejected">
					<p>
						{ r.reason.startsWith( 'void_' ) ? 'Couldn’t undo' : 'Not saved' }: { entryLine( asRow( r.entry, names ), numbers ) }. { reasonText( r.reason ) }.
					</p>
					<div className="rejected__actions">
						<Actions r={ r } { ...actions } />
					</div>
				</div>
			) ) }
		</>
	);
}
