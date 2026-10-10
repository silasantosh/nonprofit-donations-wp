<?php
/**
 * Dedicated alert-only mailbox reader. Reads bank mails over IMAP (no PHP imap extension needed),
 * feeds their text to the bank matcher, and keeps nothing but the match. It never deletes, sends,
 * or marks mail, and it never confirms a donation: a person taps Confirm.
 *
 * @package Nonprofit_Donations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal IMAP client over a TLS socket.
 */
class NPD_Imap {

	/** @var resource|null */
	private $fp;
	/** @var int */
	private $n = 0;
	/** @var string */
	public $error = '';

	/**
	 * Connect and log in.
	 *
	 * @param string $host Host.
	 * @param int    $port Port.
	 * @param string $user User.
	 * @param string $pass Password.
	 * @param string $mode ssl (default) or tcp (tests only).
	 * @return bool
	 */
	public function open( $host, $port, $user, $pass, $mode = 'ssl' ) {
		$ctx = stream_context_create( array( 'ssl' => array( 'verify_peer' => true, 'verify_peer_name' => true ) ) );
		$this->fp = @stream_socket_client( ( 'tcp' === $mode ? 'tcp' : 'ssl' ) . '://' . $host . ':' . (int) $port, $en, $es, 20, STREAM_CLIENT_CONNECT, $ctx );
		if ( ! $this->fp ) {
			$this->error = 'Could not connect to ' . $host . ':' . (int) $port . ( $es ? ' (' . $es . ')' : '' );
			return false;
		}
		stream_set_timeout( $this->fp, 30 );
		$greet = fgets( $this->fp );
		if ( false === $greet || 0 !== strpos( $greet, '* OK' ) ) {
			$this->error = 'Unexpected greeting from the mail server.';
			return false;
		}
		$r = $this->cmd( 'LOGIN ' . $this->q( $user ) . ' ' . $this->q( $pass ) );
		if ( ! $r['ok'] ) {
			$this->error = 'Login was refused. Check the address and password (some providers need an app password).';
			return false;
		}
		return true;
	}

	/**
	 * Quote a string for IMAP.
	 *
	 * @param string $s String.
	 * @return string
	 */
	private function q( $s ) {
		return '"' . str_replace( array( '\\', '"', "\r", "\n" ), array( '\\\\', '\\"', '', '' ), (string) $s ) . '"';
	}

	/**
	 * Run one command and collect the response, including literals.
	 *
	 * @param string $cmd Command.
	 * @return array{ok:bool,lines:string[],lit:string[]}
	 */
	public function cmd( $cmd ) {
		$tag = 'A' . ( ++$this->n );
		fwrite( $this->fp, $tag . ' ' . $cmd . "\r\n" );
		$lines = array();
		$lit   = array();
		$ok    = false;
		while ( ( $line = fgets( $this->fp ) ) !== false ) {
			$line = rtrim( $line, "\r\n" );
			if ( preg_match( '/\{(\d+)\}$/', $line, $m ) ) {
				$len  = min( (int) $m[1], 1048576 );
				$data = '';
				while ( strlen( $data ) < (int) $m[1] && ! feof( $this->fp ) ) {
					$chunk = fread( $this->fp, (int) $m[1] - strlen( $data ) );
					if ( false === $chunk || '' === $chunk ) {
						break;
					}
					$data .= $chunk;
				}
				$lit[] = substr( $data, 0, $len );
			}
			if ( 0 === strpos( $line, $tag . ' ' ) ) {
				$ok = (bool) preg_match( '/^' . $tag . ' OK/', $line );
				break;
			}
			$lines[] = $line;
		}
		return array( 'ok' => $ok, 'lines' => $lines, 'lit' => $lit );
	}

	/**
	 * Open the folder read-only.
	 *
	 * @param string $box Folder.
	 * @return array{ok:bool,uidvalidity:int,exists:int}
	 */
	public function examine( $box = 'INBOX' ) {
		$r  = $this->cmd( 'EXAMINE ' . $this->q( $box ) );
		$uv = 0;
		$ex = 0;
		foreach ( $r['lines'] as $l ) {
			if ( preg_match( '/UIDVALIDITY (\d+)/', $l, $m ) ) {
				$uv = (int) $m[1];
			}
			if ( preg_match( '/^\* (\d+) EXISTS/', $l, $m ) ) {
				$ex = (int) $m[1];
			}
		}
		return array( 'ok' => $r['ok'], 'uidvalidity' => $uv, 'exists' => $ex );
	}

