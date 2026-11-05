// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { _resetApiForTests } from '../api';
import { OfflineBanner } from './OfflineBanner';
import { TabBar } from './TabBar';
import { TwoTap } from './TwoTap';

beforeEach( () => {
	window.SPPA_BOOT = {
		restRoot: '/wp-json/sppa/v1/',
		nonce: 'n',
		ajaxUrl: '/wp-admin/admin-ajax.php',
		loginUrl: '/wp-login.php',
		logoutUrl: '/wp-login.php?action=logout',
		accountUrl: '/my-account/',
		helpUrl: 'https://help.example.test/',
		appBase: '/my-arl/',
		appName: 'My ARL',
		siteName: 'ARL',
	};
	_resetApiForTests();
} );

afterEach( () => {
	cleanup();
	delete window.SPPA_BOOT;
} );

describe( 'TabBar', () => {
	it( 'marks the current tab with aria-current=page and no other', () => {
		render( <TabBar current="/games/:id" teamId={ 7 } /> );
		const current = screen.getAllByRole( 'link' ).filter( ( a ) => a.getAttribute( 'aria-current' ) === 'page' );
		expect( current.map( ( a ) => a.textContent ) ).toEqual( [ 'Schedule' ] );
	} );

	it( 'links the Team tab to the first team and shows Tonight only to organizers', () => {
		const { rerender } = render( <TabBar current="/" teamId={ 7 } /> );
		expect( screen.getByRole( 'link', { name: 'Team' } ).getAttribute( 'href' ) ).toBe( '/my-arl/team/7' );
		expect( screen.queryByRole( 'link', { name: 'Tonight' } ) ).toBeNull();
		rerender( <TabBar current="/tonight" organizer /> );
		expect( screen.queryByRole( 'link', { name: 'Team' } ) ).toBeNull();
		expect( screen.getByRole( 'link', { name: 'Tonight' } ).getAttribute( 'aria-current' ) ).toBe( 'page' );
	} );
} );

describe( 'TwoTap', () => {
	it( 'announces the armed label in a polite live region and confirms on the second tap', () => {
		const onConfirm = vi.fn();
		render( <TwoTap label="Cancel call" confirm="Tap again to cancel" onConfirm={ onConfirm } /> );
		const live = screen.getByRole( 'status' );
		expect( live.getAttribute( 'aria-live' ) ).toBe( 'polite' );
		expect( live.textContent ).toBe( '' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Cancel call' } ) );
		expect( live.textContent ).toBe( 'Tap again to cancel' );
		expect( onConfirm ).not.toHaveBeenCalled();
		fireEvent.click( screen.getByRole( 'button', { name: 'Tap again to cancel' } ) );
		expect( onConfirm ).toHaveBeenCalledTimes( 1 );
		expect( live.textContent ).toBe( '' );
	} );
} );

describe( 'OfflineBanner', () => {
	it( 'keeps an empty status region mounted while online', () => {
		render( <OfflineBanner /> );
		const region = screen.getByRole( 'status' );
		expect( region.textContent ).toBe( '' );
	} );

	it( 'puts the message into the same region when the browser goes offline', () => {
		render( <OfflineBanner savedAt={ new Date( '2026-11-04T23:42:00Z' ) } /> );
		const region = screen.getByRole( 'status' );
		act( () => {
			window.dispatchEvent( new Event( 'offline' ) );
		} );
		expect( screen.getByRole( 'status' ) ).toBe( region );
		expect( region.textContent ).toBe( 'Offline · showing what we saved at 6:42 PM' );
		act( () => {
			window.dispatchEvent( new Event( 'online' ) );
		} );
		expect( region.textContent ).toBe( '' );
	} );
} );
