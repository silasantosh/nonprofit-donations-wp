<?php
/**
 * Site-owner reports. Data stays on this site; nothing is sent anywhere.
 *
 * @package Nonprofit_Donations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports page under Donations.
 */
class NPD_Reports {

	/**
	 * Hook in.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_head', array( __CLASS__, 'css' ) );
	}

	/**
	 * Menu entry.
	 */
	public static function menu() {
		if ( current_user_can( 'manage_options' ) ) {
			add_submenu_page( 'npd', __( 'Reports', 'nonprofit-donations' ), __( 'Reports', 'nonprofit-donations' ), 'manage_options', 'npd-reports', array( __CLASS__, 'page' ) );
		} elseif ( current_user_can( 'npd_view_reports' ) ) {
			add_menu_page( __( 'Donation Reports', 'nonprofit-donations' ), __( 'Donation Reports', 'nonprofit-donations' ), 'npd_view_reports', 'npd-reports', array( __CLASS__, 'page' ), 'dashicons-chart-bar', 58 );
		}
	}

	/**
	 * Small mobile-friendly styles, only on our page.
	 */
	public static function css() {
		if ( ! isset( $_GET['page'] ) || 'npd-reports' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		echo '<style>.npd-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin:16px 0}.npd-tile{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:14px}.npd-tile b{display:block;font-size:22px}.npd-tile span{color:#50575e}.npd-scroll{overflow-x:auto;background:#fff;border:1px solid #dcdcde;border-radius:10px;margin:0 0 20px}.npd-scroll table{border:0;min-width:480px}.npd-rep h2{margin-top:24px}</style>';
	}

	/**
	 * Rupees from paise.
	 *
	 * @param int $paise Paise.
	 * @return string
	 */
	private static function rs( $paise ) {
		return 'Rs ' . number_format_i18n( (int) floor( ( (int) $paise ) / 100 ) );
	}

	/**
	 * Grouped totals.
	 *
	 * @param string $fmt  MySQL DATE_FORMAT string.
	 * @param string $from Earliest date (Y-m-d).
	 * @return array
	 */
	private static function grouped( $fmt, $from ) {
		global $wpdb;
		$t = NPD_DB::table( 'donations' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT DATE_FORMAT(paid_at, %s) AS k, COUNT(*) AS cnt, SUM(amount_paise) AS total FROM {$t} WHERE status = 'paid' AND paid_at >= %s GROUP BY k ORDER BY k DESC", $fmt, $from ) );
	}

	/**
	 * Cause label from a campaign key.
	 *
	 * @param string $key Campaign key.
	 * @return string
	 */
	private static function label( $key ) {
		if ( preg_match( '/^cause-(\d+)$/', $key, $m ) ) {
			$p = get_post( (int) $m[1] );
			return $p ? $p->post_title : $key;
		}
		return '' === $key ? __( 'General (no cause)', 'nonprofit-donations' ) : $key;
	}

	/**
	 * Render a small table.
	 *
	 * @param array $heads Column headings.
	 * @param array $rows  Rows of strings (already escaped).
	 */
	private static function table( $heads, $rows ) {
		echo '<div class="npd-scroll"><table class="widefat striped"><thead><tr>';
		foreach ( $heads as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="' . (int) count( $heads ) . '">' . esc_html__( 'Nothing yet.', 'nonprofit-donations' ) . '</td></tr>';
		}
		foreach ( $rows as $r ) {
			echo '<tr><td>' . implode( '</td><td>', $r ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table></div>';
	}

	/**
	 * The page.
	 */
	public static function page() {
		if ( ! current_user_can( 'npd_view_reports' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'nonprofit-donations' ) );
		}
		global $wpdb;
		$t     = NPD_DB::table( 'donations' );
		$n     = NPD_DB::table( 'donors' );
		$now   = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$today = gmdate( 'Y-m-d', $now );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$all    = $wpdb->get_row( "SELECT COUNT(*) AS cnt, COALESCE(SUM(amount_paise),0) AS total FROM {$t} WHERE status = 'paid'" );
		$pend   = $wpdb->get_row( "SELECT COUNT(*) AS cnt, COALESCE(SUM(amount_paise),0) AS total FROM {$t} WHERE status = 'pending'" );
		$donors = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT donor_id) FROM {$t} WHERE status = 'paid'" );
		$tod    = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS cnt, COALESCE(SUM(amount_paise),0) AS total FROM {$t} WHERE status = 'paid' AND DATE(paid_at) = %s", $today ) );
		$causes = $wpdb->get_results( "SELECT campaign, SUM(status='paid') AS paid_cnt, COALESCE(SUM(CASE WHEN status='paid' THEN amount_paise END),0) AS paid_total, SUM(status='pending') AS pend_cnt FROM {$t} GROUP BY campaign ORDER BY paid_total DESC" );
		$recent = $wpdb->get_results( "SELECT d.id, d.created_at, d.paid_at, d.amount_paise, d.status, d.mode, d.utr, d.campaign, n.name, n.email, n.phone, n.city, n.state FROM {$t} d LEFT JOIN {$n} n ON n.id = d.donor_id ORDER BY d.id DESC LIMIT 50" );
		// phpcs:enable
		echo '<div class="wrap npd-rep"><h1>' . esc_html__( 'Reports', 'nonprofit-donations' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Only donations marked Paid are counted as received. Everything here stays on your site.', 'nonprofit-donations' ) . '</p>';
		echo '<div class="npd-tiles">';
		$tiles = array(
			array( self::rs( $all->total ), sprintf( /* translators: %d: count */ __( 'Received (%d donations)', 'nonprofit-donations' ), (int) $all->cnt ) ),
			array( self::rs( $tod->total ), sprintf( /* translators: %d: count */ __( 'Today (%d)', 'nonprofit-donations' ), (int) $tod->cnt ) ),
			array( self::rs( $pend->total ), sprintf( /* translators: %d: count */ __( 'Waiting to be marked Paid (%d)', 'nonprofit-donations' ), (int) $pend->cnt ) ),
			array( (string) $donors, __( 'Donors who gave', 'nonprofit-donations' ) ),
		);
		foreach ( $tiles as $x ) {
			echo '<div class="npd-tile"><b>' . esc_html( $x[0] ) . '</b><span>' . esc_html( $x[1] ) . '</span></div>';
		}
		echo '</div>';

		$sets = array(
			array( __( 'Daily (last 30 days)', 'nonprofit-donations' ), '%Y-%m-%d', gmdate( 'Y-m-d', $now - 29 * DAY_IN_SECONDS ) ),
			array( __( 'Monthly (last 12 months)', 'nonprofit-donations' ), '%Y-%m', gmdate( 'Y-m-01', strtotime( '-11 months', $now ) ) ),
			array( __( 'Yearly', 'nonprofit-donations' ), '%Y', '2000-01-01' ),
		);
		foreach ( $sets as $set ) {
			echo '<h2>' . esc_html( $set[0] ) . '</h2>';
			$rows = array();
			foreach ( self::grouped( $set[1], $set[2] ) as $r ) {
				$rows[] = array( esc_html( $r->k ), esc_html( (string) $r->cnt ), esc_html( self::rs( $r->total ) ) );
			}
			self::table( array( __( 'Period', 'nonprofit-donations' ), __( 'Donations', 'nonprofit-donations' ), __( 'Amount', 'nonprofit-donations' ) ), $rows );
		}

		echo '<h2>' . esc_html__( 'By cause', 'nonprofit-donations' ) . '</h2>';
		$rows = array();
		foreach ( $causes as $r ) {
			$rows[] = array( esc_html( self::label( (string) $r->campaign ) ), esc_html( (string) (int) $r->paid_cnt ), esc_html( self::rs( $r->paid_total ) ), esc_html( (string) (int) $r->pend_cnt ) );
		}
		self::table( array( __( 'Cause', 'nonprofit-donations' ), __( 'Paid', 'nonprofit-donations' ), __( 'Received', 'nonprofit-donations' ), __( 'Waiting', 'nonprofit-donations' ) ), $rows );

		echo '<h2>' . esc_html__( 'Latest donations (50)', 'nonprofit-donations' ) . '</h2>';
		$rows = array();
		foreach ( $recent as $r ) {
			$rows[] = array(
				esc_html( 'DON-' . $r->id ),
				esc_html( substr( (string) $r->created_at, 0, 16 ) ),
				esc_html( (string) $r->name ),
				esc_html( (string) $r->email ),
				esc_html( (string) $r->phone ),
				esc_html( trim( $r->city . ', ' . $r->state, ', ' ) ),
				esc_html( self::rs( $r->amount_paise ) ),
				esc_html( $r->status . ( $r->utr ? ' (UTR ' . $r->utr . ')' : '' ) ),
				esc_html( self::label( (string) $r->campaign ) ),
			);
		}
		self::table( array( __( 'Ref', 'nonprofit-donations' ), __( 'Date', 'nonprofit-donations' ), __( 'Name', 'nonprofit-donations' ), __( 'Email', 'nonprofit-donations' ), __( 'Phone', 'nonprofit-donations' ), __( 'Location', 'nonprofit-donations' ), __( 'Amount', 'nonprofit-donations' ), __( 'Status', 'nonprofit-donations' ), __( 'Cause', 'nonprofit-donations' ) ), $rows );
		$csv   = wp_nonce_url( admin_url( 'admin-post.php?action=npd_export' ), 'npd_export' );
		$lines = array(
			get_bloginfo( 'name' ) . ' - ' . __( 'donation summary', 'nonprofit-donations' ) . ' (' . wp_date( 'j M Y' ) . ')',
			sprintf( /* translators: 1: amount 2: count */ __( 'Received: %1$s (%2$d donations)', 'nonprofit-donations' ), self::rs( $all->total ), (int) $all->cnt ),
			sprintf( /* translators: 1: amount 2: count */ __( 'Today: %1$s (%2$d)', 'nonprofit-donations' ), self::rs( $tod->total ), (int) $tod->cnt ),
			sprintf( /* translators: 1: amount 2: count */ __( 'Waiting to be marked Paid: %1$s (%2$d)', 'nonprofit-donations' ), self::rs( $pend->total ), (int) $pend->cnt ),
			sprintf( /* translators: %d: count */ __( 'Donors who gave: %d', 'nonprofit-donations' ), $donors ),
		);
		$sum = implode( "\n", $lines );
		echo '<h2>' . esc_html__( 'Share this report', 'nonprofit-donations' ) . '</h2><p class="npd-share">';
		echo '<a class="button button-primary" target="_blank" rel="noopener" href="' . esc_attr( 'https://wa.me/?text=' . rawurlencode( $sum ) ) . '">' . esc_html__( 'Share on WhatsApp', 'nonprofit-donations' ) . '</a> ';
		echo '<a class="button" href="' . esc_attr( 'mailto:?subject=' . rawurlencode( $lines[0] ) . '&body=' . rawurlencode( $sum ) ) . '">' . esc_html__( 'Email summary', 'nonprofit-donations' ) . '</a> ';
		echo '<button type="button" class="button" id="npd-share-csv" data-csv="' . esc_url( $csv ) . '" data-text="' . esc_attr( $sum ) . '" style="display:none">' . esc_html__( 'Share CSV file', 'nonprofit-donations' ) . '</button></p>';
		echo '<p class="description">' . esc_html__( 'The summary has totals only, no donor details. The CSV file has donor names, emails and phones, so share it only with people you trust.', 'nonprofit-donations' ) . '</p>';
		echo '<script>(function(){var b=document.getElementById("npd-share-csv");if(!b||!navigator.share||!window.File){return;}b.style.display="";b.addEventListener("click",function(){fetch(b.dataset.csv,{credentials:"same-origin"}).then(function(r){return r.blob();}).then(function(bl){var f=new File([bl],"donations-"+new Date().toISOString().slice(0,10)+".csv",{type:"text/csv"});var d={files:[f],title:"Donations",text:b.dataset.text};if(navigator.canShare&&!navigator.canShare(d)){d={title:"Donations",text:b.dataset.text};}return navigator.share(d);}).catch(function(){});});})();</script>';
		echo '<p><a class="button" href="' . esc_url( $csv ) . '">' . esc_html__( 'Download CSV', 'nonprofit-donations' ) . '</a></p>';
		echo '<p class="description">' . esc_html__( 'City and state are asked on every donation. Full address is asked only when someone requests an 80G receipt.', 'nonprofit-donations' ) . '</p>';
		if ( isset( $_GET['access'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$ac = sanitize_text_field( wp_unslash( $_GET['access'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-' . ( 'saved' === $ac ? 'success' : 'warning' ) . '"><p>' . esc_html( 'saved' === $ac ? __( 'Access saved.', 'nonprofit-donations' ) : sprintf( /* translators: %s: names */ __( 'Saved, but no user found for: %s', 'nonprofit-donations' ), rawurldecode( substr( $ac, 8 ) ) ) ) . '</p></div>';
		}
		NPD_Access::form();
		echo '</div>';
	}
}
