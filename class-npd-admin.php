<?php
/**
 * Admin screens: settings, donations, donors, CSV export.
 *
 * @package Nonprofit_Donations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI.
 */
class NPD_Admin {

	/**
	 * Hook in.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_npd_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_npd_verify', array( __CLASS__, 'do_verify' ) );
		add_action( 'admin_post_npd_export', array( __CLASS__, 'export' ) );
	}

	/**
	 * Menu.
	 */
	public static function menu() {
		add_menu_page( __( 'Donations', 'nonprofit-donations' ), __( 'Donations', 'nonprofit-donations' ), 'manage_options', 'npd', array( __CLASS__, 'page_donations' ), 'dashicons-heart', 58 );
		add_submenu_page( 'npd', __( 'Donations', 'nonprofit-donations' ), __( 'Donations', 'nonprofit-donations' ), 'manage_options', 'npd', array( __CLASS__, 'page_donations' ) );
		add_submenu_page( 'npd', __( 'Verify UPI', 'nonprofit-donations' ), __( 'Verify UPI', 'nonprofit-donations' ), 'manage_options', 'npd-verify', array( __CLASS__, 'page_verify' ) );
		add_submenu_page( 'npd', __( 'Donors', 'nonprofit-donations' ), __( 'Donors', 'nonprofit-donations' ), 'manage_options', 'npd-donors', array( __CLASS__, 'page_donors' ) );
		add_submenu_page( 'npd', __( 'Settings', 'nonprofit-donations' ), __( 'Settings', 'nonprofit-donations' ), 'manage_options', 'npd-settings', array( __CLASS__, 'page_settings' ) );
	}

	/**
	 * Format paise as rupees.
	 *
	 * @param int $paise Amount.
	 * @return string
	 */
	private static function rs( $paise ) {
		return 'Rs ' . number_format_i18n( $paise / 100, 2 );
	}

