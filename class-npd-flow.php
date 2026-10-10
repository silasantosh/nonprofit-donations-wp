<?php
/**
 * Confirmation flow: reverse a confirmation, 80G hold, donor status page and emails,
 * signed confirm links, unknown credits, and bank-evidence auto-confirm (off by default).
 *
 * @package Nonprofit_Donations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Flow helpers.
 */
class NPD_Flow {

	const OPT          = 'npd_flow';
	const LEARN_NEEDED = 20;

	/** @var string Who is confirming right now: manual, onetap or auto. */
	public static $src = 'manual';

	/**
	 * Hooks. Runs on public requests too: status pages and cron live outside wp-admin.
	 */
	public static function init() {
		add_action( 'npd_donation_paid', array( __CLASS__, 'on_paid' ), 5 );
		add_action( 'npd_issue_hold', array( __CLASS__, 'issue_hold' ) );
		add_action( 'npd_donor_claimed', array( __CLASS__, 'claimed_mail' ), 15 );
		add_action( 'npd_stale_daily', array( __CLASS__, 'nudges' ), 30 );
		add_action( 'template_redirect', array( __CLASS__, 'public_pages' ), 1 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 22 );
		add_action( 'admin_post_npd_reverse', array( __CLASS__, 'do_reverse' ) );
		add_action( 'admin_post_npd_flow_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_npd_credit', array( __CLASS__, 'credit_action' ) );
		add_action( 'admin_post_npd_issue80g', array( __CLASS__, 'issue_now' ) );
		add_action( 'admin_notices', array( __CLASS__, 'mail_notice' ) );
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public static function opts() {
		return wp_parse_args(
			get_option( self::OPT, array() ),
			array(
				'auto'     => 0,
				'cap'      => 10000,
				'auto_80g' => 0,
				'onetap'   => 0,
				'email2'   => '',
				'learned'  => 0,
			)
		);
	}

	/**
	 * Save one setting group.
	 *
	 * @param array $new Values.
	 */
	private static function put( $new ) {
		update_option( self::OPT, array_merge( self::opts(), $new ), false );
	}

	/**
	 * Write an audit line.
	 *
	 * @param int    $id     Donation id.
	 * @param string $action Short action name.
	 * @param string $actor  Who.
	 * @param string $note   Detail.
	 */
	public static function audit( $id, $action, $actor, $note = '' ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert( NPD_DB::table( 'audit' ), array( 'donation_id' => (int) $id, 'action' => substr( $action, 0, 24 ), 'actor' => substr( $actor, 0, 80 ), 'note' => $note, 'created_at' => current_time( 'mysql' ) ) );
	}

	/**
	 * Has this audit action been written for the donation?
	 *
	 * @param int    $id     Donation id.
	 * @param string $action Action.
	 * @return bool
	 */
	public static function has_audit( $id, $action ) {
		global $wpdb;
		$t = NPD_DB::table( 'audit' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE donation_id = %d AND action = %s LIMIT 1", $id, $action ) );
	}

	/**
	 * Current actor label for the audit log.
	 *
	 * @return string
	 */
	private static function actor() {
		if ( 'auto' === self::$src ) {
			return 'auto';
		}
		$u = wp_get_current_user();
		return $u && $u->ID ? $u->user_login : 'link';
	}

	/**
	 * Donation with donor. PAN is not loaded here.
	 *
	 * @param int $id Donation id.
	 * @return object|null
	 */
	public static function load( $id ) {
		global $wpdb;
		$d = NPD_DB::table( 'donations' );
		$r = NPD_DB::table( 'donors' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT d.*, r.name AS donor_name, r.email AS donor_email, r.pan_enc AS donor_pan FROM {$d} d LEFT JOIN {$r} r ON r.id = d.donor_id WHERE d.id = %d", $id ) );
		if ( $row && '' === (string) $row->status_token ) {
			$row->status_token = wp_generate_password( 16, false ); // Older rows get a token the first time one is needed.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $d, array( 'status_token' => $row->status_token ), array( 'id' => (int) $id ) );
		}
		return $row;
	}

	/**
	 * Will an 80G receipt follow after the hold (so the plain thank-you should wait)?
	 *
	 * @param object $row Donation row.
	 * @return bool
	 */
	public static function will_hold( $row ) {
		return $row->want_80g && NPD_Receipt::can_issue() && '' !== NPD_Settings::decrypt( (string) $row->donor_pan ) && (bool) wp_next_scheduled( 'npd_issue_hold', array( (int) $row->id ) );
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
	 * Donor status link.
	 *
	 * @param object $row Donation with status_token.
	 * @return string
	 */
	public static function status_url( $row ) {
		return add_query_arg( 'npd_status', (int) $row->id . '-' . $row->status_token, home_url( '/' ) );
	}

	/**
	 * Signed one-use action link for the owner.
	 *
	 * @param int    $id     Donation id.
	 * @param string $action c (confirm) or r (not received).
	 * @return string
	 */
	public static function act_url( $id, $action ) {
		$exp = time() + 3 * DAY_IN_SECONDS;
		$sig = substr( hash_hmac( 'sha256', $id . '|' . $action . '|' . $exp, wp_salt( 'auth' ) ), 0, 32 );
		return add_query_arg( 'npd_act', $id . '.' . $action . '.' . $exp . '.' . $sig, home_url( '/' ) );
	}

	/**
	 * Is the signed token valid and unused?
	 *
	 * @param string $tok Token.
	 * @return array|null id, action
	 */
	private static function check_token( $tok ) {
		if ( ! preg_match( '/^(\d+)\.([cr])\.(\d+)\.([a-f0-9]{32})$/', (string) $tok, $m ) ) {
			return null;
		}
		if ( (int) $m[3] < time() ) {
			return null;
		}
		$want = substr( hash_hmac( 'sha256', $m[1] . '|' . $m[2] . '|' . $m[3], wp_salt( 'auth' ) ), 0, 32 );
		if ( ! hash_equals( $want, $m[4] ) || self::has_audit( (int) $m[1], 'tok:' . substr( $m[4], 0, 16 ) ) ) {
			return null;
		}
		return array( 'id' => (int) $m[1], 'action' => $m[2], 'sig' => $m[4] );
	}

	/**
	 * Called the moment a donation becomes paid. Sets the hold for the 80G receipt.
	 *
	 * @param int $id Donation id.
	 */
	public static function on_paid( $id ) {
		global $wpdb;
		$o   = self::opts();
		$row = self::load( (int) $id );
		if ( ! $row ) {
			return;
		}
		$src  = self::$src;
		$hold = 'auto' === $src ? DAY_IN_SECONDS : 10 * MINUTE_IN_SECONDS;
		$t    = NPD_DB::table( 'donations' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET confirm_src = %s, hold_until = %s WHERE id = %d", $src, gmdate( 'Y-m-d H:i:s', time() + $hold + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ), $id ) );
		self::audit( $id, 'confirmed', self::actor(), $src . ( '' !== (string) $row->bank_match ? ', bank match' : '' ) );
		if ( 'auto' !== $src && '' !== (string) $row->bank_match && '' === (string) $row->review_flag ) {
			self::put( array( 'learned' => (int) $o['learned'] + 1 ) ); // The owner agreed with a bank match.
		}
		if ( 'auto' === $src && empty( $o['auto_80g'] ) ) {
			return; // Auto confirm never issues 80G unless that switch is on. The receipt waits in the list.
		}
		wp_schedule_single_event( time() + $hold, 'npd_issue_hold', array( (int) $id ) );
	}

	/**
	 * Hold is over: issue the 80G receipt if still paid.
	 *
	 * @param int $id Donation id.
	 */
	public static function issue_hold( $id ) {
		$row = self::load( (int) $id );
		if ( $row && 'paid' === $row->status ) {
			NPD_Receipt::issue( (int) $id );
		}
	}

	/**
	 * Reverse a confirmation. Keeps the record, logs who, when and why.
	 *
	 * @param int    $id     Donation id.
	 * @param string $reason Reason.
	 * @return bool
	 */
	public static function reverse( $id, $reason ) {
		global $wpdb;
		$row = self::load( (int) $id );
		if ( ! $row || 'paid' !== $row->status || 'upi' !== $row->mode ) {
			return false;
		}
		$t  = NPD_DB::table( 'donations' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ok = (bool) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 'cancelled', hold_until = NULL WHERE id = %d AND status = 'paid'", $id ) );
		if ( ! $ok ) {
			return false;
		}
		wp_clear_scheduled_hook( 'npd_issue_hold', array( (int) $id ) );
		$sent = ! empty( $row->receipt_no ) ? ' A receipt number was already used (' . $row->receipt_no . ( $row->pre_arn ? ', Pre-ARN ' . $row->pre_arn : '' ) . '): tell your CA.' : '';
		self::audit( $id, 'reversed', self::actor(), $reason . $sent );
		if ( 'auto' === $row->confirm_src ) {
			self::put( array( 'auto' => 0 ) ); // A wrong automatic confirm switches automatic confirming off.
			wp_mail( self::owner(), __( 'Automatic confirming switched off', 'nonprofit-donations' ), sprintf( __( "DON-%d was confirmed automatically and then reversed, so automatic confirming is now OFF. Switch it on again on the Confirmation page when you are ready.", 'nonprofit-donations' ), $id ) );
		}
		if ( is_email( $row->donor_email ) ) {
			$org = NPD_Settings::get( 'org_name' );
			wp_mail(
				$row->donor_email,
				/* translators: %s: org */
				sprintf( __( 'A correction from %s', 'nonprofit-donations' ), $org ),
				sprintf(
					/* translators: 1: name 2: ref 3: link 4: org */
					__( "Dear %1\$s,\n\nWe sent you a thank-you for gift %2\$s, but we could not match it with our bank after all, so we have taken it back for now. If you did pay, please reply with your UPI reference and we will check again. You can see the status here:\n%3\$s\n\n%4\$s", 'nonprofit-donations' ),
					$row->donor_name,
					'DON-' . $row->id,
					self::status_url( $row ),
					$org
				)
			);
		}
		return true;
	}

	/**
	 * Owner address.
	 *
	 * @return string
	 */
	private static function owner() {
		$m = NPD_Settings::get( 'notify_email' );
		return is_email( $m ) ? $m : get_option( 'admin_email' );
	}

	/**
	 * Admin: reverse button.
	 */
	public static function do_reverse() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'nonprofit-donations' ), 403 );
		}
		check_admin_referer( 'npd_reverse' );
		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$ok     = '' !== trim( $reason ) && self::reverse( $id, $reason );
		wp_safe_redirect( admin_url( 'admin.php?page=npd-verify&rev=' . ( $ok ? 'ok' : 'need-reason' ) ) );
		exit;
	}

	/**
	 * Admin: issue a waiting 80G receipt now.
	 */
	public static function issue_now() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'nonprofit-donations' ), 403 );
		}
		check_admin_referer( 'npd_issue80g' );
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$ok = $id ? NPD_Receipt::issue( $id, true ) : false;
		self::audit( $id, 'issued80g', self::actor(), $ok ? 'sent' : 'not sent' );
		wp_safe_redirect( admin_url( 'admin.php?page=npd-verify&rev=' . ( $ok ? 'issued' : 'not-issued' ) ) );
		exit;
	}

	/**
	 * Verify page sections below the table: recently confirmed with Reverse, and receipts waiting.
	 */
	public static function verify_extras() {
		global $wpdb;
		$d    = NPD_DB::table( 'donations' );
		$r    = NPD_DB::table( 'donors' );
		$from = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$recent = $wpdb->get_results( $wpdb->prepare( "SELECT d.id, d.amount_paise, d.paid_at, d.hold_until, d.confirm_src, d.want_80g, d.receipt_no, d.receipt_sent_at, r.name FROM {$d} d LEFT JOIN {$r} r ON r.id = d.donor_id WHERE d.status = 'paid' AND d.mode = 'upi' AND d.paid_at >= %s ORDER BY d.paid_at DESC LIMIT 30", $from ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wait = $wpdb->get_results( "SELECT d.id, d.amount_paise, d.paid_at, r.name, r.pan_enc FROM {$d} d LEFT JOIN {$r} r ON r.id = d.donor_id WHERE d.status = 'paid' AND d.want_80g = 1 AND d.receipt_no = '' ORDER BY d.paid_at DESC LIMIT 30" );
		$can  = NPD_Receipt::can_issue();
		$act  = admin_url( 'admin-post.php' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$rev = isset( $_GET['rev'] ) ? sanitize_key( wp_unslash( $_GET['rev'] ) ) : '';
		// phpcs:enable
		$msg = array( 'ok' => __( 'Confirmation reversed. The donor was told.', 'nonprofit-donations' ), 'need-reason' => __( 'Please give a reason to reverse.', 'nonprofit-donations' ), 'issued' => __( '80G receipt sent.', 'nonprofit-donations' ), 'not-issued' => __( '80G receipt could not be sent (needs a verified 80G registration, PAN and email).', 'nonprofit-donations' ) );
		?>
		<?php if ( isset( $msg[ $rev ] ) ) : ?><div class="notice notice-info"><p><?php echo esc_html( $msg[ $rev ] ); ?></p></div><?php endif; ?>
		<h2 style="margin-top:2em"><?php echo esc_html__( 'Confirmed in the last 7 days', 'nonprofit-donations' ); ?></h2>
		<p class="description"><?php echo esc_html__( 'Tapped Confirm by mistake? Reverse it here. The donor gets a plain correction. An 80G receipt waits 10 minutes after a manual confirm and 24 hours after an automatic one, so a reversal in that time stops it.', 'nonprofit-donations' ); ?></p>
		<table class="widefat striped"><thead><tr><th>Ref</th><th><?php echo esc_html__( 'Donor', 'nonprofit-donations' ); ?></th><th><?php echo esc_html__( 'Amount', 'nonprofit-donations' ); ?></th><th><?php echo esc_html__( 'Confirmed', 'nonprofit-donations' ); ?></th><th></th></tr></thead><tbody>
		<?php if ( ! $recent ) : ?><tr><td colspan="5"><?php echo esc_html__( 'Nothing confirmed in the last 7 days.', 'nonprofit-donations' ); ?></td></tr><?php endif; ?>
		<?php foreach ( $recent as $x ) : ?>
			<tr>
				<td><strong>DON-<?php echo esc_html( $x->id ); ?></strong></td>
				<td><?php echo esc_html( $x->name ); ?></td>
				<td><?php echo esc_html( self::rs( (int) $x->amount_paise ) ); ?></td>
				<td><?php echo esc_html( $x->paid_at ); ?> <small>(<?php echo esc_html( $x->confirm_src ? $x->confirm_src : 'manual' ); ?>)</small>
					<?php if ( $x->want_80g && '' === (string) $x->receipt_no && $x->hold_until && strtotime( $x->hold_until ) > (int) current_time( 'timestamp' ) ) : // phpcs:ignore WordPress.DateTime ?><br><small><?php echo esc_html( sprintf( /* translators: %s: time */ __( '80G receipt waits until %s', 'nonprofit-donations' ), $x->hold_until ) ); ?></small><?php endif; ?></td>
				<td>
					<form method="post" action="<?php echo esc_url( $act ); ?>" style="display:flex;gap:6px;flex-wrap:wrap">
						<input type="hidden" name="action" value="npd_reverse"><input type="hidden" name="id" value="<?php echo esc_attr( $x->id ); ?>">
						<?php wp_nonce_field( 'npd_reverse' ); ?>
						<input type="text" name="reason" placeholder="<?php echo esc_attr__( 'Reason (required)', 'nonprofit-donations' ); ?>" style="min-width:160px">
						<button class="button"><?php echo esc_html__( 'Reverse', 'nonprofit-donations' ); ?></button>
					</form>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody></table>
		<?php if ( $wait ) : ?>
			<h2 style="margin-top:2em"><?php echo esc_html__( '80G receipts waiting', 'nonprofit-donations' ); ?></h2>
			<p class="description"><?php echo esc_html__( 'Confirmed donors who asked for 80G and have no receipt yet. Automatic confirms never send 80G by themselves. A receipt needs a verified 80G registration and the donor\'s PAN.', 'nonprofit-donations' ); ?></p>
			<table class="widefat striped"><tbody>
			<?php foreach ( $wait as $x ) : ?>
				<tr><td><strong>DON-<?php echo esc_html( $x->id ); ?></strong></td><td><?php echo esc_html( $x->name ); ?></td><td><?php echo esc_html( self::rs( (int) $x->amount_paise ) ); ?></td>
				<td><?php echo $x->pan_enc ? esc_html__( 'PAN given', 'nonprofit-donations' ) : esc_html__( 'No PAN', 'nonprofit-donations' ); ?></td>
				<td><?php if ( $can && $x->pan_enc ) : ?><form method="post" action="<?php echo esc_url( $act ); ?>"><input type="hidden" name="action" value="npd_issue80g"><input type="hidden" name="id" value="<?php echo esc_attr( $x->id ); ?>"><?php wp_nonce_field( 'npd_issue80g' ); ?><button class="button"><?php echo esc_html__( 'Send 80G receipt', 'nonprofit-donations' ); ?></button></form><?php else : ?><small><?php echo esc_html__( 'Not possible yet (registration or PAN missing)', 'nonprofit-donations' ); ?></small><?php endif; ?></td></tr>
			<?php endforeach; ?>
			</tbody></table>
		<?php endif; ?>
		<?php
	}

	/**
	 * The donor said they paid: send a "we got your note" mail with the status link.
	 *
	 * @param int $id Donation id.
	 */
	public static function claimed_mail( $id ) {
		$row = self::load( (int) $id );
		if ( ! $row || ! is_email( $row->donor_email ) || self::has_audit( $id, 'claimmail' ) ) {
			return;
		}
		$org = NPD_Settings::get( 'org_name' );
		wp_mail(
			$row->donor_email,
			/* translators: %s: org */
			sprintf( __( 'We got your note - %s', 'nonprofit-donations' ), $org ),
			sprintf(
				/* translators: 1: name 2: amount 3: ref 4: link 5: org */
				__( "Dear %1\$s,\n\nThank you. We noted your gift of %2\$s (%3\$s). We confirm it after we check our bank statement. You will get a thank-you mail when that is done.\n\nYou can check the status any time here, no login needed:\n%4\$s\n\n%5\$s", 'nonprofit-donations' ),
				$row->donor_name,
				self::rs( (int) $row->amount_paise ),
				'DON-' . $row->id,
				self::status_url( $row ),
				$org
			)
		);
		self::audit( $id, 'claimmail', 'system' );
	}

	/**
	 * Daily: gentle donor nudges at 3 and 7 days for claimed donations still waiting.
	 */
	public static function nudges() {
		$rows = NPD_DB::list_donations( array( 'status' => 'pending', 'limit' => 500 ) );
		$now  = (int) current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		foreach ( $rows as $r ) {
			if ( 'upi' !== $r->mode || ! $r->donor_claimed || ! is_email( $r->donor_email ) ) {
				continue;
			}
			$age = $now - (int) strtotime( $r->created_at ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions
			$tag = $age > 7 * DAY_IN_SECONDS ? 'nudge7' : ( $age > 3 * DAY_IN_SECONDS ? 'nudge3' : '' );
			if ( '' === $tag || self::has_audit( (int) $r->id, $tag ) ) {
				continue;
			}
			$row = self::load( (int) $r->id );
			$org = NPD_Settings::get( 'org_name' );
			wp_mail(
				$r->donor_email,
				/* translators: %s: org */
				sprintf( __( 'Still checking your gift - %s', 'nonprofit-donations' ), $org ),
				sprintf(
					/* translators: 1: name 2: ref 3: link 4: org */
					__( "Dear %1\$s,\n\nWe have not been able to match your gift %2\$s with our bank yet. If you have your UPI reference number, you can add it here and we will check again:\n%3\$s\n\n%4\$s", 'nonprofit-donations' ),
					$r->donor_name,
					'DON-' . $r->id,
					self::status_url( $row ),
					$org
				)
			);
			self::audit( (int) $r->id, $tag, 'system' );
		}
	}

	/**
	 * Mask a PAN as ABCDE****F.
	 *
	 * @param string $enc Encrypted PAN.
	 * @return string
	 */
	private static function masked_pan( $enc ) {
		$p = NPD_Settings::decrypt( (string) $enc );
		return strlen( $p ) === 10 ? substr( $p, 0, 5 ) . '****' . substr( $p, 9 ) : '';
	}

	/**
	 * Public pages without a WordPress page: status, find, signed action.
	 */
	public static function public_pages() {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_GET['npd_status'] ) ) {
			self::page_status( sanitize_text_field( wp_unslash( $_GET['npd_status'] ) ) );
		} elseif ( isset( $_GET['npd_poll'] ) ) {
			self::page_poll( sanitize_text_field( wp_unslash( $_GET['npd_poll'] ) ) );
		} elseif ( isset( $_GET['npd_find'] ) ) {
			self::page_find();
		} elseif ( isset( $_GET['npd_act'] ) ) {
			self::page_act( sanitize_text_field( wp_unslash( $_GET['npd_act'] ) ) );
		}
		// phpcs:enable
	}

	/**
	 * Minimal page shell. Never indexed.
	 *
	 * @param string $title Title.
	 * @param string $html  Body HTML (already escaped).
	 */
	private static function shell( $title, $html ) {
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Content-Type: text/html; charset=utf-8' );
		$org = NPD_Settings::get( 'org_name' );
		echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html( $title ) . '</title><style>body{font:16px/1.5 system-ui,sans-serif;background:#f6f7f7;margin:0;color:#1d2327}main{max-width:480px;margin:0 auto;padding:20px}.c{background:#fff;border-radius:10px;padding:20px;box-shadow:0 1px 3px #0002}h1{font-size:20px;margin:0 0 12px}.big{display:block;width:100%;box-sizing:border-box;padding:16px;font-size:18px;border:0;border-radius:8px;background:#2563eb;color:#fff;margin-top:12px}.alt{background:#fff;color:#1d2327;border:1px solid #888}input[type=text],input[type=email]{width:100%;box-sizing:border-box;padding:12px;font-size:16px;margin:4px 0 10px}.s{display:inline-block;padding:4px 10px;border-radius:20px;background:#eef;font-weight:600}.ok{background:#dcfce7}.bad{background:#fee2e2}small{color:#555}</style></head><body><main><p><b>' . esc_html( $org ) . '</b></p><div class="c">' . $html . '</div></main></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/**
	 * Donor status page, by token.
	 *
	 * @param string $key id-token.
	 */
	private static function page_status( $key ) {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0';
		$rk = 'npd_rl_' . md5( $ip );
		$n  = (int) get_transient( $rk );
		set_transient( $rk, $n + 1, HOUR_IN_SECONDS );
		if ( $n > 60 || ! preg_match( '/^(\d+)-([A-Za-z0-9]{10,24})$/', $key, $m ) ) {
			self::shell( 'Status', '<h1>Not found</h1><p>This link does not look right.</p>' );
		}
		$row = self::load( (int) $m[1] );
		if ( ! $row || '' === (string) $row->status_token || ! hash_equals( (string) $row->status_token, $m[2] ) ) {
			self::shell( 'Status', '<h1>Not found</h1><p>This link does not look right.</p>' );
		}
		$note = '';
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) && $n < 30 ) {
			$what = isset( $_POST['what'] ) ? sanitize_key( wp_unslash( $_POST['what'] ) ) : '';
			if ( 'ref' === $what && 'pending' === $row->status ) {
				$utr = strtoupper( preg_replace( '/\s+/', '', isset( $_POST['utr'] ) ? sanitize_text_field( wp_unslash( $_POST['utr'] ) ) : '' ) );
				if ( preg_match( '/^[A-Z0-9]{8,30}$/', $utr ) ) {
					NPD_DB::submit_utr( (int) $row->id, $utr, NPD_DB::utr_taken( $utr, (int) $row->id ) ? 'dup_ref' : '' );
					self::audit( (int) $row->id, 'donor_ref', 'donor', $utr );
					$note = 'Thank you. We will check again.';
				} else {
					$note = 'That reference does not look right. It is 8 to 30 letters and digits.';
				}
			} elseif ( 'pan' === $what && '' === (string) $row->receipt_no && in_array( $row->status, array( 'pending', 'paid' ), true ) && $row->want_80g ) {
				$pan = strtoupper( preg_replace( '/\s+/', '', isset( $_POST['pan'] ) ? sanitize_text_field( wp_unslash( $_POST['pan'] ) ) : '' ) );
				if ( preg_match( '/^[A-Z]{5}[0-9]{4}[A-Z]$/', $pan ) ) {
					global $wpdb;
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->update( NPD_DB::table( 'donors' ), array( 'pan_enc' => NPD_Settings::encrypt( $pan ) ), array( 'id' => (int) $row->donor_id ) );
					self::audit( (int) $row->id, 'donor_pan', 'donor', 'PAN corrected' );
					$note = 'PAN updated.';
				} else {
					$note = 'That PAN does not look right (like ABCDE1234F).';
				}
			}
			$row = self::load( (int) $m[1] );
		}
		// phpcs:enable
		$words = array(
			'pending'   => $row->donor_claimed ? array( 'Waiting for our bank check', '' ) : array( 'We have not heard that you paid', '' ),
			'paid'      => array( 'Received. Thank you!', 'ok' ),
			'failed'    => array( 'We could not match this payment', 'bad' ),
			'cancelled' => array( 'This gift was taken back for now', 'bad' ),
		);
		$w    = isset( $words[ $row->status ] ) ? $words[ $row->status ] : array( ucfirst( $row->status ), '' );
		$h    = '<h1>DON-' . (int) $row->id . '</h1><p>' . esc_html( self::rs( (int) $row->amount_paise ) ) . ' &middot; ' . esc_html( mysql2date( 'j M Y', $row->created_at ) ) . '</p><p><span class="s ' . esc_attr( $w[1] ) . '">' . esc_html( $w[0] ) . '</span></p>';
		if ( '' !== $note ) {
			$h .= '<p><b>' . esc_html( $note ) . '</b></p>';
		}
		$h .= self::tracker( $row );
		if ( 'pending' === $row->status ) {
			$h .= '<div id="npd-shot-host"></div>';
			$h .= '<form method="post"><input type="hidden" name="what" value="ref"><label>UPI reference number (optional)<br><small>Shown in your UPI app under the payment.</small><input type="text" name="utr" maxlength="30" autocomplete="off"></label><button class="big">Add reference</button></form>';
		}
		if ( 'failed' === $row->status || 'cancelled' === $row->status ) {
			$h .= '<p>If you did pay, please add your UPI reference and contact us.</p>';
		}
		if ( $row->want_80g && in_array( $row->status, array( 'pending', 'paid' ), true ) ) {
			$mp = self::masked_pan( $row->donor_pan );
			$h .= '<hr><p>PAN for your 80G receipt: <b>' . esc_html( '' !== $mp ? $mp : 'not given' ) . '</b></p>';
			if ( '' === (string) $row->receipt_no ) {
				$h .= '<form method="post"><input type="hidden" name="what" value="pan"><label>Correct my PAN<input type="text" name="pan" maxlength="10" placeholder="ABCDE1234F" autocapitalize="characters" autocomplete="off"></label><button class="big alt">Save PAN</button></form>';
			}
		}
		self::shell( 'Donation status', $h . self::tracker_js( $row, $m[1] . '-' . $m[2] ) );
	}

	/**
	 * Bank transfer details, or null when off or incomplete.
	 *
	 * @return array|null
	 */
	public static function bank() {
		$b = wp_parse_args( get_option( 'npd_bank_xfer', array() ), array( 'mode' => 'off', 'name' => '', 'no' => '', 'ifsc' => '', 'bank' => '', 'branch' => '' ) );
		if ( 'off' === $b['mode'] || '' === trim( $b['name'] ) || ! preg_match( '/^\d{9,18}$/', $b['no'] ) || ! preg_match( '/^[A-Z]{4}0[A-Z0-9]{6}$/', $b['ifsc'] ) ) {
			return null;
		}
		return $b;
	}

	/**
	 * Called after every mailbox run. Keeps a failure streak and tells the owner, never the donors.
	 *
	 * @param bool   $ok  Run worked.
	 * @param string $msg Message.
	 */
	public static function mail_health( $ok, $msg ) {
		$h = wp_parse_args( get_option( 'npd_mail_health', array() ), array( 'fails' => 0, 'since' => '', 'msg' => '', 'mailed' => 0 ) );
		if ( $ok ) {
			if ( $h['fails'] ) {
				delete_option( 'npd_mail_health' );
				delete_transient( 'npd_mail_backoff' );
			}
			return;
		}
		++$h['fails'];
		$h['since'] = $h['since'] ? $h['since'] : current_time( 'mysql' );
		$h['msg']   = substr( (string) $msg, 0, 200 );
		set_transient( 'npd_mail_backoff', 1, 5 * MINUTE_IN_SECONDS );
		$auth = (bool) preg_match( '/auth|login|password|credential|invalid|denied|disabled|not found|no such/i', $h['msg'] );
		if ( ( $auth || $h['fails'] >= 3 ) && time() - (int) $h['mailed'] > DAY_IN_SECONDS ) {
			$h['mailed'] = time();
			wp_mail(
				self::owner(),
				__( 'Your alert mailbox stopped working', 'nonprofit-donations' ),
				sprintf(
					/* translators: 1: error 2: mailbox page 3: verify page 4: bank page */
					__( "The mailbox that reads your bank alerts could not be reached.

What it said: %1\$s

Nothing is lost. Donations are saved on your site, donors can still pay and tell us, and every gift still shows on the Verify UPI page where you confirm it with one tap. You can also upload your bank statement to match them in bulk.

Fix the mailbox (for example the password changed, or the mailbox was removed):
%2\$s

Confirm gifts:
%3\$s

Upload a bank statement:
%4\$s

This mail comes at most once a day while the problem lasts.", 'nonprofit-donations' ),
					$h['msg'],
					admin_url( 'admin.php?page=npd-mailbox' ),
					admin_url( 'admin.php?page=npd-verify' ),
					admin_url( 'admin.php?page=npd-bank' )
				)
			);
		}
		update_option( 'npd_mail_health', $h, false );
	}

	/**
	 * Admin notice on the plugin pages and the dashboard while the mailbox is failing.
	 */
	public static function mail_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$h = get_option( 'npd_mail_health', array() );
		if ( empty( $h['fails'] ) ) {
			return;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$scr  = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( 0 !== strpos( $page, 'npd' ) && ( ! $scr || 'dashboard' !== $scr->id ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Alert mailbox is not working.', 'nonprofit-donations' ) . '</strong> ' . esc_html( $h['msg'] ) . ' ' . esc_html( sprintf( /* translators: 1: since 2: tries */ __( '(since %1$s, %2$d failed tries)', 'nonprofit-donations' ), $h['since'], (int) $h['fails'] ) ) . '<br>' . esc_html__( 'Donations still work. Confirm them on Verify UPI, or upload a bank statement.', 'nonprofit-donations' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=npd-mailbox' ) ) . '">' . esc_html__( 'Fix the mailbox', 'nonprofit-donations' ) . '</a> | <a href="' . esc_url( admin_url( 'admin.php?page=npd-verify' ) ) . '">' . esc_html__( 'Verify UPI', 'nonprofit-donations' ) . '</a> | <a href="' . esc_url( admin_url( 'admin.php?page=npd-bank' ) ) . '">' . esc_html__( 'Bank statement', 'nonprofit-donations' ) . '</a></p></div>';
	}

	/**
	 * Pending manual-lane gifts from the last 7 days (the ones a bank alert could settle).
	 *
	 * @return int
	 */
	public static function waiting_count() {
		global $wpdb;
		$t    = NPD_DB::table( 'donations' );
		$from = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE status = 'pending' AND mode = 'upi' AND created_at >= %s", $from ) );
	}

	/**
	 * Texts for the screenshot reader.
	 *
	 * @return array
	 */
	public static function shot_texts() {
		return array(
			'pick'    => __( 'Have the payment success screen? Pick the screenshot and we read the reference number for you:', 'nonprofit-donations' ),
			'privacy' => __( 'The picture is read on your phone and never leaves it. First time it loads about 8 MB, then your phone keeps it.', 'nonprofit-donations' ),
			'reading' => __( 'Reading the screenshot...', 'nonprofit-donations' ),
			/* translators: %s: reference */
			'found'   => __( 'We read reference %s. Please check it matches your app, then tap the button below.', 'nonprofit-donations' ),
			'amountOk' => __( 'The amount matches.', 'nonprofit-donations' ),
			'amountNo' => __( 'We could not see the amount clearly, that is fine.', 'nonprofit-donations' ),
			'none'    => __( 'We could not read a reference from this picture. You can type it in instead, or skip it.', 'nonprofit-donations' ),
			'fail'    => __( 'Could not read the picture on this device. You can type the reference instead.', 'nonprofit-donations' ),
		);
	}

	/**
	 * Three-step tracker: gift noted, bank being watched, confirmed.
	 *
	 * @param object $row Donation.
	 * @return string HTML.
	 */
	private static function tracker( $row ) {
		if ( ! in_array( $row->status, array( 'pending', 'paid' ), true ) ) {
			return '';
		}
		$step  = 'paid' === $row->status ? 3 : ( $row->donor_claimed ? 2 : 1 );
		$names = array( 1 => 'Gift noted', 2 => 'Bank being watched', 3 => 'Confirmed' );
		$h     = '<ol id="npd-trk" style="list-style:none;padding:0;margin:12px 0">';
		foreach ( $names as $n => $label ) {
			$on = $n <= $step;
			$h .= '<li style="padding:6px 0;color:' . ( $on ? '#166534' : '#888' ) . '"><span style="display:inline-block;width:22px;height:22px;line-height:22px;text-align:center;border-radius:50%;background:' . ( $on ? '#16a34a' : '#ddd' ) . ';color:#fff;margin-right:8px">' . ( $on ? '&#10003;' : (int) $n ) . '</span>' . esc_html( $label ) . ( $n === $step && 2 === $n ? ' <small>(checking about every few seconds)</small>' : '' ) . '</li>';
		}
		return $h . '</ol>';
	}

	/**
	 * Small script: ask for the status every few seconds while waiting, reload when it changes.
	 *
	 * @param object $row Donation.
	 * @param string $key id-token.
	 * @return string
	 */
	private static function tracker_js( $row, $key ) {
		$shot = '';
		if ( 'pending' === $row->status ) {
			$base = NPD_URL . 'ocr/';
			$shot = '<script>(function(){var b=' . wp_json_encode( $base ) . ',t=' . wp_json_encode( self::shot_texts() ) . ',s=document.createElement("script");s.src=b+"npd-shot.js";s.onload=function(){var i=document.querySelector("input[name=utr]"),h=document.getElementById("npd-shot-host");if(i&&h){window.npdShot.attach({input:i,host:h,rupees:' . wp_json_encode( round( $row->amount_paise / 100, 2 ) ) . ',base:b,t:t})}};document.head.appendChild(s)})();</script>';
		}
		if ( 'pending' !== $row->status || ! $row->donor_claimed ) {
			return $shot;
		}
		$url = add_query_arg( 'npd_poll', $key, home_url( '/' ) );
		return '<script>(function(){var u=' . wp_json_encode( $url ) . ',t0=Date.now(),busy=false;function go(){if(document.hidden||busy){return}busy=true;fetch(u,{cache:"no-store"}).then(function(r){return r.json()}).then(function(j){busy=false;if(j&&j.s!=="pending"){location.reload()}}).catch(function(){busy=false})}function loop(){var age=Date.now()-t0;if(age>900000){return}go();setTimeout(loop,age<180000?5000:30000)}setTimeout(loop,4000)})();</script>' . $shot;
	}

	/**
	 * JSON status for the tracker. While a claimed gift waits, it also gives the mailbox a turn.
	 *
	 * @param string $key id-token.
	 */
	private static function page_poll( $key ) {
		nocache_headers();
		header( 'Content-Type: application/json' );
		header( 'X-Robots-Tag: noindex' );
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0';
		$rk = 'npd_pl_' . md5( $ip );
		$n  = (int) get_transient( $rk );
		set_transient( $rk, $n + 1, 10 * MINUTE_IN_SECONDS );
		if ( $n > 200 || ! preg_match( '/^(\d+)-([A-Za-z0-9]{10,24})$/', $key, $m ) ) {
			echo wp_json_encode( array( 's' => 'x' ) );
			exit;
		}
		$row = self::load( (int) $m[1] );
		if ( ! $row || ! hash_equals( (string) $row->status_token, $m[2] ) ) {
			echo wp_json_encode( array( 's' => 'x' ) );
			exit;
		}
		if ( 'pending' === $row->status && $row->donor_claimed ) {
			self::fast_poll();
			$row = self::load( (int) $m[1] );
		}
		echo wp_json_encode( array( 's' => $row->status ) );
		exit;
	}

	/**
	 * Give the alert mailbox a turn right now, at most once every 10 seconds site-wide.
	 * Runs only when the mailbox is on and a claimed gift is waiting, so an idle site never connects.
	 */
	public static function fast_poll() {
		$o = NPD_Mailbox::opts();
		if ( empty( $o['enabled'] ) || get_transient( 'npd_fastpoll' ) || get_transient( 'npd_mail_backoff' ) ) {
			return;
		}
		set_transient( 'npd_fastpoll', 1, 10 );
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
		// phpcs:ignore Squiz.PHP.DiscouragedFunctions
		@set_time_limit( 30 );
		NPD_Mailbox::poll();
	}

	/**
	 * Find my donation: DON number and email.
	 */
	private static function page_find() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0';
		$rk = 'npd_fd_' . md5( $ip );
		$n  = (int) get_transient( $rk );
		$msg = '';
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) {
			set_transient( $rk, $n + 1, HOUR_IN_SECONDS );
			$num = isset( $_POST['don'] ) ? absint( preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['don'] ) ) ) ) : 0;
			$em  = isset( $_POST['email'] ) ? strtolower( trim( sanitize_email( wp_unslash( $_POST['email'] ) ) ) ) : '';
			$row = ( $n < 10 && $num && is_email( $em ) ) ? self::load( $num ) : null;
			if ( $row && strtolower( (string) $row->donor_email ) === $em && '' !== (string) $row->status_token ) {
				wp_safe_redirect( self::status_url( $row ) );
				exit;
			}
			$msg = $n >= 10 ? 'Too many tries. Please try again later.' : 'We could not find a gift with those details. Check the DON number in your mail and the email you used.';
		}
		// phpcs:enable
		$h = '<h1>Find my donation</h1>' . ( '' !== $msg ? '<p><b>' . esc_html( $msg ) . '</b></p>' : '' ) . '<form method="post"><label>DON number (like DON-12)<input type="text" name="don" autocomplete="off"></label><label>Email you used<input type="email" name="email"></label><button class="big">Check status</button></form>';
		self::shell( 'Find my donation', $h );
	}

	/**
	 * Owner signed link page. A GET never changes anything.
	 *
	 * @param string $tok Token.
	 */
	private static function page_act( $tok ) {
		$t = self::check_token( $tok );
		if ( ! $t ) {
			self::shell( 'Link', '<h1>This link has expired or was already used</h1><p>Open the Verify UPI page in wp-admin instead.</p>' );
		}
		$row = self::load( $t['id'] );
		if ( ! $row || 'pending' !== $row->status || 'upi' !== $row->mode ) {
			self::shell( 'Link', '<h1>Nothing to do</h1><p>This donation is no longer waiting.</p>' );
		}
		$o     = self::opts();
		$login = is_user_logged_in() && current_user_can( 'manage_options' );
		$tap   = ! empty( $o['onetap'] ) && (int) $row->amount_paise <= (int) $o['cap'] * 100;
		if ( ! $login && ! $tap ) {
			wp_safe_redirect( wp_login_url( add_query_arg( 'npd_act', $tok, home_url( '/' ) ) ) );
			exit;
		}
		$word = 'c' === $t['action'] ? 'Confirm' : 'Not received';
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) {
			self::audit( (int) $row->id, 'tok:' . substr( $t['sig'], 0, 16 ), $login ? wp_get_current_user()->user_login : 'link' );
			self::$src = $login ? 'manual' : 'onetap';
			$ok = 'c' === $t['action'] ? NPD_DB::verify_upi( (int) $row->id ) : NPD_DB::reject_upi( (int) $row->id );
			self::shell( 'Done', '<h1>' . ( $ok ? 'Done' : 'Could not update' ) . '</h1><p>DON-' . (int) $row->id . ': ' . esc_html( $ok ? $word . ' recorded.' : 'it may have changed already.' ) . '</p>' );
		}
		// phpcs:enable
		$bm = '' !== (string) $row->bank_match ? 'Bank statement shows a matching credit.' : 'No matching bank credit found yet. Check your bank first.';
		$h  = '<h1>' . esc_html( $word ) . '?</h1><p><b>DON-' . (int) $row->id . '</b><br>' . esc_html( self::rs( (int) $row->amount_paise ) ) . '<br>' . esc_html( $row->donor_name ) . '<br>UPI ref: ' . esc_html( '' !== (string) $row->utr ? $row->utr : '-' ) . '</p><p>' . esc_html( $bm ) . '</p><form method="post"><button class="big' . ( 'c' === $t['action'] ? '' : ' alt' ) . '">' . esc_html( $word ) . '</button></form>';
		self::shell( 'Confirm donation', $h );
	}

	/**
	 * Evidence tier for a match: A = DON number in the row, B = ref + date within 7 days + name, else C.
	 *
	 * @param object $don Donation (id, created_at, donor_name).
	 * @param array  $row Parsed bank row.
	 * @return string
	 */
	public static function tier( $don, $row ) {
		$up = strtoupper( $row['text'] );
		if ( preg_match( '/\bDON-' . (int) $don->id . '\b/', $up ) ) {
			return 'A';
		}
		$ts = '' !== trim( (string) $row['date'] ) ? strtotime( str_replace( '/', '-', $row['date'] ) ) : false;
		if ( $ts && $ts >= strtotime( $don->created_at ) - DAY_IN_SECONDS && $ts <= strtotime( $don->created_at ) + 7 * DAY_IN_SECONDS ) {
			foreach ( preg_split( '/\s+/', strtoupper( (string) $don->donor_name ) ) as $tok ) {
				if ( strlen( $tok ) >= 3 && false !== strpos( $up, $tok ) ) {
					return 'B';
				}
			}
		}
		return 'C';
	}

	/**
	 * May this match confirm by itself? Needs the switch, finished learning, strong evidence,
	 * admin-supplied or authenticated source, no review flag, and an amount under the cap.
	 *
	 * @param object $don    Donation.
	 * @param string $tier   A, B or C.
	 * @param string $source statement, mail or paste.
	 * @param bool   $auth   Mail passed SPF/DKIM.
	 * @return bool
	 */
	public static function can_auto( $don, $tier, $source, $auth ) {
		$o = self::opts();
		if ( empty( $o['auto'] ) || (int) $o['learned'] < self::LEARN_NEEDED || ! in_array( $tier, array( 'A', 'B' ), true ) ) {
			return false;
		}
		if ( '' !== (string) $don->review_flag || (int) $don->amount_paise > (int) $o['cap'] * 100 ) {
			return false;
		}
		return 'statement' === $source || ( 'mail' === $source && $auth );
	}

	/**
	 * Save matches from a statement or mail. Suggests every match, confirms only what can_auto allows.
	 *
	 * @param array[]  $matches id => row (from NPD_Bank::match).
	 * @param object[] $dons    Waiting donations.
	 * @param string   $source  statement, mail or paste.
	 * @param bool     $auth    Mail authenticated.
	 * @return array{matched:int,auto:int}
	 */
	public static function apply_matches( $matches, $dons, $source, $auth = false ) {
		$by = array();
		foreach ( $dons as $d ) {
			$by[ (int) $d->id ] = $d;
		}
		$auto = 0;
		foreach ( $matches as $id => $r ) {
			NPD_DB::set_bank_match( $id, $r['hash'], trim( $r['date'] ) );
			$d    = $by[ $id ];
			$tier = self::tier( $d, $r );
			if ( self::can_auto( $d, $tier, $source, $auth ) ) {
				self::$src = 'auto';
				if ( NPD_DB::verify_upi( $id ) ) {
					self::audit( $id, 'auto', 'auto', 'tier ' . $tier . ', ' . $source . ', row ' . $r['hash'] );
					++$auto;
				}
				self::$src = 'manual';
			}
		}
		return array( 'matched' => count( $matches ), 'auto' => $auto );
	}

	/**
	 * Remember credits that matched no donation, so a person can attach them.
	 *
	 * @param array[] $rows    All parsed rows.
	 * @param array   $matches id => row.
	 * @param array   $used    Used hashes.
	 */
	public static function remember_unknown( $rows, $matches, $used ) {
		global $wpdb;
		$hit = array();
		foreach ( $matches as $r ) {
			$hit[ $r['hash'] ] = 1;
		}
		$n = 0;
		foreach ( $rows as $r ) {
			if ( isset( $hit[ $r['hash'] ] ) || in_array( $r['hash'], $used, true ) || $n >= 300 ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . NPD_DB::table( 'credits' ) . ' (hash, credit_date, amount_paise, info, status, created_at) VALUES (%s, %s, %d, %s, %s, %s)', $r['hash'], substr( $r['date'], 0, 30 ), (int) $r['amounts'][0], substr( $r['text'], 0, 250 ), 'open', current_time( 'mysql' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			++$n;
		}
	}

	/**
	 * Unknown credits list on the Bank statement page.
	 */
	public static function credits_box() {
		global $wpdb;
		$t = NPD_DB::table( 'credits' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$t} WHERE status = 'open' ORDER BY id DESC LIMIT 50" );
		?>
		<h2 style="margin-top:2em"><?php echo esc_html__( 'Unknown credits', 'nonprofit-donations' ); ?></h2>
		<p class="description"><?php echo esc_html__( 'Credits in your statements that matched no donation. A donor may have paid without tapping "I have paid". Attach one to a waiting donation (it then shows as a bank match, you still confirm), or dismiss it.', 'nonprofit-donations' ); ?></p>
		<table class="widefat striped"><tbody>
		<?php if ( ! $rows ) : ?><tr><td><?php echo esc_html__( 'None.', 'nonprofit-donations' ); ?></td></tr><?php endif; ?>
		<?php foreach ( $rows as $c ) : ?>
			<tr><td><strong><?php echo esc_html( self::rs( (int) $c->amount_paise ) ); ?></strong><br><small><?php echo esc_html( $c->credit_date ); ?></small></td>
			<td><small><?php echo esc_html( $c->info ); ?></small></td>
			<td><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:6px;flex-wrap:wrap"><input type="hidden" name="action" value="npd_credit"><input type="hidden" name="cid" value="<?php echo esc_attr( $c->id ); ?>"><?php wp_nonce_field( 'npd_credit' ); ?>
				<input type="text" name="don" placeholder="DON-12" style="width:90px"><button class="button" name="do" value="attach"><?php echo esc_html__( 'Attach', 'nonprofit-donations' ); ?></button><button class="button" name="do" value="dismiss"><?php echo esc_html__( 'Dismiss', 'nonprofit-donations' ); ?></button></form></td></tr>
		<?php endforeach; ?>
		</tbody></table>
		<?php
	}

	/**
	 * Attach or dismiss an unknown credit.
	 */
	public static function credit_action() {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'nonprofit-donations' ), 403 );
		}
		check_admin_referer( 'npd_credit' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$cid = isset( $_POST['cid'] ) ? absint( $_POST['cid'] ) : 0;
		$do  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$don = isset( $_POST['don'] ) ? absint( preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['don'] ) ) ) ) : 0;
		// phpcs:enable
		$t = NPD_DB::table( 'credits' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$c = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d AND status = 'open'", $cid ) );
		if ( $c && 'dismiss' === $do ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $t, array( 'status' => 'dismissed' ), array( 'id' => $cid ) );
		} elseif ( $c && 'attach' === $do && $don ) {
			$d = NPD_DB::get_donation( $don );
			if ( $d && 'pending' === $d->status && (int) $d->amount_paise === (int) $c->amount_paise && ! in_array( $c->hash, NPD_DB::used_bank_hashes(), true ) ) {
				NPD_DB::set_bank_match( $don, $c->hash, $c->credit_date );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->update( $t, array( 'status' => 'attached', 'donation_id' => $don ), array( 'id' => $cid ) );
				self::audit( $don, 'attached', self::actor(), 'credit ' . $c->hash );
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=npd-bank' ) );
		exit;
	}

	/**
	 * Menu.
	 */
	public static function menu() {
		add_submenu_page( 'npd', __( 'Confirmation', 'nonprofit-donations' ), __( 'Confirmation', 'nonprofit-donations' ), 'manage_options', 'npd-flow', array( __CLASS__, 'page' ) );
	}

	/**
	 * Confirmation settings.
	 */
	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o = self::opts();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Confirmation', 'nonprofit-donations' ); ?></h1>
			<p class="description"><?php echo esc_html__( 'Three ways a donation becomes confirmed: you tap Confirm (always works), you tap the link in the alert mail, or the bank evidence is exact and automatic confirming is on. Anything unclear is left for you.', 'nonprofit-donations' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="npd_flow_save"><?php wp_nonce_field( 'npd_flow_save' ); ?>
				<table class="form-table" role="presentation">
					<tr><th><?php echo esc_html__( 'Links in the alert mail', 'nonprofit-donations' ); ?></th><td>
						<label><input type="checkbox" name="onetap" value="1" <?php checked( ! empty( $o['onetap'] ) ); ?>> <?php echo esc_html__( 'One-tap: the link works without logging in (up to the amount below). Anyone who gets that mail can use it, so keep this off unless your mailbox is private.', 'nonprofit-donations' ); ?></label><br>
						<span class="description"><?php echo esc_html__( 'Off (default): the link asks you to log in first if your phone is not already logged in. Links work once and expire after 3 days. Opening a link never confirms by itself.', 'nonprofit-donations' ); ?></span></td></tr>
					<tr><th><?php echo esc_html__( 'Automatic confirming', 'nonprofit-donations' ); ?></th><td>
						<label><input type="checkbox" name="auto" value="1" <?php checked( ! empty( $o['auto'] ) ); ?>> <?php echo esc_html__( 'Confirm by itself when a bank statement row (or an authenticated bank mail) matches exactly: the DON number, or UPI reference with matching date and donor name, plus the same amount.', 'nonprofit-donations' ); ?></label><br>
						<span class="description"><?php echo esc_html( sprintf( /* translators: 1: learned 2: needed */ __( 'It starts after you have confirmed %2$d bank-matched donations yourself, so you see it being right first. Progress: %1$d of %2$d.', 'nonprofit-donations' ), (int) $o['learned'], self::LEARN_NEEDED ) ); ?></span><br>
						<?php echo esc_html__( 'Only for amounts up to Rs', 'nonprofit-donations' ); ?> <input type="number" name="cap" min="1" style="width:100px" value="<?php echo esc_attr( $o['cap'] ); ?>"><br>
						<label><input type="checkbox" name="auto_80g" value="1" <?php checked( ! empty( $o['auto_80g'] ) ); ?>> <?php echo esc_html__( 'Also send the 80G receipt automatically after an automatic confirm (24 hours later). Leave off until your CA confirms the instant-receipt rule. Otherwise these wait in "80G receipts waiting".', 'nonprofit-donations' ); ?></label>
						<br><span class="description"><?php echo esc_html__( 'A donation you reverse after an automatic confirm switches automatic confirming off. Text pasted into the Bank statement page never confirms by itself.', 'nonprofit-donations' ); ?></span></td></tr>
					<tr><th><?php echo esc_html__( 'Second person for reminders', 'nonprofit-donations' ); ?></th><td><input type="email" name="email2" class="regular-text" value="<?php echo esc_attr( $o['email2'] ); ?>"><br><span class="description"><?php echo esc_html__( 'Optional. Also gets the daily mail about donations still waiting.', 'nonprofit-donations' ); ?></span></td></tr>
				</table>
				<?php $b = wp_parse_args( get_option( 'npd_bank_xfer', array() ), array( 'mode' => 'off', 'name' => '', 'no' => '', 'ifsc' => '', 'bank' => '', 'branch' => '' ) ); ?>
				<h2><?php echo esc_html__( 'Bank transfer (NEFT / IMPS)', 'nonprofit-donations' ); ?></h2>
				<p class="description"><?php echo esc_html__( 'For when UPI is down, or as an extra choice. Donors see these details with their DON number to put in the transfer remarks. Gifts are confirmed the same way as UPI gifts: from your bank statement or alert.', 'nonprofit-donations' ); ?></p>
				<table class="form-table" role="presentation">
					<tr><th><?php echo esc_html__( 'Show bank transfer', 'nonprofit-donations' ); ?></th><td><select name="bx_mode">
						<option value="off" <?php selected( $b['mode'], 'off' ); ?>><?php echo esc_html__( 'Off', 'nonprofit-donations' ); ?></option>
						<option value="extra" <?php selected( $b['mode'], 'extra' ); ?>><?php echo esc_html__( 'Next to UPI (extra choice)', 'nonprofit-donations' ); ?></option>
						<option value="only" <?php selected( $b['mode'], 'only' ); ?>><?php echo esc_html__( 'Only bank transfer (UPI paused)', 'nonprofit-donations' ); ?></option>
					</select></td></tr>
					<tr><th><?php echo esc_html__( 'Account name', 'nonprofit-donations' ); ?></th><td><input name="bx_name" class="regular-text" value="<?php echo esc_attr( $b['name'] ); ?>"></td></tr>
					<tr><th><?php echo esc_html__( 'Account number', 'nonprofit-donations' ); ?></th><td><input name="bx_no" class="regular-text" inputmode="numeric" value="<?php echo esc_attr( $b['no'] ); ?>"></td></tr>
					<tr><th>IFSC</th><td><input name="bx_ifsc" class="regular-text" value="<?php echo esc_attr( $b['ifsc'] ); ?>" placeholder="HDFC0001234"></td></tr>
					<tr><th><?php echo esc_html__( 'Bank and branch', 'nonprofit-donations' ); ?></th><td><input name="bx_bank" class="regular-text" value="<?php echo esc_attr( $b['bank'] ); ?>" placeholder="HDFC Bank">  <input name="bx_branch" class="regular-text" value="<?php echo esc_attr( $b['branch'] ); ?>"></td></tr>
				</table>
				<p><button class="button button-primary"><?php echo esc_html__( 'Save', 'nonprofit-donations' ); ?></button></p>
			</form>
			<p class="description"><?php echo esc_html( sprintf( /* translators: %s: url */ __( 'Donor page to check a gift: %s', 'nonprofit-donations' ), add_query_arg( 'npd_find', '1', home_url( '/' ) ) ) ); ?></p>
		</div>
		<?php
	}

	/**
	 * Save settings.
	 */
	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'nonprofit-donations' ), 403 );
		}
		check_admin_referer( 'npd_flow_save' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		self::put(
			array(
				'onetap'   => empty( $_POST['onetap'] ) ? 0 : 1,
				'auto'     => empty( $_POST['auto'] ) ? 0 : 1,
				'auto_80g' => empty( $_POST['auto_80g'] ) ? 0 : 1,
				'cap'      => isset( $_POST['cap'] ) ? max( 1, absint( $_POST['cap'] ) ) : 10000,
				'email2'   => isset( $_POST['email2'] ) && is_email( wp_unslash( $_POST['email2'] ) ) ? sanitize_email( wp_unslash( $_POST['email2'] ) ) : '',
			)
		);
		$ifsc = isset( $_POST['bx_ifsc'] ) ? strtoupper( preg_replace( '/\s+/', '', sanitize_text_field( wp_unslash( $_POST['bx_ifsc'] ) ) ) ) : '';
		$no   = isset( $_POST['bx_no'] ) ? preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['bx_no'] ) ) ) : '';
		$mode = isset( $_POST['bx_mode'] ) ? sanitize_key( wp_unslash( $_POST['bx_mode'] ) ) : 'off';
		update_option(
			'npd_bank_xfer',
			array(
				'mode'   => in_array( $mode, array( 'off', 'extra', 'only' ), true ) ? $mode : 'off',
				'name'   => isset( $_POST['bx_name'] ) ? sanitize_text_field( wp_unslash( $_POST['bx_name'] ) ) : '',
				'no'     => $no,
				'ifsc'   => $ifsc,
				'bank'   => isset( $_POST['bx_bank'] ) ? sanitize_text_field( wp_unslash( $_POST['bx_bank'] ) ) : '',
				'branch' => isset( $_POST['bx_branch'] ) ? sanitize_text_field( wp_unslash( $_POST['bx_branch'] ) ) : '',
			),
			false
		);
		// phpcs:enable
		wp_safe_redirect( admin_url( 'admin.php?page=npd-flow&saved=1' ) );
		exit;
	}
}
