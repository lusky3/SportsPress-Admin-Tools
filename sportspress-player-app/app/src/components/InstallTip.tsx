import { useSyncExternalStore } from 'react';
import { boot } from '../boot';
import { dismiss, installPrompt, isDismissed, isIosSafari, isStandalone, runInstall, subscribeInstall } from '../lib/install';

function snapshot(): string {
	if ( isStandalone() ) {
		return 'installed';
	}
	return `${ installPrompt() ? 'prompt' : isIosSafari() ? 'ios' : 'none' }${ isDismissed() ? ':dismissed' : '' }`;
}

/**
 * A small, skippable "add to home screen" tip. On Next it shows until "Not now";
 * on Me (`always`) it stays as a quiet line, since that is where people look for it.
 */
export function InstallTip( { always = false }: { always?: boolean } ) {
	const state = useSyncExternalStore( subscribeInstall, snapshot, () => 'none' );
	const [ kind, dismissed ] = state.split( ':' );
	const { appName } = boot();
	if ( kind === 'installed' || kind === 'none' || ( dismissed && ! always ) ) {
		return null;
	}
	return (
		<aside className="tip" aria-label={ `Install ${ appName }` }>
			<p>
				{ kind === 'prompt'
					? `Put ${ appName } on your home screen. It opens like an app and still shows your games with no signal.`
					: `On iPhone, tap Share, then Add to Home Screen, to open ${ appName } like an app.` }
			</p>
			<div className="actions">
				{ kind === 'prompt' ? (
					<button type="button" className="btn-primary" onClick={ () => void runInstall() }>
						<span>{ `Add ${ appName }` }</span>
					</button>
				) : null }
				{ always ? null : (
					<button type="button" className="btn-link" onClick={ dismiss }>
						Not now
					</button>
				) }
			</div>
		</aside>
	);
}
