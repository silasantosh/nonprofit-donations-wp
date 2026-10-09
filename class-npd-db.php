<?php
/**
 * Database tables and queries.
 *
 * @package Nonprofit_Donations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Own tables, no custom post types.
 */
class NPD_DB {

	const DB_VERSION = '4';

	/**
	 * Table name helper.
	 *
	 * @param string $name donors|donations|events.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'npd_' . $name;
	}

	/**
	 * Create tables.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$donors  = self::table( 'donors' );
		$dons    = self::table( 'donations' );
		$events  = self::table( 'events' );

		dbDelta(
			"CREATE TABLE {$donors} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			phone varchar(40) NOT NULL DEFAULT '',
			pan_enc text NULL,
			address text NULL,
			consent tinyint(1) NOT NULL DEFAULT 0,
			consent_at datetime NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY email (email)
			) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$dons} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			donor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			amount_paise bigint(20) unsigned NOT NULL DEFAULT 0,
			currency varchar(8) NOT NULL DEFAULT 'INR',
			status varchar(20) NOT NULL DEFAULT 'created',
			mode varchar(20) NOT NULL DEFAULT 'upi',
			utr varchar(40) NOT NULL DEFAULT '',
			donor_claimed tinyint(1) NOT NULL DEFAULT 0,
			rz_order_id varchar(64) NOT NULL DEFAULT '',
			rz_payment_id varchar(64) NOT NULL DEFAULT '',
			campaign varchar(120) NOT NULL DEFAULT '',
			want_80g tinyint(1) NOT NULL DEFAULT 0,
			receipt_no varchar(40) NOT NULL DEFAULT '',
			pre_arn varchar(40) NOT NULL DEFAULT '',
			receipt_sent_at datetime NULL,
			fy varchar(9) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			paid_at datetime NULL,
			PRIMARY KEY  (id),
			KEY donor_id (donor_id),
			KEY rz_order_id (rz_order_id),
			KEY status (status),
			KEY utr (utr),
			KEY fy (fy)
			) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$events} (
			event_id varchar(80) NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (event_id)
			) {$charset};"
		);
		update_option( 'npd_db_version', self::DB_VERSION );
	}

	/**
	 * Install tables when missing (e.g. plugin loaded without an activation hook).
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'npd_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Indian financial year label (April to March) for a MySQL datetime.
	 *
	 * @param string $mysql_time Datetime in site time.
	 * @return string e.g. 2026-27.
	 */
	public static function fy_for( $mysql_time ) {
		$ts    = strtotime( $mysql_time );
		$year  = (int) gmdate( 'Y', $ts );
		$month = (int) gmdate( 'n', $ts );
		$start = ( $month >= 4 ) ? $year : $year - 1;
		return sprintf( '%d-%02d', $start, ( $start + 1 ) % 100 );
	}

