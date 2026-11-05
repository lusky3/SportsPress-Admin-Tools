interface CrestProps {
	name: string;
	/** Crest URL; '' draws a monogram instead. */
	logo: string;
	size?: number;
}

function initials( name: string ): string {
	const words = name.replace( /[^\p{L}\p{N}\s]/gu, '' ).split( /\s+/ ).filter( Boolean );
	return ( words.length > 1 ? words[ 0 ][ 0 ] + words[ words.length - 1 ][ 0 ] : ( words[ 0 ] ?? '?' ).slice( 0, 2 ) ).toUpperCase();
}

/** A team crest, or the team's initials on a skewed tile when it has none. Decorative: the name is always written beside it. */
export function Crest( { name, logo, size = 40 }: CrestProps ) {
	if ( logo ) {
		return <img className="crest" src={ logo } alt="" width={ size } height={ size } loading="lazy" decoding="async" />;
	}
	return (
		<span className="crest crest--mono" style={ { width: size, height: size, fontSize: size * 0.42 } } aria-hidden="true">
			<span>{ initials( name ) }</span>
		</span>
	);
}