	/**
	 * Prefix cells that spreadsheets would run as formulas.
	 *
	 * @param string $v Cell.
	 * @return string
	 */
	public static function csv_safe( $v ) {
		$v = (string) $v;
		if ( '' !== $v && in_array( $v[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $v;
		}
		return $v;
	}

	/**
	 * Current filters from the query string.
	 *
	 * @return array
	 */
	private static function filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$fy     = isset( $_GET['fy'] ) ? sanitize_text_field( wp_unslash( $_GET['fy'] ) ) : '';
		// phpcs:enable
		return array(
			'status' => in_array( $status, array( 'created', 'pending', 'paid', 'failed' ), true ) ? $status : '',
			'fy'     => preg_match( '/^\d{4}-\d{2}$/', $fy ) ? $fy : '',
		);
	}

	/**
	 * Donations list.
	 */
	public static function page_donations() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$f    = self::filters();
		$rows = NPD_DB::list_donations( array_merge( $f, array( 'limit' => 200 ) ) );
		$fys  = NPD_DB::totals_by_fy();
		$exp  = wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'npd_export' ), $f ), admin_url( 'admin-post.php' ) ), 'npd_export' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Donations', 'nonprofit-donations' ); ?></h1>
			<?php if ( $fys ) : ?>
				<p>
					<?php foreach ( $fys as $r ) : ?>
						<strong><?php echo esc_html( $r->fy ); ?>:</strong> <?php echo esc_html( self::rs( (int) $r->total ) ); ?> (<?php echo esc_html( (int) $r->cnt ); ?>) &nbsp;
					<?php endforeach; ?>
				</p>
			<?php endif; ?>
			<form method="get">
				<input type="hidden" name="page" value="npd">
				<select name="status">
					<option value=""><?php echo esc_html__( 'All statuses', 'nonprofit-donations' ); ?></option>
					<?php foreach ( array( 'paid', 'pending', 'created', 'failed' ) as $s ) : ?>
						<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $f['status'], $s ); ?>><?php echo esc_html( $s ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="text" name="fy" value="<?php echo esc_attr( $f['fy'] ); ?>" placeholder="2026-27" size="8">
				<button class="button"><?php echo esc_html__( 'Filter', 'nonprofit-donations' ); ?></button>
				<a class="button" href="<?php echo esc_url( $exp ); ?>"><?php echo esc_html__( 'Export CSV', 'nonprofit-donations' ); ?></a>
			</form>
			<table class="widefat striped" style="margin-top:1em">
				<thead><tr>
					<th>#</th><th><?php echo esc_html__( 'Date', 'nonprofit-donations' ); ?></th><th><?php echo esc_html__( 'Donor', 'nonprofit-donations' ); ?></th><th><?php echo esc_html__( 'Amount', 'nonprofit-donations' ); ?></th><th><?php echo esc_html__( 'Status', 'nonprofit-donations' ); ?></th><th><?php echo esc_html__( 'Mode', 'nonprofit-donations' ); ?></th><th>80G</th><th><?php echo esc_html__( 'Payment ID', 'nonprofit-donations' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="8"><?php echo esc_html__( 'No donations yet.', 'nonprofit-donations' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<td><?php echo esc_html( $r->id ); ?></td>
						<td><?php echo esc_html( $r->created_at ); ?></td>
						<td><?php echo esc_html( $r->donor_name ); ?><br><small><?php echo esc_html( $r->donor_email ); ?></small></td>
						<td><?php echo esc_html( self::rs( (int) $r->amount_paise ) ); ?></td>
						<td><?php echo esc_html( $r->status ); ?></td>
						<td><?php echo esc_html( $r->mode ); ?></td>
						<td><?php echo $r->want_80g ? esc_html__( 'Yes', 'nonprofit-donations' ) : '-'; ?></td>
						<td><?php echo esc_html( $r->rz_payment_id ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Pending UPI donations: the heart of UPI mode. Match against the bank statement, confirm in one tap.
	 */
	public static function page_verify() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$q     = isset( $_GET['q'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['q'] ) ) ) : '';
		$only  = isset( $_GET['claimed'] );
		$done  = isset( $_GET['done'] ) ? absint( $_GET['done'] ) : 0;
		// phpcs:enable
		$rows  = NPD_DB::list_donations( array( 'status' => 'pending', 'limit' => 500 ) );
		$now   = (int) current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$sum   = 0;
		$show  = array();
		foreach ( $rows as $r ) {
			if ( 'upi' !== $r->mode ) {
				continue;
			}
			$sum += (int) $r->amount_paise;
			if ( $only && ! $r->donor_claimed ) {
				continue;
			}
			if ( '' !== $q ) {
				$hay = strtolower( 'DON-' . $r->id . ' ' . $r->donor_name . ' ' . $r->donor_email . ' ' . $r->utr . ' ' . ( $r->amount_paise / 100 ) );
				if ( false === strpos( $hay, strtolower( $q ) ) ) {
					continue;
				}
			}
			$show[] = $r;
		}
		$act = admin_url( 'admin-post.php' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Verify UPI donations', 'nonprofit-donations' ); ?></h1>
			<?php if ( $done ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
					/* translators: %d: number of donations */
					echo esc_html( sprintf( _n( '%d donation updated.', '%d donations updated.', $done, 'nonprofit-donations' ), $done ) );
					?>
				</p></div>
			<?php endif; ?>
			<p>
				<?php
				/* translators: 1: count, 2: total amount */
				echo esc_html( sprintf( __( '%1$d waiting, %2$s in total.', 'nonprofit-donations' ), count( $rows ), self::rs( $sum ) ) );
				?>
			</p>
			<p class="description"><?php echo esc_html__( 'Open your bank statement. Each gift carries a reference like DON-12 in the payment note. If a credit with the same amount is there, tap Confirm. Receipts go out only after you confirm. Donors do not need to do anything after paying, so some entries may be people who never paid. Leave those or mark them Not received.', 'nonprofit-donations' ); ?></p>
			<form method="get" style="margin:1em 0">
				<input type="hidden" name="page" value="npd-verify">
				<input type="search" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php echo esc_attr__( 'Search DON-12, name, amount, UTR', 'nonprofit-donations' ); ?>">
				<label><input type="checkbox" name="claimed" value="1" <?php checked( $only ); ?>> <?php echo esc_html__( 'Only donors who tapped "I have paid"', 'nonprofit-donations' ); ?></label>
				<button class="button"><?php echo esc_html__( 'Filter', 'nonprofit-donations' ); ?></button>
			</form>
			<form method="post" action="<?php echo esc_url( $act ); ?>">
				<input type="hidden" name="action" value="npd_verify">
				<?php wp_nonce_field( 'npd_verify' ); ?>
				<p>
					<button class="button button-primary" name="do" value="confirm"><?php echo esc_html__( 'Confirm selected', 'nonprofit-donations' ); ?></button>
					<button class="button" name="do" value="reject"><?php echo esc_html__( 'Mark selected not received', 'nonprofit-donations' ); ?></button>
				</p>
				<table class="widefat striped">
					<thead><tr>
						<td class="check-column"><input type="checkbox" onclick="document.querySelectorAll('.npd-sel').forEach(function(c){c.checked=this.checked}.bind(this))"></td>
						<th><?php echo esc_html__( 'Ref', 'nonprofit-donations' ); ?></th><th><?php echo esc_html__( 'Amount', 'nonprofit-donations' ); ?></th><th><?php echo esc_html__( 'Donor', 'nonprofit-donations' ); ?></th><th><?php echo esc_html__( 'When', 'nonprofit-donations' ); ?></th><th><?php echo esc_html__( 'Donor says', 'nonprofit-donations' ); ?></th><th>UTR</th>
					</tr></thead>
					<tbody>
					<?php if ( ! $show ) : ?>
						<tr><td colspan="7"><?php echo esc_html__( 'Nothing waiting for verification.', 'nonprofit-donations' ); ?></td></tr>
					<?php endif; ?>
					<?php
					foreach ( $show as $r ) :
						$age = $now - (int) strtotime( $r->created_at ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions
						?>
						<tr>
							<th scope="row" class="check-column"><input class="npd-sel" type="checkbox" name="ids[]" value="<?php echo esc_attr( $r->id ); ?>"></th>
							<td><strong>DON-<?php echo esc_html( $r->id ); ?></strong></td>
							<td><strong><?php echo esc_html( self::rs( (int) $r->amount_paise ) ); ?></strong></td>
							<td><?php echo esc_html( $r->donor_name ); ?><br><small><?php echo esc_html( $r->donor_email ); ?><?php echo $r->donor_phone ? ' / ' . esc_html( $r->donor_phone ) : ''; ?></small></td>
							<td><?php echo esc_html( $r->created_at ); ?><br><small>
								<?php
								/* translators: %s: time span like "2 hours" */
								echo esc_html( sprintf( __( '%s ago', 'nonprofit-donations' ), human_time_diff( $now - max( 0, $age ), $now ) ) );
								?>
							</small></td>
							<td><?php echo $r->donor_claimed ? '<span style="color:#15803d">' . esc_html__( 'I have paid', 'nonprofit-donations' ) . '</span>' : '<span style="color:#6b7280">' . esc_html__( 'no action', 'nonprofit-donations' ) . '</span>'; ?></td>
							<td><code><?php echo esc_html( $r->utr ); ?></code></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle bulk confirm or reject.
	 */
	public static function do_verify() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'nonprofit-donations' ), 403 );
		}
		check_admin_referer( 'npd_verify' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$do  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
		// phpcs:enable
		$n = 0;
		foreach ( array_unique( $ids ) as $id ) {
			if ( 'confirm' === $do && NPD_DB::verify_upi( $id ) ) {
				++$n;
			} elseif ( 'reject' === $do && NPD_DB::reject_upi( $id ) ) {
				++$n;
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=npd-verify&done=' . $n ) );
		exit;
	}

	/**
	 * Donors summary.
	 */
	public static function page_donors() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$rows = NPD_DB::donor_summary();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Donors', 'nonprofit-donations' ); ?></h1>
			<table class="widefat striped">
				<thead><tr><th><?php echo esc_html__( 'Name', 'nonprofit-donations' ); ?></th><th><?php echo esc_html__( 'Email', 'nonprofit-donations' ); ?></th><th><?php echo esc_html__( 'Phone', 'nonprofit-donations' ); ?></th><th><?php echo esc_html__( 'Gifts', 'nonprofit-donations' ); ?></th><th><?php echo esc_html__( 'Total', 'nonprofit-donations' ); ?></th></tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="5"><?php echo esc_html__( 'No donors yet.', 'nonprofit-donations' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<td><?php echo esc_html( $r->name ); ?></td>
						<td><?php echo esc_html( $r->email ); ?></td>
						<td><?php echo esc_html( $r->phone ); ?></td>
						<td><?php echo esc_html( (int) $r->gifts ); ?></td>
						<td><?php echo esc_html( self::rs( (int) $r->total ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Settings page.
	 */
	public static function page_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = NPD_Settings::all();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$saved = isset( $_GET['saved'] );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Donation settings', 'nonprofit-donations' ); ?></h1>
			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Settings saved.', 'nonprofit-donations' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="npd_save">
				<?php wp_nonce_field( 'npd_save' ); ?>
				<table class="form-table" role="presentation">
					<tr><th><label for="npd_mail"><?php echo esc_html__( 'Contact email for notices', 'nonprofit-donations' ); ?></label></th><td><input id="npd_mail" type="email" class="regular-text" name="notify_email" value="<?php echo esc_attr( $s['notify_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"><p class="description"><?php echo esc_html__( 'We send registration check results and expiry notices here.', 'nonprofit-donations' ); ?></p></td></tr>
					<tr><th><label for="npd_org"><?php echo esc_html__( 'Organisation name', 'nonprofit-donations' ); ?></label></th><td><input id="npd_org" class="regular-text" name="org_name" value="<?php echo esc_attr( $s['org_name'] ); ?>"></td></tr>
					<tr><th><label for="npd_vpa"><?php echo esc_html__( 'UPI ID (VPA)', 'nonprofit-donations' ); ?></label></th><td><input id="npd_vpa" class="regular-text" name="upi_vpa" value="<?php echo esc_attr( $s['upi_vpa'] ); ?>" placeholder="yourngo@bank" autocomplete="off">
						<p class="description"><?php echo esc_html__( 'Your NGO bank account UPI ID. Donors pay it directly, no fees. You confirm each gift against your bank statement.', 'nonprofit-donations' ); ?></p></td></tr>
					<tr><th><label for="npd_upn"><?php echo esc_html__( 'Name shown in UPI app', 'nonprofit-donations' ); ?></label></th><td><input id="npd_upn" class="regular-text" name="upi_name" value="<?php echo esc_attr( $s['upi_name'] ); ?>"></td></tr>
					<tr><th><?php echo esc_html__( '80G receipts', 'nonprofit-donations' ); ?></th><td><label><input type="checkbox" name="is_80g" value="1" <?php checked( $s['is_80g'], 1 ); ?>> <?php echo esc_html__( 'We hold 80G registration; offer donors an 80G receipt option (asks for PAN).', 'nonprofit-donations' ); ?></label></td></tr>
					<tr><th><label for="npd_amt"><?php echo esc_html__( 'Preset amounts (Rs)', 'nonprofit-donations' ); ?></label></th><td><input id="npd_amt" class="regular-text" name="amounts" value="<?php echo esc_attr( $s['amounts'] ); ?>"><p class="description"><?php echo esc_html__( 'Comma separated, like 500,1000,2500.', 'nonprofit-donations' ); ?></p></td></tr>
					<tr><th><?php echo esc_html__( 'On uninstall', 'nonprofit-donations' ); ?></th><td><label><input type="checkbox" name="delete_on_uninstall" value="1" <?php checked( $s['delete_on_uninstall'], 1 ); ?>> <?php echo esc_html__( 'Delete all donation data when the plugin is deleted', 'nonprofit-donations' ); ?></label></td></tr>
				</table>
				<details style="margin:1em 0"><summary><strong><?php echo esc_html__( 'Need card payments? (optional)', 'nonprofit-donations' ); ?></strong></summary>
				<p class="description"><?php echo esc_html__( 'Most NGOs do not need this. UPI direct goes straight to your bank with no cut. Turning on Razorpay sends donors through your own Razorpay account instead, with their fees.', 'nonprofit-donations' ); ?></p>
				<table class="form-table" role="presentation">
					<tr><th><label for="npd_mode"><?php echo esc_html__( 'Mode', 'nonprofit-donations' ); ?></label></th><td>
						<select id="npd_mode" name="mode">
							<option value="upi" <?php selected( $s['mode'], 'upi' ); ?>><?php echo esc_html__( 'UPI direct (zero fees)', 'nonprofit-donations' ); ?></option>
							<option value="test" <?php selected( $s['mode'], 'test' ); ?>><?php echo esc_html__( 'Razorpay test keys', 'nonprofit-donations' ); ?></option>
							<option value="live" <?php selected( $s['mode'], 'live' ); ?>><?php echo esc_html__( 'Live', 'nonprofit-donations' ); ?></option>
						</select></td></tr>
					<tr><th><label for="npd_key"><?php echo esc_html__( 'Razorpay Key ID', 'nonprofit-donations' ); ?></label></th><td><input id="npd_key" class="regular-text" name="key_id" value="<?php echo esc_attr( $s['key_id'] ); ?>" autocomplete="off"></td></tr>
					<tr><th><label for="npd_secret"><?php echo esc_html__( 'Razorpay Key Secret', 'nonprofit-donations' ); ?></label></th><td><input id="npd_secret" type="password" class="regular-text" name="key_secret" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $s['key_secret_enc'] ? __( 'Saved (leave blank to keep)', 'nonprofit-donations' ) : '' ); ?>"></td></tr>
					<tr><th><label for="npd_wh"><?php echo esc_html__( 'Webhook secret', 'nonprofit-donations' ); ?></label></th><td><input id="npd_wh" type="password" class="regular-text" name="webhook_secret" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $s['webhook_secret_enc'] ? __( 'Saved (leave blank to keep)', 'nonprofit-donations' ) : '' ); ?>">
						<p class="description"><?php echo esc_html__( 'Webhook URL to add in Razorpay (events payment.captured and order.paid):', 'nonprofit-donations' ); ?> <code><?php echo esc_html( rest_url( NPD_REST::NS . '/webhook' ) ); ?></code></p></td></tr>
				</table></details>
				<h2><?php echo esc_html__( '80G receipts and filing', 'nonprofit-donations' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th><label for="npd_oa"><?php echo esc_html__( 'Organisation address (on receipts)', 'nonprofit-donations' ); ?></label></th><td><textarea id="npd_oa" class="large-text" rows="3" name="org_address"><?php echo esc_textarea( $s['org_address'] ); ?></textarea></td></tr>
					<tr><th><label for="npd_pa"><?php echo esc_html__( 'Pre-ARN numbers (unused)', 'nonprofit-donations' ); ?></label></th><td><textarea id="npd_pa" class="large-text code" rows="4" name="pre_arns"><?php echo esc_textarea( $s['pre_arns'] ); ?></textarea>
						<p class="description"><?php echo esc_html__( 'Paste the Pre-ARNs you generated on the income tax portal, one per line. Each receipt uses the next one. Empty is fine: receipts then go without one.', 'nonprofit-donations' ); ?></p></td></tr>
					<tr><th><?php echo esc_html__( 'Filing sheet defaults', 'nonprofit-donations' ); ?></th><td>
						<input name="f113_id_code" placeholder="ID Code" value="<?php echo esc_attr( $s['f113_id_code'] ); ?>"> <input name="f113_section" placeholder="Section Code" value="<?php echo esc_attr( $s['f113_section'] ); ?>"> <input name="f113_type" placeholder="Donation Type" value="<?php echo esc_attr( $s['f113_type'] ); ?>"> <input name="f113_mode" placeholder="Mode of receipt" value="<?php echo esc_attr( $s['f113_mode'] ); ?>">
						<p class="description"><?php echo esc_html__( 'Type the exact dropdown values from the portal template. We do not guess them. Check the sheet against the portal template before you upload.', 'nonprofit-donations' ); ?></p>
						<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=npd_export113&fy=' . rawurlencode( NPD_DB::fy_for( current_time( 'mysql' ) ) ) ), 'npd_export113' ) ); ?>"><?php echo esc_html__( 'Download filing sheet (this financial year)', 'nonprofit-donations' ); ?></a></p></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
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
		check_admin_referer( 'npd_save' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'upi';
		$new  = array(
			'mode'                => in_array( $mode, array( 'upi', 'test', 'live' ), true ) ? $mode : 'upi',
			'notify_email'        => isset( $_POST['notify_email'] ) ? sanitize_email( wp_unslash( $_POST['notify_email'] ) ) : '',
			'org_name'            => isset( $_POST['org_name'] ) ? sanitize_text_field( wp_unslash( $_POST['org_name'] ) ) : '',
			'upi_vpa'             => isset( $_POST['upi_vpa'] ) && NPD_Settings::valid_vpa( trim( sanitize_text_field( wp_unslash( $_POST['upi_vpa'] ) ) ) ) ? trim( sanitize_text_field( wp_unslash( $_POST['upi_vpa'] ) ) ) : '',
			'upi_name'            => isset( $_POST['upi_name'] ) ? sanitize_text_field( wp_unslash( $_POST['upi_name'] ) ) : '',
			'key_id'              => isset( $_POST['key_id'] ) ? sanitize_text_field( wp_unslash( $_POST['key_id'] ) ) : '',
			'is_80g'              => empty( $_POST['is_80g'] ) ? 0 : 1,
			'org_address'         => isset( $_POST['org_address'] ) ? sanitize_textarea_field( wp_unslash( $_POST['org_address'] ) ) : '',
			'pre_arns'            => isset( $_POST['pre_arns'] ) ? preg_replace( '/[^A-Za-z0-9\-_\/\n]/', '', str_replace( array( ' ', ',', "\r" ), "\n", sanitize_textarea_field( wp_unslash( $_POST['pre_arns'] ) ) ) ) : '',
			'f113_id_code'        => isset( $_POST['f113_id_code'] ) ? sanitize_text_field( wp_unslash( $_POST['f113_id_code'] ) ) : '',
			'f113_section'        => isset( $_POST['f113_section'] ) ? sanitize_text_field( wp_unslash( $_POST['f113_section'] ) ) : '',
			'f113_type'           => isset( $_POST['f113_type'] ) ? sanitize_text_field( wp_unslash( $_POST['f113_type'] ) ) : '',
			'f113_mode'           => isset( $_POST['f113_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['f113_mode'] ) ) : '',
			'amounts'             => isset( $_POST['amounts'] ) ? preg_replace( '/[^0-9,]/', '', sanitize_text_field( wp_unslash( $_POST['amounts'] ) ) ) : '500,1000,2500',
			'delete_on_uninstall' => empty( $_POST['delete_on_uninstall'] ) ? 0 : 1,
		);
		if ( ! empty( $_POST['key_secret'] ) ) {
			$new['key_secret_enc'] = NPD_Settings::encrypt( sanitize_text_field( wp_unslash( $_POST['key_secret'] ) ) );
		}
		if ( ! empty( $_POST['webhook_secret'] ) ) {
			$new['webhook_secret_enc'] = NPD_Settings::encrypt( sanitize_text_field( wp_unslash( $_POST['webhook_secret'] ) ) );
		}
		// phpcs:enable
		NPD_Settings::save( $new );
		wp_safe_redirect( admin_url( 'admin.php?page=npd-settings&saved=1' ) );
		exit;
	}

	/**
	 * CSV export (no PAN, formula-safe).
	 */
	public static function export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'nonprofit-donations' ), 403 );
		}
		check_admin_referer( 'npd_export' );
		$rows = NPD_DB::list_donations( array_merge( self::filters(), array( 'limit' => 100000 ) ) );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="donations-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $out, array( 'id', 'date', 'name', 'email', 'phone', 'amount_inr', 'status', 'mode', 'utr', 'fy', 'campaign', 'want_80g', 'payment_id' ) );
		foreach ( $rows as $r ) {
			fputcsv(
				$out,
				array(
					$r->id,
					$r->created_at,
					self::csv_safe( $r->donor_name ),
					self::csv_safe( $r->donor_email ),
					self::csv_safe( $r->donor_phone ),
					number_format( $r->amount_paise / 100, 2, '.', '' ),
					$r->status,
					$r->mode,
					self::csv_safe( $r->utr ),
					$r->fy,
					self::csv_safe( $r->campaign ),
					$r->want_80g ? 'yes' : 'no',
					self::csv_safe( $r->rz_payment_id ),
				)
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
}
