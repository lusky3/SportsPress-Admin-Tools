<?php
/**
 * Carry a team's captain onto its next season's player list.
 *
 * The captain is stored per list (`spt_captain` on the sp_list), and every new
 * season gets a new list: season rollover creates it empty, and the batch list
 * creator, the League Manager roster tools and SportsPress's own list editor then
 * fill it. None of them copied the captain, so each team's badge disappeared at
 * rollover until someone picked the captain again.
 *
 * Hooked on `added_post_meta` for `sp_player` rows, which every one of those
 * paths writes, so it does not matter how the roster is filled. When a player is
 * added to a list that has no captain, and that player was the captain on the
 * team's previous list, they become this list's captain too.
 *
 * Copied at most once per list (`_spt_captain_carried`): a captain an admin
 * later clears or changes is never put back by a roster re-import.
 *
 * @package SportsPress_Player_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPT_Captain_Carryover {

	/** Marker set on a list once its captain was carried over. */
	const CARRIED_META = '_spt_captain_carried';

	/** @var array<int,int> list ID => previous list's captain (0 when none), per request. */
	private $previous_captain = array();

	public function __construct() {
		add_action( 'added_post_meta', array( $this, 'on_player_added' ), 10, 4 );
	}

	/**
	 * @param int    $meta_id    Meta row ID (unused).
	 * @param int    $list_id    Post the meta was added to.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $player_id  Meta value: the player added.
	 * @return void
	 */
	public function on_player_added( $meta_id, $list_id, $meta_key, $player_id ) {
		unset( $meta_id );
		if ( 'sp_player' !== $meta_key || 'sp_list' !== get_post_type( $list_id ) ) {
			return;
		}

		$player_id = (int) $player_id;
		if ( $player_id <= 0 || '' !== (string) get_post_meta( $list_id, 'spt_captain', true )
			|| '' !== (string) get_post_meta( $list_id, self::CARRIED_META, true ) ) {
			return;
		}

		if ( $this->previous_captain( (int) $list_id ) === $player_id ) {
			update_post_meta( $list_id, 'spt_captain', $player_id );
			update_post_meta( $list_id, self::CARRIED_META, '1' );
		}
	}

	/**
	 * The captain of the team's most recent other list, or 0.
	 *
	 * Only the most recent one counts: a team whose last list had no captain
	 * (cleared on purpose, say) does not get an older captain back.
	 *
	 * @param int $list_id The list being filled.
	 * @return int
	 */
	private function previous_captain( $list_id ) {
		if ( isset( $this->previous_captain[ $list_id ] ) ) {
			return $this->previous_captain[ $list_id ];
		}

		$captain = 0;
		$team_id = (int) get_post_meta( $list_id, 'sp_team', true );
		if ( $team_id > 0 ) {
			$previous = get_posts(
				array(
					'post_type'      => 'sp_list',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'post__not_in'   => array( $list_id ),
					'orderby'        => 'date',
					'order'          => 'DESC',
					'date_query'     => array( array( 'before' => get_post_field( 'post_date', $list_id ) ) ),
					'meta_query'     => array(
						array(
							'key'   => 'sp_team',
							'value' => $team_id,
						),
					),
				)
			);
			if ( ! empty( $previous ) ) {
				$captain = (int) get_post_meta( (int) $previous[0], 'spt_captain', true );
			}
		}

		$this->previous_captain[ $list_id ] = $captain;
		return $captain;
	}
}
