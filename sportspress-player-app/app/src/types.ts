/**
 * Shapes of the sppa/v1 REST API as built (class-rest.php), mirroring spec section 5.
 * Timestamps are ISO 8601 with the league's UTC offset.
 */

/**
 * `tbd` is SportsPress's own event status "TBD" (`sp_status = 'tbd'`, time to be decided),
 * which SPPA_Event_Presenter passes through; the game is still on, its time is not settled.
 * `postponed` and `cancelled` mean called off (events-manager's `_spem_cancelled` reads as cancelled).
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

/** Every field may be ''. */
export interface Venue {
	/** Usually the pad: "Black", "Red", "Appleby 1". */
	name: string;
	/** The facility: "Wave Twin Rinks". */
	rink: string;
	address: string;
	/** A Google Maps link. */
	map_url: string;
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
	/** /me/games and /ops/night rows: the live log's summary (home/away), null when nothing was logged. */
	live?: LiveSummary;
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
	/** May keep time (record live games); true for every organizer too. A keeper may be unlinked. */
	is_timekeeper?: boolean;
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
	/** Active log entries flagged for review (serious penalties). */
	needs_review_count?: number;
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
	data?: { status?: number; reason?: string; [ key: string ]: unknown };
}

/*
 * Live scorekeeping (backend contract). Scores are always { home, away }: home is the event's first team.
 */

export type LiveStatus = 'none' | 'live' | 'final';

export interface Score {
	home: number;
	away: number;
}

/** Periods 1-3, 4 = overtime, 5 = shootout. Null when nothing was logged. */
export type LiveSummary = { status: 'live' | 'final'; score: Score; period: number } | null;

export type OffenseKey = 'minor' | 'double_minor' | 'major' | 'misconduct' | 'game_misconduct' | 'gross_misconduct' | 'match' | 'fighting' | 'ejection' | 'other';

export type GoalFlag = 'pp' | 'sh' | 'en';

export interface RosterPlayer {
	player_id: number;
	name: string;
	/** Jersey, '' when unset; a string ("00" is not "0"). */
	number: string;
	position: string;
	/** An accepted spare for this team in this game (listed last). */
	spare: boolean;
}

export interface TeamSide extends TeamRef {
	roster: RosterPlayer[];
}

/** A GET /keep/night row. */
export interface KeeperGame {
	id: number;
	start: string;
	status: GameStatus;
	venue: Venue;
	home: TeamSide;
	away: TeamSide;
	live: LiveSummary;
	needs_review_count: number;
	/** SportsPress has a result, even a half-entered one. */
	has_result: boolean;
	result: SavedResult | null;
}

/** A SportsPress result; a side left blank is null. Also `data.result` on a 409 sppa_result_exists. */
export interface SavedResult {
	home: number | null;
	away: number | null;
}

export interface KeeperNight extends Paged<KeeperGame> {
	date: string;
}

export type EntryType = 'goal' | 'penalty' | 'period' | 'note';

/** One entry as the phone sends it to POST /games/{id}/log; absent fields are left out. */
export interface EntryInput {
	uid: string;
	type: EntryType;
	period: number;
	team_id?: number;
	clock?: string;
	player_id?: number;
	assist1_id?: number;
	assist2_id?: number;
	jersey?: string;
	minutes?: number;
	offense?: OffenseKey;
	flags?: GoalFlag[];
	text?: string;
}

/** An entry as players see it (GET /games/{id}/live). */
export interface PublicEntry {
	uid: string;
	type: EntryType;
	team_id: number;
	side: 'home' | 'away' | null;
	period: number;
	clock: string | null;
	player_id: number | null;
	player_name: string | null;
	jersey: string | null;
	not_on_list: boolean;
	assist1_id: number | null;
	assist1_name: string | null;
	assist2_id: number | null;
	assist2_name: string | null;
	minutes: number | null;
	offense: OffenseKey | null;
	flags: GoalFlag[];
	recorded_at: string;
}

export interface UserRef {
	id: number;
	name: string;
}

/** An entry as keepers see it (GET /games/{id}/log, POST responses). */
export interface KeeperEntry extends PublicEntry {
	text: string | null;
	needs_review: boolean;
	recorded_by: UserRef | null;
	voided: boolean;
	voided_by: UserRef | null;
	voided_at: string | null;
	can_void: boolean;
}

export interface Totals {
	goals: number;
	penalties: number;
	needs_review: number;
	unattributed_goals: number;
	pim: Score;
	shootout: Score;
	players: Array<{ team_id: number; player_id: number; name: string; g: number; a: number; pim: number }>;
}

export type DecidedIn = 'regulation' | 'overtime' | 'shootout' | 'tie';

/** GET /games/{id}/log. */
export interface GameLog {
	game: KeeperGame;
	status: LiveStatus;
	score: Score;
	period: number;
	decided_in: DecidedIn;
	totals: Totals;
	finalized: ( Score & { by: UserRef | null; at: string } ) | null;
	can_reopen: boolean;
	/** Final, but SportsPress no longer holds what Finish of the current log would write: finish again. */
	needs_finish?: boolean;
	/** Newest first, voided included. */
	entries: KeeperEntry[];
}

export interface EntryResult {
	uid: string;
	status: 'created' | 'duplicate' | 'rejected';
	reason?: string;
	entry?: KeeperEntry;
}

/** POST /games/{id}/log. */
export interface PostLogResult {
	results: EntryResult[];
	live: LiveSummary;
}

/** GET /games/{id}/live. */
export interface LiveView {
	event_id: number;
	status: LiveStatus;
	score: Score;
	period: number;
	home: TeamRef;
	away: TeamRef;
	/** Up to 20 recent non-voided goals, penalties and period markers, newest first. */
	entries: PublicEntry[];
}