	/**
	 * UIDs above a number.
	 *
	 * @param int $after Last seen UID.
	 * @return int[]
	 */
	public function uids_after( $after ) {
		$r    = $this->cmd( 'UID SEARCH UID ' . ( (int) $after + 1 ) . ':*' );
		$uids = array();
		foreach ( $r['lines'] as $l ) {
			if ( 0 === strpos( $l, '* SEARCH' ) ) {
				foreach ( preg_split( '/\s+/', trim( substr( $l, 8 ) ) ) as $u ) {
					if ( ctype_digit( $u ) && (int) $u > (int) $after ) { // "N:*" always returns the last message.
						$uids[] = (int) $u;
					}
				}
			}
		}
		sort( $uids );
		return $uids;
	}

	/**
	 * Fetch a whole message without marking it read.
	 *
	 * @param int $uid UID.
	 * @return string Raw message (capped).
	 */
	public function fetch( $uid ) {
		$r = $this->cmd( 'UID FETCH ' . (int) $uid . ' BODY.PEEK[]<0.262144>' );
		return $r['ok'] && $r['lit'] ? (string) $r['lit'][0] : '';
	}

	/**
	 * Log out.
	 */
	public function close() {
		if ( $this->fp ) {
			@fwrite( $this->fp, 'A999 LOGOUT' . "\r\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			@fclose( $this->fp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			$this->fp = null;
		}
	}
}

/**
 * Mailbox settings, polling, and per-provider guidance screen.
 */
class NPD_Mailbox {

