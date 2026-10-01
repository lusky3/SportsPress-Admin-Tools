// Pure helpers for discipline notice rows. No imports, no React. A row is the
// shape returned by SPLM_Discipline_Notice_REST::row_to_response(); a missing
// `source` means an automatic (threshold) notice.

// Conveners get plain language, never the stored vocabulary. 'baseline' in
// particular means "recorded so we don't mail them retroactively", which is
// not a phrase anyone should have to decode from a status badge.
export const STATUS_LABELS = {
	baseline: 'On record',
	pending: 'Waiting for you',
	sent: 'Sent',
	failed: 'Could not send',
	discarded: 'Discarded',
	served: 'Served',
	revoked: 'Withdrawn',
};

const isManual = ( row ) => row?.source === 'manual';

const gamesText = ( games ) => ( games === 1 ? '1 game' : `${ games } games` );

// Mirrors the server's consequence vocabulary: manual rows carry
// outcome ('games'|'indefinite') and a revoke correction row has
// consequence 'none' (scope 'manual-revoked').
export function consequenceLabel( row ) {
	if ( ! isManual( row ) ) {
		if ( row.consequence === 'suspend' ) {
			return `Suspension — ${ gamesText( row.games ) }`;
		}
		return 'Warning';
	}
	// Must precede the indefinite check: a revoke correction row copies its
	// parent's outcome (revoked_fields() in the PHP service), so revoking an
	// indefinite suspension yields outcome 'indefinite' + consequence 'none'.
	if ( row.consequence === 'none' ) {
		return 'Correction — notice withdrawn';
	}
	if ( row.outcome === 'indefinite' ) {
		return 'Suspension — indefinite (pending review)';
	}
	if ( row.consequence === 'suspend' && row.games === 0 ) {
		return 'Balance of the game';
	}
	return `Suspension — ${ gamesText( row.games ) }`;
}

// Automatic rows: which accumulation crossed the line (window vs season).
// Manual rows: the infraction that was cited, with its rulebook reference.
export function penaltyLabel( row ) {
	if ( ! isManual( row ) ) {
		if ( row.scope === 'window' ) {
			return `${ row.value_at_fire } PIM in the recent window`;
		}
		return `${ row.value_at_fire } PIM this season`;
	}
	const title = row.infraction_title || '';
	if ( row.rule_ref ) {
		return title ? `Rule ${ row.rule_ref } — ${ title }` : `Rule ${ row.rule_ref }`;
	}
	return title || '—';
}

// Manual row scopes: manual (issued), manual-decided, manual-amended,
// manual-revoked.
const MANUAL_KINDS = {
	'manual-decided': 'decision',
	'manual-amended': 'amended',
	'manual-revoked': 'withdrawn',
};

export function kindLabel( row ) {
	if ( ! isManual( row ) ) {
		return 'Automatic';
	}
	return `Manual — ${ MANUAL_KINDS[ row.scope ] || 'issued' }`;
}

// A row is replaced when another row in the list has parent_id === row.id and
// that child is not discarded (a discarded child never took effect, so the
// parent stays live). The server also flags `replaced: true` on each row, which
// stays correct under a status filter or pagination where the child may not be
// in the list; the result is the union of both. Returns a Set of replaced ids.
export function replacedIds( rows ) {
	const replaced = new Set();
	( rows || [] ).forEach( ( row ) => {
		if ( row.replaced === true ) {
			replaced.add( row.id );
		}
		if ( row.parent_id && row.status !== 'discarded' ) {
			replaced.add( row.parent_id );
		}
	} );
	return replaced;
}

// Live = still the effective row of its chain (not replaced).
export function isLive( row, replaced = new Set() ) {
	return ! replaced.has( row.id );
}

const isSuspend = ( row ) => row.consequence === 'suspend';
const inStatus = ( row, ...statuses ) => statuses.includes( row.status );

// The unfinished states a notice can still be released or discarded from
// (SPLM_Discipline_Notice_REST release/discard: pending|failed).
const isUnsent = ( row ) => inStatus( row, 'pending', 'failed' );

