import { useEffect, useRef } from '@wordpress/element';

import imgDashboard from '../assets/help/dashboard.jpg';
import imgSchedule from '../assets/help/schedule.jpg';
import imgScores from '../assets/help/scores.jpg';
import imgScoreSheets from '../assets/help/score-sheets.jpg';
import imgStandings from '../assets/help/standings.jpg';
import imgRosters from '../assets/help/rosters.jpg';
import imgPayments from '../assets/help/payments.jpg';
import imgWaitlist from '../assets/help/waitlist.jpg';
import imgDivBalance from '../assets/help/div-balance.jpg';
import imgTeamCompare from '../assets/help/team-compare.jpg';
import imgLeaders from '../assets/help/leaders.jpg';
import imgLeadersPenaltyWatch from '../assets/help/leaders-penalty-watch.jpg';
import imgNotices from '../assets/help/notices.jpg';
import imgSeasonReport from '../assets/help/season-report.jpg';
import imgSeasonSetup from '../assets/help/season-setup.jpg';
import imgHealth from '../assets/help/health.jpg';
import imgScheduleGen from '../assets/help/schedule-gen.jpg';

// One entry per help section. `id` matches the `topic` passed to <HelpLink>.
const SECTIONS = [
	{ id: 'dashboard', title: 'Dashboard' },
	{ id: 'schedule', title: 'Schedule' },
	{ id: 'scores', title: 'Score Entry' },
	{ id: 'score-sheets', title: 'Score Sheets' },
	{ id: 'standings', title: 'Standings' },
	{ id: 'rosters', title: 'Rosters & Skill' },
	{ id: 'payments', title: 'Payments' },
	{ id: 'waitlist', title: 'Waitlist' },
	{ id: 'div-balance', title: 'Division Balance' },
	{ id: 'team-compare', title: 'Compare' },
	{ id: 'leaders', title: 'Leaders' },
	{ id: 'notices', title: 'Notices' },
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
				<img className="splm-help-screenshot" src={ imgDashboard } alt="Dashboard landing view showing team/player counts, upcoming games, recent scores, recent activity, and penalty watch" />
			</section>

			<section id="help-schedule" className="splm-help-section">
				<h3>Schedule</h3>
				<p>Browse games with the <strong>Show</strong> (Upcoming / Past / All), <strong>Division</strong>, and <strong>Team</strong> filters. Each game links to <strong>View</strong> (public event page) and, for managers, <strong>Edit</strong> (the WordPress event editor), plus Reschedule and Cancel.</p>
				<img className="splm-help-screenshot" src={ imgSchedule } alt="Schedule page listing games grouped by date, each with View, Edit, Reschedule, and Cancel actions" />
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
				<img className="splm-help-screenshot" src={ imgScores } alt="Score Entry in One at a time mode, showing a single game's teams and score steppers with a Submit Score button" />
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
				<img className="splm-help-screenshot" src={ imgScoreSheets } alt="Score Sheets list showing uploaded sheets with their status and a Reprocess action" />
			</section>

			<section id="help-standings" className="splm-help-section">
				<h3>Standings</h3>
				<p>Standings per division, separated into <strong>Regular Season</strong> and <strong>Playoffs</strong>, ordered by division. Columns are hockey-standard: GP, W, L, T, OT, GF, GA, DIFF, Pts. Team names link to the team.</p>
				<img className="splm-help-screenshot" src={ imgStandings } alt="Standings table broken out by division, with GP, W, L, T, OT, GF, GA, DIFF, and Pts columns" />
			</section>

			<section id="help-rosters" className="splm-help-section">
				<h3>Rosters &amp; Skill</h3>
				<p>Pick a team to see its roster. You can move players, set captains, edit skill levels, add notes (the <em>Notes</em> button shows a count when notes exist), and import a roster by CSV.</p>
				<h4>Calculate Skills</h4>
				<p>Auto-rates players 1–10 from their game stats. Pick the season to rate from (defaults to <strong>All-time</strong>; a season includes its playoffs), then <strong>Calculate Skills</strong>. Ratings come from the box scores, weighting <strong>goals ×2 and assists ×0.5</strong> per game (goalies by goals-against average), mapped to a 1–10 percentile. Players with fewer than 3 games are skipped. Manually-set skills are never overwritten.</p>
				<p className="splm-muted">“Not registered” means the player has no registration record for the selected season — check Payments/registration if that’s unexpected.</p>
				<img className="splm-help-screenshot" src={ imgRosters } alt="Team roster table with player number, name, position, skill, email, registration status, and Notes/Move actions" />
			</section>

			<section id="help-payments" className="splm-help-section">
				<h3>Payments</h3>
				<p>Per-player payment status for the season, paged (controls at top and bottom). The amount links to the underlying WooCommerce order. The summary counts reflect the current page.</p>
				<img className="splm-help-screenshot" src={ imgPayments } alt="Payments table listing each player, team, payment status, and amount, with paid/unpaid/pending counts above" />
			</section>

			<section id="help-waitlist" className="splm-help-section">
				<h3>Waitlist</h3>
				<p>Manages the queue for a full season. Someone joins by buying a $0 waitlist product; when a spot opens, <strong>Offer</strong> emails them a timed claim link (the window defaults to 48 hours, and can be set per offer). Clicking the link redirects into normal checkout for the paired registration product. <strong>Re-offer</strong> appears once an offer lapses, so you can move straight to the next person.</p>
				<p>A row without a paired registration product shows "No registration product paired" — set one via the number field next to the flag before you can offer that spot.</p>
				<p className="splm-muted"><strong>Season access</strong> gates a registration product so it can only be bought by someone holding a live offer (or a manager); this emails a real person, so double-check the target before sending an offer.</p>
				<img className="splm-help-screenshot" src={ imgWaitlist } alt="Waitlist queue showing joined date, name, email, season, position, status, and Re-offer/Remove actions, with the season-access gate panel above" />
			</section>

			<section id="help-div-balance" className="splm-help-section">
				<h3>Division Balance</h3>
				<p>Per division: team count, rated players, average skill and range, and a skill-level distribution. <strong>Click a bar</strong> in the distribution to see exactly which players are at that skill level (each links to their editor).</p>
				<img className="splm-help-screenshot" src={ imgDivBalance } alt="Division Balance cards, one per division, each with team count, rated player count, average skill, and range" />
			</section>

			<section id="help-team-compare" className="splm-help-section">
				<h3>Compare</h3>
				<p>Head-to-head record and roster comparison for two teams. With a season selected, roster counts are scoped to that season.</p>
				<img className="splm-help-screenshot" src={ imgTeamCompare } alt="Team Comparison showing head-to-head win/draw/loss counts and a roster comparison table for two selected teams" />
			</section>

			<section id="help-leaders" className="splm-help-section">
				<h3>Leaders</h3>
				<p>Leaderboards for points, goals, assists and penalty minutes, built from the player stats entered on each game. <strong>Division</strong> narrows the boards to one division; <strong>Range</strong> switches between the full season and the last 4 or 8 weeks, so you can see who has been hot lately rather than who leads overall. <strong>Include playoff games</strong> folds playoff results into the totals.</p>
				<img className="splm-help-screenshot" src={ imgLeaders } alt="Leaders page showing Points and Goals leaderboard tables with division and range filters above" />
				<h4>Penalty Watch</h4>
				<p>Below the boards, managers see every player who has passed a penalty threshold — either a season total or a total inside the recent rolling window. Thresholds and the window length are set by an administrator in League Manager settings.</p>
				<p><strong>Acknowledge</strong> records that you have dealt with a flag, along with the player’s total at that moment. They drop off the list and come back only if they pass that total, cross a higher threshold, or pick up enough minutes in a later window. It is a “seen and handled” marker, not a suspension — it changes nothing about the player or the game record.</p>
				<img className="splm-help-screenshot" src={ imgLeadersPenaltyWatch } alt="Penalty Watch table listing flagged players with season/recent PIM, a severity flag, and an Acknowledge action" />
			</section>

			<section id="help-notices" className="splm-help-section">
				<h3>Notices</h3>
				<p>The delivery queue for discipline notices (warnings and suspensions) raised elsewhere in League Manager. Each notice moves through a small lifecycle: <strong>Waiting for you</strong> (needs a manager to release or discard it), <strong>Could not send</strong> (delivery failed — the player has no usable email, or sending errored), <strong>Sent</strong>, <strong>Served</strong> (a suspension's games-missed requirement has been fulfilled), and <strong>Discarded</strong>. Use <strong>Show</strong> to filter by stage, or pick <em>Everything</em> to see the full history.</p>
				<p className="splm-muted">This queue is where a notice actually leaves the building — raising a discipline action elsewhere queues it here first, so nothing reaches a player without a manager's own review.</p>
				<img className="splm-help-screenshot" src={ imgNotices } alt="Discipline Notices page with a Show status filter and the notice list below it" />
			</section>

			<section id="help-season-report" className="splm-help-section">
				<h3>Season Report</h3>
				<p>A season overview: game totals, a registration &amp; payment reconciliation (roster vs registered vs paid), a per-division summary, and stat leaders (points/goals/assists/PIM).</p>
				<img className="splm-help-screenshot" src={ imgSeasonReport } alt="Season Report showing games scheduled/played/remaining, registration and payment totals, a by-division breakdown, and points leaders" />
			</section>

			<section id="help-season-setup" className="splm-help-section">
				<h3>Seasons &amp; Rollover</h3>
				<p><strong>Create Season</strong>: name the season, then add each division — pick an existing division or create a new one, click <em>Add</em>, and choose its teams in the box that appears. <em>Preview changes</em> shows what will be created (standings tables, calendars, playoffs, default season) before you confirm.</p>
				<p><strong>Player Rollover</strong>: move players who didn’t re-register from their current team to past teams. Pick the season they’re coming <em>from</em> and the new season, preview who isn’t returning, then apply.</p>
				<img className="splm-help-screenshot" src={ imgSeasonSetup } alt="Season Setup's Create Season form, with season name, option checkboxes, and an Add a division control" />
			</section>

			<section id="help-health" className="splm-help-section">
				<h3>Health Checks</h3>
				<p>Flags data problems that skew standings, notifications, or reports — past games missing scores, games without a venue, players without an email, teams with no players. Each check lists the affected records with a link to fix them, and missing scores links to Score Entry.</p>
				<img className="splm-help-screenshot" src={ imgHealth } alt="Health Checks page listing flagged problems such as past games missing scores, games without a venue, and players without an email, each with an affected-record count" />
			</section>

			<section id="help-schedule-gen" className="splm-help-section">
				<h3>Schedule Generator</h3>
				<p>Builds a balanced season schedule from a saved configuration (teams, venues, dates, constraints), previews it, and publishes it as events. Requires the Schedule Generator module.</p>
				<img className="splm-help-screenshot" src={ imgScheduleGen } alt="Schedule Generator start screen with Saved Configurations, and Start Fresh, Use Preset, and Import JSON buttons" />
			</section>
		</div>
	);
}
