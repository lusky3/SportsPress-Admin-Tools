import { useState } from '@wordpress/element';
import Layout from './components/Layout';
import Dashboard from './pages/Dashboard';
import Schedule from './pages/Schedule';
import ScoreEntry from './pages/ScoreEntry';
import Standings from './pages/Standings';
import './styles.css';

const PAGES = {
	dashboard: Dashboard,
	schedule: Schedule,
	scores: ScoreEntry,
	standings: Standings,
};

export default function App() {
	const [ page, setPage ] = useState( 'dashboard' );
	const PageComponent = PAGES[ page ] || Dashboard;

	return (
		<Layout currentPage={ page } onNavigate={ setPage }>
			<PageComponent onNavigate={ setPage } />
		</Layout>
	);
}
