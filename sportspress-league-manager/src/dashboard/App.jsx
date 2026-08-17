import { useState, useEffect, useRef } from '@wordpress/element';
import Layout from './components/Layout';
import DependencyNotice from './components/DependencyNotice';
import Dashboard from './pages/Dashboard';
import Schedule from './pages/Schedule';
import ScoreEntry from './pages/ScoreEntry';
import Standings from './pages/Standings';
import Rosters from './pages/Rosters';
import Payments from './pages/Payments';
import HealthChecks from './pages/HealthChecks';
import ScheduleGenerator from './pages/ScheduleGenerator';
import DivisionBalance from './pages/DivisionBalance';
import TeamComparison from './pages/TeamComparison';
import SeasonReport from './pages/SeasonReport';
import Leaders from './pages/Leaders';
import SeasonSetup from './pages/SeasonSetup';
import ScoreSheets from './pages/ScoreSheets';
import Help from './pages/Help';
import { saveUserPreferences } from './lib/api';
import './styles.css';

const PAGES = {
	dashboard: Dashboard,
	schedule: Schedule,
	scores: ScoreEntry,
	standings: Standings,
	rosters: Rosters,
	payments: Payments,
	health: HealthChecks,
	'schedule-gen': ScheduleGenerator,
	'div-balance': DivisionBalance,
	'team-compare': TeamComparison,
	leaders: Leaders,
	'season-report': SeasonReport,
	'season-setup': SeasonSetup,
	'score-sheets': ScoreSheets,
	help: Help,
};

// UX-11: derive the initial page from the URL hash so deep links / refresh land
// on the right screen. Falls back to 'dashboard' for unknown hashes.
function pageFromHash() {
	const hash = ( window.location.hash || '' ).replace( /^#\/?/, '' );
	return PAGES[ hash ] ? hash : 'dashboard';
}

// M19: POST /user/preferences already stored splm_preferred_season, but nothing
// read it back, so the header filter reset to the newest season on every load.
// Prefer the saved season when it still exists as a term; otherwise fall back to
// the server-computed current season.
function initialSeason() {
	const config = window.splmDashboard || {};
	const saved = Number( config.preferredSeason ) || 0;
	if ( saved && ( config.seasons || [] ).some( ( s ) => Number( s.id ) === saved ) ) {
		return String( saved );
	}
	return config.currentSeason ?? '';
}

export default function App() {
	const [ page, setPage ] = useState( pageFromHash );
	const [ season, setSeason ] = useState( initialSeason );
	const [ announcement, setAnnouncement ] = useState( '' );
	const [ helpTopic, setHelpTopic ] = useState( '' );
	const isFirstRender = useRef( true );
	const PageComponent = PAGES[ page ] || Dashboard;

	// Graceful degradation: SportsPress core is a hard dependency. When it is
	// inactive the dashboard cannot function, so render the blocking notice
	// INSTEAD of the normal UI (no Layout, no pages that would 404 against
	// absent endpoints).
	const sportsPressActive = window.splmDashboard?.dependencies?.sportspress !== false;
	if ( ! sportsPressActive ) {
		return (
			<div className="splm-app splm-app--blocked">
				<DependencyNotice />
			</div>
		);
	}

	// UX-11: write page → URL hash so Back/refresh/sharing work, and react to
	// popstate (Back/Forward) by reading the hash back into state.
	const navigate = ( next ) => {
		setPage( next );
		const target = `#/${ next }`;
		if ( window.location.hash !== target ) {
			window.location.hash = target;
		}
	};

	useEffect( () => {
		const onPop = () => setPage( pageFromHash() );
		window.addEventListener( 'popstate', onPop );
		window.addEventListener( 'hashchange', onPop );
		// Normalise the hash on first mount (e.g. bare '#').
		if ( ! window.location.hash ) {
			window.history.replaceState( null, '', `#/${ pageFromHash() }` );
		}
		return () => {
			window.removeEventListener( 'popstate', onPop );
			window.removeEventListener( 'hashchange', onPop );
		};
	}, [] );

	useEffect( () => {
		if ( isFirstRender.current ) { isFirstRender.current = false; return; }
		setAnnouncement( `Navigated to ${ page }` );
	}, [ page ] );

	// "?" HelpLinks fire a window event; open the Help page at the requested
	// topic. A ref bumps a nonce so re-clicking the same topic re-scrolls.
	useEffect( () => {
		const onHelp = ( e ) => {
			setHelpTopic( `${ e.detail || '' }#${ Date.now() }` );
			setPage( 'help' );
			const target = '#/help';
			if ( window.location.hash !== target ) {
				window.location.hash = target;
			}
		};
		window.addEventListener( 'splm:help', onHelp );
		return () => window.removeEventListener( 'splm:help', onHelp );
	}, [] );

	// M19: persist the picked season so it survives a reload. Best-effort — a
	// failed preference write must never interrupt navigation.
	const changeSeason = ( next ) => {
		setSeason( next );
		saveUserPreferences( { preferred_season: Number( next ) || 0 } ).catch( () => {} );
	};

	return (
		<Layout currentPage={ page } onNavigate={ navigate } onSeasonChange={ changeSeason } season={ season }>
			<div aria-live="polite" aria-atomic="true" className="screen-reader-text">
				{ announcement }
			</div>
			<DependencyNotice />
			<PageComponent onNavigate={ navigate } season={ season } helpTopic={ helpTopic } />
		</Layout>
	);
}
