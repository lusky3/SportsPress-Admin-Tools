/**
 * Draws the install icons from the leaf mark: a blue maple leaf on the ink navy.
 *
 *   npm run icons
 *
 * Writes public/icons/{icon-192,icon-512,icon-maskable-512}.png (the names the PHP
 * web app manifest uses); Vite copies them to build/icons/. The PNGs are committed,
 * so this only needs to run when the mark changes. The maskable icon keeps the leaf
 * inside the central 80% safe zone so any launcher mask can crop it.
 */
import { readFileSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import sharp from 'sharp';

const here = dirname( fileURLToPath( import.meta.url ) );
const out = join( here, '..', 'public', 'icons' );

const INK = '#132343';
const ICE = '#74C0E1';

const source = readFileSync( join( here, '..', 'src', 'lib', 'leaf.ts' ), 'utf8' );
const match = /LEAF_PATH\s*=\s*\n?\s*'([^']+)'/.exec( source );
if ( ! match ) {
	throw new Error( 'LEAF_PATH not found in src/lib/leaf.ts' );
}
const leaf = match[ 1 ];

/** Leaf drawn `fraction` of the icon wide, centred; the leaf box is 100 wide with the mark spanning x 4-96 and y 4-96. */
function svg( size, fraction ) {
	const scale = ( size * fraction ) / 92;
	const offset = ( size - 100 * scale ) / 2;
	return `<svg xmlns="http://www.w3.org/2000/svg" width="${ size }" height="${ size }" viewBox="0 0 ${ size } ${ size }">
<rect width="${ size }" height="${ size }" fill="${ INK }"/>
<g transform="translate(${ offset } ${ offset }) scale(${ scale })"><path d="${ leaf }" fill="${ ICE }"/></g>
</svg>`;
}

mkdirSync( out, { recursive: true } );

const icons = [
	// "any" icons: the leaf fills about 70% of the square.
	{ file: 'icon-192.png', size: 192, fraction: 0.7 },
	{ file: 'icon-512.png', size: 512, fraction: 0.7 },
	// Maskable: the mark's corners must sit inside the circle of radius 40% of the size.
	{ file: 'icon-maskable-512.png', size: 512, fraction: 0.5 },
];

for ( const { file, size, fraction } of icons ) {
	await sharp( Buffer.from( svg( size, fraction ) ) )
		.png( { compressionLevel: 9 } )
		.toFile( join( out, file ) );
	console.log( `wrote public/icons/${ file }` );
}
