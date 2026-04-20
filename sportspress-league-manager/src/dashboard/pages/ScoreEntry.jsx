import { useState, useEffect } from '@wordpress/element';
import { fetchGames, updateScore } from '../lib/api';

export default function ScoreEntry() {
	const [ games, setGames ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ current, setCurrent ] = useState( 0 );
	const [ homeScore, setHomeScore ] = useState( 0 );
	const [ awayScore, setAwayScore ] = useState( 0 );
	const [ saving, setSaving ] = useState( false );
	const [ saved, setSaved ] = useState( false );

	useEffect( () => {
		fetchGames().then( ( data ) => {
			const today = new Date().toISOString().split( 'T' )[ 0 ];
			const needScores = data.filter( ( g ) => g.date <= today && g.home_score === null && ! g.cancelled );
			setGames( needScores );
			setLoading( false );
		} );
	}, [] );

	const game = games[ current ];

	const handleSubmit = async () => {
		if ( ! game ) return;
		setSaving( true );
		await updateScore( game.id, homeScore, awayScore );
		setSaving( false );
		setSaved( true );
		setTimeout( () => {
			setSaved( false );
			setHomeScore( 0 );
			setAwayScore( 0 );
			if ( current < games.length - 1 ) {
				setCurrent( current + 1 );
			} else {
				setGames( [] );
			}
		}, 1500 );
	};

	if ( loading ) {
		return <div className="splm-loading">Loading games...</div>;
	}

	if ( games.length === 0 ) {
		return (
			<div className="splm-score-entry">
				<h2>Score Entry</h2>
				<div className="splm-empty-state">
					<p>✅ All games have scores entered!</p>
				</div>
			</div>
		);
	}

	return (
		<div className="splm-score-entry">
			<h2>Score Entry</h2>
			<p className="splm-score-entry__progress">
				Game { current + 1 } of { games.length }
			</p>

			{ saved ? (
				<div className="splm-score-entry__saved">✅ Score saved!</div>
			) : (
				<div className="splm-score-entry__card">
					<div className="splm-score-entry__date">
						{ game.date } — { game.venue }
					</div>

					<div className="splm-score-entry__teams">
						<div className="splm-score-entry__team">
							<span className="splm-score-entry__team-name">{ game.home_team.name }</span>
							<div className="splm-score-entry__controls">
								<button
									className="splm-score-btn"
									onClick={ () => setHomeScore( Math.max( 0, homeScore - 1 ) ) }
								>
									−
								</button>
								<span className="splm-score-entry__value">{ homeScore }</span>
								<button
									className="splm-score-btn"
									onClick={ () => setHomeScore( homeScore + 1 ) }
								>
									+
								</button>
							</div>
						</div>

						<div className="splm-score-entry__vs">vs</div>

						<div className="splm-score-entry__team">
							<span className="splm-score-entry__team-name">{ game.away_team.name }</span>
							<div className="splm-score-entry__controls">
								<button
									className="splm-score-btn"
									onClick={ () => setAwayScore( Math.max( 0, awayScore - 1 ) ) }
								>
									−
								</button>
								<span className="splm-score-entry__value">{ awayScore }</span>
								<button
									className="splm-score-btn"
									onClick={ () => setAwayScore( awayScore + 1 ) }
								>
									+
								</button>
							</div>
						</div>
					</div>

					<button
						className="splm-btn splm-btn--primary splm-btn--large"
						onClick={ handleSubmit }
						disabled={ saving }
					>
						{ saving ? 'Saving...' : 'Submit Score' }
					</button>
				</div>
			) }
		</div>
	);
}
