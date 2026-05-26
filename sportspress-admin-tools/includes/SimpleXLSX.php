<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
/**
 * XLSX parser for PHP
 * Reads actual XLSX files using ZIP extraction
 *
 * @author Cody (lusky3)
 */

class SimpleXLSX {

	const MAX_FILE_BYTES         = 52428800;
	const MAX_UNCOMPRESSED_BYTES = 104857600;
	const MAX_SHARED_STRINGS     = 200000;
	const MAX_ROWS               = 5000;

	private $data = array();

	public static function parse( $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			return false;
		}
		if ( filesize( $file_path ) > self::MAX_FILE_BYTES ) {
			return false;
		}

		$instance = new self();
		return $instance->parseFile( $file_path ) ? $instance : false;
	}

	private function parseFile( $file_path ) {
		$extension = pathinfo( $file_path, PATHINFO_EXTENSION );

		if ( strtolower( $extension ) === 'csv' ) {
			return $this->parseCSV( $file_path );
		}

		if ( strtolower( $extension ) === 'xlsx' ) {
			return $this->parseXLSX( $file_path );
		}

		return false;
	}

	private function parseXLSX( $file_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return false;
		}

		$zip = new ZipArchive();
		if ( $zip->open( $file_path ) !== true ) {
			return false;
		}

		$sheet_stat = $zip->statName( 'xl/worksheets/sheet1.xml' );
		if ( ! $sheet_stat || ! isset( $sheet_stat['size'] ) || $sheet_stat['size'] > self::MAX_UNCOMPRESSED_BYTES ) {
			$zip->close();
			return false;
		}

		$strings_stat = $zip->statName( 'xl/sharedStrings.xml' );
		if ( $strings_stat && isset( $strings_stat['size'] ) && $strings_stat['size'] > self::MAX_UNCOMPRESSED_BYTES ) {
			$zip->close();
			return false;
		}

		$shared_strings = $this->extractSharedStrings( $zip );
		$sheet_xml      = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
		$zip->close();

		if ( ! $sheet_xml ) {
			return false;
		}

		$doc = $this->loadXmlSafe( $sheet_xml );
		if ( ! $doc ) {
			return false;
		}
		$this->data = array();

		$rows = $doc->getElementsByTagName( 'row' );
		if ( $rows->length > self::MAX_ROWS ) {
			return false;
		}

		foreach ( $rows as $row ) {
			$this->data[] = $this->parseRow( $row, $shared_strings );
		}

		return ! empty( $this->data );
	}

	private function extractSharedStrings( $zip ) {
		$shared_strings = array();
		$strings_xml    = $zip->getFromName( 'xl/sharedStrings.xml' );

		if ( ! $strings_xml ) {
			return $shared_strings;
		}

		$doc = $this->loadXmlSafe( $strings_xml );
		if ( ! $doc ) {
			return $shared_strings;
		}
		$nodes = $doc->getElementsByTagName( 't' );
		if ( $nodes->length > self::MAX_SHARED_STRINGS ) {
			return array();
		}
		foreach ( $nodes as $node ) {
			$shared_strings[] = $node->nodeValue;
		}

		return $shared_strings;
	}

	private function loadXmlSafe( $xml_string ) {
		$doc = new DOMDocument();
		if ( PHP_VERSION_ID < 80000 ) {
			$libxml_loader = libxml_disable_entity_loader( true );
		}
		$previous_internal_errors = libxml_use_internal_errors( true );
		$loaded                   = $doc->loadXML( $xml_string, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_internal_errors );
		if ( PHP_VERSION_ID < 80000 ) {
			libxml_disable_entity_loader( $libxml_loader );
		}
		return $loaded ? $doc : null;
	}

	private function parseRow( $row, $shared_strings ) {
		$row_data  = array();
		$col_index = 0;

		foreach ( $row->getElementsByTagName( 'c' ) as $cell ) {
			$col_letter = preg_replace( '/\d+/', '', $cell->getAttribute( 'r' ) );
			$target_col = $this->columnIndexFromString( $col_letter );

			if ( $target_col > 256 ) {
				return array();
			}

			// Fill empty columns
			while ( $col_index < $target_col ) {
				$row_data[] = '';
				$col_index++;
			}

			$row_data[] = $this->getCellValue( $cell, $shared_strings );
			$col_index++;
		}

		return $row_data;
	}

	private function getCellValue( $cell, $shared_strings ) {
		$value_node = $cell->getElementsByTagName( 'v' )->item( 0 );

		if ( ! $value_node ) {
			return '';
		}

		if ( $cell->getAttribute( 't' ) === 's' ) {
			$index = (int) $value_node->nodeValue;
			return isset( $shared_strings[ $index ] ) ? $shared_strings[ $index ] : '';
		}

		return $value_node->nodeValue;
	}

	private function columnIndexFromString( $column ) {
		$index  = 0;
		$length = strlen( $column );
		for ( $i = 0; $i < $length; $i++ ) {
			$index = $index * 26 + ( ord( $column[ $i ] ) - ord( 'A' ) + 1 );
		}
		return $index - 1;
	}

	private function parseCSV( $file_path ) {
		$handle = fopen( $file_path, 'r' );
		if ( ! $handle ) {
			return false;
		}

		$this->data = array();
		$row_count  = 0;
		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			$row_count++;
			if ( $row_count > self::MAX_ROWS ) {
				fclose( $handle );
				$this->data = array();
				return false;
			}
			$this->data[] = $row;
		}

		fclose( $handle );
		return ! empty( $this->data );
	}

	public function rows() {
		return $this->data;
	}
}
