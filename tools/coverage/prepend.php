<?php
/**
 * Coverage prepend (auto_prepend_file). Arms PCOV, then on shutdown dumps this
 * process's line coverage to a unique JSON file under $SPSS_COV_DIR. One file
 * per test process; tools/coverage/merge.php combines them into a Clover report.
 *
 * PCOV line values: >0 = executed count, -1 = executable-but-not-run, absent =
 * non-executable line. \pcov\start() is required — pcov.enabled=1 alone does
 * not begin recording under an auto_prepend_file.
 */
if ( ! extension_loaded( 'pcov' ) ) {
	return;
}

\pcov\start();

register_shutdown_function(
	static function () {
		$dir = getenv( 'SPSS_COV_DIR' );
		if ( ! $dir || ! is_dir( $dir ) ) {
			return;
		}
		\pcov\stop();
		$data = \pcov\collect();
		$file = rtrim( $dir, '/' ) . '/cov-' . getmypid() . '-' . uniqid( '', true ) . '.json';
		file_put_contents( $file, json_encode( $data ) );
	}
);
