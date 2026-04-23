import { useState } from '@wordpress/element';
import Layout from './components/Layout';
import Dashboard from './pages/Dashboard';
import Schedule from './pages/Schedule';
import ScoreEntry from './pages/ScoreEntry';
import Standings from './pages/Standings';
import Rosters from './pages/Rosters';
import Payments from './pages/Payments';
import HealthChecks from './pages/HealthChecks';
import ScheduleGenerator from './pages/ScheduleGenerator';
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
};

export default function App() {
	const [ page, setPage ] = useState( 'dashboard' );
	const [ season, setSeason ] = useState( window.splmDashboard?.currentSeason ?? '' );
	const PageComponent = PAGES[ page ] || Dashboard;

	return (
		<Layout currentPage={ page } onNavigate={ setPage } onSeasonChange={ setSeason } season={ season }>
			<div aria-live="polite" aria-atomic="true" className="screen-reader-text">
				{ page && `Navigated to ${ page }` }
			</div>
			<PageComponent onNavigate={ setPage } season={ season } />
		</Layout>
	);
}
