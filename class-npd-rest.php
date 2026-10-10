<?php
/**
 * REST routes: donate, confirm, webhook.
 *
 * @package Nonprofit_Donations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public donation endpoints. Payment is marked paid only after a signature check
 * or a verified webhook.
 */
class NPD_REST {

	const NS         = 'nonprofit-donations/v1';
	const MIN_RUPEES = 1;

	/**
	 * Indian states and union territories.
	 *
	 * @return string[]
	 */
	/** Suggestions only: any city typed by the donor is accepted. */
	public static function cities() {
		return array( 'Mumbai', 'Delhi', 'New Delhi', 'Bengaluru', 'Hyderabad', 'Ahmedabad', 'Chennai', 'Kolkata', 'Pune', 'Jaipur', 'Surat', 'Lucknow', 'Kanpur', 'Nagpur', 'Indore', 'Thane', 'Bhopal', 'Visakhapatnam', 'Patna', 'Vadodara', 'Ghaziabad', 'Ludhiana', 'Agra', 'Nashik', 'Faridabad', 'Meerut', 'Rajkot', 'Varanasi', 'Srinagar', 'Aurangabad', 'Dhanbad', 'Amritsar', 'Navi Mumbai', 'Prayagraj', 'Ranchi', 'Howrah', 'Coimbatore', 'Jabalpur', 'Gwalior', 'Vijayawada', 'Jodhpur', 'Madurai', 'Raipur', 'Kota', 'Guwahati', 'Chandigarh', 'Solapur', 'Hubli', 'Mysuru', 'Tiruchirappalli', 'Bareilly', 'Aligarh', 'Tiruppur', 'Gurugram', 'Moradabad', 'Jalandhar', 'Bhubaneswar', 'Salem', 'Warangal', 'Guntur', 'Bhiwandi', 'Saharanpur', 'Gorakhpur', 'Bikaner', 'Amravati', 'Noida', 'Jamshedpur', 'Bhilai', 'Cuttack', 'Firozabad', 'Kochi', 'Thiruvananthapuram', 'Kozhikode', 'Thrissur', 'Dehradun', 'Durgapur', 'Asansol', 'Nanded', 'Kolhapur', 'Ajmer', 'Gulbarga', 'Jamnagar', 'Ujjain', 'Loni', 'Siliguri', 'Jhansi', 'Ulhasnagar', 'Nellore', 'Jammu', 'Belagavi', 'Mangaluru', 'Tirunelveli', 'Gaya', 'Udaipur', 'Mathura', 'Bokaro', 'Rourkela', 'Shimla', 'Panaji', 'Puducherry', 'Imphal', 'Shillong', 'Gangtok', 'Itanagar', 'Aizawl', 'Kohima', 'Agartala', 'Port Blair', 'Tirupati', 'Rajahmundry', 'Kakinada', 'Karimnagar', 'Nizamabad', 'Vellore', 'Erode', 'Thanjavur', 'Panipat', 'Karnal', 'Rohtak', 'Hisar', 'Patiala', 'Bathinda', 'Haridwar', 'Roorkee', 'Muzaffarpur', 'Bhagalpur', 'Darbhanga', 'Sambalpur', 'Berhampur', 'Bilaspur', 'Anand', 'Bhavnagar', 'Gandhinagar', 'Sangli', 'Satara', 'Latur', 'Akola', 'Dhule', 'Jalgaon', 'Ratlam', 'Sagar', 'Satna', 'Rewa', 'Alwar', 'Bhilwara', 'Sikar' );
	}

