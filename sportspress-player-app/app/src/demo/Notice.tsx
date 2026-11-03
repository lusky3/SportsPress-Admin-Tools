import { useEffect, useRef, useState } from 'react';

/** Wider than any phone held upright: the demo is laid out for one. */
export const WIDE_QUERY = '(min-width: 640px)';

function useMatch( query: string ): boolean {
	const get = () => typeof window.matchMedia === 'function' && window.matchMedia( query ).matches;
	const [ matches, setMatches ] = useState( get );
	useEffect( () => {
		if ( typeof window.matchMedia !== 'function' ) {
			return;
		}
		const list = window.matchMedia( query );
		const update = () => setMatches( list.matches );
		list.addEventListener( 'change', update );
		return () => list.removeEventListener( 'change', update );
	}, [ query ] );
	return matches;
}

/**
 * The note every visitor sees first: this is a demo with made-up data, and on a wide
 * screen, that it is built for a phone. Shown on every visit, because a forwarded link
 * reaches people who never saw it. Closing it returns focus to the page.
 */
export function Notice( { url }: { url: string } ) {
	const [ open, setOpen ] = useState( true );
	const wide = useMatch( WIDE_QUERY );
	const button = useRef<HTMLButtonElement>( null );

	useEffect( () => {
		if ( ! open ) {
			return;
		}
		button.current?.focus();
		const onKey = ( e: KeyboardEvent ) => {
			if ( 'Escape' === e.key ) {
				setOpen( false );
			}
		};
		window.addEventListener( 'keydown', onKey );
		return () => window.removeEventListener( 'keydown', onKey );
	}, [ open ] );

	if ( ! open ) {
		return null;
	}

	return (
		<div className="demo-notice" role="presentation">
			<div className="demo-notice__card" role="dialog" aria-modal="true" aria-labelledby="demo-notice-title" aria-describedby="demo-notice-body">
				<h2 id="demo-notice-title" className="demo-notice__title">This is a demo</h2>
				<div id="demo-notice-body" className="demo-notice__body">
					<p>
						My ARL is the ARL player app. This page shows what it looks like with made-up
						players, games and scores. The team names are real; nothing else is.
					</p>
					<p>
						Some features are simulated or switched off. Nothing you tap is saved or sent,
						no one is emailed, and reloading starts over.
					</p>
					<p>Use the bar at the top to see the app as a Player, a Spare or an Organizer.</p>
					{ wide && (
						<p className="demo-notice__wide">
							<strong>It’s built for phones.</strong> For the real feel, open it on your
							phone at <span className="demo-notice__url">{ url }</span> or make this
							window narrow. On a wide screen it shows in a phone-sized column.
						</p>
					) }
				</div>
				<button ref={ button } type="button" className="demo-notice__ok" onClick={ () => setOpen( false ) }>
					Got it
				</button>
			</div>
		</div>
	);
}
