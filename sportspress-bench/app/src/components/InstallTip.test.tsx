// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { _resetInstallForTests, watchInstall } from '../lib/install';
import { InstallTip } from './InstallTip';

const UA = navigator.userAgent;

function setUA( ua: string ) {
	Object.defineProperty( navigator, 'userAgent', { value: ua, configurable: true } );
}

beforeEach( () => {
	// Newer Node has its own (empty) localStorage global that hides jsdom's; use a plain map.
	const store = new Map<string, string>();
	vi.stubGlobal( 'localStorage', {
		getItem: ( k: string ) => store.get( k ) ?? null,
		setItem: ( k: string, v: string ) => void store.set( k, v ),
		removeItem: ( k: string ) => void store.delete( k ),
		clear: () => store.clear(),
	} );
	_resetInstallForTests();
	watchInstall();
} );

afterEach( () => {
	cleanup();
	setUA( UA );
	vi.unstubAllGlobals();
} );

function offerInstall() {
	const event = new Event( 'beforeinstallprompt' ) as Event & { prompt: () => Promise<void>; userChoice: Promise<{ outcome: string }> };
	event.prompt = vi.fn( async () => {} );
	event.userChoice = Promise.resolve( { outcome: 'accepted' } );
	act( () => {
		window.dispatchEvent( event );
	} );
	return event;
}

describe( 'InstallTip', () => {
	it( 'stays hidden until the browser offers an install', () => {
		render( <InstallTip /> );
		expect( screen.queryByRole( 'button', { name: /add bench/i } ) ).toBeNull();
	} );

	it( 'offers the install prompt and runs it on tap', async () => {
		render( <InstallTip /> );
		const event = offerInstall();
		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: /add bench/i } ) );
		} );
		expect( event.prompt ).toHaveBeenCalled();
	} );

	it( 'remembers Not now', () => {
		const { unmount } = render( <InstallTip /> );
		offerInstall();
		fireEvent.click( screen.getByRole( 'button', { name: 'Not now' } ) );
		expect( screen.queryByRole( 'button', { name: /add bench/i } ) ).toBeNull();
		unmount();
		render( <InstallTip /> );
		expect( screen.queryByRole( 'button', { name: /add bench/i } ) ).toBeNull();
	} );

	it( 'shows the Add to Home Screen hint on iPhone Safari', () => {
		setUA( 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1' );
		render( <InstallTip /> );
		expect( screen.getByText( /add to home screen/i ) ).toBeTruthy();
	} );
} );
