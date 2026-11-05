/** What the PHP shell puts on `window.SPPA_BOOT` (SPPA_App_Shell::boot_data). */
export interface SppaBoot {
	/** REST root with a trailing slash, e.g. https://arl.hockey/wp-json/sppa/v1/ */
	restRoot: string;
	/** `wp_rest` nonce; goes stale on a long-open app, see api.ts. */
	nonce: string;
	ajaxUrl: string;
	loginUrl: string;
	logoutUrl: string;
	/** The theme's My Account page, where a user claims their player. */
	accountUrl: string;
	helpUrl: string;
	/** Where the app lives, with both slashes: '/my-arl/' unless the site changed it. */
	appBase: string;
	/** The name players see, 'My ARL' unless the site changed it. */
	appName: string;
	siteName: string;
}

declare global {
	interface Window {
		SPPA_BOOT?: SppaBoot;
	}
}

/** Defaults for `vite dev`, where no PHP shell injects the object. */
const DEV_BOOT: SppaBoot = {
	restRoot: '/wp-json/sppa/v1/',
	nonce: '',
	ajaxUrl: '/wp-admin/admin-ajax.php',
	loginUrl: '/wp-login.php',
	logoutUrl: '/wp-login.php?action=logout',
	accountUrl: '/my-account/',
	helpUrl: 'https://help.arl.hockey/',
	appBase: '/',
	appName: 'My ARL',
	siteName: 'ARL',
};

export function boot(): SppaBoot {
	return ( typeof window !== 'undefined' && window.SPPA_BOOT ) || DEV_BOOT;
}
