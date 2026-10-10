<?php
/**
 * Owner alerts for UPI donations, a reminder for old pending ones, and the plain thank-you email.
 *
 * @package nonprofit-donations
 */

defined( 'ABSPATH' ) || exit;

/**
 * Alerts and acknowledgements.
 */
class NPD_Alerts {

	const STALE_DAYS = 3;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'npd_donor_claimed', array( __CLASS__, 'alert_owner' ) );
		add_action( 'npd_donation_paid', array( __CLASS__, 'thanks' ), 20 );
		add_action( 'npd_stale_daily', array( __CLASS__, 'digest' ) );
		if ( ! wp_next_scheduled( 'npd_stale_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'npd_stale_daily' );
		}
	}

	/**
	 * Where owner notices go.
	 *
	 * @return string
	 */
	private static function owner_mail() {
		$m = NPD_Settings::get( 'notify_email' );
		return is_email( $m ) ? $m : get_option( 'admin_email' );
	}

	/**
	 * One donation with donor details.
	 *
	 * @param int $id Donation id.
	 * @return object|null
	 */
	private static function load( $id ) {
		global $wpdb;
		$d = NPD_DB::table( 'donations' );
		$r = NPD_DB::table( 'donors' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT d.*, r.name AS donor_name, r.email AS donor_email, r.city AS donor_city, r.state AS donor_state FROM {$d} d LEFT JOIN {$r} r ON r.id = d.donor_id WHERE d.id = %d", $id ) );
	}

	/**
	 * Amount text.
	 *
	 * @param int $paise Paise.
	 * @return string
	 */
	private static function rs( $paise ) {
		return 'Rs ' . number_format_i18n( $paise / 100, 2 );
	}

	/**
	 * Link that opens Verify UPI on this one donation.
	 *
	 * @param int $id Donation id.
	 * @return string
	 */
	public static function confirm_url( $id ) {
		return add_query_arg( array( 'page' => 'npd-verify', 'q' => 'DON-' . (int) $id ), admin_url( 'admin.php' ) );
	}

	/**
	 * The donor tapped "I have paid": tell the owner right away.
	 *
	 * @param int $id Donation id.
	 */
	public static function alert_owner( $id ) {
		$row = self::load( (int) $id );
		if ( ! $row || 'pending' !== $row->status ) {
			return;
		}
		$org  = NPD_Settings::get( 'org_name' );
		$body = sprintf(
			/* translators: 1: ref 2: amount 3: name 4: UPI ref 5: link */
			__( "A donor says they have paid.\n\nRef: %1\$s\nAmount: %2\$s\nDonor: %3\$s\nUPI reference given: %4\$s\n\nCheck your bank or Vyapar app for a credit of this amount. The payment note carries %1\$s.\n\nIf you see it, tap here, then press Confirm:\n%5\$s\n\nIf you do NOT see it, tap here, then press Not received:\n%6\$s\n\nOpening a link never confirms by itself. Nothing is marked as received until you confirm. A donor message alone never counts.", 'nonprofit-donations' ),
			'DON-' . $row->id,
			self::rs( (int) $row->amount_paise ),
			$row->donor_name . ( $row->donor_city ? ', ' . $row->donor_city : '' ),
			'' !== (string) $row->utr ? $row->utr : '-',
			NPD_Flow::act_url( (int) $row->id, 'c' ),
			NPD_Flow::act_url( (int) $row->id, 'r' )
		);
		/* translators: 1: ref 2: amount 3: org */
		wp_mail( self::owner_mail(), sprintf( __( 'To confirm: DON-%1$d, %2$s (%3$s)', 'nonprofit-donations' ), $row->id, self::rs( (int) $row->amount_paise ), $org ), $body );
	}

	/**
	 * Daily: remind about donors who said they paid more than STALE_DAYS ago and are still waiting.
	 */
	public static function digest() {
		$rows  = NPD_DB::list_donations( array( 'status' => 'pending', 'limit' => 500 ) );
		$limit = (int) current_time( 'timestamp' ) - self::STALE_DAYS * DAY_IN_SECONDS; // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$lines = array();
		$sum   = 0;
		foreach ( $rows as $r ) {
			if ( 'upi' === $r->mode && $r->donor_claimed && strtotime( $r->created_at ) < $limit ) { // phpcs:ignore WordPress.DateTime.RestrictedFunctions
				$lines[] = 'DON-' . $r->id . '  ' . self::rs( (int) $r->amount_paise ) . '  ' . $r->donor_name . '  (' . substr( $r->created_at, 0, 10 ) . ')';
				$sum    += (int) $r->amount_paise;
			}
		}
		if ( ! $lines ) {
			return;
		}
		$body = sprintf(
			/* translators: 1: count 2: days 3: list 4: link */
			__( "%1\$d donor(s) said they paid more than %2\$d days ago and are still not confirmed:\n\n%3\$s\n\nCheck the bank for each. Confirm the ones you find, and press Not received on the ones you do not:\n%4\$s", 'nonprofit-donations' ),
			count( $lines ),
			self::STALE_DAYS,
			implode( "\n", $lines ),
			admin_url( 'admin.php?page=npd-verify&claimed=1' )
		);
		/* translators: %d: count */
		$o2 = NPD_Flow::opts();
		$to = is_email( $o2['email2'] ) ? array( self::owner_mail(), $o2['email2'] ) : self::owner_mail();
		wp_mail( $to, sprintf( __( 'Still waiting: %d donation(s) not confirmed', 'nonprofit-donations' ), count( $lines ) ), $body );
	}

	/**
	 * How many pending donations are waiting after "I have paid" (menu badge).
	 *
	 * @return int
	 */
	public static function claimed_count() {
		global $wpdb;
		$t = NPD_DB::table( 'donations' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status = 'pending' AND mode = 'upi' AND donor_claimed = 1" );
	}

	/**
	 * After the owner confirms: a plain thank-you with no tax claims. The 80G receipt (when the
	 * registration is verified and the donor asked for it) is sent by NPD_Receipt first and sets receipt_sent_at.
	 *
	 * @param int $id Donation id.
	 */
	public static function thanks( $id ) {
		global $wpdb;
		$row = self::load( (int) $id );
		if ( ! $row || 'paid' !== $row->status || ! empty( $row->receipt_sent_at ) || ! is_email( $row->donor_email ) ) {
			return;
		}
		if ( NPD_Flow::will_hold( $row ) ) {
			return; // The 80G receipt (sent after the hold) is the thank-you.
		}
		$org  = NPD_Settings::get( 'org_name' );
		$body = sprintf(
			/* translators: 1: name 2: org 3: amount 4: date 5: ref 6: UPI ref */
			__( "Dear %1\$s,\n\nThank you. %2\$s has received your gift of %3\$s on %4\$s.\n\nRef: %5\$s%6\$s\n\nThis email only confirms that your gift reached us. It is not a tax certificate.\n\nWith thanks,\n%2\$s", 'nonprofit-donations' ),
			$row->donor_name,
			$org,
			self::rs( (int) $row->amount_paise ),
			mysql2date( 'j M Y', $row->paid_at ),
			'DON-' . $row->id,
			'' !== (string) $row->utr ? "\nUPI reference: " . $row->utr : ''
		);
		/* translators: %s: org */
		$ok = wp_mail( $row->donor_email, sprintf( __( 'Thank you from %s', 'nonprofit-donations' ), $org ), $body );
		if ( $ok ) {
			$t = NPD_DB::table( 'donations' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET receipt_sent_at = %s WHERE id = %d", current_time( 'mysql' ), $row->id ) );
		}
	}
}
