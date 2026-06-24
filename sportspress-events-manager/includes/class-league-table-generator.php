<?php
/**
 * League Table Generator Class
 *
 * NOTE: The interactive "Generate League Table" modal (markup + AJAX handler +
 * JS) was removed because it was dead code — the modal was rendered into the
 * admin footer and the JS exposed `window.openLeagueTableModal`, but nothing
 * anywhere ever called the opener (no button, menu item, or inline handler),
 * so the modal could never be displayed and the AJAX route was unreachable.
 * Shipping unreachable markup/handlers is a maintenance and review hazard, so
 * the dead surface has been removed. The module remains registered with the
 * parent plugin as a no-op placeholder; reintroduce a real opener (and the
 * AJAX handler) here when the feature is wired into the settings UI.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPEM_League_Table_Generator {

	public function __construct() {
		// Intentionally inert. See file-level note: the previous modal/AJAX
		// surface was dead (no opener was ever invoked) and has been removed.
	}
}
