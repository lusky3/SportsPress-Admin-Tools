import { LEAF_PATH, LEAF_VIEWBOX } from '../lib/leaf';

interface LeafProps {
	size?: number;
	className?: string;
	/** Announced to screen readers; leave out when the leaf is decoration. */
	title?: string;
}

/** The maple leaf mark, drawn in the current text colour. */
export function Leaf( { size = 32, className, title }: LeafProps ) {
	return (
		<svg
			className={ className }
			width={ size }
			height={ size }
			viewBox={ LEAF_VIEWBOX }
			fill="currentColor"
			role={ title ? 'img' : undefined }
			aria-label={ title }
			aria-hidden={ title ? undefined : true }
			focusable="false"
		>
			<path d={ LEAF_PATH } />
		</svg>
	);
}
