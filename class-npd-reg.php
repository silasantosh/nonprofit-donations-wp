<?php
/**
 * 12A / 80G registration details with a verification state.
 *
 * States: not_provided -> pending -> verified | rejected -> expired (by date).
 * The check against the official portal is done by an operator (admin action),
 * never by a scraper. Nothing is shown publicly until verified and in date.
 *
 * @package Nonprofit_Donations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registrations.
 */
class NPD_Reg {

	const OPTION = 'npd_reg';
	const TYPES  = array( '12a', '80g', 'upi' );

	/**
	 * Hook in.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_npd_reg_submit', array( __CLASS__, 'submit' ) );
		add_action( 'admin_post_npd_reg_decide', array( __CLASS__, 'decide' ) );
		add_action( 'npd_reg_daily', array( __CLASS__, 'daily' ) );
		if ( ! wp_next_scheduled( 'npd_reg_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'npd_reg_daily' );
		}
	}

	/**
	 * Default record.
	 *
	 * @return array
	 */
	private static function blank() {
		return array(
			'number'     => '',
			'pan'        => '',
			'valid_to'   => '',
			'proof_id'   => 0,
			'status'     => 'not_provided',
			'reason'     => '',
			'checked_at' => '',
			'resolved'   => '',
		);
	}

	/**
	 * All records.
	 *
	 * @return array
	 */
	public static function all() {
		$o   = get_option( self::OPTION, array() );
		$out = array();
		foreach ( self::TYPES as $t ) {
			$out[ $t ] = wp_parse_args( isset( $o[ $t ] ) ? $o[ $t ] : array(), self::blank() );
		}
		return $out;
	}

	/**
	 * Save one record.
	 *
	 * @param string $type Type.
	 * @param array  $rec  Record.
	 */
	private static function put( $type, $rec ) {
		$o          = get_option( self::OPTION, array() );
		$o[ $type ] = $rec;
		update_option( self::OPTION, $o, false );
	}

	/**
	 * Effective status: verified turns expired after the validity date.
	 *
	 * @param array $r Record.
	 * @return string
	 */
	public static function status( $r ) {
		if ( 'verified' === $r['status'] && '' !== $r['valid_to'] && $r['valid_to'] < gmdate( 'Y-m-d' ) ) {
			return 'expired';
		}
		return $r['status'];
	}

	/**
	 * Registrations safe to show on the public page.
	 *
	 * @return array type => number
	 */
	public static function public_list() {
		$out = array();
		foreach ( self::all() as $t => $r ) {
			if ( 'upi' !== $t && 'verified' === self::status( $r ) && '' !== $r['number'] ) {
				$out[ $t ] = $r['number'];
			}
		}
		return $out;
	}

	/**
	 * Menu.
	 */
	public static function menu() {
		add_submenu_page( 'npd', __( 'Registrations', 'nonprofit-donations' ), __( 'Registrations', 'nonprofit-donations' ), 'manage_options', 'npd-reg', array( __CLASS__, 'page' ) );
	}

	/**
	 * Label for a type.
	 *
	 * @param string $t Type.
	 * @return string
	 */
	private static function label( $t ) {
		if ( 'upi' === $t ) {
			return __( 'UPI ID', 'nonprofit-donations' );
		}
		return '12a' === $t ? __( '12A registration', 'nonprofit-donations' ) : __( '80G approval', 'nonprofit-donations' );
	}

