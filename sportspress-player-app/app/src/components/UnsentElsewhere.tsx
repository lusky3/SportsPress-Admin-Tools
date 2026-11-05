import { useUnsentElsewhere } from '../lib/outbox';

/** Says when another keeper's entries wait on this phone: they only send from that keeper's sign-in. */
export function UnsentElsewhere() {
	const n = useUnsentElsewhere();
	if ( ! n ) {
		return null;
	}
	return (
		<p className="notice" role="status">
			{ n } unsent { n === 1 ? 'entry' : 'entries' } from another account on this phone — sign in as that keeper to send { n === 1 ? 'it' : 'them' }.
		</p>
	);
}
