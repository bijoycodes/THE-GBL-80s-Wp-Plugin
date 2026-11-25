<?php
/**
 * Plugin Name: GBL 80s Front-end Dashboard
 * Description: Role-based front-end dashboard ([gbl_dashboard]) for the GBL 80s Story site. Club members submit, club contributors approve for their own club, the GBL 80s Team and administrators see everything. Works with SCF/ACF, Members, Frontend Admin and Elementor Pro.
 * Version: 2.3.0
 * Author: GBL 80s Team
 * Requires PHP: 7.4
 * Text Domain: gbl80s
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// This plugin replaces the older "GBL 80s Admin Dashboard" plugin. Never run both.
if ( function_exists( 'gbl80s_add_role' ) ) {
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-error"><p><strong>GBL 80s Front-end Dashboard</strong> replaces the older &ldquo;GBL 80s Admin Dashboard&rdquo; plugin. Deactivate and delete the older plugin, then reactivate this one.</p></div>';
	} );
	return;
}

define( 'GBL_FD_VERSION', '2.3.0' );
define( 'GBL_FD_DIR', plugin_dir_path( __FILE__ ) );
define( 'GBL_FD_URL', plugin_dir_url( __FILE__ ) );

require_once GBL_FD_DIR . 'includes/core.php';
require_once GBL_FD_DIR . 'includes/data.php';
require_once GBL_FD_DIR . 'includes/guards.php';
require_once GBL_FD_DIR . 'includes/ajax.php';
require_once GBL_FD_DIR . 'includes/render.php';
require_once GBL_FD_DIR . 'includes/views.php';
require_once GBL_FD_DIR . 'includes/forms.php';
require_once GBL_FD_DIR . 'includes/club.php';

register_activation_hook( __FILE__, 'gbl_fd_install' );
