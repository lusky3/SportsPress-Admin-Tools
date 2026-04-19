<?php
/**
 * XLSX Exporter Class
 *
 * Exports schedules to Excel format with formatting and color-coding.
 * Uses PHP's built-in ZipArchive to create XLSX files (no external dependencies).
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	wp_die();
}

/**
 * XLSX Exporter — dependency-free implementation
 *
 * XLSX files are ZIP archives containing XML files that follow the
 * Office Open XML (OOXML) SpreadsheetML specification.
 */
class SPSG_XLSX_Exporter implements SPSG_Exporter_Interface {

	/**
	 * Division color palette (ARGB without leading FF — added in XML).
	 * Light pastel backgrounds so text remains readable.
	 */
	private const DIVISION_COLORS = array(
		'E2EFDA', // Light green
		'FCE4D6', // Light orange
		'D9E1F2', // Light blue
		'FFF2CC', // Light yellow
		'F4B084', // Light coral
		'C5E0B4', // Sage green
		'B4C7E7', // Sky blue
		'FFD966', // Gold
		'E7E6E6', // Light gray
		'BDD7EE', // Powder blue
	);

	/** Header row fill colour (dark blue). */
	private const HEADER_BG = '4472C4';

	/** Header font colour (white). */
	private const HEADER_FG = 'FFFFFF';

	/**
	 * Export schedule to XLSX format.
	 *
	 * @param array $schedule Array of game objects/arrays.
	 * @param mixed $config   Configuration object or array (unused but required by interface).
	 * @return array|WP_Error Export result with file path and URL.
	 */
	public function export( $schedule, $config = null ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error(
				'missing_zip',
				__( 'PHP ZipArchive extension is required for XLSX export.', 'sportspress-schedule-generator' )
			);
		}

		$upload_dir = wp_upload_dir();
		$export_dir = $upload_dir['basedir'] . '/spsg-exports';
		$filename   = 'schedule-' . wp_date( 'Y-m-d-His' ) . '.xlsx';
		$filepath   = $export_dir . '/' . $filename;

		if ( ! file_exists( $export_dir ) ) {
			wp_mkdir_p( $export_dir );
		}

		// Normalise every game to an array so both object and array data work.
		$rows             = array();
		$division_map     = array(); // division_name => color_index
		$color_index      = 0;

		foreach ( $schedule as $game ) {
			$g = $this->normalise_game( $game );

			$div_name = $g['division_name'];
			if ( $div_name !== '' && ! isset( $division_map[ $div_name ] ) ) {
				$division_map[ $div_name ] = $color_index % count( self::DIVISION_COLORS );
				$color_index++;
			}

			$rows[] = $g;
		}

		try {
			$this->write_xlsx( $filepath, $rows, $division_map );
		} catch ( \Exception $e ) {
			error_log( '[SPSG] XLSX export error: ' . $e->getMessage() );
			return new WP_Error( 'export_failed', __( 'Failed to export schedule to XLSX format.', 'sportspress-schedule-generator' ) );
		}

