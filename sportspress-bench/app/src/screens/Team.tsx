import { Crest } from '../components/Crest';
import { Problem, Skeleton } from '../components/Problem';
import { Link } from '../router';
import type { Me, TeamDetail } from '../types';
import { useResource } from '../useResource';

/** Your team: crest, division, record, and the roster with numbers, a C for the captain and you picked out. */
export function Team( { id, me }: { id: string; me: Me } ) {
	const team = useResource<TeamDetail>( `/teams/${ Number( id ) || 0 }` );
	const t = team.data && team.data.id === Number( id ) ? team.data : undefined;

	let body;
	if ( ! t ) {
		body = team.loading ? (
			<Skeleton rows={ 6 } />
		) : (
			<Problem error={ team.error } reload={ team.reload } forbidden="That team’s roster is only shown to its players." missing="We couldn’t find that team." />
		);
	} else {
		const r = t.record;
		body = (
			<>
				<header className="team-head">
					<Crest name={ t.name } logo={ t.logo } size={ 64 } />
					<div>
						<h1>{ t.name }</h1>
						{ t.division ? <p className="muted">{ t.division }</p> : null }
					</div>
					<p className="record" aria-label={ `Record: ${ r.w } wins, ${ r.l } losses, ${ r.t } ties${ r.otl ? `, ${ r.otl } overtime losses` : '' }` }>
						<span aria-hidden="true">{ [ r.w, r.l, r.t, ...( r.otl ? [ r.otl ] : [] ) ].join( '–' ) }</span>
						<span className="record__key" aria-hidden="true">
							{ r.otl ? 'W–L–T–OTL' : 'W–L–T' }
						</span>
					</p>
				</header>
				{ t.roster.length ? (
					<ul className="people people--roster" aria-label="Roster">
						{ t.roster.map( ( p ) => (
							<li key={ p.player_id } className={ p.is_me ? 'person is-me' : 'person' }>
								<span className="person__num">{ p.number ? `#${ p.number }` : '' }</span>
								<span className="person__name">
									{ p.name }
									{ p.is_captain ? (
										<>
											<span className="badge-c" aria-hidden="true" title="Captain">
												C
											</span>
											<span className="sr-only">Captain</span>
										</>
									) : null }
									{ p.is_me ? <span className="chip chip--ice">You</span> : null }
								</span>
								<span className="person__pos">{ p.positions.join( ', ' ) }</span>
							</li>
						) ) }
					</ul>
				) : (
					<p className="muted">The roster isn’t posted yet.</p>
				) }
			</>
		);
	}

	return (
		<section className="screen">
			{ me.teams.length > 1 ? (
				<nav className="switch" aria-label="Your teams">
					{ me.teams.map( ( mine ) => (
						<Link key={ mine.id } to={ `/team/${ mine.id }` } className="switch__opt" aria-current={ mine.id === Number( id ) ? 'page' : undefined }>
							{ mine.name }
						</Link>
					) ) }
				</nav>
			) : null }
			{ body }
		</section>
	);
}
