/**
 * A small "?" affordance placed next to a page/section heading. Clicking it
 * opens the consolidated Help page scrolled to the matching topic.
 *
 * Decoupled from routing: it fires a `splm:help` window event that App listens
 * for (navigates to Help + passes the topic), so any page can drop in a
 * <HelpLink topic="…" /> without threading navigation props through.
 */
export default function HelpLink( { topic, label } ) {
	const openHelp = () => {
		window.dispatchEvent( new CustomEvent( 'splm:help', { detail: topic } ) );
	};
	return (
		<button
			type="button"
			className="splm-help-link"
			onClick={ openHelp }
			aria-label={ label || 'Help for this section' }
			title={ label || 'Help' }
		>
			?
		</button>
	);
}
