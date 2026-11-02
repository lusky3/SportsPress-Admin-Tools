/*
 * "Put the app on your home screen". Chrome and Edge fire beforeinstallprompt once,
 * early, so it is caught at startup (watchInstall in main.tsx) and kept until a
 * screen shows the tip. iPhone Safari has no prompt; it gets a one-line hint.
 */

interface InstallPromptEvent extends Event {
	prompt: () => Promise<void>;
}

const DISMISSED = 'sppa-install-dismissed';

let deferred: InstallPromptEvent | null = null;
let installed = false;
const listeners = new Set<() => void>();

function emit(): void {
	for ( const listener of [ ...listeners ] ) {
		listener();
	}
}

export function subscribeInstall( listener: () => void ): () => void {
	listeners.add( listener );
	return () => {
		listeners.delete( listener );
	};
}

/** The held prompt, or null. */
export function installPrompt(): InstallPromptEvent | null {
	return deferred;
}

/** Start listening. Safe to call more than once. */
export function watchInstall(): void {
	window.addEventListener( 'beforeinstallprompt', onPrompt );
	window.addEventListener( 'appinstalled', onInstalled );
}

function onPrompt( event: Event ): void {
	event.preventDefault(); // we show our own tip instead of the browser's mini-bar
	deferred = event as InstallPromptEvent;
	emit();
}

function onInstalled(): void {
	installed = true;
	deferred = null;
	emit();
}

/** Already running from the home screen (or just installed). */
export function isStandalone(): boolean {
	return installed || window.matchMedia?.( '(display-mode: standalone)' ).matches || ( navigator as { standalone?: boolean } ).standalone === true;
}

/** iPhone or iPad Safari, which installs only through Share > Add to Home Screen. */
export function isIosSafari(): boolean {
	const ua = navigator.userAgent;
	return /iPhone|iPad|iPod/.test( ua ) && /Safari\//.test( ua ) && ! /CriOS|FxiOS|EdgiOS/.test( ua );
}

export function isDismissed(): boolean {
	try {
		return localStorage.getItem( DISMISSED ) === '1';
	} catch {
		return false;
	}
}

export function dismiss(): void {
	try {
		localStorage.setItem( DISMISSED, '1' );
	} catch {
		// Private mode: the tip just comes back next time.
	}
	emit();
}

/** Show the browser's install dialog; the prompt can only be used once. */
export async function runInstall(): Promise<void> {
	const event = deferred;
	deferred = null;
	emit();
	await event?.prompt();
}

export function _resetInstallForTests(): void {
	window.removeEventListener( 'beforeinstallprompt', onPrompt );
	window.removeEventListener( 'appinstalled', onInstalled );
	deferred = null;
	installed = false;
	listeners.clear();
}
