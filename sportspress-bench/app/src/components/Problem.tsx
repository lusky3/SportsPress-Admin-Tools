import { useEffect } from 'react';
import type { ApiError } from '../api';
import { boot } from '../boot';
import { clearOfflineData } from '../lib/offline';
import { EmptyState } from './EmptyState';

/** The session is gone. Saved copies are dropped so the next person on this phone does not see them. */
export function SignedOut() {
	useEffect( () => {
		void clearOfflineData();
	}, [] );
	return (
		<EmptyState
			message="You’ve been signed out. Sign in again to pick up where you left off."
			action={
				<a className="btn-primary" href={ boot().loginUrl }>
					<span>Sign in</span>
				</a>
			}
		/>
	);
}

interface ProblemProps {
	error: ApiError | undefined;
	reload: () => void;
	/** What to say on a 403, e.g. "This game isn't one of yours." */
	forbidden?: string;
	/** What to say on a 404. */
	missing?: string;
}

/** A screen's data could not be loaded and there is no saved copy: say why in one line, offer the one next step. */
export function Problem( { error, reload, forbidden = 'This isn’t one of yours, so it’s not shown here.', missing = 'We couldn’t find that.' }: ProblemProps ) {
	if ( error?.code === 'signed_out' ) {
		return <SignedOut />;
	}
	if ( error?.status === 403 ) {
		return <EmptyState message={ forbidden } />;
	}
	if ( error?.status === 404 ) {
		return <EmptyState message={ missing } />;
	}
	return (
		<EmptyState
			message={ error?.code === 'offline' ? 'You’re offline and we haven’t saved this one yet.' : 'We can’t reach the bench right now.' }
			action={
				<button type="button" className="btn-primary" onClick={ reload }>
					<span>Try again</span>
				</button>
			}
		/>
	);
}

/** Placeholder rows while the first copy loads. */
export function Skeleton( { rows = 3 }: { rows?: number } ) {
	return (
		<div className="skeleton" role="status" aria-label="Loading">
			{ Array.from( { length: rows }, ( _, i ) => (
				<span key={ i } className="skeleton__row" />
			) ) }
		</div>
	);
}
