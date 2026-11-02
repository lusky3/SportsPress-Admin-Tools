import { renameSync } from 'node:fs';
import { resolve } from 'node:path';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';
import { viteSingleFile } from 'vite-plugin-singlefile';

/**
 * The clickable demo: one self-contained demo-dist/index.html (script, styles and fonts inlined)
 * that runs the real app against in-memory sample data. Not part of the plugin: `npm run build:demo`,
 * output ignored by version control. VITE_DEMO switches on the small seams in api.ts, router.tsx and lib/offline.ts.
 */
export default defineConfig( {
	base: './',
	publicDir: false,
	define: { 'import.meta.env.VITE_DEMO': '"1"' },
	plugins: [
		react(),
		viteSingleFile(),
		{
			name: 'demo-index',
			apply: 'build',
			closeBundle() {
				renameSync( resolve( __dirname, 'demo-dist/demo.html' ), resolve( __dirname, 'demo-dist/index.html' ) );
			},
		},
	],
	build: {
		outDir: 'demo-dist',
		emptyOutDir: true,
		sourcemap: false,
		rollupOptions: { input: 'demo.html' },
	},
} );