		return array(
			'path'     => $filepath,
			'url'      => $upload_dir['baseurl'] . '/spsg-exports/' . $filename,
			'filename' => $filename,
			'format'   => 'xlsx',
		);
	}

	/* ------------------------------------------------------------------
	 * Data helpers
	 * ----------------------------------------------------------------*/

	/**
	 * Normalise a game (object or array) into a flat associative array.
	 */
	private function normalise_game( $game ) {
		$g = (array) $game;
		$g['division']  = isset( $g['division'] )  ? (array) $g['division']  : array();
		$g['home_team'] = isset( $g['home_team'] ) ? (array) $g['home_team'] : array();
		$g['away_team'] = isset( $g['away_team'] ) ? (array) $g['away_team'] : array();
		$g['venue']     = isset( $g['venue'] )     ? (array) $g['venue']     : array();

		$home = $g['home_team']['name'] ?? $g['home_team']['id'] ?? '';
		$away = $g['away_team']['name'] ?? $g['away_team']['id'] ?? '';
		$is_inter = ! empty( $g['is_inter_division'] );

		return array(
			'date'           => $g['date'] ?? '',
			'time_slot'      => $g['time_slot'] ?? '',
			'end_time'       => $g['end_time'] ?? '',
			'match_length'   => $g['match_length'] ?? 60,
			'home'           => $home,
			'away'           => $away,
			'venue'          => $g['venue']['name'] ?? $g['venue']['id'] ?? '',
			'division_name'  => $g['division']['name'] ?? $g['division']['id'] ?? '',
			'home_away'      => sprintf( '%s (H) vs %s (A)', $home, $away ),
			'inter_division' => $is_inter ? 'Yes' : 'No',
			'is_makeup'      => ! empty( $g['is_makeup'] ) ? 'Yes' : 'No',
			'week_number'    => $g['week_number'] ?? '',
		);
	}

	/* ------------------------------------------------------------------
	 * XLSX generation (ZipArchive + raw XML)
	 * ----------------------------------------------------------------*/

	/**
	 * Build the XLSX file on disk.
	 *
	 * @param string $filepath      Absolute path for the output file.
	 * @param array  $rows          Normalised game rows.
	 * @param array  $division_map  Division name → colour index.
	 */
	private function write_xlsx( $filepath, $rows, $division_map ) {
		$zip = new \ZipArchive();
		if ( $zip->open( $filepath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) !== true ) {
			throw new \RuntimeException( 'Cannot create ZIP file: ' . $filepath );
		}

		// Build the fills array: 0 = none, 1 = gray125 (defaults), 2 = header, 3+ = divisions.
		$fills         = array();
		$fills[]       = self::HEADER_BG; // index 0 → will become fill index 2 (after the two built-in fills)
		$div_fill_base = 1;               // division fills start at index 1 in our array → fill index 3 in XML
		foreach ( $division_map as $div_name => $ci ) {
			$fills[] = self::DIVISION_COLORS[ $ci ];
		}

		// Map division name → fill index in the XML (offset by 2 built-in + 1 header).
		$div_fill_ids = array();
		$idx          = 0;
		foreach ( $division_map as $div_name => $ci ) {
			$div_fill_ids[ $div_name ] = 3 + $idx; // 0,1 built-in + 2 header = first custom at 3
			$idx++;
		}

		$shared  = array();  // shared strings table
		$ss_idx  = array();  // string → index

		$headers = array(
			'Date', 'Start Time', 'End Time', 'Duration (min)',
			'Home Team', 'Away Team', 'Venue', 'Division',
			'Home/Away', 'Inter-Division', 'Makeup', 'Week',
		);

		// Pre-populate shared strings with headers.
		foreach ( $headers as $h ) {
			$ss_idx[ $h ] = count( $shared );
			$shared[]     = $h;
		}

		// Pre-populate shared strings with cell values.
		foreach ( $rows as $r ) {
			foreach ( $this->row_values( $r ) as $v ) {
				$v = (string) $v;
				if ( $v !== '' && ! isset( $ss_idx[ $v ] ) ) {
					$ss_idx[ $v ] = count( $shared );
					$shared[]     = $v;
				}
			}
		}

		// --- Build XF styles ---
		// XF 0 = default, XF 1 = header (bold, white on blue, centred),
		// XF 2 = centred (for date/time cols on division rows),
		// XF 3+ = one per division fill (left-aligned),
		// after that = centred variants for each division.
		$xf_header  = 1;
		$xf_center  = 2; // default centred (no fill)
		$xf_div     = array(); // div_name => left-aligned xf
		$xf_div_c   = array(); // div_name => centred xf
		$next_xf    = 3;
		foreach ( $division_map as $div_name => $ci ) {
			$xf_div[ $div_name ]   = $next_xf++;
			$xf_div_c[ $div_name ] = $next_xf++;
		}

		// ---- Content Types ----
		$zip->addFromString( '[Content_Types].xml', $this->xml_content_types() );

		// ---- Relationships ----
		$zip->addFromString( '_rels/.rels', $this->xml_rels() );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', $this->xml_workbook_rels() );

		// ---- Workbook ----
		$zip->addFromString( 'xl/workbook.xml', $this->xml_workbook() );

		// ---- Styles ----
		$zip->addFromString( 'xl/styles.xml', $this->xml_styles( $fills, count( $division_map ) ) );

		// ---- Shared Strings ----
		$zip->addFromString( 'xl/sharedStrings.xml', $this->xml_shared_strings( $shared ) );

		// ---- Sheet ----
		$sheet_xml = $this->xml_sheet(
			$headers,
			$rows,
			$ss_idx,
			$xf_header,
			$xf_center,
			$xf_div,
			$xf_div_c
		);
		$zip->addFromString( 'xl/worksheets/sheet1.xml', $sheet_xml );

		$zip->close();
	}

	/**
	 * Return the cell values for a normalised row (same order as headers).
	 */
	private function row_values( $r ) {
		return array(
			$r['date'],
			$r['time_slot'],
			$r['end_time'],
			$r['match_length'],
			$r['home'],
			$r['away'],
			$r['venue'],
			$r['division_name'],
			$r['home_away'],
			$r['inter_division'],
			$r['is_makeup'],
			$r['week_number'],
		);
	}

	/* ------------------------------------------------------------------
	 * XML builders
	 * ----------------------------------------------------------------*/

	private function xml_content_types() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
			. '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
			. '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
			. '</Types>';
	}

	private function xml_rels() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
			. '</Relationships>';
	}

	private function xml_workbook_rels() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
			. '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
			. '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
			. '</Relationships>';
	}

	private function xml_workbook() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
			. ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<sheets><sheet name="Schedule" sheetId="1" r:id="rId1"/></sheets>'
			. '</workbook>';
	}

	/**
	 * Build styles.xml with fills and cell XFs.
	 *
	 * @param array $custom_fills Array of hex colours (header + divisions).
	 * @param int   $div_count    Number of divisions.
	 */
	private function xml_styles( $custom_fills, $div_count ) {
		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

		// --- Fonts ---
		// 0 = default, 1 = bold white (header), 2 = bold blue (inter-div)
		$xml .= '<fonts count="3">'
			. '<font><sz val="11"/><name val="Calibri"/></font>'
			. '<font><b/><sz val="12"/><color rgb="FF' . self::HEADER_FG . '"/><name val="Calibri"/></font>'
			. '<font><b/><sz val="11"/><color rgb="FF0066CC"/><name val="Calibri"/></font>'
			. '</fonts>';

		// --- Fills ---
		// 0 = none, 1 = gray125 (required by spec), then custom fills.
		$fill_count = 2 + count( $custom_fills );
		$xml       .= '<fills count="' . $fill_count . '">'
			. '<fill><patternFill patternType="none"/></fill>'
			. '<fill><patternFill patternType="gray125"/></fill>';
		foreach ( $custom_fills as $hex ) {
			$xml .= '<fill><patternFill patternType="solid"><fgColor rgb="FF' . $hex . '"/></patternFill></fill>';
		}
		$xml .= '</fills>';

		// --- Borders ---
		// 0 = none, 1 = thin all around.
		$xml .= '<borders count="2">'
			. '<border><left/><right/><top/><bottom/><diagonal/></border>'
			. '<border>'
			. '<left style="thin"><color auto="1"/></left>'
			. '<right style="thin"><color auto="1"/></right>'
			. '<top style="thin"><color auto="1"/></top>'
			. '<bottom style="thin"><color auto="1"/></bottom>'
			. '<diagonal/>'
			. '</border>'
			. '</borders>';

		// --- Cell XFs ---
		// XF 0 = default (border)
		// XF 1 = header (font 1, fill 2, border, centred)
		// XF 2 = centred no fill (border)
		// XF 3,4 = div 1 left / centred … etc.
		$xf_count = 3 + ( $div_count * 2 );
		$xml     .= '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>';
		$xml     .= '<cellXfs count="' . $xf_count . '">';

		// XF 0 — default with border
		$xml .= '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>';

		// XF 1 — header
		$xml .= '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0"'
			. ' applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
			. '<alignment horizontal="center" vertical="center"/></xf>';

		// XF 2 — centred, no fill
		$xml .= '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"'
			. ' applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>';

		// Division XFs (pairs: left-aligned, centred)
		for ( $i = 0; $i < $div_count; $i++ ) {
			$fill_id = 3 + $i; // 0,1 built-in + header at 2
			// Left-aligned
			$xml .= '<xf numFmtId="0" fontId="0" fillId="' . $fill_id . '" borderId="1" xfId="0"'
				. ' applyFill="1" applyBorder="1"/>';
			// Centred
			$xml .= '<xf numFmtId="0" fontId="0" fillId="' . $fill_id . '" borderId="1" xfId="0"'
				. ' applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>';
		}

		$xml .= '</cellXfs>';
		$xml .= '</styleSheet>';

		return $xml;
	}

	/**
	 * Build sharedStrings.xml.
	 */
	private function xml_shared_strings( $strings ) {
		$count = count( $strings );
		$xml   = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
			. ' count="' . $count . '" uniqueCount="' . $count . '">';
		foreach ( $strings as $s ) {
			$xml .= '<si><t>' . $this->xml_escape( $s ) . '</t></si>';
		}
		$xml .= '</sst>';
		return $xml;
	}

	/**
	 * Build the worksheet XML.
	 */
	private function xml_sheet( $headers, $rows, $ss_idx, $xf_header, $xf_center, $xf_div, $xf_div_c ) {
		$col_letters = array( 'A','B','C','D','E','F','G','H','I','J','K','L' );
		$col_count   = count( $col_letters );
		$row_count   = 1 + count( $rows );

		// Column widths (approximate).
		$widths = array( 12, 11, 11, 13, 18, 18, 16, 14, 30, 14, 10, 8 );

		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
			. ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';

		// Freeze top row.
		$xml .= '<sheetViews><sheetView tabSelected="1" workbookViewId="0">'
			. '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
			. '</sheetView></sheetViews>';

		// Column widths.
		$xml .= '<cols>';
		for ( $i = 0; $i < $col_count; $i++ ) {
			$min = $i + 1;
			$xml .= '<col min="' . $min . '" max="' . $min . '" width="' . $widths[ $i ] . '" customWidth="1"/>';
		}
		$xml .= '</cols>';

		$xml .= '<sheetData>';

		// Header row.
		$xml .= '<row r="1" ht="25" customHeight="1">';
		for ( $c = 0; $c < $col_count; $c++ ) {
			$ref = $col_letters[ $c ] . '1';
			$xml .= '<c r="' . $ref . '" t="s" s="' . $xf_header . '"><v>' . $ss_idx[ $headers[ $c ] ] . '</v></c>';
		}
		$xml .= '</row>';

		// Data rows.
		$r = 2;
		foreach ( $rows as $row_data ) {
			$div_name = $row_data['division_name'];
			$xf_left  = isset( $xf_div[ $div_name ] )   ? $xf_div[ $div_name ]   : 0;
			$xf_cent  = isset( $xf_div_c[ $div_name ] ) ? $xf_div_c[ $div_name ] : $xf_center;

			$values = $this->row_values( $row_data );

			$xml .= '<row r="' . $r . '">';
			for ( $c = 0; $c < $col_count; $c++ ) {
				$ref = $col_letters[ $c ] . $r;
				$val = (string) $values[ $c ];

				// Columns 0-3 (Date, times, duration) and 9-11 (inter-div, makeup, week) are centred.
				$style = ( $c <= 3 || $c >= 9 ) ? $xf_cent : $xf_left;

				if ( $val === '' ) {
					$xml .= '<c r="' . $ref . '" s="' . $style . '"/>';
				} elseif ( isset( $ss_idx[ $val ] ) ) {
					$xml .= '<c r="' . $ref . '" t="s" s="' . $style . '"><v>' . $ss_idx[ $val ] . '</v></c>';
				} else {
					// Numeric value.
					$xml .= '<c r="' . $ref . '" s="' . $style . '"><v>' . $this->xml_escape( $val ) . '</v></c>';
				}
			}
			$xml .= '</row>';
			$r++;
		}

		$xml .= '</sheetData>';

		// Auto-filter on header row.
		$xml .= '<autoFilter ref="A1:L' . $row_count . '"/>';

		$xml .= '</worksheet>';
		return $xml;
	}

	/**
	 * Escape a string for XML content.
	 */
	private function xml_escape( $str ) {
		return htmlspecialchars( (string) $str, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	/* ------------------------------------------------------------------
	 * Interface methods
	 * ----------------------------------------------------------------*/

	public function get_format() {
		return 'XLSX';
	}

	public function get_format_name() {
		return __( 'Excel (XLSX)', 'sportspress-schedule-generator' );
	}

	public function get_extension() {
		return 'xlsx';
	}

	public function get_mime_type() {
		return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
	}

	public function supports_formatting() {
		return true;
	}
}