	const OPT = 'npd_mailbox';
	const CRON = 'npd_mail_poll';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 21 );
		add_action( 'admin_post_npd_mailbox_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_npd_mailbox_run', array( __CLASS__, 'run_now' ) );
		add_action( self::CRON, array( __CLASS__, 'cron_poll' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'rest' ) );
		add_action( 'admin_post_npd_push_save', array( __CLASS__, 'push_save' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval
	}

	/**
	 * Add a 15 minute cron interval.
	 *
	 * @param array $s Schedules.
	 * @return array
	 */
	public static function schedules( $s ) {
		$s['npd_15min'] = array( 'interval' => 900, 'display' => __( 'Every 15 minutes', 'nonprofit-donations' ) );
		$s['npd_1min']  = array( 'interval' => 60, 'display' => __( 'Every minute', 'nonprofit-donations' ) );
		return $s;
	}

	/**
	 * Menu.
	 */
	public static function menu() {
		add_submenu_page( 'npd', __( 'Alert mailbox', 'nonprofit-donations' ), __( 'Alert mailbox', 'nonprofit-donations' ), 'manage_options', 'npd-mailbox', array( __CLASS__, 'page' ) );
	}

	/**
	 * Saved settings.
	 *
	 * @return array
	 */
	public static function opts() {
		return wp_parse_args(
			get_option( self::OPT, array() ),
			array(
				'enabled'  => 0,
				'provider' => 'domain',
				'host'     => '',
				'port'     => 993,
				'user'     => '',
				'pass'     => '',
				'senders'  => '',
				'uidv'     => 0,
				'last_uid' => 0,
				'last_run' => '',
				'last_msg' => '',
			)
		);
	}

	/**
	 * Provider presets and plain-words setup steps. Items marked "verify" were not confirmed on the
	 * provider's official pages when this was written, so the screen says so.
	 *
	 * @return array
	 */
	public static function providers() {
		return array(
			'domain' => array(
				'label' => __( 'My own domain mail (Hostinger, cPanel, other webmail)', 'nonprofit-donations' ),
				'host'  => '',
				'steps' => array(
					__( 'In your hosting panel create a new mailbox just for bank alerts, for example bank-alerts@yourdomain.org. Do not use a mailbox people write to.', 'nonprofit-donations' ),
					__( 'Hostinger: server imap.hostinger.com, port 993 (SSL). cPanel hosts: usually mail.yourdomain.org, port 993. Your host\'s email help page lists the exact server.', 'nonprofit-donations' ),
					__( 'Type that server, the full mailbox address and its password in the boxes below. The password is stored encrypted on this site only.', 'nonprofit-donations' ),
					__( 'In your bank\'s net banking, add this mailbox as the email for statements and credit alerts, or forward your bank mails to it (see "Forward from another mail" below).', 'nonprofit-donations' ),
					__( 'Press Test connection. It only logs in and counts mail.', 'nonprofit-donations' ),
				),
			),
			'gmail'  => array(
				'label' => __( 'Gmail (forward to a dedicated mailbox)', 'nonprofit-donations' ),
				'host'  => 'imap.gmail.com',
				'steps' => array(
					__( 'Best way: do not give this plugin your Gmail password. Create a separate alert-only mailbox (any provider above) and forward bank mails to it.', 'nonprofit-donations' ),
					__( 'In Gmail: Settings > See all settings > Forwarding and POP/IMAP > Add a forwarding address. Enter the alert mailbox address.', 'nonprofit-donations' ),
					__( 'Google sends a confirmation mail to the alert mailbox. Open it there and click the link. Then pick the address in Gmail.', 'nonprofit-donations' ),
					__( 'Settings > Filters and blocked addresses > Create a new filter: From = your bank\'s alert sender, then Forward it to the alert mailbox. Using a filter avoids forwarding all your mail.', 'nonprofit-donations' ),
					__( 'Reading Gmail directly is not offered here: Google asks apps to use "Sign in with Google", and app passwords are only possible with 2-Step Verification and are not available on every account.', 'nonprofit-donations' ),
				),
			),
			'outlook' => array(
				'label' => __( 'Outlook / Hotmail / Microsoft 365 (forward to a dedicated mailbox)', 'nonprofit-donations' ),
				'host'  => 'outlook.office365.com',
				'steps' => array(
					__( 'Microsoft requires OAuth sign-in for reading mail by IMAP, and basic password sign-in is switched off for Microsoft 365. This plugin does not read Outlook directly.', 'nonprofit-donations' ),
					__( 'Instead create an alert-only mailbox with another provider and forward your bank mails to it.', 'nonprofit-donations' ),
					__( 'In Outlook: Settings > Mail > Rules (or Forwarding): add a rule "From = your bank\'s alert sender, forward to the alert mailbox". Exact menu names differ between Outlook.com and Microsoft 365: verify at setup.', 'nonprofit-donations' ),
					__( 'Check the alert mailbox for the first forwarded mail before relying on it.', 'nonprofit-donations' ),
				),
			),
			'zoho'   => array(
				'label' => __( 'Zoho Mail', 'nonprofit-donations' ),
				'host'  => 'imap.zoho.com',
				'steps' => array(
					__( 'Zoho Mail works as the alert mailbox. In Zoho: Settings > Mail Accounts > IMAP Access, tick "Enable IMAP Access". Organisation admins may have disabled it: ask your admin.', 'nonprofit-donations' ),
					__( 'Server: imap.zoho.com, port 993 (SSL). Organisation accounts may use imappro.zoho.com instead. Use a mailbox just for bank alerts.', 'nonprofit-donations' ),
					__( 'If you use two-factor sign-in, create an app-specific password in your Zoho account security page and use that as the password here.', 'nonprofit-donations' ),
					__( 'To send bank mails from another mailbox into Zoho, add a forwarding rule there. Zoho\'s own forwarding steps were not confirmed: verify at setup.', 'nonprofit-donations' ),
				),
			),
		);
	}

	/**
	 * Settings and guidance screen.
	 */
	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o     = self::opts();
		$prov  = self::providers();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$note  = isset( $_GET['note'] ) ? sanitize_text_field( wp_unslash( $_GET['note'] ) ) : '';
		// phpcs:enable
		$sec   = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Alert mailbox', 'nonprofit-donations' ); ?></h1>
			<?php if ( '' !== $note ) : ?>
				<div class="notice notice-info"><p><?php echo esc_html( $note ); ?></p></div>
			<?php endif; ?>
			<p class="description"><?php echo esc_html__( 'Optional. The plugin reads one dedicated mailbox that only receives bank credit alerts and e-statements, finds credits that match waiting donations, and marks them as a bank match. It never replies, deletes or marks mail, keeps nothing but the match, and never confirms a donation by itself: you always tap Confirm. You can also upload a statement by hand on the Bank statement page. Do not use your main email account here.', 'nonprofit-donations' ); ?></p>
			<h2><?php echo esc_html__( 'Which mail do you use?', 'nonprofit-donations' ); ?></h2>
			<?php foreach ( $prov as $k => $p ) : ?>
				<details style="margin:6px 0;background:#fff;border:1px solid #c3c4c7;padding:8px 12px">
					<summary><b><?php echo esc_html( $p['label'] ); ?></b></summary>
					<ol><?php foreach ( $p['steps'] as $s ) : ?><li><?php echo esc_html( $s ); ?></li><?php endforeach; ?></ol>
				</details>
			<?php endforeach; ?>
			<h2><?php echo esc_html__( 'Connect the alert mailbox', 'nonprofit-donations' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="npd_mailbox_save">
				<?php wp_nonce_field( 'npd_mailbox' ); ?>
				<table class="form-table" role="presentation">
					<tr><th><?php echo esc_html__( 'Provider', 'nonprofit-donations' ); ?></th><td><select name="provider"><?php foreach ( $prov as $k => $p ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $o['provider'], $k ); ?>><?php echo esc_html( $p['label'] ); ?></option><?php endforeach; ?></select></td></tr>
					<tr><th><?php echo esc_html__( 'IMAP server', 'nonprofit-donations' ); ?></th><td><input type="text" name="host" class="regular-text" value="<?php echo esc_attr( $o['host'] ); ?>" placeholder="imap.hostinger.com"> <input type="number" name="port" style="width:80px" value="<?php echo esc_attr( $o['port'] ); ?>"></td></tr>
					<tr><th><?php echo esc_html__( 'Mailbox address', 'nonprofit-donations' ); ?></th><td><input type="text" name="user" class="regular-text" value="<?php echo esc_attr( $o['user'] ); ?>" autocomplete="off"></td></tr>
					<tr><th><?php echo esc_html__( 'Password', 'nonprofit-donations' ); ?></th><td><input type="password" name="pass" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo '' !== $o['pass'] ? esc_attr__( 'Saved. Leave empty to keep it.', 'nonprofit-donations' ) : ''; ?>"></td></tr>
					<tr><th><?php echo esc_html__( 'Only read mail from', 'nonprofit-donations' ); ?></th><td><input type="text" name="senders" class="regular-text" value="<?php echo esc_attr( $o['senders'] ); ?>" placeholder="hdfcbank.net, hdfcbank.bank.in"><br><span class="description"><?php echo esc_html__( 'Your bank\'s sending domains, separated by commas. Mail from anyone else is ignored. Strongly recommended.', 'nonprofit-donations' ); ?></span></td></tr>
					<tr><th><?php echo esc_html__( 'Check the mailbox', 'nonprofit-donations' ); ?></th><td><label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $o['enabled'] ) ); ?>> <?php echo esc_html__( 'Check about every minute while a gift is waiting (every 15 minutes when idle)', 'nonprofit-donations' ); ?></label>
					<?php if ( ! $sec ) : ?><br><span class="description"><?php echo esc_html__( 'Runs when your site gets visits, so it can be late on a quiet site. A server cron job that opens wp-cron.php every 15 minutes makes it steady.', 'nonprofit-donations' ); ?></span><?php endif; ?></td></tr>
				</table>
				<p>
					<button class="button button-primary"><?php echo esc_html__( 'Save', 'nonprofit-donations' ); ?></button>
					<button class="button" name="test" value="1"><?php echo esc_html__( 'Save and test connection', 'nonprofit-donations' ); ?></button>
				</p>
			</form>
			<h2><?php echo esc_html__( 'Forwarded mail (no inbox needed)', 'nonprofit-donations' ); ?></h2>
			<?php $pp = self::push_opts(); ?>
			<p class="description"><?php echo esc_html__( 'For a domain that only forwards mail. Your own mail rule posts each bank alert to this site, signed with a shared secret. Nothing is stored except the match. Off by default.', 'nonprofit-donations' ); ?></p>
			<p><code><?php echo esc_html( rest_url( 'npd/v1/mail' ) ); ?></code></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="npd_push_save">
				<?php wp_nonce_field( 'npd_push' ); ?>
				<table class="form-table" role="presentation">
					<tr><th><?php echo esc_html__( 'Shared secret', 'nonprofit-donations' ); ?></th><td><input type="password" name="push_secret" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo '' !== $pp['secret'] ? esc_attr__( 'Saved. Leave empty to keep it.', 'nonprofit-donations' ) : esc_attr__( 'At least 24 characters', 'nonprofit-donations' ); ?>"></td></tr>
					<tr><th><?php echo esc_html__( 'Only accept mail from', 'nonprofit-donations' ); ?></th><td><input type="text" name="push_senders" class="regular-text" value="<?php echo esc_attr( $pp['senders'] ); ?>"></td></tr>
					<tr><th><?php echo esc_html__( 'Receive forwarded mail', 'nonprofit-donations' ); ?></th><td><label><input type="checkbox" name="push_enabled" value="1" <?php checked( ! empty( $pp['enabled'] ) ); ?>> <?php echo esc_html__( 'On', 'nonprofit-donations' ); ?></label><?php if ( '' !== $pp['last'] ) : ?> <span class="description"><?php echo esc_html( $pp['last'] ); ?></span><?php endif; ?></td></tr>
				</table>
				<p><button class="button button-primary"><?php echo esc_html__( 'Save', 'nonprofit-donations' ); ?></button></p>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="npd_mailbox_run">
				<?php wp_nonce_field( 'npd_mailbox_run' ); ?>
				<p><button class="button"><?php echo esc_html__( 'Check now', 'nonprofit-donations' ); ?></button>
				<?php if ( '' !== $o['last_run'] ) : ?><span class="description"> <?php echo esc_html( sprintf( /* translators: 1: time, 2: result */ __( 'Last check %1$s: %2$s', 'nonprofit-donations' ), $o['last_run'], $o['last_msg'] ) ); ?></span><?php endif; ?></p>
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
		check_admin_referer( 'npd_mailbox' );
		$o = self::opts();
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$prov = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : 'domain';
		$new  = array(
			'provider' => isset( self::providers()[ $prov ] ) ? $prov : 'domain',
			'host'     => isset( $_POST['host'] ) ? preg_replace( '/[^a-z0-9.\-]/i', '', wp_unslash( $_POST['host'] ) ) : '',
			'port'     => isset( $_POST['port'] ) ? min( 65535, max( 1, absint( $_POST['port'] ) ) ) : 993,
			'user'     => isset( $_POST['user'] ) ? sanitize_text_field( wp_unslash( $_POST['user'] ) ) : '',
			'senders'  => isset( $_POST['senders'] ) ? sanitize_text_field( wp_unslash( $_POST['senders'] ) ) : '',
			'enabled'  => empty( $_POST['enabled'] ) ? 0 : 1,
		);
		$pw = isset( $_POST['pass'] ) ? (string) wp_unslash( $_POST['pass'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		// phpcs:enable
		if ( '' !== $pw ) {
			$new['pass'] = NPD_Settings::encrypt( $pw );
		}
		if ( $new['host'] !== $o['host'] || $new['user'] !== $o['user'] ) {
			$new['uidv']     = 0;
			$new['last_uid'] = 0; // New mailbox: start from now, not from old mail.
			$new['fresh']    = 1;
		}
		if ( '' === $new['host'] || '' === $new['user'] ) {
			$new['pass']    = ''; // Mailbox removed on purpose: forget the password too.
			$new['enabled'] = 0;
		}
		update_option( self::OPT, array_merge( $o, $new ), false );
		delete_option( 'npd_mail_health' ); // A saved change starts a clean slate; the next run decides again.
		delete_transient( 'npd_mail_backoff' );
		wp_clear_scheduled_hook( self::CRON );
		if ( $new['enabled'] ) {
			wp_schedule_event( time() + 60, 'npd_1min', self::CRON );
		}
		$note = __( 'Saved.', 'nonprofit-donations' );
		if ( ! empty( $_POST['test'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$r    = self::test();
			$note = $r['msg'];
		}
		wp_safe_redirect( add_query_arg( 'note', rawurlencode( $note ), admin_url( 'admin.php?page=npd-mailbox' ) ) );
		exit;
	}

	/**
	 * Check now button.
	 */
	public static function run_now() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'nonprofit-donations' ), 403 );
		}
		check_admin_referer( 'npd_mailbox_run' );
		$r = self::poll();
		wp_safe_redirect( add_query_arg( 'note', rawurlencode( $r['msg'] ), admin_url( 'admin.php?page=npd-mailbox' ) ) );
		exit;
	}

	/**
	 * Connect with the saved details.
	 *
	 * @return NPD_Imap|string Client or error text.
	 */
	private static function connect() {
		$o = self::opts();
		if ( '' === $o['host'] || '' === $o['user'] || '' === $o['pass'] ) {
			return __( 'Fill in the server, mailbox address and password first.', 'nonprofit-donations' );
		}
		$c    = new NPD_Imap();
		$mode = defined( 'NPD_IMAP_TEST_TCP' ) && NPD_IMAP_TEST_TCP ? 'tcp' : 'ssl';
		if ( ! $c->open( $o['host'], (int) $o['port'], $o['user'], NPD_Settings::decrypt( $o['pass'] ), $mode ) ) {
			return $c->error;
		}
		return $c;
	}

	/**
	 * Test: log in and count mail. Reads nothing.
	 *
	 * @return array{ok:bool,msg:string}
	 */
	public static function test() {
		$c = self::connect();
		if ( is_string( $c ) ) {
			return array( 'ok' => false, 'msg' => $c );
		}
		$e = $c->examine();
		$c->close();
		/* translators: %d: number of messages */
		return $e['ok'] ? array( 'ok' => true, 'msg' => sprintf( __( 'Connected. The mailbox has %d messages. Nothing was read or changed.', 'nonprofit-donations' ), $e['exists'] ) ) : array( 'ok' => false, 'msg' => __( 'Logged in, but could not open the inbox.', 'nonprofit-donations' ) );
	}

	/**
	 * Is the sender allowed?
	 *
	 * @param string $from Raw From header.
	 * @param string $list Comma separated domains.
	 * @return bool
	 */
	public static function sender_ok( $from, $list ) {
		$list = array_filter( array_map( 'trim', explode( ',', strtolower( (string) $list ) ) ) );
		if ( ! $list ) {
			return true;
		}
		if ( ! preg_match( '/[\w.+\-]+@([\w.\-]+)/', strtolower( (string) $from ), $m ) ) {
			return false;
		}
		foreach ( $list as $d ) {
			$d = ltrim( $d, '@' );
			if ( $m[1] === $d || substr( $m[1], -strlen( '.' . $d ) ) === '.' . $d ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Turn a raw message into plain text (headers From/Subject, then decoded text parts).
	 *
	 * @param string $raw Raw message.
	 * @return array{from:string,text:string}
	 */
	public static function message_text( $raw ) {
		$raw = str_replace( "\r\n", "\n", (string) $raw );
		$parts = explode( "\n\n", $raw, 2 );
		$head  = preg_replace( "/\n[ \t]+/", ' ', $parts[0] );
		$body  = isset( $parts[1] ) ? $parts[1] : '';
		$from  = preg_match( '/^From:\s*(.+)$/mi', $head, $m ) ? trim( $m[1] ) : '';
		$text  = self::decode_part( $head, $body );
		return array( 'from' => $from, 'text' => $text );
	}

	/**
	 * Decode one MIME entity, recursing into multiparts.
	 *
	 * @param string $head Headers.
	 * @param string $body Body.
	 * @param int    $depth Depth.
	 * @return string
	 */
	private static function decode_part( $head, $body, $depth = 0 ) {
		if ( $depth > 4 ) {
			return '';
		}
		$ctype = preg_match( '/^Content-Type:\s*([^;\n]+)/mi', $head, $m ) ? strtolower( trim( $m[1] ) ) : 'text/plain';
		if ( 0 === strpos( $ctype, 'multipart/' ) && preg_match( '/boundary="?([^";\n]+)"?/i', $head, $b ) ) {
			$out = '';
			foreach ( explode( '--' . $b[1], $body ) as $seg ) {
				$seg = ltrim( $seg, "\n" );
				if ( '' === trim( $seg ) || 0 === strpos( $seg, '--' ) ) {
					continue;
				}
				$p    = explode( "\n\n", $seg, 2 );
				$out .= "\n" . self::decode_part( preg_replace( "/\n[ \t]+/", ' ', $p[0] ), isset( $p[1] ) ? $p[1] : '', $depth + 1 );
			}
			return $out;
		}
		if ( 0 !== strpos( $ctype, 'text/' ) ) {
			return ''; // Attachments are not read here.
		}
		$enc = preg_match( '/^Content-Transfer-Encoding:\s*(\S+)/mi', $head, $m ) ? strtolower( $m[1] ) : '7bit';
		if ( 'base64' === $enc ) {
			$body = (string) base64_decode( $body ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		} elseif ( 'quoted-printable' === $enc ) {
			$body = quoted_printable_decode( $body );
		}
		if ( 'text/html' === $ctype ) {
			$body = preg_replace( '/<(br|\/p|\/tr|\/div)[^>]*>/i', "\n", $body );
			$body = html_entity_decode( wp_strip_all_tags( $body ), ENT_QUOTES, 'UTF-8' );
		}
		return $body;
	}

	/**
	 * Poll the mailbox: read new mail, find matches, remember the last UID. Suggest only.
	 *
	 * @return array{ok:bool,msg:string}
	 */
	public static function cron_poll() {
		$o = self::opts();
		$n = NPD_Flow::waiting_count();
		// Every minute while a gift is waiting; otherwise at most once every 15 minutes, so a quiet site barely connects.
		if ( ! $n && ! empty( $o['last_run'] ) && time() - (int) strtotime( $o['last_run'] ) < 840 ) {
			return;
		}
		self::poll();
	}

	/**
	 * Poll the mailbox now.
	 *
	 * @return array{ok:bool,msg:string}
	 */
	public static function poll() {
		$o = self::opts();
		if ( empty( $o['enabled'] ) && ! doing_action( 'admin_post_npd_mailbox_run' ) ) {
			return array( 'ok' => false, 'msg' => 'off' );
		}
		$c = self::connect();
		if ( is_string( $c ) ) {
			return self::done( false, $c );
		}
		$e = $c->examine();
		if ( ! $e['ok'] ) {
			$c->close();
			return self::done( false, __( 'Could not open the inbox.', 'nonprofit-donations' ) );
		}
		if ( (int) $o['uidv'] !== (int) $e['uidvalidity'] || ! empty( $o['fresh'] ) ) {
			// First run or a rebuilt mailbox: begin after the newest existing mail, so old mail is not replayed.
			$all  = $c->uids_after( 0 );
			$o['last_uid'] = $all ? max( $all ) : 0;
			$o['uidv']     = (int) $e['uidvalidity'];
			unset( $o['fresh'] );
			update_option( self::OPT, $o, false );
			if ( empty( $_POST['npd_first_scan'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$c->close();
				return self::done( true, __( 'Connected. Watching for new bank mail from now on.', 'nonprofit-donations' ) );
			}
		}
		$uids = array_slice( $c->uids_after( (int) $o['last_uid'] ), 0, 50 );
		$rows = array();
		$read = 0;
		$auth = true;
		foreach ( $uids as $uid ) {
			$raw  = $c->fetch( $uid );
			$msg  = self::message_text( $raw );
			$o['last_uid'] = $uid;
			if ( ! self::sender_ok( $msg['from'], $o['senders'] ) ) {
				continue;
			}
			++$read;
			$auth = $auth && self::authenticated( $raw );
			$rows = array_merge( $rows, NPD_Bank::parse( $msg['text'] ) );
		}
		$c->close();
		$dons = array_filter( NPD_DB::list_donations( array( 'status' => 'pending', 'limit' => 500 ) ), function ( $d ) {
			return 'upi' === $d->mode;
		} );
		$n = 0;
		if ( $rows && $dons ) {
			$found = NPD_Bank::match( $rows, $dons, NPD_DB::used_bank_hashes() );
			$res = NPD_Flow::apply_matches( $found['matches'], $dons, 'mail', $auth );
			$n   = $res['matched'];
		}
		update_option( self::OPT, array_merge( self::opts(), array( 'last_uid' => $o['last_uid'] ) ), false );
		/* translators: 1: mails read, 2: matches */
		return self::done( true, sprintf( __( '%1$d new bank mails read, %2$d donations matched.', 'nonprofit-donations' ), $read, $n ) );
	}

	/**
	 * Did the receiving server record dkim=pass and (spf=pass or dmarc=pass) for this mail?
	 * Without that header the mail is only a suggestion, never an automatic confirm.
	 *
	 * @param string $raw Raw message.
	 * @return bool
	 */
	public static function authenticated( $raw ) {
		$raw  = str_replace( "\r\n", "\n", (string) $raw );
		$head = explode( "\n\n", $raw, 2 );
		$head = preg_replace( "/\n[ \t]+/", ' ', $head[0] ); // Headers only: a body line can never pass.
		if ( ! preg_match_all( '/^Authentication-Results:\s*(.+)$/mi', $head, $m ) ) {
			return false;
		}
		foreach ( $m[1] as $v ) {
			$v = strtolower( $v );
			if ( preg_match( '/dkim=pass/', $v ) && preg_match( '/(spf|dmarc)=pass/', $v ) && ! preg_match( '/(dkim|spf|dmarc)=(fail|softfail|none)/', $v ) ) {
				return true;
			}
		}
		return false;
	}

	const PUSH = 'npd_push';

	/**
	 * Push settings: off by default. The secret is stored encrypted and never shown again.
	 *
	 * @return array
	 */
	public static function push_opts() {
		return wp_parse_args( get_option( self::PUSH, array() ), array( 'enabled' => 0, 'secret' => '', 'senders' => '', 'last' => '' ) );
	}

	/**
	 * Register the receiving route.
	 */
	public static function rest() {
		register_rest_route(
			'npd/v1',
			'/mail',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'push_receive' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Receive one forwarded mail. Needs a valid HMAC of "timestamp.body" made with the shared secret,
	 * a timestamp within 5 minutes, and a body not seen before. Same matching rules as the mailbox.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public static function push_receive( $req ) {
		$p = self::push_opts();
		if ( empty( $p['enabled'] ) || '' === $p['secret'] ) {
			return new WP_REST_Response( array( 'ok' => false ), 404 );
		}
		$body = (string) $req->get_body();
		$ts   = (int) $req->get_header( 'x-npd-time' );
		$sig  = strtolower( (string) $req->get_header( 'x-npd-sig' ) );
		$key  = NPD_Settings::decrypt( $p['secret'] );
		if ( '' === $key || strlen( $body ) > 400000 || abs( time() - $ts ) > 300 ) {
			return new WP_REST_Response( array( 'ok' => false ), 403 );
		}
		if ( ! hash_equals( hash_hmac( 'sha256', $ts . '.' . $body, $key ), $sig ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 403 );
		}
		$seen = 'npd_push_' . md5( $body );
		if ( get_transient( $seen ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'dup' => true ), 200 );
		}
		set_transient( $seen, 1, DAY_IN_SECONDS );
		$msg = self::message_text( $body );
		$o   = self::opts();
		$list = '' !== $p['senders'] ? $p['senders'] : $o['senders'];
		if ( ! self::sender_ok( $msg['from'], $list ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'matched' => 0, 'ignored' => 'sender' ), 200 );
		}
		$rows = NPD_Bank::parse( $msg['text'] );
		$dons = array_filter( NPD_DB::list_donations( array( 'status' => 'pending', 'limit' => 500 ) ), function ( $d ) {
			return 'upi' === $d->mode;
		} );
		$n = 0;
		if ( $rows && $dons ) {
			$found = NPD_Bank::match( $rows, $dons, NPD_DB::used_bank_hashes() );
			$res   = NPD_Flow::apply_matches( $found['matches'], $dons, 'mail', self::authenticated( $body ) );
			$n     = $res['matched'];
		}
		update_option( self::PUSH, array_merge( $p, array( 'last' => current_time( 'mysql' ) . ': ' . count( $rows ) . ' credits read, ' . $n . ' matched' ) ), false );
		return new WP_REST_Response( array( 'ok' => true, 'matched' => $n ), 200 );
	}

	/**
	 * Save push settings (admin only).
	 */
	public static function push_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'nonprofit-donations' ), 403 );
		}
		check_admin_referer( 'npd_push' );
		$p = self::push_opts();
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput
		$p['enabled'] = empty( $_POST['push_enabled'] ) ? 0 : 1;
		$p['senders'] = isset( $_POST['push_senders'] ) ? sanitize_text_field( wp_unslash( $_POST['push_senders'] ) ) : '';
		$sec          = isset( $_POST['push_secret'] ) ? (string) wp_unslash( $_POST['push_secret'] ) : '';
		// phpcs:enable
		if ( strlen( $sec ) >= 24 ) {
			$p['secret'] = NPD_Settings::encrypt( $sec );
		}
		if ( '' === $p['secret'] ) {
			$p['enabled'] = 0;
		}
		update_option( self::PUSH, $p, false );
		wp_safe_redirect( add_query_arg( 'note', rawurlencode( __( 'Saved.', 'nonprofit-donations' ) ), admin_url( 'admin.php?page=npd-mailbox' ) ) );
		exit;
	}

	/**
	 * Record the result of a run.
	 *
	 * @param bool   $ok  Success.
	 * @param string $msg Message.
	 * @return array
	 */
	private static function done( $ok, $msg ) {
		update_option( self::OPT, array_merge( self::opts(), array( 'last_run' => current_time( 'mysql' ), 'last_msg' => $msg ) ), false );
		NPD_Flow::mail_health( $ok, $msg );
		return array( 'ok' => $ok, 'msg' => $msg );
	}
}
