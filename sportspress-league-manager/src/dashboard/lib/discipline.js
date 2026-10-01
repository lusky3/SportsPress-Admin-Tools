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

// Action keys a row supports, in a stable order. Mirrors the REST routes:
//  release/discard  POST /discipline/notices/{id}/release|discard  (pending|failed, not replaced)
//  serve            POST /discipline/notices/{id}/serve            (sent suspension, not replaced)
//  decide           POST .../suspensions/{id}/decide  (splm_suspension_not_decidable)
//  amend            POST .../suspensions/{id}/amend   (splm_suspension_not_amendable)
//  revoke           POST .../suspensions/{id}/revoke  (splm_suspension_not_revocable)
//  recalculate      POST .../suspensions/{id}/recalculate (splm_suspension_not_recalculable)
// Automatic rows only ever get release/discard/serve. A replaced row offers
// nothing: the newest notice in its chain is the live one.
export function availableActions( row, replaced ) {
	const live = isLive( row, replaced );
	const manual = isManual( row );
	const actionable = inStatus( row, 'pending', 'failed' ) && live;
	const suspend = isSuspend( row );
	const flags = {
		release: actionable,
		discard: actionable,
		// An indefinite suspension has no length to serve; the convener decides
		// one first and the decided child row is then served.
		serve: inStatus( row, 'sent' ) && suspend && live && row.outcome !== 'indefinite',
		decide: manual && row.outcome === 'indefinite' && inStatus( row, 'sent', 'served' ) && live && suspend,
		amend:
			manual &&
			row.outcome === 'games' &&
			inStatus( row, 'sent' ) &&
			suspend &&
			live &&
			row.scope !== 'manual-revoked',
		revoke: manual && suspend && inStatus( row, 'sent', 'pending', 'failed' ) && live,
		recalculate: manual && row.outcome === 'games' && suspend && inStatus( row, 'pending', 'sent' ) && live,
	};
	return Object.keys( flags ).filter( ( key ) => flags[ key ] );
}
