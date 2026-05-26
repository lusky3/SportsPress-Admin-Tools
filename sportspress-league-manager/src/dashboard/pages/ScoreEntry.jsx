import { useState, useEffect } from '@wordpress/element';
import { fetchGames, updateScore, fetchGamePlayers, saveGamePlayers, batchUpdateScores } from '../lib/api';

function GameNight( { season } ) {
	const [ date, setDate ] = useState( new Date().toISOString().split( 'T' )[ 0 ] );
	const [ games, setGames ] = useState( [] );
	const [ scores, setScores ] = useState( {} );
	const [ saving, setSaving ] = useState( false );
	const [ result, setResult ] = useState( null );

	useEffect( () => {
		fetchGames( season ? { season } : {} ).then( ( data ) => {
			const dayGames = data.filter( ( g ) => g.date === date && ! g.cancelled );
			setGames( dayGames );
			const init = {};
			dayGames.forEach( ( g ) => { init[ g.id ] = { home: g.home_score ?? 0, away: g.away_score ?? 0 }; } );
			setScores( init );
		} ).catch( () => {} );
	}, [ date, season ] );

	const handleSaveAll = async () => {
		setSaving( true );
		const batch = games
			.filter( ( g ) => g.home_score === null )
			.map( ( g ) => ( { game_id: g.id, home_score: scores[ g.id ]?.home ?? 0, away_score: scores[ g.id ]?.away ?? 0 } ) );
		if ( batch.length === 0 ) { setSaving( false ); return; }
		try {
			const res = await batchUpdateScores( batch );
			setResult( res );
		} catch ( err ) {
			setResult( { errors: [ err?.message || 'Failed' ] } );
		}
		setSaving( false );
	};

	const updateField = ( id, field, val ) => {
		setScores( ( prev ) => ( { ...prev, [ id ]: { ...prev[ id ], [ field ]: Math.max( 0, parseInt( val ) || 0 ) } } ) );
	};

	const unscored = games.filter( ( g ) => g.home_score === null );

	return (
		<div>
			<label>Date: <input type="date" value={ date } onChange={ ( e ) => { setDate( e.target.value ); setResult( null ); } } /></label>
			{ games.length === 0 ? (
				<p className="splm-empty">No games on this date.</p>
			) : (
				<>
					<div className="splm-table-wrapper">
						<table className="splm-table">
							<thead><tr><th>Time</th><th>Home</th><th></th><th>Away</th><th></th><th>Venue</th></tr></thead>
							<tbody>
								{ games.map( ( g ) => (
									<tr key={ g.id } className={ g.home_score !== null ? 'splm-row--muted' : '' }>
										<td>{ g.time }</td>
										<td>{ g.home_team.name }</td>
										<td>
											<input type="number" min="0" className="splm-score-input" value={ scores[ g.id ]?.home ?? 0 }
												onChange={ ( e ) => updateField( g.id, 'home', e.target.value ) }
												disabled={ g.home_score !== null }
												aria-label={ `${ g.home_team.name } score` }
											/>
											{ ' - ' }
											<input type="number" min="0" className="splm-score-input" value={ scores[ g.id ]?.away ?? 0 }
												onChange={ ( e ) => updateField( g.id, 'away', e.target.value ) }
												disabled={ g.home_score !== null }
												aria-label={ `${ g.away_team.name } score` }
											/>
										</td>
										<td>{ g.away_team.name }</td>
										<td>{ g.home_score !== null ? `${ g.home_score }-${ g.away_score }` : '' }</td>
										<td>{ g.venue }</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
					{ unscored.length > 0 && (
						<button className="splm-btn splm-btn--primary" onClick={ handleSaveAll } disabled={ saving }>
							{ saving ? 'Saving...' : `Save All (${ unscored.length } games)` }
						</button>
					) }
					{ result && (
						<div className={ `splm-alert splm-alert--${ result.errors?.length ? 'warning' : 'success' }` } role="alert">
							{ result.updated ? `✅ ${ result.updated } scores saved.` : '' }
							{ result.errors?.map( ( e, i ) => <div key={ i }>{ e }</div> ) }
						</div>
					) }
				</>
			) }
		</div>
	);
}

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
		} ).catch( () => {} );
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
	const [ error, setError ] = useState( '' );
	const [ showStats, setShowStats ] = useState( false );
	const [ scoreSubmitted, setScoreSubmitted ] = useState( false );
	const [ showingAll, setShowingAll ] = useState( false );
	const [ mode, setMode ] = useState( 'single' );

	const loadGames = ( params ) => {
		setLoading( true );
		fetchGames( params ).then( ( data ) => {
			const today = new Date().toISOString().split( 'T' )[ 0 ];
			const needScores = data.filter( ( g ) => g.date <= today && g.home_score === null && ! g.cancelled );
			setGames( needScores );
			setCurrent( 0 );
			setLoading( false );
		} ).catch( () => setLoading( false ) );
	};

	useEffect( () => {
		let cancelled = false;
		const params = season ? { season } : {};
		setLoading( true );
		fetchGames( params ).then( ( data ) => {
			if ( cancelled ) return;
			const today = new Date().toISOString().split( 'T' )[ 0 ];
			const needScores = data.filter( ( g ) => g.date <= today && g.home_score === null && ! g.cancelled );
			setGames( needScores );
			setCurrent( 0 );
			setLoading( false );
		} ).catch( () => { if ( ! cancelled ) setLoading( false ); } );
		return () => { cancelled = true; };
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
		setError( '' );
		try {
			await updateScore( game.id, homeScore, awayScore );
			setSaved( true );
			setSaving( false );
		} catch ( err ) {
			setError( err?.message || 'Failed to save score' );
			setSaving( false );
		}
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
			<div className="splm-score-entry__mode-toggle">
				<button className={ `splm-btn ${ mode === 'single' ? 'splm-btn--primary' : '' }` } onClick={ () => setMode( 'single' ) }>
					One at a time
				</button>
				<button className={ `splm-btn ${ mode === 'batch' ? 'splm-btn--primary' : '' }` } onClick={ () => setMode( 'batch' ) }>
					Game Night
				</button>
			</div>
			{ mode === 'batch' ? (
				<GameNight season={ season } />
			) : (
			<>
			{ error && <div className="splm-alert splm-alert--warning" role="alert">{ error }</div> }
			<p className="splm-score-entry__progress">
				Game { current + 1 } of { games.length }
			</p>

			{ saved && ! showStats ? (
				<div className="splm-score-entry__saved" role="alert">
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
					<div className="splm-score-entry__saved" role="alert">✅ Score saved!</div>
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
									aria-label={ `Decrease ${ game.home_team?.name || 'home' } score` }
								>
									−
								</button>
								<span className="splm-score-entry__value">{ homeScore }</span>
								<button
									className="splm-score-btn"
									onClick={ () => setHomeScore( homeScore + 1 ) }
									aria-label={ `Increase ${ game.home_team?.name || 'home' } score` }
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
									aria-label={ `Decrease ${ game.away_team?.name || 'away' } score` }
								>
									−
								</button>
								<span className="splm-score-entry__value">{ awayScore }</span>
								<button
									className="splm-score-btn"
									onClick={ () => setAwayScore( awayScore + 1 ) }
									aria-label={ `Increase ${ game.away_team?.name || 'away' } score` }
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
			</>
			) }
		</div>
	);
}
