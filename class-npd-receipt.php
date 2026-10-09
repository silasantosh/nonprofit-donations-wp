<?php
/**
 * 80G receipt PDF, Pre-ARN use, and the Form 113 helper CSV.
 *
 * @package NonprofitDonations
 */

defined( 'ABSPATH' ) || exit;

/**
 * Receipts and filing helper.
 */
class NPD_Receipt {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'npd_donation_paid', array( __CLASS__, 'issue' ) );
		add_action( 'admin_post_npd_export113', array( __CLASS__, 'export113' ) );
		add_action( 'admin_post_npd_resend', array( __CLASS__, 'resend' ) );
		add_action( 'admin_post_npd_testmail', array( __CLASS__, 'test_mail' ) );
	}

	/**
	 * Load donation with donor.
	 *
	 * @param int $id Donation id.
	 * @return object|null
	 */
	private static function load( $id ) {
		global $wpdb;
		$d  = NPD_DB::table( 'donations' );
		$r  = NPD_DB::table( 'donors' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT d.*, r.name AS donor_name, r.email AS donor_email, r.address AS donor_address, r.pan_enc FROM {$d} d LEFT JOIN {$r} r ON r.id = d.donor_id WHERE d.id = %d", $id ) );
	}

	/**
	 * Is the NGO allowed to issue 80G receipts right now?
	 *
	 * @return array|null The verified 80G record, or null.
	 */
	private static function reg80g() {
		$all = NPD_Reg::all();
		if ( empty( $all['80g'] ) || 'verified' !== NPD_Reg::status( $all['80g'] ) ) {
			return null;
		}
		return $all['80g'];
	}

	/**
	 * Issue and email the receipt for a confirmed donation.
	 *
	 * @param int  $id    Donation id.
	 * @param bool $force Send again even if already sent.
	 * @return bool
	 */
	public static function issue( $id, $force = false ) {
		global $wpdb;
		$row = self::load( (int) $id );
		if ( ! $row || 'paid' !== $row->status || ! $row->want_80g || ! is_email( $row->donor_email ) ) {
			return false;
		}
		if ( ! $force && ! empty( $row->receipt_sent_at ) ) {
			return false;
		}
		$reg = self::reg80g();
		if ( ! $reg ) {
			return false; // No verified 80G registration, no 80G receipt.
		}
		$t = NPD_DB::table( 'donations' );
		if ( '' === $row->receipt_no ) {
			$row->receipt_no = 'R-' . $row->fy . '-' . $row->id;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET receipt_no = %s WHERE id = %d", $row->receipt_no, $row->id ) );
		}
		if ( '' === $row->pre_arn ) {
			$row->pre_arn = self::take_pre_arn();
			if ( '' !== $row->pre_arn ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET pre_arn = %s WHERE id = %d", $row->pre_arn, $row->id ) );
			}
		}
		$pdf  = self::build_pdf( $row, $reg );
		$dir  = get_temp_dir();
		$file = trailingslashit( $dir ) . 'receipt-' . $row->id . '-' . wp_generate_password( 6, false ) . '.pdf';
		// phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === file_put_contents( $file, $pdf ) ) {
			return false;
		}
		$org  = NPD_Settings::get( 'org_name' );
		$sent = wp_mail(
			$row->donor_email,
			/* translators: %s: organisation name */
			sprintf( __( 'Your donation receipt from %s', 'nonprofit-donations' ), $org ),
			/* translators: 1: donor name, 2: organisation name */
			sprintf( __( "Dear %1\$s,\n\nThank you for your donation to %2\$s. Your receipt is attached.\n\nWith thanks,\n%2\$s", 'nonprofit-donations' ), $row->donor_name, $org ),
			'',
			array( $file )
		);
		wp_delete_file( $file );
		if ( $sent ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET receipt_sent_at = %s WHERE id = %d", current_time( 'mysql' ), $row->id ) );
		}
		return (bool) $sent;
	}

	/**
	 * Admin: send a clearly marked TEST receipt to the notice address, to prove mail delivery.
	 */
	public static function test_mail() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'nonprofit-donations' ), 403 );
		}
		check_admin_referer( 'npd_testmail' );
		$to  = (string) NPD_Settings::get( 'notify_email' );
		$ok  = false;
		if ( is_email( $to ) ) {
			$row = (object) array(
				'id'            => 0,
				'receipt_no'    => 'TEST-0000',
				'paid_at'       => current_time( 'mysql' ),
				'pre_arn'       => '',
				'donor_name'    => 'TEST ONLY - not a real donor',
				'donor_address' => 'Sample address for a test receipt',
				'pan_enc'       => '',
				'amount_paise'  => 100000,
				'mode'          => 'upi',
				'utr'           => '',
			);
			$reg = array(
				'number'   => 'TEST-NOT-A-REAL-NUMBER',
				'valid_to' => '',
				'pan'      => '',
			);
			$file = trailingslashit( get_temp_dir() ) . 'receipt-test-' . wp_generate_password( 6, false ) . '.pdf';
			// phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false !== file_put_contents( $file, self::build_pdf( $row, $reg ) ) ) {
				$ok = wp_mail(
					$to,
					__( 'TEST: sample donation receipt', 'nonprofit-donations' ),
					__( "This is a test email from the Nonprofit Donations plugin. It proves that this website can send email. The attached receipt is a sample with made-up details. It is not a real receipt.", 'nonprofit-donations' ),
					'',
					array( $file )
				);
				wp_delete_file( $file );
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=npd-settings&testmail=' . ( $ok ? 'sent' : 'failed' ) ) );
		exit;
	}

	/**
	 * Admin: send a receipt again.
	 */
	public static function resend() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'nonprofit-donations' ), 403 );
		}
		check_admin_referer( 'npd_resend' );
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$ok = $id ? self::issue( $id, true ) : false;
		wp_safe_redirect( admin_url( 'admin.php?page=nonprofit-donations&receipt=' . ( $ok ? 'sent' : 'failed' ) ) );
		exit;
	}

	/**
	 * Take the next unused Pre-ARN from the pasted list.
	 *
	 * @return string
	 */
	private static function take_pre_arn() {
		$s    = NPD_Settings::all();
		$list = preg_split( '/[\s,]+/', (string) $s['pre_arns'], -1, PREG_SPLIT_NO_EMPTY );
		if ( ! $list ) {
			return '';
		}
		$first = array_shift( $list );
		$s['pre_arns'] = implode( "\n", $list );
		NPD_Settings::save( $s );
		return sanitize_text_field( $first );
	}

	/**
	 * Amount in words, Indian grouping.
	 *
	 * @param int $n Whole rupees.
	 * @return string
	 */
	public static function words( $n ) {
		$n = (int) $n;
		if ( $n <= 0 ) {
			return 'Zero';
		}
		$out = '';
		foreach ( array( 10000000 => 'Crore', 100000 => 'Lakh', 1000 => 'Thousand', 100 => 'Hundred' ) as $v => $name ) {
			if ( $n >= $v ) {
				$q    = intdiv( $n, $v );
				$out .= self::words( $q ) . ' ' . $name . ' ';
				$n   -= $q * $v;
			}
		}
		if ( $n > 0 ) {
			$ones = array( '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen' );
			$tens = array( '', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety' );
			if ( $n < 20 ) {
				$out .= $ones[ $n ];
			} else {
				$out .= $tens[ intdiv( $n, 10 ) ] . ( $n % 10 ? ' ' . $ones[ $n % 10 ] : '' );
			}
		}
		return trim( $out );
	}

	/**
	 * Make text safe for a standard PDF font.
	 *
	 * @param string $t Text.
	 * @return string
	 */
	private static function clean( $t ) {
		$t = remove_accents( (string) $t );
		$t = preg_replace( '/[^\x20-\x7E]/', '?', $t );
		return str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $t );
	}

	/**
	 * Build a one-page A4 PDF (no library, standard Helvetica).
	 *
	 * @param object $row Donation with donor.
	 * @param array  $reg 80G record.
	 * @return string
	 */
	private static function build_pdf( $row, $reg ) {
		$s     = NPD_Settings::all();
		$pan   = NPD_Settings::decrypt( (string) $row->pan_enc );
		$rs    = (int) floor( $row->amount_paise / 100 );
		$lines = array();
		$y     = 790;
		$add   = function ( $size, $bold, $text ) use ( &$lines, &$y ) {
			foreach ( explode( "\n", wordwrap( (string) $text, (int) ( 500 / ( $size * 0.5 ) ), "\n", true ) ) as $ln ) {
				$lines[] = array( $size, $bold, $y, $ln );
				$y      -= $size + 6;
			}
		};
		$add( 18, 1, $s['org_name'] );
		if ( '' !== $s['org_address'] ) {
			$add( 10, 0, $s['org_address'] );
		}
		if ( ! empty( $reg['pan'] ) ) {
			$add( 10, 0, 'PAN: ' . $reg['pan'] );
		}
		$y -= 10;
		$add( 15, 1, 'DONATION RECEIPT' );
		$y -= 6;
		$add( 11, 0, 'Receipt no: ' . $row->receipt_no );
		$add( 11, 0, 'Date: ' . gmdate( 'd-m-Y', strtotime( $row->paid_at ) ) );
		if ( '' !== $row->pre_arn ) {
			$add( 11, 0, 'Pre-ARN: ' . $row->pre_arn );
		}
		$y -= 8;
		$add( 11, 1, 'Received with thanks from' );
		$add( 11, 0, 'Name: ' . $row->donor_name );
		if ( '' !== (string) $row->donor_address ) {
			$add( 11, 0, 'Address: ' . $row->donor_address );
		}
		if ( '' !== $pan ) {
			$add( 11, 0, 'PAN: ' . $pan );
		}
		$y -= 8;
		$add( 13, 1, 'Amount: Rs. ' . number_format( $rs ) . '/-' );
		$add( 11, 0, 'Rupees ' . self::words( $rs ) . ' only' );
		$add( 11, 0, 'Mode: ' . ( 'upi' === $row->mode ? 'UPI' : 'Online (Razorpay)' ) . ( '' !== $row->utr ? ', ref ' . $row->utr : '' ) . ', donation ' . $row->id );
		$y -= 8;
		$add( 11, 1, 'Registration' );
		$add( 10, 0, 'This organisation has shown the following approval for 80G donations: no. ' . $reg['number'] . ( '' !== $reg['valid_to'] ? ', valid up to ' . $reg['valid_to'] : '' ) . '.' );
		$y -= 12;
		$add( 9, 0, 'The deduction, if any, is as per the approval above and the Income-tax law in force. Please keep this receipt for your records.' );

		$c = "0.5 w 40 40 515 780 re S\n";
		foreach ( $lines as $l ) {
			$c .= sprintf( "BT /F%d %d Tf 52 %d Td (%s) Tj ET\n", $l[1] ? 2 : 1, $l[0], $l[2], self::clean( $l[3] ) );
		}
		$objs   = array();
		$objs[] = '<< /Type /Catalog /Pages 2 0 R >>';
		$objs[] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
		$objs[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> >>';
		$objs[] = '<< /Length ' . strlen( $c ) . " >>\nstream\n" . $c . 'endstream';
		$objs[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		$objs[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
		$pdf    = "%PDF-1.4\n";
		$off    = array();
		foreach ( $objs as $i => $o ) {
			$off[] = strlen( $pdf );
			$pdf  .= ( $i + 1 ) . " 0 obj\n" . $o . "\nendobj\n";
		}
		$x    = strlen( $pdf );
		$pdf .= "xref\n0 " . ( count( $objs ) + 1 ) . "\n0000000000 65535 f \n";
		foreach ( $off as $o ) {
			$pdf .= sprintf( "%010d 00000 n \n", $o );
		}
		$pdf .= 'trailer << /Size ' . ( count( $objs ) + 1 ) . ' /Root 1 0 R >>' . "\nstartxref\n" . $x . "\n%%EOF";
		return $pdf;
	}

	/**
	 * Form 113 helper CSV: 12 columns in the portal template order. Check against the portal's current template before upload.
	 */
	public static function export113() {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'nonprofit-donations' ), 403 );
		}
		check_admin_referer( 'npd_export113' );
		$fy = isset( $_GET['fy'] ) ? sanitize_text_field( wp_unslash( $_GET['fy'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $fy ) ) {
			$fy = NPD_DB::fy_for( current_time( 'mysql' ) );
		}
		$s   = NPD_Settings::all();
		$reg = NPD_Reg::all();
		$urn = ! empty( $reg['80g']['number'] ) ? $reg['80g']['number'] : '';
		$d   = NPD_DB::table( 'donations' );
		$r   = NPD_DB::table( 'donors' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT d.id, d.donor_id, d.amount_paise, d.paid_at, d.pre_arn, r.name, r.address, r.pan_enc FROM {$d} d JOIN {$r} r ON r.id = d.donor_id WHERE d.status = 'paid' AND d.want_80g = 1 AND d.fy = %s ORDER BY d.paid_at ASC LIMIT 25000", $fy ) );
		$out_rows = array();
		$agg      = array();
		foreach ( $rows as $x ) {
			$pan = NPD_Settings::decrypt( (string) $x->pan_enc );
			if ( '' !== $x->pre_arn ) {
				$out_rows[] = array( $x->pre_arn, $pan, $x->paid_at, $x->name, $x->address, (int) $x->amount_paise );
				continue;
			}
			$k = $x->donor_id;
			if ( ! isset( $agg[ $k ] ) ) {
				$agg[ $k ] = array( '', $pan, $x->paid_at, $x->name, $x->address, 0 );
			}
			$agg[ $k ][5] += (int) $x->amount_paise;
		}
		$out_rows = array_merge( $out_rows, array_values( $agg ) );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="form113-helper-' . $fy . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $out, array( 'Sl. No.', 'Pre Acknowledgement Number', 'ID Code', 'Unique Identification Number', 'Section Code', 'Unique Registration Number', 'Date of Issuance', 'Name of donor', 'Address of donor', 'Donation Type', 'Mode of receipt', 'Amount of donation (INR)' ) );
		$i = 0;
		foreach ( $out_rows as $o ) {
			++$i;
			fputcsv(
				$out,
				array(
					$i,
					NPD_Admin::csv_safe( $o[0] ),
					NPD_Admin::csv_safe( $s['f113_id_code'] ),
					NPD_Admin::csv_safe( $o[1] ),
					NPD_Admin::csv_safe( $s['f113_section'] ),
					NPD_Admin::csv_safe( $urn ),
					gmdate( 'd/m/Y', strtotime( $o[2] ) ),
					NPD_Admin::csv_safe( $o[3] ),
					NPD_Admin::csv_safe( $o[4] ),
					NPD_Admin::csv_safe( $s['f113_type'] ),
					NPD_Admin::csv_safe( $s['f113_mode'] ),
					number_format( $o[5] / 100, 2, '.', '' ),
				)
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
}
