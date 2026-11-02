import type { ReactNode } from 'react';
import { Leaf } from './Leaf';

interface EmptyStateProps {
	/** One line. Plain and friendly: "Never played? Perfect." */
	message: ReactNode;
	/** An optional single next step, usually a link or button. */
	action?: ReactNode;
}

/** The leaf mark and one line, for any screen with nothing to show. */
export function EmptyState( { message, action }: EmptyStateProps ) {
	return (
		<div className="empty-state">
			<Leaf size={ 40 } className="empty-state__leaf" />
			<p className="empty-state__message">{ message }</p>
			{ action ? <div className="empty-state__action">{ action }</div> : null }
		</div>
	);
}
