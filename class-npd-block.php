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
		add_action( 'template_redirect', array( __CLASS__, 'no_cache' ), 0 );
	}

	/**
	 * Register the block.
	 */
	/**
	 * Never let a cache serve an old donate form: the form and its checks must always match.
	 */
	public static function no_cache() {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post || empty( $post->post_content ) || false === strpos( $post->post_content, 'nonprofit-donations/donate' ) ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true );
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
	}

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
		if ( ! NPD_Settings::upi() && ! NPD_Settings::razorpay() && ! NPD_Flow::bank() ) {
			if ( current_user_can( 'manage_options' ) ) {
				return '<p class="npd-demo-note">' . esc_html__( 'Donate form: add your UPI ID in Donations > Settings, then submit it in Donations > Registrations. The form opens to visitors after the UPI ID is verified.', 'nonprofit-donations' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=npd-settings' ) ) . '">' . esc_html__( 'Open settings', 'nonprofit-donations' ) . '</a></p>';
			}
			return '';
		}
		$bank    = NPD_Flow::bank();
		$has_upi = (bool) NPD_Settings::upi() && ! ( $bank && 'only' === $bank['mode'] );
		$is_upi  = $has_upi || (bool) $bank;
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
		if ( $has_upi ) {
			wp_enqueue_script( 'npd-qr', NPD_URL . 'qrcode.js', array(), '2.0.4', true );
		}
		wp_enqueue_script( 'npd-donate', NPD_URL . 'donate.js', $is_upi ? array( 'npd-qr' ) : array(), NPD_VERSION, true );
		wp_localize_script(
			'npd-donate',
			'NPD',
			array(
				'api'   => esc_url_raw( rest_url( NPD_REST::NS . '/' ) ),
								'upi'   => $is_upi ? 1 : 0,
				'shot'  => $is_upi ? array( 'base' => NPD_URL . 'ocr/', 't' => NPD_Flow::shot_texts() ) : null,
				'i18n'  => array(
					'working'   => __( 'Please wait...', 'nonprofit-donations' ),
					'donate'    => __( 'Donate', 'nonprofit-donations' ),
					'thanks'    => __( 'Thank you. Your donation was received.', 'nonprofit-donations' ),
					'error'     => __( 'Something went wrong. Please try again.', 'nonprofit-donations' ),
					'upiPay'    => __( 'Pay with your UPI app', 'nonprofit-donations' ),
					'upiAny'    => __( 'Any UPI app', 'nonprofit-donations' ),
					'upiScan'   => __( 'On a computer? Scan this QR code with any UPI app.', 'nonprofit-donations' ),
					'upiUtr'    => __( 'Optional: your UPI reference number (UTR), if you want to add it:', 'nonprofit-donations' ),
					'upiSend'   => __( 'I have paid', 'nonprofit-donations' ),
					'upiDone'   => __( 'Thank you. We will match your payment on our bank statement and confirm it.', 'nonprofit-donations' ),
					'upiVpa'    => __( 'Paying to:', 'nonprofit-donations' ),
					'bankHead'  => __( 'Pay by bank transfer (NEFT / IMPS / net banking)', 'nonprofit-donations' ),
					'bankName'  => __( 'Account name', 'nonprofit-donations' ),
					'bankNo'    => __( 'Account number', 'nonprofit-donations' ),
					'bankBank'  => __( 'Bank', 'nonprofit-donations' ),
					'bankRemark' => __( 'Remarks / note (please type this)', 'nonprofit-donations' ),
					'bankCopy'  => __( 'Copy', 'nonprofit-donations' ),
					'bankCopied' => __( 'Copied', 'nonprofit-donations' ),
					'bankHelp'  => __( 'IMPS arrives in minutes, NEFT within a few hours. Put the remarks above so we can find your gift, then tap I have paid below.', 'nonprofit-donations' ),
					'verify'    => __( 'Payment could not be verified. If money was deducted, it will be matched automatically.', 'nonprofit-donations' ),
				),
			)
		);

		$privacy = get_privacy_policy_url();
		ob_start();
		?>
		<?php $npd_col = (string) NPD_Settings::get( 'accent_color' ); ?>
		<div class="npd-wrap" data-campaign="<?php echo esc_attr( $camp ); ?>"<?php echo preg_match( '/^#[0-9a-f]{6}$/', $npd_col ) ? ' style="--npd-accent:' . esc_attr( $npd_col ) . '"' : ''; ?>>
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
				<?php if ( $has_upi ) : ?>
					<p class="npd-upi-note">
						<?php
						$who = NPD_Settings::get( 'org_name' );
						/* translators: %s: organisation name */
						echo esc_html( sprintf( __( 'Your UPI app should show %s when you pay. If it shows a person\'s name, do not pay.', 'nonprofit-donations' ), '' !== $who ? $who : __( 'the organisation name', 'nonprofit-donations' ) ) );
						?>
					</p>
				<?php endif; ?>
				<fieldset class="npd-amounts">
					<legend><?php echo esc_html__( 'Choose an amount', 'nonprofit-donations' ); ?></legend>
					<?php foreach ( NPD_Settings::amounts() as $i => $amt ) : ?>
						<label class="npd-chip">
							<input type="radio" name="amount_choice" value="<?php echo esc_attr( $amt ); ?>" <?php checked( 0, $i ); ?>>
							<span>&#8377;<?php echo esc_html( number_format_i18n( $amt ) ); ?></span>
						</label>
					<?php endforeach; ?>
					<label class="npd-chip">
						<input type="radio" name="amount_choice" value="other">
						<span><?php echo esc_html__( 'Other', 'nonprofit-donations' ); ?></span>
					</label>
					<div class="npd-other-wrap" hidden>
						<label for="npd-other-amt"><?php echo esc_html( sprintf( /* translators: %d: minimum rupees */ __( 'Your amount in rupees (minimum %d)', 'nonprofit-donations' ), NPD_REST::MIN_RUPEES ) ); ?></label>
						<span class="npd-rs"><span aria-hidden="true">&#8377;</span><input class="npd-other" id="npd-other-amt" type="number" inputmode="numeric" pattern="[0-9]*" step="1" min="<?php echo esc_attr( NPD_REST::MIN_RUPEES ); ?>" name="amount_other" placeholder="<?php echo esc_attr( NPD_REST::MIN_RUPEES ); ?>"></span>
					</div>
				</fieldset>
				<p><label><?php echo esc_html__( 'Full name', 'nonprofit-donations' ); ?><input type="text" name="name" autocomplete="name" required></label></p>
				<p><label><?php echo esc_html__( 'Email', 'nonprofit-donations' ); ?><input type="email" name="email" autocomplete="email" required></label></p>
				<p><label><?php echo esc_html__( 'Phone (optional)', 'nonprofit-donations' ); ?><input type="tel" name="phone" autocomplete="tel" inputmode="tel"></label></p>
				<p><label><?php echo esc_html__( 'State', 'nonprofit-donations' ); ?>
					<input type="text" name="state" list="npd-states" autocomplete="address-level1" placeholder="<?php echo esc_attr__( 'Start typing your state', 'nonprofit-donations' ); ?>" required></label></p>
				<datalist id="npd-states">
					<?php foreach ( NPD_REST::states() as $st ) : ?>
						<option value="<?php echo esc_attr( $st ); ?>"></option>
					<?php endforeach; ?>
				</datalist>
				<p><label><?php echo esc_html__( 'City', 'nonprofit-donations' ); ?><input type="text" name="city" list="npd-cities" autocomplete="address-level2" autocapitalize="words" placeholder="<?php echo esc_attr__( 'Start typing your city', 'nonprofit-donations' ); ?>" data-cities="<?php echo esc_attr( wp_json_encode( NPD_REST::cities_by_state() ) ); ?>" required></label></p>
				<p><label><?php echo esc_html__( 'Pincode', 'nonprofit-donations' ); ?><input type="text" name="pincode" inputmode="numeric" pattern="[1-9][0-9]{5}" maxlength="6" autocomplete="postal-code" placeholder="<?php echo esc_attr__( '6-digit pincode', 'nonprofit-donations' ); ?>" required></label></p>
				<datalist id="npd-cities">
					<?php foreach ( NPD_REST::cities() as $ct ) : ?>
						<option value="<?php echo esc_attr( $ct ); ?>"></option>
					<?php endforeach; ?>
				</datalist>
				<?php if ( ! empty( $s['is_80g'] ) ) : ?>
					<p class="npd-check"><label><input type="checkbox" name="want_80g" value="1"> <?php echo esc_html__( 'I want an 80G tax receipt', 'nonprofit-donations' ); ?></label></p>
					<p class="npd-pan" hidden><label><?php echo esc_html__( 'PAN (needed for 80G receipt)', 'nonprofit-donations' ); ?><input type="text" name="pan" maxlength="10" autocapitalize="characters" placeholder="ABCDE1234F"></label></p>
					<p class="npd-addr" hidden><label><?php echo esc_html__( 'Address (needed for 80G receipt)', 'nonprofit-donations' ); ?><textarea name="address" rows="2" maxlength="400"></textarea></label></p>
				<?php endif; ?>
				<p class="npd-hp" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>
				<p class="npd-check"><label><input type="checkbox" name="consent" value="1" required><span>
					<?php
					echo esc_html__( 'I agree that my details are used to process this donation and send my receipt.', 'nonprofit-donations' );
					if ( $privacy ) {
						echo ' <a href="' . esc_url( $privacy ) . '">' . esc_html__( 'Privacy Policy', 'nonprofit-donations' ) . '</a>';
					}
					?></span>
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
