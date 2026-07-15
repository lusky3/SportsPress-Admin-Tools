import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import HelpLink from '../components/HelpLink';
import { fetchGames, updateScore, fetchGamePlayers, saveGamePlayers, batchUpdateScores } from '../lib/api';

const DATE_FMT = new Intl.DateTimeFormat( undefined, { weekday: 'short', month: 'short', day: 'numeric' } );
const TIME_FMT = new Intl.DateTimeFormat( undefined, { hour: 'numeric', minute: '2-digit' } );

function formatGameDate( raw ) {
	if ( ! raw ) return '';
	const d = new Date( `${ raw }T00:00:00` );
	return Number.isNaN( d.getTime() ) ? String( raw ) : DATE_FMT.format( d );
}
function formatGameTime( raw ) {
	if ( ! raw ) return '';
	const [ h, m ] = String( raw ).split( ':' );
	if ( h === undefined ) return '';
	const d = new Date();
	d.setHours( Number( h ), Number( m ) || 0, 0, 0 );
	return Number.isNaN( d.getTime() ) ? '' : TIME_FMT.format( d );
}
function gameWhen( g ) {
	return [ formatGameDate( g.date ), formatGameTime( g.time ), g.venue ].filter( Boolean ).join( ' · ' );
}

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
							<thead><tr><th scope="col">Time</th><th scope="col">Home</th><th scope="col">Score</th><th scope="col">Away</th><th scope="col">Final</th><th scope="col">Venue</th></tr></thead>
							<tbody>
								{ games.map( ( g ) => (
									<tr key={ g.id } className={ g.home_score !== null ? 'splm-row--muted' : '' }>
										<td>{ formatGameTime( g.time ) || g.time }</td>
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

function PlayerStats( { gameId, game, onDone, onBack } ) {
	const [ data, setData ] = useState( null );
	const [ stats, setStats ] = useState( {} );
	const [ saving, setSaving ] = useState( false );
	// Rosters can be long (sp_current_team accumulates every historical member),
	// so a name/number filter keeps the stats grid usable.
	const [ filter, setFilter ] = useState( '' );

	useEffect( () => {
		fetchGamePlayers( gameId ).then( ( res ) => {
			setData( res );
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

	const heading = game ? `${ game.home_team.name } vs ${ game.away_team.name }` : 'Player Stats';

	// Actionable empty state — never a dead-end. Events are created with no
	// players selected by default, so explain that and always offer a way out.
	if ( data.performances.length === 0 || data.teams.every( ( t ) => t.players.length === 0 ) ) {
		return (
			<div className="splm-player-stats">
				<h4>{ heading }</h4>
				<div className="splm-alert splm-alert--warning" role="status">
					{ data.performances.length === 0
						? 'No box-score stat types are configured for this sport, so player stats can’t be entered here. Ask an administrator to add visible performance columns in SportsPress.'
						: 'Neither team has players on its roster yet, so player stats can’t be entered. Add players to these teams first (they need a current team assigned), then come back.' }
				</div>
				<div className="splm-score-entry__actions">
					{ onBack && <button className="splm-btn" onClick={ onBack }>← Back</button> }
					<button className="splm-btn splm-btn--secondary" onClick={ onDone }>Skip → Next game</button>
				</div>
			</div>
		);
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

	const q = filter.trim().toLowerCase();
	const totalPlayers = data.teams.reduce( ( n, t ) => n + t.players.length, 0 );
	const matchPlayer = ( p ) => ! q || p.name.toLowerCase().includes( q ) || String( p.number || '' ).includes( q );

	return (
		<div className="splm-player-stats">
			<h4>{ heading }</h4>
			{ totalPlayers > 12 && (
				<label className="splm-player-stats__filter">
					<span className="screen-reader-text">Filter players</span>
					<input
						type="search"
						className="splm-select"
						placeholder="Filter players by name or number…"
						value={ filter }
						onChange={ ( e ) => setFilter( e.target.value ) }
					/>
				</label>
			) }
			{ data.teams.map( ( team ) => {
				const visible = team.players.filter( matchPlayer );
				return (
				<div key={ team.id } className="splm-player-stats__team">
					<h4>{ team.name }</h4>
					{ team.players.length === 0 ? (
						<p className="splm-muted">No players assigned to this team.</p>
					) : visible.length === 0 ? (
						<p className="splm-muted">No players match “{ filter }”.</p>
					) : (
						<table className="splm-player-stats__table">
							<thead>
								<tr>
									<th scope="col">Player</th>
									{ data.performances.map( ( perf ) => (
										<th scope="col" key={ perf.slug }>{ perf.label }</th>
									) ) }
								</tr>
							</thead>
							<tbody>
								{ visible.map( ( player ) => (
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
													aria-label={ `${ perf.label } for ${ player.name }` }
												/>
											</td>
										) ) }
									</tr>
								) ) }
							</tbody>
						</table>
					) }
				</div>
				);
			} ) }
			<div className="splm-score-entry__actions">
				{ onBack && <button className="splm-btn" onClick={ onBack }>← Back</button> }
				<button className="splm-btn splm-btn--primary" onClick={ handleSave } disabled={ saving }>
					{ saving ? 'Saving...' : 'Save Stats' }
				</button>
			</div>
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
	const [ showingAll, setShowingAll ] = useState( false );
	const [ mode, setMode ] = useState( 'single' );
	// UX-10: incrementing key forces the role=status node to remount each save
	// so screen readers re-announce "Score saved!" on the 2nd, 3rd… game.
	const [ saveAnnounceKey, setSaveAnnounceKey ] = useState( 0 );

	const liveRef = useRef( 0 );

	const loadGames = useCallback( ( params ) => {
		const token = ++liveRef.current;
		setLoading( true );
		fetchGames( params ).then( ( data ) => {
			if ( token !== liveRef.current ) return; // stale / unmounted
			const today = new Date().toISOString().split( 'T' )[ 0 ];
			const needScores = data.filter( ( g ) => g.date <= today && g.home_score === null && ! g.cancelled );
			setGames( needScores );
			setCurrent( 0 );
			setLoading( false );
		} ).catch( () => { if ( token === liveRef.current ) setLoading( false ); } );
	}, [] );

	useEffect( () => {
		loadGames( season ? { season } : {} );
		return () => { liveRef.current++; };
	}, [ season, loadGames ] );

	const loadAllUnscored = () => {
		setShowingAll( true );
		loadGames( {} );
	};

	const game = games[ current ];

	const advanceToNext = () => {
		setSaved( false );
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
			setSaveAnnounceKey( ( k ) => k + 1 ); // UX-10: re-announce each save
			setSaving( false );
		} catch ( err ) {
			setError( err?.message || 'Failed to save score' );
			setSaving( false );
		}
	};

	if ( loading ) {
		return <div className="splm-loading">Loading games...</div>;
	}

	if ( games.length === 0 ) {
		return (
			<div className="splm-score-entry">
				<h2>Score Entry <HelpLink topic="scores" /></h2>
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

					{ showStats ? (
						<div>
							{ saved && <div className="splm-alert splm-alert--success" role="status" key={ saveAnnounceKey }>Score saved!</div> }
							<PlayerStats gameId={ game.id } game={ game } onDone={ advanceToNext } onBack={ () => setShowStats( false ) } />
						</div>
					) : saved ? (
						<div className="splm-score-entry__saved">
							<div className="splm-alert splm-alert--success" role="status" key={ saveAnnounceKey }>
								Score saved!
							</div>
							<div className="splm-score-entry__after-save">
								<button className="splm-btn splm-btn--primary" onClick={ () => setShowStats( true ) }>
									Enter Player Stats
								</button>
								<button className="splm-btn splm-btn--secondary" onClick={ advanceToNext }>
									Skip → Next game
								</button>
							</div>
						</div>
					) : (
						<div className="splm-score-entry__card">
							<div className="splm-score-entry__date">{ gameWhen( game ) }</div>

							<div className="splm-score-entry__teams">
								<div className="splm-score-entry__team">
									<span className="splm-score-entry__team-name">{ game.home_team.name }</span>
									<div className="splm-score-entry__controls">
										<button className="splm-score-btn" onClick={ () => setHomeScore( Math.max( 0, homeScore - 1 ) ) } aria-label={ `Decrease ${ game.home_team?.name || 'home' } score` }>−</button>
										<span className="splm-score-entry__value">{ homeScore }</span>
										<button className="splm-score-btn" onClick={ () => setHomeScore( homeScore + 1 ) } aria-label={ `Increase ${ game.home_team?.name || 'home' } score` }>+</button>
									</div>
								</div>

								<div className="splm-score-entry__vs">vs</div>

								<div className="splm-score-entry__team">
									<span className="splm-score-entry__team-name">{ game.away_team.name }</span>
									<div className="splm-score-entry__controls">
										<button className="splm-score-btn" onClick={ () => setAwayScore( Math.max( 0, awayScore - 1 ) ) } aria-label={ `Decrease ${ game.away_team?.name || 'away' } score` }>−</button>
										<span className="splm-score-entry__value">{ awayScore }</span>
										<button className="splm-score-btn" onClick={ () => setAwayScore( awayScore + 1 ) } aria-label={ `Increase ${ game.away_team?.name || 'away' } score` }>+</button>
									</div>
								</div>
							</div>

							<div className="splm-score-entry__actions">
								<button className="splm-btn splm-btn--primary splm-btn--large" onClick={ handleSubmit } disabled={ saving }>
									{ saving ? 'Saving...' : 'Submit Score' }
								</button>
								<button className="splm-btn splm-btn--secondary" onClick={ () => setShowStats( true ) }>
									Enter Player Stats
								</button>
							</div>
						</div>
					) }
				</>
			) }
		</div>
	);
}