	/** City suggestions per state (suggestions only). */
	public static function cities_by_state() {
		return array( 'Maharashtra' => array( 'Mumbai', 'Pune', 'Nagpur', 'Thane', 'Nashik', 'Navi Mumbai', 'Aurangabad', 'Solapur', 'Bhiwandi', 'Amravati', 'Nanded', 'Kolhapur', 'Ulhasnagar', 'Sangli', 'Satara', 'Latur', 'Akola', 'Dhule', 'Jalgaon', 'Loni' ), 'Delhi' => array( 'Delhi', 'New Delhi' ), 'Karnataka' => array( 'Bengaluru', 'Hubli', 'Mysuru', 'Belagavi', 'Mangaluru', 'Gulbarga' ), 'Telangana' => array( 'Hyderabad', 'Warangal', 'Karimnagar', 'Nizamabad' ), 'Gujarat' => array( 'Ahmedabad', 'Surat', 'Vadodara', 'Rajkot', 'Jamnagar', 'Bhavnagar', 'Gandhinagar', 'Anand' ), 'Tamil Nadu' => array( 'Chennai', 'Coimbatore', 'Madurai', 'Tiruchirappalli', 'Tiruppur', 'Salem', 'Tirunelveli', 'Vellore', 'Erode', 'Thanjavur' ), 'West Bengal' => array( 'Kolkata', 'Howrah', 'Durgapur', 'Asansol', 'Siliguri' ), 'Rajasthan' => array( 'Jaipur', 'Jodhpur', 'Kota', 'Bikaner', 'Ajmer', 'Udaipur', 'Alwar', 'Bhilwara', 'Sikar' ), 'Uttar Pradesh' => array( 'Lucknow', 'Kanpur', 'Ghaziabad', 'Agra', 'Meerut', 'Varanasi', 'Prayagraj', 'Bareilly', 'Aligarh', 'Moradabad', 'Saharanpur', 'Gorakhpur', 'Noida', 'Firozabad', 'Jhansi', 'Mathura' ), 'Madhya Pradesh' => array( 'Indore', 'Bhopal', 'Jabalpur', 'Gwalior', 'Ujjain', 'Ratlam', 'Sagar', 'Satna', 'Rewa' ), 'Andhra Pradesh' => array( 'Visakhapatnam', 'Vijayawada', 'Guntur', 'Nellore', 'Tirupati', 'Rajahmundry', 'Kakinada' ), 'Bihar' => array( 'Patna', 'Gaya', 'Muzaffarpur', 'Bhagalpur', 'Darbhanga' ), 'Punjab' => array( 'Ludhiana', 'Amritsar', 'Jalandhar', 'Patiala', 'Bathinda' ), 'Haryana' => array( 'Faridabad', 'Gurugram', 'Panipat', 'Karnal', 'Rohtak', 'Hisar' ), 'Jharkhand' => array( 'Ranchi', 'Dhanbad', 'Jamshedpur', 'Bokaro' ), 'Odisha' => array( 'Bhubaneswar', 'Cuttack', 'Rourkela', 'Sambalpur', 'Berhampur' ), 'Chhattisgarh' => array( 'Raipur', 'Bhilai', 'Bilaspur' ), 'Assam' => array( 'Guwahati' ), 'Kerala' => array( 'Kochi', 'Thiruvananthapuram', 'Kozhikode', 'Thrissur' ), 'Uttarakhand' => array( 'Dehradun', 'Haridwar', 'Roorkee' ), 'Chandigarh' => array( 'Chandigarh' ), 'Jammu and Kashmir' => array( 'Srinagar', 'Jammu' ), 'Himachal Pradesh' => array( 'Shimla' ), 'Goa' => array( 'Panaji' ), 'Puducherry' => array( 'Puducherry' ), 'Manipur' => array( 'Imphal' ), 'Meghalaya' => array( 'Shillong' ), 'Sikkim' => array( 'Gangtok' ), 'Arunachal Pradesh' => array( 'Itanagar' ), 'Mizoram' => array( 'Aizawl' ), 'Nagaland' => array( 'Kohima' ), 'Tripura' => array( 'Agartala' ), 'Andaman and Nicobar Islands' => array( 'Port Blair' ) );
	}

	public static function states() {
		return array( 'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chhattisgarh', 'Goa', 'Gujarat', 'Haryana', 'Himachal Pradesh', 'Jharkhand', 'Karnataka', 'Kerala', 'Madhya Pradesh', 'Maharashtra', 'Manipur', 'Meghalaya', 'Mizoram', 'Nagaland', 'Odisha', 'Punjab', 'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana', 'Tripura', 'Uttar Pradesh', 'Uttarakhand', 'West Bengal', 'Andaman and Nicobar Islands', 'Chandigarh', 'Dadra and Nagar Haveli and Daman and Diu', 'Delhi', 'Jammu and Kashmir', 'Ladakh', 'Lakshadweep', 'Puducherry', 'Outside India' );
	}

	const MAX_RUPEES = 1000000;

