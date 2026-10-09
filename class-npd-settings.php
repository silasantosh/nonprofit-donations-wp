<?php
/**
 * Settings and secret handling.
 *
 * @package Nonprofit_Donations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin settings. Secrets are stored encrypted with a key derived from the site salts.
 */
class NPD_Settings {

	const OPTION = 'npd_settings';

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'mode'                => 'upi', // upi | test | live.
			'org_name'            => '',
			'notify_email'        => '',
			'upi_vpa'             => '',
			'upi_name'            => '',
			'key_id'              => '',
			'key_secret_enc'      => '',
			'webhook_secret_enc'  => '',
			'is_80g'              => 0,
			'amounts'             => '500,1000,2500',
			'delete_on_uninstall' => 0,
		);
	}

	/**
	 * Get all settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		$all   = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		if ( ! in_array( $all['mode'], array( 'upi', 'test', 'live' ), true ) ) {
			$all['mode'] = 'upi';
		}
		return $all;
	}

	/**
	 * Get one setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Save settings from a sanitized array.
	 *
	 * @param array $new New values.
	 */
	public static function save( $new ) {
		update_option( self::OPTION, wp_parse_args( $new, self::all() ), false );
	}

	/**
	 * Encryption key from the site's salts.
	 *
	 * @return string 32 raw bytes.
	 */
	private static function key() {
		return hash( 'sha256', wp_salt( 'auth' ) . 'npd-secret', true );
	}

	/**
	 * Encrypt a secret for storage.
	 *
	 * @param string $plain Plain text.
	 * @return string Base64 payload, or empty string.
	 */
	public static function encrypt( $plain ) {
		if ( '' === $plain || ! function_exists( 'openssl_encrypt' ) ) {
			return '';
		}
		$iv  = random_bytes( 12 );
		$tag = '';
		$ct  = openssl_encrypt( $plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $ct ) {
			return '';
		}
		return base64_encode( $iv . $tag . $ct ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a stored secret.
	 *
	 * @param string $payload Base64 payload.
	 * @return string Plain text or empty string.
	 */
	public static function decrypt( $payload ) {
		if ( '' === $payload || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$raw = base64_decode( $payload, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return '';
		}
		$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
		return false === $plain ? '' : $plain;
	}

	/**
	 * Razorpay credentials in use for the current mode, or null when not in test or live mode.
	 *
	 * @return array|null
	 */
	public static function razorpay() {
		$s = self::all();
		if ( ! in_array( $s['mode'], array( 'test', 'live' ), true ) ) {
			return null;
		}
		$secret = self::decrypt( $s['key_secret_enc'] );
		if ( '' === $s['key_id'] || '' === $secret ) {
			return null;
		}
		return array(
			'key_id'         => $s['key_id'],
			'key_secret'     => $secret,
			'webhook_secret' => self::decrypt( $s['webhook_secret_enc'] ),
		);
	}

	/**
	 * UPI-direct details when UPI mode is on and the VPA looks valid, else null.
	 *
	 * @return array|null
	 */
	public static function upi() {
		$s = self::all();
		if ( 'upi' !== $s['mode'] || ! self::valid_vpa( $s['upi_vpa'] ) ) {
			return null;
		}
		// Live sites take no UPI payment until the ID is verified (name matched) and unchanged.
		$rec = NPD_Reg::all();
		if ( 'verified' !== NPD_Reg::status( $rec['upi'] ) || $rec['upi']['number'] !== $s['upi_vpa'] ) {
			return null;
		}
		return array(
			'vpa'  => $s['upi_vpa'],
			'name' => '' !== $s['upi_name'] ? $s['upi_name'] : $s['org_name'],
		);
	}

	/**
	 * Check a UPI address (name@handle).
	 *
	 * @param string $vpa Address.
	 * @return bool
	 */
	public static function valid_vpa( $vpa ) {
		return (bool) preg_match( '/^[a-zA-Z0-9.\-_]{2,64}@[a-zA-Z]{2,32}$/', (string) $vpa );
	}

	/**
	 * Preset amounts as integers in rupees.
	 *
	 * @return int[]
	 */
	public static function amounts() {
		$list = array_filter( array_map( 'absint', explode( ',', (string) self::get( 'amounts' ) ) ) );
		return $list ? array_values( $list ) : array( 500, 1000, 2500 );
	}
}
