import { useEffect, useRef } from '@wordpress/element';

// One entry per help section. `id` matches the `topic` passed to <HelpLink>.
const SECTIONS = [
	{ id: 'dashboard', title: 'Dashboard' },
	{ id: 'schedule', title: 'Schedule' },
	{ id: 'scores', title: 'Score Entry' },
	{ id: 'score-sheets', title: 'Score Sheets' },
	{ id: 'standings', title: 'Standings' },
	{ id: 'rosters', title: 'Rosters & Skill' },
	{ id: 'payments', title: 'Payments' },
	{ id: 'div-balance', title: 'Division Balance' },
	{ id: 'team-compare', title: 'Compare' },
	{ id: 'season-report', title: 'Season Report' },
	{ id: 'season-setup', title: 'Seasons & Rollover' },
	{ id: 'health', title: 'Health Checks' },
	{ id: 'schedule-gen', title: 'Schedule Generator' },
];

export default function Help( { helpTopic } ) {
	const rootRef = useRef( null );

	// Scroll to the requested topic when arriving via a "?" link. helpTopic may
	// carry a "#<nonce>" suffix so re-clicking the same topic re-triggers this.
	useEffect( () => {
		const topic = ( helpTopic || '' ).split( '#' )[ 0 ];
		if ( ! topic ) return;
		const el = document.getElementById( `help-${ topic }` );
		if ( el ) {
			el.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			el.classList.add( 'splm-help-section--flash' );
			const t = setTimeout( () => el.classList.remove( 'splm-help-section--flash' ), 1600 );
			return () => clearTimeout( t );
		}
	}, [ helpTopic ] );

	const jump = ( id ) => {
		document.getElementById( `help-${ id }` )?.scrollIntoView( { behavior: 'smooth', block: 'start' } );
	};

	return (
		<div className="splm-help" ref={ rootRef }>
			<h2>Help</h2>
			<p className="splm-muted">How each part of the dashboard works. The <span className="splm-help-link splm-help-link--inline">?</span> icons on each tab jump straight to the matching section here.</p>

			<nav className="splm-help-toc" aria-label="Help contents">
				{ SECTIONS.map( ( s ) => (
					<button key={ s.id } type="button" className="splm-help-toc__item" onClick={ () => jump( s.id ) }>{ s.title }</button>
				) ) }
			</nav>

			<section id="help-dashboard" className="splm-help-section">
				<h3>Dashboard</h3>
				<p>The landing view: upcoming games, recent scores, and recent activity (registrations, payments, roster changes). Use the season filter in the top bar to scope the whole dashboard to a season, and the player search to jump to a roster.</p>
			</section>

			<section id="help-schedule" className="splm-help-section">
				<h3>Schedule</h3>
				<p>Browse games with the <strong>Show</strong> (Upcoming / Past / All), <strong>Division</strong>, and <strong>Team</strong> filters. Each game links to <strong>View</strong> (public event page) and, for managers, <strong>Edit</strong> (the WordPress event editor), plus Reschedule and Cancel.</p>
				<h4>Importing games (CSV or XLSX)</h4>
				<p>Click <strong>Import Games</strong> and upload a spreadsheet with a header row and one row per game. Column names are matched case-insensitively.</p>
				<ul>
					<li><strong>Required:</strong> <code>Date</code>, <code>Home Team</code>, <code>Away Team</code></li>
					<li><strong>Optional:</strong> <code>Time</code>, <code>Venue</code>, <code>Division</code></li>
				</ul>
				<p className="splm-muted">Accepted header aliases: Date (or “Game Date”, “Event Date”) · Time (or “Game Time”, “Start Time”) · Home Team (or “Home”) · Away Team (or “Away”, “Visitor”) · Venue (or “Location”, “Arena”, “Rink”) · Division (or “League”). Team names are matched to existing teams. Rows missing a required value are skipped and reported, and you get a preview before anything is created.</p>
			</section>

			<section id="help-scores" className="splm-help-section">
				<h3>Score Entry</h3>
				<p>Two modes: <strong>One at a time</strong> steps through games needing scores (with date, time, and venue); <strong>Game Night</strong> enters a whole night’s scores in one table. After a score you can <strong>Enter Player Stats</strong> (goals/assists/PIM) — also reachable up front. If a game shows no players, assign players to its teams first (Rosters), or skip.</p>
			</section>

			<section id="help-score-sheets" className="splm-help-section">
				<h3>Score Sheets</h3>
				<p>Turn a photo of a completed, handwritten score sheet into an event’s final score and player stats.</p>
				<ol>
					<li><strong>Add a sheet</strong> — <em>Upload sheet</em> (photo or PDF). Sheets can also arrive by email/SMS/WhatsApp if an administrator set up remote intake.</li>
					<li><strong>Automatic reading</strong> — a sheet moves through <em>Queued → Processing</em>, then <em>Pending review</em>. <em>Failed</em> means it couldn’t be read; upload a clearer photo.</li>
					<li><strong>Review</strong> — open a <em>Pending review</em> sheet; anything uncertain is highlighted. Confirm the game, fix misread numbers/scores, then <em>Confirm &amp; apply to event</em>.</li>
					<li><strong>Done</strong> — confirmed sheets write straight to the event and open read-only.</li>
				</ol>
				<p className="splm-muted">Write-ins are fine — leave a substitute as “Write-in / no player record”; their goals still count in the team total. Only pick a roster player when a jersey was misread.</p>
			</section>

			<section id="help-standings" className="splm-help-section">
				<h3>Standings</h3>
				<p>Standings per division, separated into <strong>Regular Season</strong> and <strong>Playoffs</strong>, ordered by division. Columns are hockey-standard: GP, W, L, T, OT, GF, GA, DIFF, Pts. Team names link to the team.</p>
			</section>

			<section id="help-rosters" className="splm-help-section">
				<h3>Rosters &amp; Skill</h3>
				<p>Pick a team to see its roster. You can move players, set captains, edit skill levels, add notes (the <em>Notes</em> button shows a count when notes exist), and import a roster by CSV.</p>
				<h4>Calculate Skills</h4>
				<p>Auto-rates players 1–10 from their game stats. Pick the season to rate from (defaults to <strong>All-time</strong>; a season includes its playoffs), then <strong>Calculate Skills</strong>. Ratings come from the box scores, weighting <strong>goals ×2 and assists ×0.5</strong> per game (goalies by goals-against average), mapped to a 1–10 percentile. Players with fewer than 3 games are skipped. Manually-set skills are never overwritten.</p>
				<p className="splm-muted">“Not registered” means the player has no registration record for the selected season — check Payments/registration if that’s unexpected.</p>
			</section>

			<section id="help-payments" className="splm-help-section">
				<h3>Payments</h3>
				<p>Per-player payment status for the season, paged (controls at top and bottom). The amount links to the underlying WooCommerce order. The summary counts reflect the current page.</p>
			</section>

			<section id="help-div-balance" className="splm-help-section">
				<h3>Division Balance</h3>
				<p>Per division: team count, rated players, average skill and range, and a skill-level distribution. <strong>Click a bar</strong> in the distribution to see exactly which players are at that skill level (each links to their editor).</p>
			</section>

			<section id="help-team-compare" className="splm-help-section">
				<h3>Compare</h3>
				<p>Head-to-head record and roster comparison for two teams. With a season selected, roster counts are scoped to that season.</p>
			</section>

			<section id="help-season-report" className="splm-help-section">
				<h3>Season Report</h3>
				<p>A season overview: game totals, a registration &amp; payment reconciliation (roster vs registered vs paid), a per-division summary, and stat leaders (points/goals/assists/PIM).</p>
			</section>

			<section id="help-season-setup" className="splm-help-section">
				<h3>Seasons &amp; Rollover</h3>
				<p><strong>Create Season</strong>: name the season, then add each division — pick an existing division or create a new one, click <em>Add</em>, and choose its teams in the box that appears. <em>Preview changes</em> shows what will be created (standings tables, calendars, playoffs, default season) before you confirm.</p>
				<p><strong>Player Rollover</strong>: move players who didn’t re-register from their current team to past teams. Pick the season they’re coming <em>from</em> and the new season, preview who isn’t returning, then apply.</p>
			</section>

			<section id="help-health" className="splm-help-section">
				<h3>Health Checks</h3>
				<p>Flags data problems that skew standings, notifications, or reports — past games missing scores, games without a venue, players without an email, teams with no players. Each check lists the affected records with a link to fix them, and missing scores links to Score Entry.</p>
			</section>

			<section id="help-schedule-gen" className="splm-help-section">
				<h3>Schedule Generator</h3>
				<p>Builds a balanced season schedule from a saved configuration (teams, venues, dates, constraints), previews it, and publishes it as events. Requires the Schedule Generator module.</p>
			</section>
		</div>
	);
}
