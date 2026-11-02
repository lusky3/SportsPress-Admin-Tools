/**
 * Shapes of the spbn/v1 REST API as built (class-rest.php), mirroring spec section 5.
 * Timestamps are ISO 8601 with the league's UTC offset.
 */

export type GameStatus = 'ok' | 'postponed' | 'cancelled' | 'tbd';

/** What a player can answer. A player who has not answered is `none` in lists. */
export type AvailabilityStatus = 'in' | 'out' | 'maybe';

export type Position = 'skater' | 'goalie';

export interface TeamRef {
	id: number;
	name: string;
	/** Crest URL, or '' when the team has none. */
	logo: string;
}

export interface Venue {
	name: string;
	address: string;
}

export interface GameResult {
	for: number;
	against: number;
	outcome: 'W' | 'L' | 'T';
}

/**
 * Head counts for one side. The games list carries `in`, `out` and `maybe`;
 * `/games/{id}` and `/ops/night` also carry `none`, `goalies_in` and `spares`.
 * Accepted spares are counted in `in`.
 */
export interface Counts {
	in: number;
	out: number;
	maybe: number;
	none?: number;
	goalies_in?: number;
	spares?: number;
}

export interface Game {
	id: number;
	start: string;
	status: GameStatus;
	venue: Venue;
	team: TeamRef;
	opponent: TeamRef;
	is_home: boolean;
	result: GameResult | null;
	changed_recently: boolean;
	my_availability: AvailabilityStatus | null;
	counts: Counts;
	as_spare: boolean;
}

/** List envelope (docs/rest-api-conventions.md). */
export interface Paged<T> {
	data: T[];
	total: number;
	page: number;
	total_pages: number;
}

export interface PlayerCard {
	id: number;
	name: string;
	number: string;
	positions: string[];
}

export interface TeamSummary {
	id: number;
	name: string;
	logo: string;
	division: string;
}

export interface SparePrefs {
	enabled: boolean;
	positions: Position[];
}

/** GET /me. An account with no linked player is 200 with `linked: false` and empty arrays. */
export interface Me {
	user: { id: number; name: string };
	linked: boolean;
	players: PlayerCard[];
	teams: TeamSummary[];
	season: { id: number; name: string };
	is_organizer: boolean;
	spare_prefs: SparePrefs;
	/** Subscribe URL for the calendar feed; '' when not linked. */
	ical_url: string;
}

export interface TeamRecord {
	w: number;
	l: number;
	t: number;
	otl?: number;
}

export interface RosterMember {
	player_id: number;
	name: string;
	number: string;
	positions: string[];
	is_captain: boolean;
	is_me: boolean;
}

/** GET /teams/{id}. */
export interface TeamDetail {
	id: number;
	name: string;
	logo: string;
	division: string;
	record: TeamRecord;
	roster: RosterMember[];
}

export interface AvailabilityPlayer {
	player_id: number;
	name: string;
	number: string;
	positions: string[];
	/** Display string, e.g. "Defence, Forward". */
	position: string;
	status: AvailabilityStatus | 'none';
	note: string;
	/** True for an accepted spare listed under the calling team. */
	spare?: boolean;
}

/** One side of a game on the game screen. */
export interface SideAvailability {
	team_id: number;
	/** The viewer's own answer; null when they have no player on this side. */
	mine: { player_id: number; status: AvailabilityStatus | null; note: string } | null;
	/** False once the game started more than 3 hours ago (organizers can still edit). */
	editable: boolean;
	counts: Counts & { none: number; goalies_in: number; spares: number };
	/** Teammates and organizers only; an accepted spare gets counts and an empty list. */
	players: AvailabilityPlayer[];
}

export type CallStatus = 'open' | 'filled' | 'cancelled';

export interface SpareCall {
	id: number;
	event_id: number;
	team_id: number;
	position: Position;
	needed: number;
	note: string;
	status: CallStatus;
	/** How many spares have accepted so far. */
	accepted: number;
	created_at: string;
	is_creator: boolean;
	accepted_by_me: boolean;
	can_cancel: boolean;
}

/** A spare call with the game it is for, from the calling team's side. */
export interface SpareCallWithGame extends SpareCall {
	game: Game;
}

/** GET /games/{id}. */
export interface GameDetail extends Game {
	availability: SideAvailability;
	/** Organizers only; null for everyone else. */
	opponent_availability: SideAvailability | null;
	spare_calls: SpareCall[];
}

/** GET /spares. */
export interface SparesPayload {
	prefs: SparePrefs;
	open_calls: SpareCallWithGame[];
	my_accepted: Array<SpareCallWithGame & { can_withdraw: boolean }>;
	my_team_calls: SpareCallWithGame[];
}

export interface NightSide {
	team: TeamRef;
	counts: Counts & { none: number; goalies_in: number; spares: number };
	goalies_in: number;
}

export interface NightGame extends Game {
	sides: NightSide[];
	open_calls: SpareCall[];
	has_result: boolean;
}

/** GET /ops/night?date=YYYY-MM-DD (organizers). */
export interface NightPayload extends Paged<NightGame> {
	date: string;
}

/** PUT /games/{id}/availability. */
export interface AvailabilityResult {
	success: true;
	availability: {
		player_id: number;
		team_id: number;
		status: AvailabilityStatus;
		note: string;
		updated_at: string;
		event_id: number;
	};
	counts: Counts;
}

/** Errors from the API are WP_Error JSON: `{ code, message, data: { status } }`. */
export interface WpErrorBody {
	code?: string;
	message?: string;
	data?: { status?: number; reason?: string };
}
