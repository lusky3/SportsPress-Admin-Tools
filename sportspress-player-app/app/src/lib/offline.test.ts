import { afterEach, describe, expect, it, vi } from 'vitest';
import { clearOfflineData, signOut } from './offline';

afterEach( () => {
	vi.unstubAllGlobals();
} );

describe( 'signing out', () => {
	it( 'deletes the shell and API caches, then follows the logout link', async () => {
		const order: string[] = [];
		vi.stubGlobal( 'caches', {
			keys: async () => [ 'sppa-shell', 'sppa-api', 'sppa-fonts', 'workbox-precache' ],
			delete: async ( name: string ) => {
				order.push( `delete ${ name }` );
				return true;
			},
		} );
		vi.stubGlobal( 'window', {
			SPPA_BOOT: { logoutUrl: 'https://example.test/wp-login.php?action=logout&_wpnonce=x' },
			location: { assign: ( url: string ) => order.push( `go ${ url }` ) },
		} );
		await signOut();
		expect( order ).toEqual( [ 'delete sppa-shell', 'delete sppa-api', 'go https://example.test/wp-login.php?action=logout&_wpnonce=x' ] );
	} );

	it( 'still signs out when storage is blocked', async () => {
		const go = vi.fn();
		vi.stubGlobal( 'caches', {
			keys: async () => {
				throw new Error( 'SecurityError' );
			},
		} );
		vi.stubGlobal( 'window', { SPPA_BOOT: { logoutUrl: '/out' }, location: { assign: go } } );
		await expect( clearOfflineData() ).resolves.toBeUndefined();
		await signOut();
		expect( go ).toHaveBeenCalledWith( '/out' );
	} );
} );