	/**
	 * Hook in.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Register routes. Donors are not logged in, so these are public by design;
	 * each handler validates its own input and signatures.
	 */
	public static function routes() {
		register_rest_route(
			self::NS,
			'/donate',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'donate' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/upi-submit',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'upi_submit' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/confirm',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'confirm' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'webhook' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Check and bump a per-IP rate limit.
	 *
	 * @return bool True when allowed.
	 */
	private static function rate_ok() {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'x';
		$key = 'npd_rl_' . md5( $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= 10 ) {
			return false;
		}
		set_transient( $key, $n + 1, 10 * MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Start a donation.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function donate( WP_REST_Request $req ) {
		// Honeypot and time trap: bots fill the hidden field or post instantly.
		if ( '' !== (string) $req->get_param( 'website' ) ) {
			return new WP_Error( 'npd_spam', __( 'Could not process this form.', 'nonprofit-donations' ), array( 'status' => 400 ) );
		}
		$started = (int) $req->get_param( 'ts' );
		if ( $started > 0 && ( time() - $started ) < 3 ) {
			return new WP_Error( 'npd_fast', __( 'Please take a moment and try again.', 'nonprofit-donations' ), array( 'status' => 429 ) );
		}
		if ( ! self::rate_ok() ) {
			return new WP_Error( 'npd_rate', __( 'Too many attempts. Please try again in a few minutes.', 'nonprofit-donations' ), array( 'status' => 429 ) );
		}

		$rupees = absint( $req->get_param( 'amount' ) );
		if ( $rupees < self::MIN_RUPEES || $rupees > self::MAX_RUPEES ) {
			/* translators: 1: minimum rupees, 2: maximum rupees */
			return new WP_Error( 'npd_amount', sprintf( __( 'Please enter an amount between Rs %1$d and Rs %2$d.', 'nonprofit-donations' ), self::MIN_RUPEES, self::MAX_RUPEES ), array( 'status' => 400 ) );
		}
		$name  = sanitize_text_field( (string) $req->get_param( 'name' ) );
		$email = sanitize_email( (string) $req->get_param( 'email' ) );
		$phone = preg_replace( '/[^0-9+]/', '', (string) $req->get_param( 'phone' ) );
		if ( '' === $name || ! is_email( $email ) ) {
			return new WP_Error( 'npd_donor', __( 'Please enter your name and a valid email.', 'nonprofit-donations' ), array( 'status' => 400 ) );
		}
		$city  = sanitize_text_field( (string) $req->get_param( 'city' ) );
		$state = sanitize_text_field( (string) $req->get_param( 'state' ) );
		foreach ( self::states() as $known ) {
			if ( 0 === strcasecmp( $state, $known ) ) {
				$state = $known;
				break;
			}
		}
		if ( strlen( $city ) < 2 || ! in_array( $state, self::states(), true ) ) {
			return new WP_Error( 'npd_location', __( 'Please enter your city and choose your state.', 'nonprofit-donations' ), array( 'status' => 400 ) );
		}
		$pincode = preg_replace( '/\s+/', '', sanitize_text_field( (string) $req->get_param( 'pincode' ) ) );
		if ( 'Outside India' === $state ) {
			$pincode = substr( $pincode, 0, 10 );
		} elseif ( null === $req->get_param( 'pincode' ) ) {
			$pincode = ''; // An older cached form that has no pincode field: do not block the donor.
		} elseif ( ! preg_match( '/^[1-9][0-9]{5}$/', $pincode ) ) {
			return new WP_Error( 'npd_pincode', __( 'Please enter a valid 6-digit pincode.', 'nonprofit-donations' ), array( 'status' => 400 ) );
		}
		if ( ! rest_sanitize_boolean( $req->get_param( 'consent' ) ) ) {
			return new WP_Error( 'npd_consent', __( 'Please tick the consent box to continue.', 'nonprofit-donations' ), array( 'status' => 400 ) );
		}
		$want_80g = rest_sanitize_boolean( $req->get_param( 'want_80g' ) );
		$pan_enc  = null;
		$address  = '';
		if ( $want_80g ) {
			$address = sanitize_textarea_field( (string) $req->get_param( 'address' ) );
			if ( strlen( $address ) < 10 ) {
				return new WP_Error( 'npd_address', __( 'For an 80G receipt please enter your full address.', 'nonprofit-donations' ), array( 'status' => 400 ) );
			}
			$address = substr( $address, 0, 400 );
			$pan = strtoupper( preg_replace( '/\s+/', '', (string) $req->get_param( 'pan' ) ) );
			if ( ! preg_match( '/^[A-Z]{5}[0-9]{4}[A-Z]$/', $pan ) ) {
				return new WP_Error( 'npd_pan', __( 'For an 80G receipt please enter a valid PAN (like ABCDE1234F).', 'nonprofit-donations' ), array( 'status' => 400 ) );
			}
			$pan_enc = NPD_Settings::encrypt( $pan );
		}

		$creds    = NPD_Settings::razorpay();
		$upi      = NPD_Settings::upi();
		$bank     = NPD_Flow::bank();
		if ( $upi && $bank && 'only' === $bank['mode'] ) {
			$upi = null; // UPI paused by the owner: bank transfer only.
		}
		if ( ! $upi && $bank ) {
			$creds = null; // Bank-transfer gifts use the same manual lane as UPI gifts.
		}
		if ( ! $upi && ! $creds && ! $bank ) {
			return new WP_Error( 'npd_unverified', __( 'Donations are not open yet.', 'nonprofit-donations' ), array( 'status' => 503 ) );
		}
		$mode     = ( $upi || $bank ) ? 'upi' : NPD_Settings::get( 'mode' );
		$donor_id = NPD_DB::add_donor(
			array(
				'name'    => $name,
				'email'   => $email,
				'phone'   => substr( $phone, 0, 20 ),
				'city'    => substr( $city, 0, 120 ),
				'state'   => $state,
				'pincode' => $pincode,
				'pan_enc' => $pan_enc,
				'address' => $address,
			)
		);
		$don_id   = NPD_DB::add_donation(
			array(
				'donor_id'     => $donor_id,
				'amount_paise' => $rupees * 100,
				'mode'         => $mode,
				'status'       => 'upi' === $mode ? 'pending' : 'created',
				'campaign'     => sanitize_text_field( (string) $req->get_param( 'campaign' ) ),
				'want_80g'     => $want_80g,
			)
		);

		if ( $upi || $bank ) {
			$token = wp_generate_password( 24, false );
			set_transient( 'npd_upi_' . $don_id, $token, DAY_IN_SECONDS );
			$ref  = 'DON-' . $don_id;
			$link = ! $upi ? '' : 'upi://pay?' . http_build_query(
				array(
					'pa' => $upi['vpa'],
					'pn' => $upi['name'],
					'am' => number_format( $rupees, 2, '.', '' ),
					'cu' => 'INR',
					'tn' => $ref,
					'tr' => $ref,
				),
				'',
				'&',
				PHP_QUERY_RFC3986
			);
			return rest_ensure_response(
				array(
					'mode'        => 'upi',
					'donation_id' => $don_id,
					'token'       => $token,
					'upi_link'    => $link,
					'vpa'         => $upi ? $upi['vpa'] : '',
					'bank'        => $bank ? array( 'name' => $bank['name'], 'no' => $bank['no'], 'ifsc' => $bank['ifsc'], 'bank' => $bank['bank'], 'branch' => $bank['branch'] ) : null,
					'ref'         => $ref,
					'amount'      => $rupees,
				)
			);
		}

		if ( ! $creds ) {
			return new WP_Error( 'npd_unverified', __( 'Donations are not open yet.', 'nonprofit-donations' ), array( 'status' => 503 ) );
		}

		$order = NPD_Razorpay::create_order( $creds, $rupees * 100, $don_id );
		if ( is_wp_error( $order ) ) {
			NPD_DB::mark_failed( $don_id );
			return new WP_Error( 'npd_gateway', $order->get_error_message(), array( 'status' => 502 ) );
		}
		NPD_DB::set_order( $don_id, sanitize_text_field( $order['id'] ) );
		return rest_ensure_response(
			array(
				'mode'        => $mode,
				'donation_id' => $don_id,
				'order_id'    => $order['id'],
				'amount'      => $rupees * 100,
				'key_id'      => $creds['key_id'],
				'name'        => NPD_Settings::get( 'org_name' ),
				'prefill'     => array(
					'name'    => $name,
					'email'   => $email,
					'contact' => $phone,
				),
			)
		);
	}

	/**
	 * Donor says they paid and gives the UPI reference. Goes to pending verification.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function upi_submit( WP_REST_Request $req ) {
		if ( ! NPD_Settings::upi() ) {
			return new WP_Error( 'npd_mode', __( 'UPI payments are off.', 'nonprofit-donations' ), array( 'status' => 400 ) );
		}
		$id    = absint( $req->get_param( 'donation_id' ) );
		$token = (string) $req->get_param( 'token' );
		$known = get_transient( 'npd_upi_' . $id );
		if ( ! $known || ! hash_equals( (string) $known, $token ) ) {
			return new WP_Error( 'npd_token', __( 'This payment session has expired. Please start again.', 'nonprofit-donations' ), array( 'status' => 400 ) );
		}
		$utr  = strtoupper( preg_replace( '/\s+/', '', (string) $req->get_param( 'utr' ) ) );
		$flag = '';
		if ( '' !== $utr ) {
			// A doubtful reference never errors at the donor. It goes to the review queue, and nothing is auto-confirmed.
			if ( ! preg_match( '/^[A-Z0-9]{8,30}$/', $utr ) ) {
				$utr  = '';
				$flag = 'unclear_ref';
			} elseif ( NPD_DB::utr_taken( $utr, $id ) ) {
				$flag = 'dup_ref';
			}
		}
		if ( ! NPD_DB::submit_utr( $id, $utr, $flag ) ) {
			return new WP_Error( 'npd_state', __( 'This donation was already updated.', 'nonprofit-donations' ), array( 'status' => 409 ) );
		}
		delete_transient( 'npd_upi_' . $id );
		do_action( 'npd_donor_claimed', $id );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Browser callback after Razorpay Checkout. Verifies the signature.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function confirm( WP_REST_Request $req ) {
		$creds = NPD_Settings::razorpay();
		if ( ! $creds ) {
			return new WP_Error( 'npd_mode', __( 'Payments are not live.', 'nonprofit-donations' ), array( 'status' => 400 ) );
		}
		$don = NPD_DB::get_donation( absint( $req->get_param( 'donation_id' ) ) );
		$oid = sanitize_text_field( (string) $req->get_param( 'razorpay_order_id' ) );
		$pid = sanitize_text_field( (string) $req->get_param( 'razorpay_payment_id' ) );
		$sig = sanitize_text_field( (string) $req->get_param( 'razorpay_signature' ) );
		if ( ! $don || $don->rz_order_id !== $oid ) {
			return new WP_Error( 'npd_notfound', __( 'Donation not found.', 'nonprofit-donations' ), array( 'status' => 404 ) );
		}
		if ( ! NPD_Razorpay::verify_payment_signature( $oid, $pid, $sig, $creds['key_secret'] ) ) {
			return new WP_Error( 'npd_sig', __( 'Payment could not be verified.', 'nonprofit-donations' ), array( 'status' => 400 ) );
		}
		NPD_DB::mark_paid( (int) $don->id, $pid );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Razorpay webhook. Verifies the signature on the raw body, ignores repeats.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function webhook( WP_REST_Request $req ) {
		$creds = NPD_Settings::razorpay();
		if ( ! $creds || '' === $creds['webhook_secret'] ) {
			return new WP_Error( 'npd_hook', __( 'Webhook is not set up.', 'nonprofit-donations' ), array( 'status' => 400 ) );
		}
		$raw = $req->get_body();
		$sig = (string) $req->get_header( 'x-razorpay-signature' );
		if ( ! NPD_Razorpay::verify_webhook( $raw, $sig, $creds['webhook_secret'] ) ) {
			return new WP_Error( 'npd_sig', __( 'Bad signature.', 'nonprofit-donations' ), array( 'status' => 400 ) );
		}
		$event_id = (string) $req->get_header( 'x-razorpay-event-id' );
		if ( '' !== $event_id && ! NPD_DB::remember_event( sanitize_text_field( $event_id ) ) ) {
			return rest_ensure_response( array( 'ok' => true, 'duplicate' => true ) );
		}
		$data  = json_decode( $raw, true );
		$event = isset( $data['event'] ) ? (string) $data['event'] : '';
		if ( ! in_array( $event, array( 'payment.captured', 'order.paid' ), true ) ) {
			return rest_ensure_response( array( 'ok' => true, 'ignored' => true ) );
		}
		$pay      = isset( $data['payload']['payment']['entity'] ) ? $data['payload']['payment']['entity'] : array();
		$order_id = isset( $pay['order_id'] ) ? (string) $pay['order_id'] : '';
		$pay_id   = isset( $pay['id'] ) ? (string) $pay['id'] : '';
		$don      = $order_id ? NPD_DB::get_by_order( $order_id ) : null;
		if ( ! $don || ! isset( $pay['amount'] ) || (int) $pay['amount'] !== (int) $don->amount_paise ) {
			return rest_ensure_response( array( 'ok' => true, 'ignored' => true ) );
		}
		NPD_DB::mark_paid( (int) $don->id, sanitize_text_field( $pay_id ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}
}