// Action keys a row supports, with the server rule each predicate mirrors.
// Order is the display order. A replaced row offers nothing (checked once in
// availableActions): the newest notice in its chain is the live one. Automatic
// rows only ever match release/discard/serve (the rest require a manual row).
const ACTION_RULES = [
	// POST /discipline/notices/{id}/release (pending|failed)
	[ 'release', isUnsent ],
	// POST /discipline/notices/{id}/discard (pending|failed)
	[ 'discard', isUnsent ],
	// POST /discipline/notices/{id}/serve: a sent suspension. An indefinite one
	// has no length to serve; the convener decides one first and the decided
	// child row is then served.
	[ 'serve', ( row ) => inStatus( row, 'sent' ) && isSuspend( row ) && row.outcome !== 'indefinite' ],
	// POST .../suspensions/{id}/decide (splm_suspension_not_decidable)
	[ 'decide', ( row ) => isManual( row ) && isSuspend( row ) && row.outcome === 'indefinite' && inStatus( row, 'sent', 'served' ) ],
	// POST .../suspensions/{id}/amend (splm_suspension_not_amendable)
	[
		'amend',
		( row ) => isManual( row ) && isSuspend( row ) && row.outcome === 'games' && inStatus( row, 'sent' ) && row.scope !== 'manual-revoked',
	],
	// POST .../suspensions/{id}/revoke (splm_suspension_not_revocable)
	[ 'revoke', ( row ) => isManual( row ) && isSuspend( row ) && inStatus( row, 'sent', 'pending', 'failed' ) ],
	// POST .../suspensions/{id}/recalculate (splm_suspension_not_recalculable)
	[ 'recalculate', ( row ) => isManual( row ) && isSuspend( row ) && row.outcome === 'games' && inStatus( row, 'pending', 'sent' ) ],
];

export function availableActions( row, replaced ) {
	if ( ! isLive( row, replaced ) ) {
		return [];
	}
	return ACTION_RULES.filter( ( [ , applies ] ) => applies( row ) ).map( ( [ key ] ) => key );
}

// Decoded captains_notified lines of a notice: [ { team, email, sent, note? } ].
// The REST row decodes the JSON already; tolerate a raw string or null anyway.
export function captainLines( notice ) {
	let list = notice?.captains_notified;
	if ( typeof list === 'string' ) {
		try {
			list = JSON.parse( list );
		} catch ( e ) {
			list = [];
		}
	}
	return Array.isArray( list ) ? list.filter( ( c ) => c && typeof c === 'object' ) : [];
}

// Captains whose copy did not go out: [ { team, email } ].
export function captainsNotNotified( notice ) {
	return captainLines( notice )
		.filter( ( c ) => c.sent === false )
		.map( ( c ) => ( { team: c.team || 'A team', email: c.email || '' } ) );
}

const notNotifiedReason = ( email ) => ( email ? 'email could not be sent' : 'no email on file' );

// Plain-text delivery state for one captain line (no colour-only meaning).
export function captainStatusText( line ) {
	const team = line.team || 'A team';
	if ( line.sent === false ) {
		return `${ team } — not notified (${ notNotifiedReason( line.email ) })`;
	}
	return `${ team } — notified${ line.note ? ` (${ line.note })` : '' }`;
}

// Visible warning for a saved notice whose captains were not all told, or ''.
// A draft has not been mailed to anyone yet, so it never warns.
export function captainWarning( notice, verb = 'Recorded' ) {
	if ( ! notice || notice.status === 'pending' ) {
		return '';
	}
	const missed = captainsNotNotified( notice );
	if ( ! missed.length ) {
		return '';
	}
	const who = missed.map( ( c ) => `${ c.team } (${ notNotifiedReason( c.email ) })` ).join( ', ' );
	return `${ verb }, but these captains were not notified: ${ who }. Tell them another way — a team that plays a suspended player forfeits.`;
}

// Confirmation text before releasing a notice. Manual notices also copy the
// team captains and convener, so the wording says so.
export function releaseMessage( row, name ) {
	if ( isManual( row ) ) {
		return `Email ${ name }, and copy the team captain(s) and convener, to tell them: ${ consequenceLabel( row ) }?`;
	}
	return `Email ${ name } to tell them: ${ consequenceLabel( row ) }?`;
}
