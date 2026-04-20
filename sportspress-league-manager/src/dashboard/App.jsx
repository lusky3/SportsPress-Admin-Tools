import { useState } from '@wordpress/element';
import Layout from './components/Layout';
import Dashboard from './pages/Dashboard';
import Schedule from './pages/Schedule';
import ScoreEntry from './pages/ScoreEntry';
import Standings from './pages/Standings';
import Rosters from './pages/Rosters';
import Payments from './pages/Payments';
import HealthChecks from './pages/HealthChecks';
import './styles.css';

const PAGES = {
	dashboard: Dashboard,
	schedule: Schedule,
	scores: ScoreEntry,
	standings: Standings,
	rosters: Rosters,
	payments: Payments,
	health: HealthChecks,
};

export default function App() {
	const [ page, setPage ] = useState( 'dashboard' );
	const [ season, setSeason ] = useState( window.splmDashboard?.currentSeason || '' );
	const PageComponent = PAGES[ page ] || Dashboard;

	return (
		<Layout currentPage={ page } onNavigate={ setPage } onSeasonChange={ setSeason } season={ season }>
			<PageComponent onNavigate={ setPage } season={ season } />
		</Layout>
	);
}
