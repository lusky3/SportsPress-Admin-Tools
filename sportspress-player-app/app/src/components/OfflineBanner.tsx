import { useSyncExternalStore } from 'react';
import { isOffline, onOffline } from '../api';
import { timeLabel } from '../lib/dates';

interface OfflineBannerProps {
	/** When the data on screen was saved (Resource.savedAt). */
	savedAt?: Date;
}

/**
 * A slim bar that appears while the app is showing saved data: "Offline · showing what we saved at 7:42 PM".
 * The status region stays mounted and empty while online, so screen readers announce the text when it arrives.
 */
export function OfflineBanner( { savedAt }: OfflineBannerProps ) {
	const offline = useSyncExternalStore( onOffline, isOffline, () => false );
	return (
		<div className="offline-slot" role="status">
			{ offline ? <p className="offline-banner">Offline · showing what we saved{ savedAt ? ` at ${ timeLabel( savedAt.toISOString() ) }` : '' }</p> : null }
		</div>
	);
}
