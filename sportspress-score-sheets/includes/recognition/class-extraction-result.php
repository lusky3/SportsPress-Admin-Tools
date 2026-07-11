<?php
/**
 * Normalized result of a recognition pass, provider-agnostic.
 *
 * Every recognition provider returns one of these so the ingest pipeline,
 * consistency checker, review UI, and SportsPress writer all speak the same
 * shape regardless of which backend (Claude, a future doc-AI, etc.) produced it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPSS_Extraction_Result {

	/** @var array Structured sheet payload (see schema in to_array()). */
	public $data;

	/** @var array List of flags: [ ['type'=>string,'detail'=>string,'player_index'=>?int], ... ]. */
	public $flags;

	/** @var string Provider id that produced this result. */
	public $provider;

	/** @var string Raw provider response (JSON), retained for audit/debug. */
	public $raw;

	public function __construct( array $data = array(), array $flags = array(), $provider = '', $raw = '' ) {
		$this->data     = $data;
		$this->flags    = $flags;
		$this->provider = (string) $provider;
		$this->raw      = (string) $raw;
	}

	public function add_flag( $type, $detail = '', $player_index = null ) {
		$this->flags[] = array(
			'type'         => (string) $type,
			'detail'       => (string) $detail,
			'player_index' => is_null( $player_index ) ? null : (int) $player_index,
		);
	}

	public function requires_review() {
		return ! empty( $this->flags );
	}

	/**
	 * Canonical serializable structure persisted in the queue row's
	 * extracted_json column and rendered by the review UI.
	 */
	public function to_array() {
		return array(
			'sheet_meta' => $this->data['sheet_meta'] ?? array(),
			'teams'      => $this->data['teams'] ?? array(),
			'periods'    => $this->data['periods'] ?? array(),
			'players'    => $this->data['players'] ?? array(),
			'goalies'    => $this->data['goalies'] ?? array(),
			'flags'      => $this->flags,
			'provider'   => $this->provider,
		);
	}

	public static function from_array( array $arr, $provider = '', $raw = '' ) {
		$flags = $arr['flags'] ?? array();
		unset( $arr['flags'] );
		return new self( $arr, is_array( $flags ) ? $flags : array(), $provider ?: ( $arr['provider'] ?? '' ), $raw );
	}
}
