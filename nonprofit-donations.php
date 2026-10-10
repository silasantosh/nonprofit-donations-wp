<?php
/**
 * Plugin Name:       Nonprofit Donations
 * Plugin URI:        https://github.com/silasantosh/nonprofit-donations-wp
 * Description:       Free donation plugin for Indian nonprofits. Connect your own Razorpay account, record donors, and keep donation records. No WooCommerce needed.
 * Version:           0.7.4
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

define( 'NPD_VERSION', '0.7.4' );
define( 'NPD_FILE', __FILE__ );
define( 'NPD_DIR', plugin_dir_path( __FILE__ ) );
define( 'NPD_URL', plugin_dir_url( __FILE__ ) );

require_once NPD_DIR . 'class-npd-db.php';
require_once NPD_DIR . 'class-npd-settings.php';
require_once NPD_DIR . 'class-npd-razorpay.php';
require_once NPD_DIR . 'class-npd-rest.php';
require_once NPD_DIR . 'class-npd-block.php';
require_once NPD_DIR . 'class-npd-admin.php';
require_once NPD_DIR . 'class-npd-reg.php';
require_once NPD_DIR . 'class-npd-receipt.php';
require_once NPD_DIR . 'class-npd-cause.php';
require_once NPD_DIR . 'class-npd-alerts.php';
require_once NPD_DIR . 'class-npd-access.php';
require_once NPD_DIR . 'class-npd-reports.php';
require_once NPD_DIR . 'class-npd-bank.php';
require_once NPD_DIR . 'class-npd-mailbox.php';
require_once NPD_DIR . 'class-npd-flow.php';

register_activation_hook( __FILE__, array( 'NPD_DB', 'install' ) );

add_action(
	'plugins_loaded',
	function () {
		NPD_DB::maybe_upgrade();
		NPD_REST::init();
		NPD_Block::init();
		NPD_Reg::init();
		NPD_Receipt::init();
		NPD_Cause::init();
		// Not admin-only: the donor alert fires from the public REST call and the cron jobs run outside wp-admin.
		NPD_Alerts::init();
		NPD_Mailbox::init();
		NPD_Flow::init();
		if ( is_admin() ) {
			NPD_Admin::init();
			NPD_Access::init();
			NPD_Reports::init();
			NPD_Bank::init();
		}
	}
);
