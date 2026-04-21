import { useState, useEffect } from '@wordpress/element';
import { fetchGames, updateScore, fetchGamePlayers, saveGamePlayers } from '../lib/api';

function PlayerStats( { gameId, onDone } ) {
	const [ data, setData ] = useState( null );
	const [ stats, setStats ] = useState( {} );
	const [ saving, setSaving ] = useState( false );

	useEffect( () => {
		fetchGamePlayers( gameId ).then( ( res ) => {
			setData( res );
			// Initialize stats from existing data.
			const init = {};
			res.teams.forEach( ( team ) => {
				init[ team.id ] = {};
				team.players.forEach( ( p ) => {
					init[ team.id ][ p.id ] = {};
					res.performances.forEach( ( perf ) => {
						init[ team.id ][ p.id ][ perf.slug ] = p.stats[ perf.slug ] || 0;
					} );
				} );
			} );
			setStats( init );
		} );
	}, [ gameId ] );

	if ( ! data ) {
		return <div className="splm-loading">Loading players...</div>;
	}

	if ( data.performances.length === 0 || data.teams.every( ( t ) => t.players.length === 0 ) ) {
		return <p className="splm-muted">No players or performance types configured.</p>;
	}

	const updateStat = ( teamId, playerId, slug, value ) => {
		setStats( ( prev ) => ( {
			...prev,
			[ teamId ]: {
				...prev[ teamId ],
				[ playerId ]: {
					...prev[ teamId ][ playerId ],
					[ slug ]: Math.max( 0, parseInt( value, 10 ) || 0 ),
				},
			},
		} ) );
	};

	const handleSave = async () => {
		setSaving( true );
		await saveGamePlayers( gameId, stats );
		setSaving( false );
		onDone();
	};

	return (
		<div className="splm-player-stats">
			{ data.teams.map( ( team ) => (
				<div key={ team.id } className="splm-player-stats__team">
					<h4>{ team.name }</h4>
					{ team.players.length === 0 ? (
						<p className="splm-muted">No players</p>
					) : (
						<table className="splm-player-stats__table">
							<thead>
								<tr>
									<th>Player</th>
									{ data.performances.map( ( perf ) => (
										<th key={ perf.slug }>{ perf.label }</th>
									) ) }
								</tr>
							</thead>
							<tbody>
								{ team.players.map( ( player ) => (
									<tr key={ player.id }>
										<td>
											{ player.number ? `#${ player.number } ` : '' }
											{ player.name }
										</td>
										{ data.performances.map( ( perf ) => (
											<td key={ perf.slug }>
												<input
													type="number"
													min="0"
													className="splm-player-stats__input"
													value={ stats[ team.id ]?.[ player.id ]?.[ perf.slug ] ?? 0 }
													onChange={ ( e ) =>
														updateStat( team.id, player.id, perf.slug, e.target.value )
													}
												/>
											</td>
										) ) }
									</tr>
								) ) }
							</tbody>
						</table>
					) }
				</div>
			) ) }
			<button
				className="splm-btn splm-btn--primary"
				onClick={ handleSave }
				disabled={ saving }
			>
				{ saving ? 'Saving...' : 'Save Stats' }
			</button>
		</div>
	);
}

export default function ScoreEntry( { season } ) {
	const [ games, setGames ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ current, setCurrent ] = useState( 0 );
	const [ homeScore, setHomeScore ] = useState( 0 );
	const [ awayScore, setAwayScore ] = useState( 0 );
	const [ saving, setSaving ] = useState( false );
	const [ saved, setSaved ] = useState( false );
	const [ showStats, setShowStats ] = useState( false );
	const [ scoreSubmitted, setScoreSubmitted ] = useState( false );
	const [ showingAll, setShowingAll ] = useState( false );

	const loadGames = ( params ) => {
		setLoading( true );
		fetchGames( params ).then( ( data ) => {
			const today = new Date().toISOString().split( 'T' )[ 0 ];
			const needScores = data.filter( ( g ) => g.date <= today && g.home_score === null && ! g.cancelled );
			setGames( needScores );
			setCurrent( 0 );
			setLoading( false );
		} );
	};

	useEffect( () => {
		loadGames( season ? { season } : {} );
	}, [ season ] );

	const loadAllUnscored = () => {
		setShowingAll( true );
		loadGames( {} );
	};

	const game = games[ current ];

	const advanceToNext = () => {
		setSaved( false );
		setScoreSubmitted( false );
		setShowStats( false );
		setHomeScore( 0 );
		setAwayScore( 0 );
		if ( current < games.length - 1 ) {
			setCurrent( current + 1 );
		} else {
			setGames( [] );
		}
	};

	const handleSubmit = async () => {
		if ( ! game ) return;
		setSaving( true );
		await updateScore( game.id, homeScore, awayScore );
		setSaving( false );
		setSaved( true );
		setScoreSubmitted( true );
	};

	if ( loading ) {
		return <div className="splm-loading">Loading games...</div>;
	}

	if ( games.length === 0 ) {
		return (
			<div className="splm-score-entry">
				<h2>Score Entry</h2>
				<div className="splm-empty-state">
					{ showingAll ? (
						<p>✅ All past games have scores entered!</p>
					) : (
						<>
							<p>No games need scores for this season yet.</p>
							<button className="splm-btn" onClick={ loadAllUnscored }>
								Show all unscored games
							</button>
						</>
					) }
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

			{ saved && ! showStats ? (
				<div className="splm-score-entry__saved">
					<p>✅ Score saved!</p>
					<details className="splm-player-stats__toggle" onToggle={ ( e ) => {
						if ( e.target.open ) setShowStats( true );
					} }>
						<summary>Enter Player Stats</summary>
					</details>
					{ ! showStats && (
						<button className="splm-btn splm-btn--secondary" onClick={ advanceToNext }>
							Skip → Next Game
						</button>
					) }
				</div>
			) : showStats && scoreSubmitted ? (
				<div>
					<div className="splm-score-entry__saved">✅ Score saved!</div>
					<PlayerStats gameId={ game.id } onDone={ advanceToNext } />
				</div>
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