	/**
	 * Page: NGO submits, operator decides.
	 */
	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$recs = self::all();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Registrations (12A and 80G)', 'nonprofit-donations' ); ?></h1>
			<p><?php echo esc_html__( 'Enter your PAN, registration number or URN and the validity date, and attach the approval order (PDF). Each one is checked against the official Income Tax portal. The number appears on your donation page only after it matches and while it is in date. You will get an email with the result.', 'nonprofit-donations' ); ?></p>
			<?php foreach ( $recs as $t => $r ) : ?>
				<?php $st = self::status( $r ); ?>
				<h2><?php echo esc_html( self::label( $t ) ); ?> <small>(<?php echo esc_html( $st ); ?>)</small></h2>
				<?php if ( 'rejected' === $st && '' !== $r['reason'] ) : ?>
					<div class="notice notice-error inline"><p><?php echo esc_html( $r['reason'] ); ?></p></div>
				<?php endif; ?>
				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="npd_reg_submit">
					<input type="hidden" name="type" value="<?php echo esc_attr( $t ); ?>">
					<?php wp_nonce_field( 'npd_reg_submit_' . $t ); ?>
					<?php if ( 'upi' === $t ) : ?>
					<table class="form-table" role="presentation">
						<tr><th><?php echo esc_html__( 'UPI ID (VPA)', 'nonprofit-donations' ); ?></th><td><input name="number" class="regular-text" value="<?php echo esc_attr( $r['number'] ? $r['number'] : NPD_Settings::get( 'upi_vpa' ) ); ?>">
							<p class="description"><?php echo esc_html__( 'Use a bank-issued current-account or merchant UPI ID in the NGO name. A personal name will be rejected.', 'nonprofit-donations' ); ?></p></td></tr>
						<?php if ( 'verified' === $st && '' !== $r['resolved'] ) : ?>
							<tr><th><?php echo esc_html__( 'Name shown in UPI apps', 'nonprofit-donations' ); ?></th><td><?php echo esc_html( $r['resolved'] ); ?></td></tr>
						<?php endif; ?>
					</table>
					<?php else : ?>
					<table class="form-table" role="presentation">
						<tr><th><?php echo esc_html__( 'NGO PAN', 'nonprofit-donations' ); ?></th><td><input name="pan" maxlength="10" value="<?php echo esc_attr( $r['pan'] ); ?>"></td></tr>
						<tr><th><?php echo esc_html__( 'Registration number / URN', 'nonprofit-donations' ); ?></th><td><input name="number" class="regular-text" value="<?php echo esc_attr( $r['number'] ); ?>"></td></tr>
						<tr><th><?php echo esc_html__( 'Valid until', 'nonprofit-donations' ); ?></th><td><input type="date" name="valid_to" value="<?php echo esc_attr( $r['valid_to'] ); ?>"></td></tr>
						<tr><th><?php echo esc_html__( 'Approval order (PDF)', 'nonprofit-donations' ); ?></th><td><input type="file" name="proof" accept="application/pdf"><?php echo $r['proof_id'] ? ' ' . esc_html__( '(file on record)', 'nonprofit-donations' ) : ''; ?></td></tr>
					</table>
					<?php endif; ?>
					<?php submit_button( __( 'Submit for checking', 'nonprofit-donations' ), 'primary', 'submit', false ); ?>
				</form>
				<?php if ( 'pending' === $st ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1em;padding:1em;background:#f6f7f7">
						<input type="hidden" name="action" value="npd_reg_decide">
						<input type="hidden" name="type" value="<?php echo esc_attr( $t ); ?>">
						<?php wp_nonce_field( 'npd_reg_decide_' . $t ); ?>
						<strong><?php echo esc_html__( 'Operator check (after matching on the official portal)', 'nonprofit-donations' ); ?></strong><br>
						<?php if ( 'upi' === $t ) : ?>
							<input name="resolved" class="regular-text" placeholder="<?php echo esc_attr__( 'Name your UPI app shows for this ID', 'nonprofit-donations' ); ?>"><br>
						<?php endif; ?>
						<input name="reason" class="regular-text" placeholder="<?php echo esc_attr__( 'Reason if not matched', 'nonprofit-donations' ); ?>">
						<button class="button button-primary" name="outcome" value="verified"><?php echo esc_html__( 'Matched: verify', 'nonprofit-donations' ); ?></button>
						<button class="button" name="outcome" value="rejected"><?php echo esc_html__( 'Not matched: reject', 'nonprofit-donations' ); ?></button>
					</form>
				<?php endif; ?>
			<?php endforeach; ?>
			<p><a href="https://incometaxindia.gov.in/Pages/utilities/exempted-institutions.aspx" target="_blank" rel="noopener"><?php echo esc_html__( 'Official Income Tax search page', 'nonprofit-donations' ); ?></a></p>
		</div>
		<?php
	}

