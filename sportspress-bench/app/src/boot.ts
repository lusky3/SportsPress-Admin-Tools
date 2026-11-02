/** What the PHP shell puts on `window.SPBN_BOOT` (SPBN_App_Shell::boot_data). */
export interface SpbnBoot {
	/** REST root with a trailing slash, e.g. https://arl.hockey/wp-json/spbn/v1/ */
	restRoot: string;
	/** `wp_rest` nonce; goes stale on a long-open app, see api.ts. */
	nonce: string;
	ajaxUrl: string;
	loginUrl: string;
	logoutUrl: string;
	/** The theme's My Account page, where a user claims their player. */
	accountUrl: string;
	helpUrl: string;
	/** Where the app lives, '/bench/'. */
	appBase: string;
	siteName: string;
}

declare global {
	interface Window {
		SPBN_BOOT?: SpbnBoot;
	}
}

/** Defaults for `vite dev`, where no PHP shell injects the object. */
const DEV_BOOT: SpbnBoot = {
	restRoot: '/wp-json/spbn/v1/',
	nonce: '',
	ajaxUrl: '/wp-admin/admin-ajax.php',
	loginUrl: '/wp-login.php',
	logoutUrl: '/wp-login.php?action=logout',
	accountUrl: '/my-account/',
	helpUrl: 'https://help.arl.hockey/',
	appBase: '/',
	siteName: 'ARL',
};

export function boot(): SpbnBoot {
	return ( typeof window !== 'undefined' && window.SPBN_BOOT ) || DEV_BOOT;
}
