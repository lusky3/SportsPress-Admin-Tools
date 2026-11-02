import { useSyncExternalStore } from 'react';
import { isOffline, onOffline } from '../api';
import { timeLabel } from '../lib/dates';

interface OfflineBannerProps {
	/** When the data on screen was saved (Resource.savedAt). */
	savedAt?: Date;
}

/** A slim bar that appears while the app is showing saved data: "Offline · showing what we saved at 7:42 PM". */
export function OfflineBanner( { savedAt }: OfflineBannerProps ) {
	const offline = useSyncExternalStore( onOffline, isOffline, () => false );
	if ( ! offline ) {
		return null;
	}
	return (
		<div className="offline-banner" role="status">
			Offline · showing what we saved{ savedAt ? ` at ${ timeLabel( savedAt.toISOString() ) }` : '' }
		</div>
	);
}
