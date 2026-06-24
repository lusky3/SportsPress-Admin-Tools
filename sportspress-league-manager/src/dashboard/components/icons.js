/**
 * Inline SVG icon set (UX-3 / UI-8).
 *
 * Replaces emoji used as structural nav icons, which render inconsistently
 * across platforms and could not be sized/themed. Schedule and Generate
 * previously both used 📅 — they now have DISTINCT glyphs (calendar vs.
 * sparkle/auto-generate). All icons are decorative: callers wrap them with an
 * adjacent text label, so the <svg> carries aria-hidden + focusable=false.
 *
 * No runtime dependency — these are tiny stroke paths drawn with currentColor
 * (styled via .splm-icon in styles.css).
 */
import { createElement } from '@wordpress/element';

const ICON_PATHS = {
	// dashboard: 2x2 grid
	dashboard: <><rect x="3" y="3" width="7" height="7" rx="1" /><rect x="14" y="3" width="7" height="7" rx="1" /><rect x="14" y="14" width="7" height="7" rx="1" /><rect x="3" y="14" width="7" height="7" rx="1" /></>,
	// schedule: calendar
	schedule: <><rect x="3" y="4" width="18" height="17" rx="2" /><line x1="3" y1="9" x2="21" y2="9" /><line x1="8" y1="2" x2="8" y2="6" /><line x1="16" y1="2" x2="16" y2="6" /></>,
	// scores: clipboard / scoreboard
	scores: <><rect x="4" y="3" width="16" height="18" rx="2" /><line x1="8" y1="8" x2="16" y2="8" /><line x1="8" y1="12" x2="16" y2="12" /><line x1="8" y1="16" x2="12" y2="16" /></>,
	// standings: trophy
	standings: <><path d="M7 4h10v4a5 5 0 0 1-10 0V4z" /><path d="M7 6H4v1a3 3 0 0 0 3 3" /><path d="M17 6h3v1a3 3 0 0 1-3 3" /><line x1="12" y1="13" x2="12" y2="17" /><line x1="8" y1="20" x2="16" y2="20" /><line x1="10" y1="17" x2="14" y2="17" /></>,
	// rosters: people
	rosters: <><circle cx="9" cy="8" r="3" /><path d="M3 20a6 6 0 0 1 12 0" /><path d="M16 6a3 3 0 0 1 0 6" /><path d="M17 14a6 6 0 0 1 4 6" /></>,
	// payments: dollar in circle
	payments: <><circle cx="12" cy="12" r="9" /><path d="M14.5 9.5a2.5 2 0 0 0-2.5-1.5c-1.5 0-2.5.8-2.5 2s1 1.8 2.5 2 2.5.8 2.5 2-1 2-2.5 2a2.5 2 0 0 1-2.5-1.5" /><line x1="12" y1="6" x2="12" y2="8" /><line x1="12" y1="16" x2="12" y2="18" /></>,
	// div-balance: scale
	'div-balance': <><line x1="12" y1="3" x2="12" y2="21" /><path d="M6 8h12" /><path d="M3 13l3-5 3 5a3 3 0 0 1-6 0z" /><path d="M15 13l3-5 3 5a3 3 0 0 1-6 0z" /></>,
	// team-compare: two arrows
	'team-compare': <><path d="M4 8h13l-3-3" /><path d="M20 16H7l3 3" /></>,
	// season-report: document with lines
	'season-report': <><path d="M6 2h9l5 5v15H6z" /><path d="M15 2v5h5" /><line x1="9" y1="13" x2="16" y2="13" /><line x1="9" y1="17" x2="16" y2="17" /></>,
	// health: magnifier with pulse
	health: <><circle cx="11" cy="11" r="7" /><line x1="21" y1="21" x2="16.65" y2="16.65" /><path d="M7.5 11h2l1-2 2 4 1-2h1.5" /></>,
	// schedule-gen / generate: sparkle (DISTINCT from schedule's calendar)
	'schedule-gen': <><path d="M12 3l1.8 4.7L18.5 9.5 13.8 11.3 12 16l-1.8-4.7L5.5 9.5l4.7-1.8z" /><path d="M18 15l.7 1.8L20.5 17.5 18.7 18.2 18 20l-.7-1.8L15.5 17.5l1.8-.7z" /></>,
	// gear (UX-14 customize toggle)
	gear: <><circle cx="12" cy="12" r="3" /><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" /></>,
	// pencil (edit affordance, UX-8)
	pencil: <><path d="M12 20h9" /><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z" /></>,
	// more / overflow
	more: <><circle cx="5" cy="12" r="1.5" /><circle cx="12" cy="12" r="1.5" /><circle cx="19" cy="12" r="1.5" /></>,
};

/**
 * Render an icon by key.
 *
 * @param {Object} props
 * @param {string} props.name  Icon key.
 * @param {number} [props.size] Optional explicit pixel size; otherwise CSS sizes it.
 */
export default function Icon( { name, size } ) {
	const paths = ICON_PATHS[ name ];
	if ( ! paths ) return null;
	const style = size ? { width: size, height: size } : undefined;
	return createElement(
		'svg',
		{
			className: 'splm-icon',
			viewBox: '0 0 24 24',
			'aria-hidden': 'true',
			focusable: 'false',
			role: 'img',
			style,
		},
		paths
	);
}
