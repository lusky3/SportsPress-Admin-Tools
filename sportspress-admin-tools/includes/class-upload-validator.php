<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPAT_Upload_Validator {

	const DEFAULT_MAX_BYTES = 5242880;
	const DEFAULT_MAX_ROWS  = 5000;

	const MIME_CSV  = array( 'text/csv', 'application/csv', 'application/vnd.ms-excel' );
	const MIME_XLSX = array( 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip' );

	public static function validate( $file, $args = array() ) {
		$args = wp_parse_args( $args, array(
			'allowed_extensions' => array( 'csv' ),
			'allowed_mime_types' => self::MIME_CSV,
			'max_bytes'          => self::DEFAULT_MAX_BYTES,
		) );

		if ( ! is_array( $file ) || empty( $file['tmp_name'] ) ) {
			return new WP_Error( 'spat_no_file', __( 'No file received.', 'sportspress-admin-tools' ) );
		}

		if ( ! empty( $file['error'] ) ) {
			return new WP_Error( 'spat_upload_error', sprintf( __( 'Upload error code %d.', 'sportspress-admin-tools' ), (int) $file['error'] ) );
		}

		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'spat_not_uploaded', __( 'File is not a valid HTTP upload.', 'sportspress-admin-tools' ) );
		}

		$size = (int) ( $file['size'] ?? filesize( $file['tmp_name'] ) );
		if ( $size <= 0 ) {
			return new WP_Error( 'spat_empty_file', __( 'File is empty.', 'sportspress-admin-tools' ) );
		}
		if ( $size > (int) $args['max_bytes'] ) {
			return new WP_Error( 'spat_file_too_large', sprintf( __( 'File exceeds %d bytes.', 'sportspress-admin-tools' ), (int) $args['max_bytes'] ) );
		}

		$original_name = $file['name'] ?? '';
		$extension     = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, $args['allowed_extensions'], true ) ) {
			return new WP_Error( 'spat_bad_extension', __( 'Disallowed file extension.', 'sportspress-admin-tools' ) );
		}

		$detected_mime = self::detect_mime( $file['tmp_name'] );
		if ( '' === $detected_mime ) {
			return new WP_Error( 'spat_mime_unknown', __( 'Unable to determine file type.', 'sportspress-admin-tools' ) );
		}
		if ( ! in_array( $detected_mime, $args['allowed_mime_types'], true ) ) {
			return new WP_Error( 'spat_bad_mime', sprintf( __( 'Disallowed file content type: %s.', 'sportspress-admin-tools' ), $detected_mime ) );
		}

		return array(
			'size'      => $size,
			'extension' => $extension,
			'mime'      => $detected_mime,
			'tmp_name'  => $file['tmp_name'],
			'name'      => $original_name,
		);
	}

	public static function detect_mime( $tmp_name ) {
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = @finfo_open( FILEINFO_MIME_TYPE );
			if ( $finfo ) {
				$mime = (string) finfo_file( $finfo, $tmp_name );
				finfo_close( $finfo );
				if ( $mime ) {
					return $mime;
				}
			}
		}
		if ( function_exists( 'mime_content_type' ) ) {
			$mime = (string) @mime_content_type( $tmp_name );
			if ( $mime ) {
				return $mime;
			}
		}
		return '';
	}

	public static function count_csv_rows( $tmp_name, $max_rows ) {
		$handle = @fopen( $tmp_name, 'rb' );
		if ( ! $handle ) {
			return new WP_Error( 'spat_open_failed', __( 'Unable to read file.', 'sportspress-admin-tools' ) );
		}
		$count = 0;
		while ( ! feof( $handle ) ) {
			$line = fgets( $handle );
			if ( false === $line ) {
				break;
			}
			$count++;
			if ( $count > $max_rows ) {
				fclose( $handle );
				return new WP_Error( 'spat_too_many_rows', sprintf( __( 'CSV exceeds %d rows.', 'sportspress-admin-tools' ), $max_rows ) );
			}
		}
		fclose( $handle );
		return $count;
	}

	public static function csv_safe( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$str = (string) $value;
		if ( '' === $str ) {
			return $str;
		}
		$first = $str[0];
		if ( '=' === $first || '+' === $first || '-' === $first || '@' === $first || "\t" === $first || "\r" === $first ) {
			return "'" . $str;
		}
		return $str;
	}
}
