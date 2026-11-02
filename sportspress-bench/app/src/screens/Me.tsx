import { useRef, useState } from 'react';
import { apiSend } from '../api';
import { boot } from '../boot';
import { Crest } from '../components/Crest';
import { InstallTip } from '../components/InstallTip';
import { TwoTap } from '../components/TwoTap';
import { googleUrl, webcalUrl } from '../lib/calendar';
import { errorText } from '../lib/errors';
import { signOut } from '../lib/offline';
import { Link } from '../router';
import type { Me as MeData } from '../types';
import { getCached, setCached } from '../useResource';

/** Calendar subscribe links for the personal feed, copy, and a reset for a link that got shared too widely. */
function Calendar( { feed: initial }: { feed: string } ) {
	const [ feed, setFeed ] = useState( initial );
	const [ note, setNote ] = useState( '' );
	const input = useRef<HTMLInputElement>( null );

	const copy = async () => {
		try {
			await navigator.clipboard.writeText( feed );
			setNote( 'Copied.' );
		} catch {
			// No clipboard permission: select the text so a long-press copies it.
			input.current?.focus();
			input.current?.select();
			setNote( 'Selected. Copy it from the menu.' );
		}
	};

	const reset = async () => {
		setNote( '' );
		try {
			const res = await apiSend<{ ical_url: string }>( 'POST', '/me/ical-token/rotate' );
			setFeed( res.ical_url );
			const me = getCached<MeData>( '/me' );
			if ( me ) {
				setCached( '/me', { ...me, ical_url: res.ical_url } );
			}
			setNote( 'New link made. Calendars using the old one stop updating, so subscribe again.' );
		} catch ( e ) {
			setNote( errorText( e, 'The link could not be reset. Try again.' ) );
		}
	};

	return (
		<section className="card" aria-labelledby="cal-h">
			<h2 id="cal-h">Your calendar</h2>
			<p className="muted">Every game, kept up to date on your phone. If a game moves, your calendar moves with it.</p>
			<div className="actions">
				<a className="btn-primary" href={ webcalUrl( feed ) }>
					<span>Subscribe</span>
				</a>
				<a className="btn-link" href={ googleUrl( feed ) } target="_blank" rel="noopener noreferrer">
					Add to Google Calendar<span className="sr-only"> (opens in a new tab)</span>
				</a>
			</div>
			<div className="field">
				<label htmlFor="cal-url">Calendar link (keep it to yourself)</label>
				<div className="copy">
					<input id="cal-url" ref={ input } readOnly value={ feed } onFocus={ ( e ) => e.target.select() } />
					<button type="button" className="btn-quiet" onClick={ () => void copy() }>
						Copy
					</button>
				</div>
			</div>
			<div className="actions">
				<TwoTap label="Reset link" confirm="Tap again to reset" onConfirm={ () => void reset() } />
			</div>
			<p className="field__hint" role="status">
				{ note }
			</p>
		</section>
	);
}

/** Who you are, your teams, your calendar, and the way out. */
export function Me( { me }: { me: MeData } ) {
	const { accountUrl, helpUrl } = boot();
	const player = me.players[ 0 ];
	return (
		<section className="screen">
			<header className="me">
				<h1>{ me.user.name }</h1>
				{ player?.number ? <p className="me__num">#{ player.number }</p> : null }
				{ me.season.name ? <p className="muted">{ me.season.name }</p> : null }
			</header>
			{ me.teams.length ? (
				<ul className="teams">
					{ me.teams.map( ( team ) => (
						<li key={ team.id }>
							<Link to={ `/team/${ team.id }` } className="team-link">
								<Crest name={ team.name } logo={ team.logo } size={ 36 } />
								<span>
									<span className="team-link__name">{ team.name }</span>
									{ team.division ? <span className="team-link__div">{ team.division }</span> : null }
								</span>
							</Link>
						</li>
					) ) }
				</ul>
			) : null }
			{ me.ical_url ? <Calendar feed={ me.ical_url } /> : null }
			<InstallTip always />
			<ul className="links">
				<li>
					<a href={ accountUrl }>My Account</a>
				</li>
				<li>
					<a href={ helpUrl } target="_blank" rel="noopener noreferrer">
						Help<span className="sr-only"> (opens in a new tab)</span>
					</a>
				</li>
				<li>
					<button type="button" className="btn-link" onClick={ () => void signOut() }>
						Sign out
					</button>
				</li>
			</ul>
		</section>
	);
}
