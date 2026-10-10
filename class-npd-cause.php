<?php
/**
 * Causes (campaigns): story, goal, progress, timeline, links, video.
 *
 * @package Nonprofit_Donations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A cause is a post with a few extra fields. Donations to it carry the campaign key cause-ID.
 */
class NPD_Cause {

	const CPT = 'npd_cause';

	/**
	 * Hook in.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'boxes' ) );
		add_action( 'save_post_' . self::CPT, array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'the_content', array( __CLASS__, 'content' ), 20 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ), 99 );
		add_filter( 'wp_sitemaps_post_types', array( __CLASS__, 'no_sitemap' ) );
		add_shortcode( 'npd_cause_progress', array( __CLASS__, 'shortcode_progress' ) );
	}

	/**
	 * Register the post type. Not in search or sitemaps; reachable by its own link.
	 */
	public static function register() {
		register_post_type(
			self::CPT,
			array(
				'labels'              => array(
					'name'          => __( 'Causes', 'nonprofit-donations' ),
					'singular_name' => __( 'Cause', 'nonprofit-donations' ),
					'add_new_item'  => __( 'Add cause', 'nonprofit-donations' ),
					'edit_item'     => __( 'Edit cause', 'nonprofit-donations' ),
				),
				'public'              => false,
				'publicly_queryable'  => true,
				'show_ui'             => true,
				'show_in_menu'        => 'npd',
				'show_in_rest'        => true,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'rewrite'             => array( 'slug' => 'cause', 'with_front' => false ),
				'supports'            => array( 'title', 'editor', 'thumbnail' ),
				'capability_type'     => 'post',
			)
		);
		$rules = get_option( 'rewrite_rules' );
		if ( get_option( 'npd_cause_flush' ) !== NPD_VERSION || ( is_array( $rules ) && ! preg_grep( '#^cause/#', array_keys( $rules ) ) ) ) {
			flush_rewrite_rules( false );
			update_option( 'npd_cause_flush', NPD_VERSION, false );
		}
	}

	/**
	 * Keep causes out of core sitemaps.
	 *
	 * @param array $types Post types.
	 * @return array
	 */
	public static function no_sitemap( $types ) {
		unset( $types[ self::CPT ] );
		return $types;
	}

	/**
	 * Causes are not for search engines until the owner decides otherwise.
	 *
	 * @param array $robots Robots directives.
	 * @return array
	 */
	public static function robots( $robots ) {
		if ( is_singular( self::CPT ) ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
			unset( $robots['index'] );
		}
		return $robots;
	}

	/**
	 * Meta box.
	 */
	public static function boxes() {
		add_meta_box( 'npd_cause_fields', __( 'Cause details', 'nonprofit-donations' ), array( __CLASS__, 'box' ), self::CPT, 'normal', 'high' );
	}

