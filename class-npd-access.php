<?php
/**
 * Who may view donation reports. Default: administrators only.
 *
 * @package nonprofit-donations
 */

defined( 'ABSPATH' ) || exit;

/**
 * Report access: a "npd_view_reports" capability the admin can hand to roles or named users.
 */
class NPD_Access {

	const OPTION = 'npd_report_access';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'user_has_cap', array( __CLASS__, 'filter_caps' ), 10, 4 );
		add_action( 'admin_post_npd_report_access', array( __CLASS__, 'save' ) );
	}

	/**
	 * Saved access list.
	 *
	 * @return array{roles:string[],users:int[]}
	 */
	public static function get() {
		$o = get_option( self::OPTION, array() );
		return array(
			'roles' => isset( $o['roles'] ) && is_array( $o['roles'] ) ? array_map( 'strval', $o['roles'] ) : array(),
			'users' => isset( $o['users'] ) && is_array( $o['users'] ) ? array_map( 'intval', $o['users'] ) : array(),
		);
	}

	/**
	 * Grant npd_view_reports to admins, chosen roles and chosen users. Nothing else changes.
	 *
	 * @param array    $allcaps All caps.
	 * @param array    $caps    Required caps.
	 * @param array    $args    Args (cap, user id).
	 * @param WP_User  $user    User.
	 * @return array
	 */
	public static function filter_caps( $allcaps, $caps, $args, $user ) {
		if ( ! in_array( 'npd_view_reports', (array) $caps, true ) ) {
			return $allcaps;
		}
		if ( ! empty( $allcaps['manage_options'] ) ) {
			$allcaps['npd_view_reports'] = true;
			return $allcaps;
		}
		$a = self::get();
		if ( in_array( (int) $user->ID, $a['users'], true ) || array_intersect( (array) $user->roles, $a['roles'] ) ) {
			$allcaps['npd_view_reports'] = true;
		}
		return $allcaps;
	}

	/**
	 * Admin-only form, shown at the bottom of Reports.
	 */
	public static function form() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$a     = self::get();
		$names = array();
		foreach ( $a['users'] as $uid ) {
			$u = get_userdata( $uid );
			if ( $u ) {
				$names[] = $u->user_login;
			}
		}
		echo '<h2>' . esc_html__( 'Who can see these reports', 'nonprofit-donations' ) . '</h2>';
		echo '<div class="npd-tile" style="max-width:640px"><p class="description">' . esc_html__( 'Administrators always can. Add a role or specific people to let them view Reports and download the CSV, without making them administrators. They cannot change settings or mark donations Paid. The CSV has donor names, emails and phones, so add only people you trust.', 'nonprofit-donations' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="npd_report_access">';
		wp_nonce_field( 'npd_report_access' );
		echo '<p><label><b>' . esc_html__( 'Specific people (usernames, comma separated)', 'nonprofit-donations' ) . '</b><br><input type="text" name="users" class="large-text" value="' . esc_attr( implode( ', ', $names ) ) . '" placeholder="' . esc_attr__( 'e.g. priya, rahul', 'nonprofit-donations' ) . '"></label><br><span class="description">' . esc_html__( 'The simplest way. Only these people get access.', 'nonprofit-donations' ) . '</span></p>';
		$main  = array();
		$other = array();
		foreach ( wp_roles()->roles as $slug => $r ) {
			if ( 'administrator' === $slug ) {
				continue;
			}
			$name = translate_user_role( $r['name'] );
			if ( in_array( $slug, array( 'editor', 'author', 'shop_manager' ), true ) || preg_match( '/team|staff|manager|accountant|worker|volunteer|admin/i', $slug . ' ' . $r['name'] ) ) {
				$main[ $slug ] = $name;
			} else {
				$other[ $slug ] = $name;
			}
		}
		$box = static function ( $list, $sel ) {
			foreach ( $list as $slug => $name ) {
				echo '<label style="display:inline-block;margin:4px 14px 4px 0"><input type="checkbox" name="roles[]" value="' . esc_attr( $slug ) . '" ' . checked( in_array( $slug, $sel, true ), true, false ) . '> ' . esc_html( $name ) . '</label>';
			}
		};
		echo '<p><b>' . esc_html__( 'Or a whole staff role', 'nonprofit-donations' ) . '</b><br>';
		$box( $main, $a['roles'] );
		echo '</p>';
		if ( $other ) {
			$open = array_intersect( array_keys( $other ), $a['roles'] ) ? ' open' : '';
			echo '<details' . $open . ' style="margin:0 0 12px"><summary>' . esc_html__( 'Other roles on this site', 'nonprofit-donations' ) . '</summary><p>';
			$box( $other, $a['roles'] );
			echo '</p></details>';
		}
		echo '<p><button class="button button-primary">' . esc_html__( 'Save access', 'nonprofit-donations' ) . '</button></p></form></div>';
	}

	/**
	 * Save (admins only).
	 */
	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'nonprofit-donations' ), 403 );
		}
		check_admin_referer( 'npd_report_access' );
		$valid = array_keys( wp_roles()->roles );
		$roles = array();
		if ( isset( $_POST['roles'] ) && is_array( $_POST['roles'] ) ) {
			foreach ( wp_unslash( $_POST['roles'] ) as $r ) {
				$r = sanitize_key( $r );
				if ( 'administrator' !== $r && in_array( $r, $valid, true ) ) {
					$roles[] = $r;
				}
			}
		}
		$users = array();
		$bad   = array();
		$raw   = isset( $_POST['users'] ) ? sanitize_text_field( wp_unslash( $_POST['users'] ) ) : '';
		foreach ( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) as $login ) {
			$u = get_user_by( 'login', $login );
			if ( $u ) {
				$users[] = (int) $u->ID;
			} else {
				$bad[] = $login;
			}
		}
		update_option( self::OPTION, array( 'roles' => $roles, 'users' => array_values( array_unique( $users ) ) ), false );
		wp_safe_redirect( add_query_arg( array( 'page' => 'npd-reports', 'access' => $bad ? 'missing:' . rawurlencode( implode( ',', $bad ) ) : 'saved' ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