	/**
	 * Insert a donor.
	 *
	 * @param array $d Donor fields.
	 * @return int Donor id.
	 */
	public static function add_donor( $d ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			self::table( 'donors' ),
			array(
				'name'       => $d['name'],
				'email'      => $d['email'],
				'phone'      => $d['phone'],
				'pan_enc'    => isset( $d['pan_enc'] ) ? $d['pan_enc'] : null,
				'address'    => isset( $d['address'] ) ? $d['address'] : '',
				'consent'    => 1,
				'consent_at' => $now,
				'created_at' => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Insert a donation in "created" state.
	 *
	 * @param array $d Donation fields.
	 * @return int Donation id.
	 */
	public static function add_donation( $d ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			self::table( 'donations' ),
			array(
				'donor_id'     => $d['donor_id'],
				'amount_paise' => $d['amount_paise'],
				'mode'         => $d['mode'],
				'status'       => isset( $d['status'] ) ? $d['status'] : 'created',
				'campaign'     => $d['campaign'],
				'want_80g'     => $d['want_80g'] ? 1 : 0,
				'fy'           => self::fy_for( $now ),
				'created_at'   => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Fetch one donation row.
	 *
	 * @param int $id Donation id.
	 * @return object|null
	 */
	public static function get_donation( $id ) {
		global $wpdb;
		$t = self::table( 'donations' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) );
	}

	/**
	 * Find a donation by Razorpay order id.
	 *
	 * @param string $order_id Order id.
	 * @return object|null
	 */
	public static function get_by_order( $order_id ) {
		global $wpdb;
		$t = self::table( 'donations' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE rz_order_id = %s", $order_id ) );
	}

	/**
	 * Set the Razorpay order id on a donation.
	 *
	 * @param int    $id       Donation id.
	 * @param string $order_id Order id.
	 */
	public static function set_order( $id, $order_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( self::table( 'donations' ), array( 'rz_order_id' => $order_id ), array( 'id' => $id ) );
	}

	/**
	 * Mark a donation paid. Safe to call twice: only the first call changes state.
	 *
	 * @param int    $id         Donation id.
	 * @param string $payment_id Razorpay payment id (empty).
	 * @return bool True when this call changed the state.
	 */
	public static function mark_paid( $id, $payment_id = '' ) {
		global $wpdb;
		$t = self::table( 'donations' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$changed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$t} SET status = 'paid', rz_payment_id = %s, paid_at = %s WHERE id = %d AND status <> 'paid'",
				$payment_id,
				current_time( 'mysql' ),
				$id
			)
		);
		if ( $changed ) {
			do_action( 'npd_donation_paid', $id );
		}
		return (bool) $changed;
	}

	/**
	 * Donor says they paid, optionally with the UPI reference. Stays pending until the NGO confirms.
	 *
	 * @param int    $id  Donation id.
	 * @param string $utr Reference number, may be empty.
	 * @return bool
	 */
	public static function submit_utr( $id, $utr = '' ) {
		global $wpdb;
		$t = self::table( 'donations' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET donor_claimed = 1, utr = %s WHERE id = %d AND status = 'pending' AND mode = 'upi'", $utr, $id ) );
	}

	/**
	 * Is this reference already used on another donation?
	 *
	 * @param string $utr Reference number.
	 * @param int    $id  Donation to ignore.
	 * @return bool
	 */
	public static function utr_taken( $utr, $id ) {
		global $wpdb;
		$t = self::table( 'donations' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE utr = %s AND id <> %d AND status <> 'failed' AND utr <> '' LIMIT 1", $utr, $id ) );
	}

	/**
	 * Admin verifies a pending UPI donation as received.
	 *
	 * @param int $id Donation id.
	 * @return bool
	 */
	public static function verify_upi( $id ) {
		global $wpdb;
		$t = self::table( 'donations' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ok = (bool) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 'paid', paid_at = %s WHERE id = %d AND status = 'pending' AND mode = 'upi'", current_time( 'mysql' ), $id ) );
		if ( $ok ) {
			do_action( 'npd_donation_paid', $id );
		}
		return $ok;
	}

	/**
	 * Admin rejects a pending UPI donation (not found on the bank statement).
	 *
	 * @param int $id Donation id.
	 * @return bool
	 */
	public static function reject_upi( $id ) {
		global $wpdb;
		$t = self::table( 'donations' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 'failed' WHERE id = %d AND status = 'pending' AND mode = 'upi'", $id ) );
	}

	/**
	 * Mark a donation failed unless it is already paid.
	 *
	 * @param int $id Donation id.
	 */
	public static function mark_failed( $id ) {
		global $wpdb;
		$t = self::table( 'donations' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 'failed' WHERE id = %d AND status = 'created'", $id ) );
	}

	/**
	 * Remember a webhook event id. Returns false when seen before.
	 *
	 * @param string $event_id Razorpay event id.
	 * @return bool
	 */
	public static function remember_event( $event_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . self::table( 'events' ) . ' (event_id, created_at) VALUES (%s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$event_id,
				current_time( 'mysql' )
			)
		);
		return (bool) $ok;
	}

	/**
	 * List donations joined with donors.
	 *
	 * @param array $args status, fy, campaign, limit, offset.
	 * @return array
	 */
	public static function list_donations( $args = array() ) {
		global $wpdb;
		$d     = self::table( 'donations' );
		$n     = self::table( 'donors' );
		$where = array( '1=1' );
		$vals  = array();
		if ( ! empty( $args['status'] ) ) {
			$where[] = 'd.status = %s';
			$vals[]  = $args['status'];
		}
		if ( ! empty( $args['fy'] ) ) {
			$where[] = 'd.fy = %s';
			$vals[]  = $args['fy'];
		}
		if ( ! empty( $args['campaign'] ) ) {
			$where[] = 'd.campaign = %s';
			$vals[]  = $args['campaign'];
		}
		$limit  = isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : 50;
		$offset = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;
		$sql    = "SELECT d.*, n.name AS donor_name, n.email AS donor_email, n.phone AS donor_phone FROM {$d} d LEFT JOIN {$n} n ON n.id = d.donor_id WHERE " . implode( ' AND ', $where ) . ' ORDER BY d.id DESC LIMIT %d OFFSET %d';
		$vals[] = $limit;
		$vals[] = $offset;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $wpdb->prepare( $sql, $vals ) );
	}

	/**
	 * Totals of paid donations by financial year.
	 *
	 * @return array
	 */
	public static function totals_by_fy() {
		global $wpdb;
		$d = self::table( 'donations' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( "SELECT fy, COUNT(*) AS cnt, SUM(amount_paise) AS total FROM {$d} WHERE status = 'paid' GROUP BY fy ORDER BY fy DESC" );
	}

	/**
	 * Donor summary: paid total and count per donor.
	 *
	 * @param int $limit Row cap.
	 * @return array
	 */
	public static function donor_summary( $limit = 200 ) {
		global $wpdb;
		$d = self::table( 'donations' );
		$n = self::table( 'donors' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT n.id, n.name, n.email, n.phone, n.created_at, COUNT(d.id) AS gifts, COALESCE(SUM(d.amount_paise),0) AS total FROM {$n} n LEFT JOIN {$d} d ON d.donor_id = n.id AND d.status = 'paid' GROUP BY n.id ORDER BY total DESC LIMIT %d",
				$limit
			)
		);
	}
}
