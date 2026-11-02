import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import { VitePWA } from 'vite-plugin-pwa';

/**
 * Builds into ../build/, which the PHP app shell (class-app-shell.php) serves:
 * - build/.vite/manifest.json names the hashed entry (key "index.html"),
 * - build/sw.js is the Workbox worker served at /bench/sw.js,
 * - build/icons/* are named by the web app manifest.
 *
 * Asset URLs are relative (base './') because the plugin can live under any
 * wp-content path; the shell prefixes them with the real build URL.
 */

/** Literal that PHP swaps for the plugin's build URL when it serves /bench/sw.js. */
const BUILD_URL_TOKEN = '__SPBN_BUILD_URL__';

export default defineConfig({
	base: './',
	plugins: [
		react(),
		VitePWA({
			strategies: 'generateSW',
			filename: 'sw.js',
			injectRegister: false,
			manifest: false,
			workbox: {
				// Only the app shell's own assets are precached. Icons are fetched on demand.
				globPatterns: [ 'assets/**/*.{js,css,woff2}', 'icons/icon-192.png', 'icons/icon-512.png' ],
				// Prefix every precache URL so the PHP route can point it at the real build dir.
				modifyURLPrefix: { '': BUILD_URL_TOKEN },
				// One file, so /bench/sw.js never imports a runtime from a URL that would 404.
				inlineWorkboxRuntime: true,
				navigateFallback: null,
				cleanupOutdatedCaches: true,
				skipWaiting: true,
				clientsClaim: true,
				sourcemap: false,
				runtimeCaching: [
					{
						// The HTML shell: fresh when online, last good copy when not.
						urlPattern: ( { request, url } ) => request.mode === 'navigate' && url.pathname.startsWith( '/bench' ),
						handler: 'NetworkFirst',
						options: {
							cacheName: 'spbn-shell',
							networkTimeoutSeconds: 4,
						},
					},
					{
						// The REST API (pretty permalinks or ?rest_route=): GET only.
						urlPattern: ( { request, url } ) =>
							request.method === 'GET' &&
							( url.pathname.indexOf( '/wp-json/spbn/v1/' ) !== -1 || ( url.searchParams.get( 'rest_route' ) || '' ).indexOf( '/spbn/v1/' ) === 0 ),
						handler: 'NetworkFirst',
						options: {
							cacheName: 'spbn-api',
							networkTimeoutSeconds: 4,
							expiration: { maxEntries: 60, maxAgeSeconds: 7 * 24 * 60 * 60 },
							plugins: [
								{
									// Tell the page when it is looking at a saved copy.
									cachedResponseWillBeUsed: async ( { cachedResponse } ) => {
										if ( ! cachedResponse ) {
											return cachedResponse;
										}
										const headers = new Headers( cachedResponse.headers );
										headers.set( 'X-SPBN-From-Cache', '1' );
										return new Response( cachedResponse.body, {
											status: cachedResponse.status,
											statusText: cachedResponse.statusText,
											headers,
										} );
									},
								},
							],
						},
					},
					{
						urlPattern: ( { request } ) => request.destination === 'font',
						handler: 'CacheFirst',
						options: {
							cacheName: 'spbn-fonts',
							expiration: { maxEntries: 10, maxAgeSeconds: 365 * 24 * 60 * 60 },
							cacheableResponse: { statuses: [ 0, 200 ] },
						},
					},
				],
			},
		}),
	],
	build: {
		outDir: '../build',
		emptyOutDir: true,
		manifest: true,
		sourcemap: false,
	},
	test: {
		include: [ 'src/**/*.test.ts' ],
		environment: 'node',
	},
});