	/**
	 * Render the meta box.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function box( $post ) {
		wp_nonce_field( 'npd_cause_save', 'npd_cause_nonce' );
		$g = function ( $k ) use ( $post ) {
			return (string) get_post_meta( $post->ID, '_npd_' . $k, true );
		};
		?>
		<p><label><strong><?php echo esc_html__( 'Goal amount (Rs)', 'nonprofit-donations' ); ?></strong><br><input type="number" min="0" name="npd_goal" value="<?php echo esc_attr( $g( 'goal' ) ); ?>"></label></p>
		<p><label><strong><?php echo esc_html__( 'Collected offline (Rs)', 'nonprofit-donations' ); ?></strong><br><input type="number" min="0" name="npd_offline" value="<?php echo esc_attr( $g( 'offline' ) ); ?>"></label><br><span class="description"><?php echo esc_html__( 'Cash or bank transfers outside this form. Paid donations through the form are added automatically.', 'nonprofit-donations' ); ?></span></p>
		<p><label><strong><?php echo esc_html__( 'Deadline', 'nonprofit-donations' ); ?></strong><br><input type="date" name="npd_deadline" value="<?php echo esc_attr( $g( 'deadline' ) ); ?>"></label></p>
		<p><label><strong><?php echo esc_html__( 'Video link (YouTube or Vimeo)', 'nonprofit-donations' ); ?></strong><br><input class="large-text" type="url" name="npd_video" value="<?php echo esc_attr( $g( 'video' ) ); ?>"></label></p>
		<p><label><strong><?php echo esc_html__( 'Timeline (one per line: date | text)', 'nonprofit-donations' ); ?></strong><br><textarea class="large-text" rows="5" name="npd_timeline" placeholder="2026-10-09 | Surgery scheduled"><?php echo esc_textarea( $g( 'timeline' ) ); ?></textarea></label></p>
		<p><label><strong><?php echo esc_html__( 'Links (one per line: label | https://...)', 'nonprofit-donations' ); ?></strong><br><textarea class="large-text" rows="3" name="npd_links" placeholder="Hospital estimate | https://example.org/estimate"><?php echo esc_textarea( $g( 'links' ) ); ?></textarea></label></p>
		<p class="description"><?php echo esc_html__( 'Photos: set the cover as the featured image and add more photos in the story text.', 'nonprofit-donations' ); ?></p>
		<?php
	}

	/**
	 * Save fields.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['npd_cause_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npd_cause_nonce'] ) ), 'npd_cause_save' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		update_post_meta( $post_id, '_npd_goal', isset( $_POST['npd_goal'] ) ? absint( $_POST['npd_goal'] ) : 0 );
		update_post_meta( $post_id, '_npd_offline', isset( $_POST['npd_offline'] ) ? absint( $_POST['npd_offline'] ) : 0 );
		$dl = isset( $_POST['npd_deadline'] ) ? sanitize_text_field( wp_unslash( $_POST['npd_deadline'] ) ) : '';
		update_post_meta( $post_id, '_npd_deadline', preg_match( '/^\d{4}-\d{2}-\d{2}$/', $dl ) ? $dl : '' );
		update_post_meta( $post_id, '_npd_video', isset( $_POST['npd_video'] ) ? esc_url_raw( wp_unslash( $_POST['npd_video'] ) ) : '' );
		update_post_meta( $post_id, '_npd_timeline', isset( $_POST['npd_timeline'] ) ? sanitize_textarea_field( wp_unslash( $_POST['npd_timeline'] ) ) : '' );
		update_post_meta( $post_id, '_npd_links', isset( $_POST['npd_links'] ) ? sanitize_textarea_field( wp_unslash( $_POST['npd_links'] ) ) : '' );
	}

	/**
	 * Rupees collected: paid donations for this cause plus the offline figure.
	 *
	 * @param int $id Cause ID.
	 * @return int
	 */
	public static function collected( $id ) {
		global $wpdb;
		$t    = NPD_DB::table( 'donations' );
		$paise = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount_paise),0) FROM {$t} WHERE status = 'paid' AND campaign = %s", 'cause-' . (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) floor( $paise / 100 ) + (int) get_post_meta( $id, '_npd_offline', true );
	}

	/**
	 * Progress markup.
	 *
	 * @param int $id Cause ID.
	 * @return string
	 */
	public static function progress_html( $id ) {
		$goal = (int) get_post_meta( $id, '_npd_goal', true );
		$got  = self::collected( $id );
		$pct  = $goal > 0 ? min( 100, (int) floor( $got * 100 / $goal ) ) : 0;
		$dl   = (string) get_post_meta( $id, '_npd_deadline', true );
		$left = '';
		if ( $dl ) {
			$days = (int) floor( ( strtotime( $dl . ' 23:59:59' ) - current_time( 'timestamp' ) ) / DAY_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
			$left = $days >= 0 ? sprintf( /* translators: %d: days */ _n( '%d day left', '%d days left', $days, 'nonprofit-donations' ), $days ) : __( 'Ended', 'nonprofit-donations' );
		}
		ob_start();
		?>
		<div class="npd-progress">
			<div class="npd-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( $pct ); ?>"><span style="width:<?php echo esc_attr( $pct ); ?>%"></span></div>
			<p class="npd-prog-line"><strong>&#8377;<?php echo esc_html( number_format_i18n( $got ) ); ?></strong>
			<?php if ( $goal > 0 ) : ?>
				<?php /* translators: %s: goal amount */ echo esc_html( sprintf( __( 'raised of Rs %s goal', 'nonprofit-donations' ), number_format_i18n( $goal ) ) ); ?> <span class="npd-pct">(<?php echo esc_html( $pct ); ?>%)</span>
			<?php endif; ?>
			<?php if ( $left ) : ?>
				<span class="npd-left"><?php echo esc_html( $left ); ?></span>
			<?php endif; ?></p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Shortcode for a progress bar on any page.
	 *
	 * @param array $a Attributes.
	 * @return string
	 */
	public static function shortcode_progress( $a ) {
		$a = shortcode_atts( array( 'id' => 0 ), $a );
		wp_enqueue_style( 'npd-donate', NPD_URL . 'donate.css', array(), NPD_VERSION );
		return self::progress_html( (int) $a['id'] );
	}

	/**
	 * Build the cause page body.
	 *
	 * @param string $content Original story.
	 * @return string
	 */
	public static function content( $content ) {
		if ( ! is_singular( self::CPT ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$id = get_the_ID();
		wp_enqueue_style( 'npd-donate', NPD_URL . 'donate.css', array(), NPD_VERSION );
		$out = '<div class="npd-cause">';
		if ( has_post_thumbnail( $id ) ) {
			$out .= '<div class="npd-cover">' . get_the_post_thumbnail( $id, 'large' ) . '</div>';
		}
		$out .= self::progress_html( $id );
		$out .= '<div class="npd-story">' . $content . '</div>';
		$video = (string) get_post_meta( $id, '_npd_video', true );
		if ( $video ) {
			$embed = wp_oembed_get( $video, array( 'width' => 640 ) );
			if ( $embed ) {
				$out .= '<div class="npd-video">' . $embed . '</div><p class="npd-video-link"><a href="' . esc_url( $video ) . '" rel="noopener nofollow">' . esc_html__( 'Watch the video in a new tab', 'nonprofit-donations' ) . '</a></p>';
			}
		}
		$tl = array_filter( array_map( 'trim', explode( "\n", (string) get_post_meta( $id, '_npd_timeline', true ) ) ) );
		if ( $tl ) {
			$out .= '<h3 class="npd-h">' . esc_html__( 'Timeline', 'nonprofit-donations' ) . '</h3><ol class="npd-timeline">';
			foreach ( $tl as $line ) {
				$p = array_map( 'trim', explode( '|', $line, 2 ) );
				if ( 2 === count( $p ) ) {
					$ts   = strtotime( $p[0] );
					$out .= '<li><time>' . esc_html( $ts ? wp_date( get_option( 'date_format' ), $ts ) : $p[0] ) . '</time><span>' . esc_html( $p[1] ) . '</span></li>';
				}
			}
			$out .= '</ol>';
		}
		$lk = array_filter( array_map( 'trim', explode( "\n", (string) get_post_meta( $id, '_npd_links', true ) ) ) );
		if ( $lk ) {
			$out .= '<h3 class="npd-h">' . esc_html__( 'Links', 'nonprofit-donations' ) . '</h3><ul class="npd-links">';
			foreach ( $lk as $line ) {
				$p = array_map( 'trim', explode( '|', $line, 2 ) );
				if ( 2 === count( $p ) && preg_match( '#^https?://#i', $p[1] ) ) {
					$out .= '<li><a href="' . esc_url( $p[1] ) . '" rel="noopener nofollow">' . esc_html( $p[0] ) . '</a></li>';
				}
			}
			$out .= '</ul>';
		}
		$dl    = (string) get_post_meta( $id, '_npd_deadline', true );
		$ended = $dl && strtotime( $dl . ' 23:59:59' ) < current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		if ( $ended ) {
			$out .= '<p class="npd-upi-note">' . esc_html__( 'This cause has ended. Thank you to everyone who gave.', 'nonprofit-donations' ) . '</p>';
		} else {
			$out .= NPD_Block::render(
				array(
					'heading'  => __( 'Support this cause', 'nonprofit-donations' ),
					'campaign' => 'cause-' . $id,
				)
			);
		}
		return $out . '</div>';
	}
}
