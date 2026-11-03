import { useEffect } from 'react';
import { entryLine, periodLabel } from '../lib/live';
import type { LiveView } from '../types';
import { useResource } from '../useResource';

export const LIVE_POLL_MS = 15_000;

/**
 * A game's live score. Loads once when `enabled`, then refreshes every 15 s only while the
 * game is live and the page is visible: it stops when the game is final or the tab is hidden,
 * and refreshes at once when the tab comes back. Refreshes go through the resource cache,
 * so an older answer still in flight never overwrites a newer one.
 */
export function useLive( id: number, enabled: boolean ) {
	const live = useResource<LiveView>( enabled && id > 0 ? `/games/${ id }/live` : '' );
	const polling = live.data?.event_id === id && live.data.status === 'live';
	const { reload } = live;

	useEffect( () => {
		if ( ! polling ) {
			return;
		}
		let timer: ReturnType<typeof setInterval> | undefined;
		const run = () => {
			clearInterval( timer );
			timer = document.visibilityState === 'visible' ? setInterval( reload, LIVE_POLL_MS ) : undefined;
		};
		const change = () => {
			if ( document.visibilityState === 'visible' ) {
				reload();
			}
			run();
		};
		run();
		document.addEventListener( 'visibilitychange', change );
		return () => {
			clearInterval( timer );
			document.removeEventListener( 'visibilitychange', change );
		};
	}, [ polling, reload ] );

	return live.data?.event_id === id ? live.data : undefined;
}

/** The game screen's live card: the score, the period, the last five plays. Hidden until something is logged. */
export function LiveCard( { live }: { live: LiveView | undefined } ) {
	if ( ! live || live.status === 'none' ) {
		return null;
	}
	const final = live.status === 'final';
	const team = ( side: string | null ) => ( side === 'home' ? live.home.name : side === 'away' ? live.away.name : '' );
	return (
		<section className="card live-card" aria-labelledby="live-h">
			<h2 id="live-h" className="live-card__head">
				{ final ? 'Final' : <span className="live-dot">Live</span> }
				{ final ? null : <span className="live-card__per"> · { periodLabel( live.period ) }</span> }
			</h2>
			<p className="live-card__score">
				<span>{ live.home.name }</span> <b>{ live.score.home }</b>
				<span aria-hidden="true"> – </span>
				<span className="sr-only">, </span>
				<b>{ live.score.away }</b> <span>{ live.away.name }</span>
			</p>
			{ live.entries.length ? (
				<ol className="live-card__plays">
					{ live.entries.slice( 0, 5 ).map( ( e ) => (
						<li key={ e.uid }>
							{ entryLine( e ) }
							{ e.side ? <span className="muted"> · { team( e.side ) }</span> : null }
						</li>
					) ) }
				</ol>
			) : null }
		</section>
	);
}