	/**
	 * NGO submits details. Status becomes pending.
	 */
	public static function submit() {
		$t = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! current_user_can( 'manage_options' ) || ! in_array( $t, self::TYPES, true ) ) {
			wp_die( esc_html__( 'Not allowed.', 'nonprofit-donations' ), 403 );
		}
		check_admin_referer( 'npd_reg_submit_' . $t );
		$rec = self::all()[ $t ];
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$pan = strtoupper( preg_replace( '/\s+/', '', isset( $_POST['pan'] ) ? sanitize_text_field( wp_unslash( $_POST['pan'] ) ) : '' ) );
		$num = isset( $_POST['number'] ) ? sanitize_text_field( wp_unslash( $_POST['number'] ) ) : '';
		$to  = isset( $_POST['valid_to'] ) ? sanitize_text_field( wp_unslash( $_POST['valid_to'] ) ) : '';
		// phpcs:enable
		$err = '';
		if ( 'upi' === $t ) {
			if ( ! NPD_Settings::valid_vpa( $num ) ) {
				$err = __( 'That UPI ID does not look right.', 'nonprofit-donations' );
			} else {
				$rec = array_merge( $rec, array( 'number' => $num, 'status' => 'pending', 'reason' => '', 'resolved' => '', 'checked_at' => '' ) );
				NPD_Settings::save( array( 'upi_vpa' => $num ) );
				self::put( $t, $rec );
				wp_safe_redirect( admin_url( 'admin.php?page=npd-reg' ) );
				exit;
			}
		} elseif ( ! preg_match( '/^[A-Z]{5}[0-9]{4}[A-Z]$/', $pan ) ) {
			$err = __( 'PAN looks wrong.', 'nonprofit-donations' );
		} elseif ( '' === $num ) {
			$err = __( 'Registration number or URN is missing.', 'nonprofit-donations' );
		} elseif ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
			$err = __( 'Validity date is missing.', 'nonprofit-donations' );
		}
		if ( '' === $err && 'upi' !== $t && ! empty( $_FILES['proof']['name'] ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$id = media_handle_upload( 'proof', 0, array(), array( 'mimes' => array( 'pdf' => 'application/pdf' ), 'test_form' => false ) );
			if ( is_wp_error( $id ) ) {
				$err = $id->get_error_message();
			} else {
				$rec['proof_id'] = (int) $id;
			}
		}
		if ( '' === $err && 'upi' !== $t && ! $rec['proof_id'] ) {
			$err = __( 'Please attach the approval order (PDF).', 'nonprofit-donations' );
		}
		if ( '' !== $err ) {
			$rec['reason'] = $err;
			$rec['status'] = 'rejected';
		} else {
			$rec = array_merge( $rec, array( 'pan' => $pan, 'number' => $num, 'valid_to' => $to, 'status' => 'pending', 'reason' => '', 'checked_at' => '' ) );
		}
		self::put( $t, $rec );
		wp_safe_redirect( admin_url( 'admin.php?page=npd-reg' ) );
		exit;
	}

	/**
	 * Operator outcome. Emails the NGO.
	 */
	public static function decide() {
		$t = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! current_user_can( 'manage_options' ) || ! in_array( $t, self::TYPES, true ) ) {
			wp_die( esc_html__( 'Not allowed.', 'nonprofit-donations' ), 403 );
		}
		check_admin_referer( 'npd_reg_decide_' . $t );
		$rec = self::all()[ $t ];
		if ( 'pending' !== $rec['status'] ) {
			wp_safe_redirect( admin_url( 'admin.php?page=npd-reg' ) );
			exit;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$out    = isset( $_POST['outcome'] ) && 'verified' === $_POST['outcome'] ? 'verified' : 'rejected';
		$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		// phpcs:enable
		$resolved = isset( $_POST['resolved'] ) ? sanitize_text_field( wp_unslash( $_POST['resolved'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( 'upi' === $t ) {
			$rec['resolved'] = $resolved;
			if ( 'verified' === $out && '' === $resolved ) {
				$out    = 'rejected';
				$reason = __( 'The name shown by the UPI app was not recorded.', 'nonprofit-donations' );
			}
			if ( 'rejected' === $out && '' === $reason ) {
				$reason = __( 'This UPI ID shows a person\'s name. Please get a bank-issued merchant or current-account UPI ID in the NGO name and submit it again.', 'nonprofit-donations' );
			}
		}
		if ( 'rejected' === $out && '' === $reason ) {
			$reason = __( 'The details did not match the official record.', 'nonprofit-donations' );
		}
		$rec['status']     = $out;
		$rec['reason']     = 'rejected' === $out ? $reason : '';
		$rec['checked_at'] = current_time( 'mysql' );
		self::put( $t, $rec );
		self::notify( $t, $rec );
		wp_safe_redirect( admin_url( 'admin.php?page=npd-reg' ) );
		exit;
	}

	/**
	 * Where notices go: the NGO's contact email, else the site admin.
	 *
	 * @return string
	 */
	private static function mail_to() {
		$m = NPD_Settings::get( 'notify_email' );
		return is_email( $m ) ? $m : get_option( 'admin_email' );
	}

	/**
	 * Daily: tell the NGO about registrations that expire in 30 days or just expired (once each).
	 */
	public static function daily() {
		$sent = get_option( 'npd_reg_warned', array() );
		foreach ( self::all() as $t => $r ) {
			if ( 'verified' !== $r['status'] || '' === $r['valid_to'] ) {
				continue;
			}
			$days = (int) floor( ( strtotime( $r['valid_to'] ) - time() ) / DAY_IN_SECONDS );
			$kind = $days < 0 ? 'expired' : ( $days <= 30 ? 'soon' : '' );
			$key  = $t . ':' . $r['valid_to'] . ':' . $kind;
			if ( '' === $kind || isset( $sent[ $key ] ) ) {
				continue;
			}
			$label = self::label( $t );
			if ( 'expired' === $kind ) {
				/* translators: %s: registration type */
				$msg = sprintf( __( 'Your %s has expired and is no longer shown on your donation page. Renew it and submit the new details in Donations > Registrations.', 'nonprofit-donations' ), $label );
			} else {
				/* translators: 1: registration type, 2: date */
				$msg = sprintf( __( 'Your %1$s is valid until %2$s. Renewal applications are due well before that. Submit the new details in Donations > Registrations when you have them.', 'nonprofit-donations' ), $label, $r['valid_to'] );
			}
			wp_mail( self::mail_to(), $label, $msg );
			$sent[ $key ] = 1;
		}
		update_option( 'npd_reg_warned', $sent, false );
	}

	/**
	 * Email the NGO with the outcome.
	 *
	 * @param string $t   Type.
	 * @param array  $rec Record.
	 */
	private static function notify( $t, $rec ) {
		$to      = self::mail_to();
		$label   = self::label( $t );
		/* translators: %s: registration type */
		$subject = sprintf( __( 'Your %s check result', 'nonprofit-donations' ), $label );
		if ( 'verified' === $rec['status'] ) {
			/* translators: %s: registration type */
			$body = sprintf( __( 'Your %s was matched on the official portal. It now appears on your donation page.', 'nonprofit-donations' ), $label );
		} else {
			/* translators: 1: registration type, 2: reason */
			$body = sprintf( __( 'Your %1$s could not be verified. Reason: %2$s. Open Donations > Registrations to fix it and submit again.', 'nonprofit-donations' ), $label, $rec['reason'] );
		}
		wp_mail( $to, $subject, $body );
	}
}
