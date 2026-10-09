<?php
/**
 * The donate block (server-rendered, no build step).
 *
 * @package Nonprofit_Donations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers nonprofit-donations/donate and renders the form.
 */
class NPD_Block {

	/**
	 * Hook in.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Register the block.
	 */
	public static function register() {
		register_block_type(
			'nonprofit-donations/donate',
			array(
				'api_version'     => 3,
				'title'           => __( 'Donate form', 'nonprofit-donations' ),
				'description'     => __( 'A donation form that works with your own Razorpay account.', 'nonprofit-donations' ),
				'category'        => 'widgets',
				'icon'            => 'heart',
				'attributes'      => array(
					'heading'  => array(
						'type'    => 'string',
						'default' => '',
					),
					'campaign' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
				'supports'        => array(
					'autoRegister' => true,
					'html'         => false,
				),
				'render_callback' => array( __CLASS__, 'render' ),
			)
		);
	}

	/**
	 * Render the form.
	 *
	 * @param array $atts Block attributes.
	 * @return string
	 */
	public static function render( $atts ) {
		$s       = NPD_Settings::all();
		if ( ! NPD_Settings::upi() && ! NPD_Settings::razorpay() ) {
			if ( current_user_can( 'manage_options' ) ) {
				return '<p class="npd-demo-note">' . esc_html__( 'Donate form: add your UPI ID in Donations > Settings, then submit it in Donations > Registrations. The form opens to visitors after the UPI ID is verified.', 'nonprofit-donations' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=npd-settings' ) ) . '">' . esc_html__( 'Open settings', 'nonprofit-donations' ) . '</a></p>';
			}
			return '';
		}
		$is_upi  = (bool) NPD_Settings::upi();
		if ( ! $is_upi && ! NPD_Settings::razorpay() ) {
			return '';
		}
		$heading = ! empty( $atts['heading'] ) ? $atts['heading'] : __( 'Make a donation', 'nonprofit-donations' );
		$camp    = ! empty( $atts['campaign'] ) ? $atts['campaign'] : '';

		wp_enqueue_style( 'npd-donate', NPD_URL . 'donate.css', array(), NPD_VERSION );
		if ( ! $is_upi ) {
			// Razorpay Checkout must load from Razorpay. Only loaded on pages with the form.
			wp_enqueue_script( 'npd-razorpay', 'https://checkout.razorpay.com/v1/checkout.js', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		}
		if ( $is_upi ) {
			wp_enqueue_script( 'npd-qr', NPD_URL . 'qrcode.js', array(), '2.0.4', true );
		}
		wp_enqueue_script( 'npd-donate', NPD_URL . 'donate.js', $is_upi ? array( 'npd-qr' ) : array(), NPD_VERSION, true );
		wp_localize_script(
			'npd-donate',
			'NPD',
			array(
				'api'   => esc_url_raw( rest_url( NPD_REST::NS . '/' ) ),
								'upi'   => $is_upi ? 1 : 0,
				'i18n'  => array(
					'working'   => __( 'Please wait...', 'nonprofit-donations' ),
					'donate'    => __( 'Donate', 'nonprofit-donations' ),
					'thanks'    => __( 'Thank you. Your donation was received.', 'nonprofit-donations' ),
					'error'     => __( 'Something went wrong. Please try again.', 'nonprofit-donations' ),
					'upiPay'    => __( 'Pay with your UPI app', 'nonprofit-donations' ),
					'upiScan'   => __( 'On a computer? Scan this QR code with any UPI app.', 'nonprofit-donations' ),
					'upiUtr'    => __( 'Optional: your UPI reference number (UTR), if you want to add it:', 'nonprofit-donations' ),
					'upiSend'   => __( 'I have paid', 'nonprofit-donations' ),
					'upiDone'   => __( 'Thank you. We will match your payment on our bank statement and confirm it.', 'nonprofit-donations' ),
					'upiVpa'    => __( 'Paying to:', 'nonprofit-donations' ),
					'verify'    => __( 'Payment could not be verified. If money was deducted, it will be matched automatically.', 'nonprofit-donations' ),
				),
			)
		);

		$privacy = get_privacy_policy_url();
		ob_start();
		?>
		<div class="npd-wrap" data-campaign="<?php echo esc_attr( $camp ); ?>">
			<form class="npd-form" novalidate>
				<?php
				$reg = NPD_Reg::public_list();
				if ( $reg ) :
					$bits = array();
					if ( isset( $reg['12a'] ) ) {
						$bits[] = '12A: ' . $reg['12a'];
					}
					if ( isset( $reg['80g'] ) ) {
						$bits[] = '80G: ' . $reg['80g'];
					}
					?>
					<p class="npd-reg"><?php echo esc_html( implode( ' | ', $bits ) ); ?> - <?php echo esc_html__( 'Checked against the official record', 'nonprofit-donations' ); ?> <a href="https://incometaxindia.gov.in/Pages/utilities/exempted-institutions.aspx" rel="noopener"><?php echo esc_html__( 'Verify yourself', 'nonprofit-donations' ); ?></a></p>
				<?php endif; ?>
				<h3 class="npd-title"><?php echo esc_html( $heading ); ?></h3>
				<?php if ( $is_upi ) : ?>
					<p class="npd-upi-note">
						<?php
						$who = NPD_Settings::get( 'org_name' );
						/* translators: %s: organisation name */
						echo esc_html( sprintf( __( 'Your UPI app should show %s when you pay. If it shows a person\'s name, do not pay.', 'nonprofit-donations' ), '' !== $who ? $who : __( 'the organisation name', 'nonprofit-donations' ) ) );
						?>
					</p>
				<?php endif; ?>
				<fieldset class="npd-amounts">
					<legend><?php echo esc_html__( 'Amount (Rs)', 'nonprofit-donations' ); ?></legend>
					<?php foreach ( NPD_Settings::amounts() as $i => $amt ) : ?>
						<label class="npd-chip">
							<input type="radio" name="amount_choice" value="<?php echo esc_attr( $amt ); ?>" <?php checked( 0, $i ); ?>>
							<span><?php echo esc_html( number_format_i18n( $amt ) ); ?></span>
						</label>
					<?php endforeach; ?>
					<label class="npd-chip">
						<input type="radio" name="amount_choice" value="other">
						<span><?php echo esc_html__( 'Other', 'nonprofit-donations' ); ?></span>
					</label>
					<input class="npd-other" type="number" inputmode="numeric" min="<?php echo esc_attr( NPD_REST::MIN_RUPEES ); ?>" name="amount_other" placeholder="<?php echo esc_attr__( 'Enter amount', 'nonprofit-donations' ); ?>" hidden>
				</fieldset>
				<p><label><?php echo esc_html__( 'Full name', 'nonprofit-donations' ); ?><input type="text" name="name" autocomplete="name" required></label></p>
				<p><label><?php echo esc_html__( 'Email', 'nonprofit-donations' ); ?><input type="email" name="email" autocomplete="email" required></label></p>
				<p><label><?php echo esc_html__( 'Phone (optional)', 'nonprofit-donations' ); ?><input type="tel" name="phone" autocomplete="tel" inputmode="tel"></label></p>
				<?php if ( ! empty( $s['is_80g'] ) ) : ?>
					<p class="npd-check"><label><input type="checkbox" name="want_80g" value="1"> <?php echo esc_html__( 'I want an 80G tax receipt', 'nonprofit-donations' ); ?></label></p>
					<p class="npd-pan" hidden><label><?php echo esc_html__( 'PAN (needed for 80G receipt)', 'nonprofit-donations' ); ?><input type="text" name="pan" maxlength="10" autocapitalize="characters" placeholder="ABCDE1234F"></label></p>
				<?php endif; ?>
				<p class="npd-hp" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>
				<p class="npd-check"><label><input type="checkbox" name="consent" value="1" required>
					<?php
					echo esc_html__( 'I agree that my details are used to process this donation and send my receipt.', 'nonprofit-donations' );
					if ( $privacy ) {
						echo ' <a href="' . esc_url( $privacy ) . '">' . esc_html__( 'Privacy Policy', 'nonprofit-donations' ) . '</a>';
					}
					?>
				</label></p>
				<input type="hidden" name="ts" value="<?php echo esc_attr( time() ); ?>">
				<p class="npd-msg" role="status" aria-live="polite"></p>
				<button type="submit" class="npd-submit wp-element-button"><?php echo esc_html__( 'Donate', 'nonprofit-donations' ); ?></button>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
