import { useRef } from '@wordpress/element';

// Module-level counter: stable unique element ids per component instance
// without randomness during render.
let uidCount = 0;

export default function useUid( prefix ) {
	const ref = useRef( null );
	if ( ref.current === null ) {
		uidCount += 1;
		ref.current = `${ prefix }-${ uidCount }`;
	}
	return ref.current;
}
