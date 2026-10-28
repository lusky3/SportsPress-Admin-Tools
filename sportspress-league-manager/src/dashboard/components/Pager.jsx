// Previous / Next with a "Showing x–y of N" status, shared by the paged
// dashboard tables. When the server did not report a page count, `atLastGuess`
// says whether this page looked like the last one.
export default function Pager( { page, totalPages, startIdx, endIdx, total, onPrev, onNext, atLastGuess } ) {
	return (
		<div className="splm-pager">
			<button type="button" className="splm-btn" onClick={ onPrev } disabled={ page <= 1 }>Previous</button>
			<span className="splm-pager__status" aria-live="polite">
				Showing { startIdx }–{ endIdx } of { total }
				{ totalPages > 1 ? ` (page ${ page } of ${ totalPages })` : '' }
			</span>
			<button
				type="button"
				className="splm-btn"
				onClick={ onNext }
				disabled={ totalPages ? page >= totalPages : atLastGuess }
			>Next</button>
		</div>
	);
}
