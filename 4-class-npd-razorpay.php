<?php
/**
 * Razorpay REST calls and signature checks.
 *
 * @package Nonprofit_Donations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Talks to api.razorpay.com with the NGO's own keys. No SDK.
 */
class NPD_Razorpay {

	/**
	 * Create an order.
	 *
	 * @param array $creds        key_id and key_secret.
	 * @param int   $amount_paise Amount in paise.
	 * @param int   $donation_id  Our donation id, used as receipt.
	 * @return array|WP_Error Order data with an id, or error.
	 */
	public static function create_order( $creds, $amount_paise, $donation_id ) {
		$res = wp_remote_post(
			'https://api.razorpay.com/v1/orders',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $creds['key_id'] . ':' . $creds['key_secret'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'amount'   => (int) $amount_paise,
						'currency' => 'INR',
						'receipt'  => 'npd-' . (int) $donation_id,
					)
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code || empty( $body['id'] ) ) {
			return new WP_Error( 'npd_razorpay', __( 'Razorpay could not create the order. Check the keys in Donations settings.', 'nonprofit-donations' ) );
		}
		return $body;
	}

	/**
	 * Verify the browser callback signature: HMAC-SHA256 of "order_id|payment_id" with the key secret.
	 *
	 * @param string $order_id   Order id.
	 * @param string $payment_id Payment id.
	 * @param string $signature  Signature from Razorpay Checkout.
	 * @param string $secret     Key secret.
	 * @return bool
	 */
	public static function verify_payment_signature( $order_id, $payment_id, $signature, $secret ) {
		if ( '' === $order_id || '' === $payment_id || '' === $signature || '' === $secret ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $order_id . '|' . $payment_id, $secret );
		return hash_equals( $expected, $signature );
	}

	/**
	 * Verify a webhook: HMAC-SHA256 of the raw body with the webhook secret.
	 *
	 * @param string $raw_body  Raw request body.
	 * @param string $signature X-Razorpay-Signature header.
	 * @param string $secret    Webhook secret.
	 * @return bool
	 */
	public static function verify_webhook( $raw_body, $signature, $secret ) {
		if ( '' === $secret || '' === $signature ) {
			return false;
		}
		return hash_equals( hash_hmac( 'sha256', $raw_body, $secret ), $signature );
	}
}
