<?php
/**
 * Plugin Name:       Nonprofit Donations
 * Plugin URI:        https://github.com/silasantosh/nonprofit-donations
 * Description:       Free donation plugin for Indian nonprofits. Connect your own Razorpay account, record donors, and keep donation records. No WooCommerce needed.
 * Version:           0.1.0
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            Impact Connect
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       nonprofit-donations
 *
 * @package Nonprofit_Donations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NPD_VERSION', '0.1.0' );
define( 'NPD_FILE', __FILE__ );
define( 'NPD_DIR', plugin_dir_path( __FILE__ ) );
define( 'NPD_URL', plugin_dir_url( __FILE__ ) );

require_once NPD_DIR . 'class-npd-db.php';
require_once NPD_DIR . 'class-npd-settings.php';
require_once NPD_DIR . 'class-npd-razorpay.php';
require_once NPD_DIR . 'class-npd-rest.php';
require_once NPD_DIR . 'class-npd-block.php';
require_once NPD_DIR . 'class-npd-admin.php';

register_activation_hook( __FILE__, array( 'NPD_DB', 'install' ) );

add_action(
	'plugins_loaded',
	function () {
		NPD_DB::maybe_upgrade();
		NPD_REST::init();
		NPD_Block::init();
		if ( is_admin() ) {
			NPD_Admin::init();
		}
	}
);
