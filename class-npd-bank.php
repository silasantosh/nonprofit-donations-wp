<?php
/**
 * Bank statement matching. Reads a statement file or pasted text, finds credits that match
 * waiting UPI donations, and only SUGGESTS them. An admin always taps Confirm.
 *
 * @package Nonprofit_Donations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Statement parser, matcher and admin screen.
 */
class NPD_Bank {

	const MAX_BYTES = 5242880;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_npd_bank', array( __CLASS__, 'handle' ) );
	}

	/**
	 * Menu.
	 */
	public static function menu() {
		add_submenu_page( 'npd', __( 'Bank statement', 'nonprofit-donations' ), __( 'Bank statement', 'nonprofit-donations' ), 'manage_options', 'npd-bank', array( __CLASS__, 'page' ) );
	}

	/**
	 * Turn statement text (CSV, TSV, or plain lines) into rows.
	 * Each row: text, amounts (credits in paise), date text, hash. Debits are skipped when a debit column is found.
	 *
	 * @param string $text File or pasted text.
	 * @return array[]
	 */
	public static function parse( $text ) {
		$text  = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $text );
		$lines = preg_split( '/\r\n|\r|\n/', $text );
		$lines = array_values( array_filter( $lines, function ( $l ) {
			return '' !== trim( $l );
		} ) );
		if ( ! $lines ) {
			return array();
		}
		$delim = self::delimiter( $lines );
		$cols  = null; // Column map from a header row.
		$out   = array();
		foreach ( $lines as $i => $line ) {
			$cells = '' === $delim ? array( $line ) : str_getcsv( $line, $delim, '"', '\\' );
			$cells = array_map( 'trim', $cells );
			if ( null === $cols && $i < 40 && self::is_header( $cells ) ) {
				$cols = self::map_header( $cells );
				continue;
			}
			$row_text = implode( ' ', $cells );
			if ( '' === $row_text ) {
				continue;
			}
			$amounts = array();
			if ( is_array( $cols ) && null !== $cols['credit'] ) {
				if ( isset( $cells[ $cols['credit'] ] ) ) {
					$p = self::to_paise( $cells[ $cols['credit'] ] );
					if ( $p > 0 ) {
						$amounts[] = $p;
					}
				}
			} elseif ( is_array( $cols ) && null !== $cols['debit'] ) {
				// Header has a debit column but no credit column: use only the other numbers except debit.
				foreach ( $cells as $k => $c ) {
					if ( $k === $cols['debit'] ) {
						continue;
					}
					$amounts = array_merge( $amounts, self::numbers( $c ) );
				}
			} else {
				$amounts = self::numbers( $row_text );
			}
			if ( ! $amounts ) {
				continue; // Not a credit row (or no amount found).
			}
			$date = ( is_array( $cols ) && null !== $cols['date'] && isset( $cells[ $cols['date'] ] ) ) ? $cells[ $cols['date'] ] : '';
			$out[] = array(
				'text'    => $row_text,
				'amounts' => array_values( array_unique( $amounts ) ),
				'date'    => $date,
				'hash'    => substr( md5( strtolower( preg_replace( '/\s+/', ' ', $row_text ) ) ), 0, 16 ),
			);
		}
		return $out;
	}

	/**
	 * Pick the most likely delimiter.
	 *
	 * @param string[] $lines Lines.
	 * @return string Delimiter, or empty for plain text.
	 */
	private static function delimiter( $lines ) {
		$best = '';
		$top  = 0;
		foreach ( array( ',', "\t", '|', ';' ) as $d ) {
			$n = 0;
			foreach ( array_slice( $lines, 0, 20 ) as $l ) {
				if ( substr_count( $l, $d ) >= 2 ) {
					++$n;
				}
			}
			if ( $n > $top ) {
				$top  = $n;
				$best = $d;
			}
		}
		return $top >= 2 ? $best : '';
	}

	/**
	 * Does this row look like a column header?
	 *
	 * @param string[] $cells Cells.
	 * @return bool
	 */
	private static function is_header( $cells ) {
		$j = strtolower( implode( ' ', $cells ) );
		return (bool) ( preg_match( '/\bdate\b/', $j ) && preg_match( '/narration|description|particulars|remarks|details/', $j ) );
	}

	/**
	 * Find the date, credit and debit columns.
	 *
	 * @param string[] $cells Header cells.
	 * @return array
	 */
	private static function map_header( $cells ) {
		$m = array( 'date' => null, 'credit' => null, 'debit' => null );
		foreach ( $cells as $k => $c ) {
			$c = strtolower( $c );
			if ( null === $m['date'] && preg_match( '/^(txn |transaction |value )?date|^date/', $c ) ) {
				$m['date'] = $k;
			}
			if ( null === $m['credit'] && preg_match( '/deposit|credit|\bcr\b/', $c ) && ! preg_match( '/debit/', $c ) ) {
				$m['credit'] = $k;
			}
			if ( null === $m['debit'] && preg_match( '/withdraw|debit|\bdr\b/', $c ) ) {
				$m['debit'] = $k;
			}
		}
		return $m;
	}

	/**
	 * Parse one money cell to paise (0 if not a number).
	 *
	 * @param string $s Cell.
	 * @return int
	 */
	private static function to_paise( $s ) {
		$s = preg_replace( '/[^0-9.]/', '', str_replace( ',', '', (string) $s ) );
		if ( '' === $s || ! preg_match( '/^\d+(\.\d{1,2})?$/', $s ) ) {
			return 0;
		}
		return (int) round( (float) $s * 100 );
	}

	/**
	 * Money-looking numbers inside free text: they need a decimal point or a thousands comma,
	 * so 12-digit references and dates are not mistaken for amounts.
	 *
	 * @param string $s Text.
	 * @return int[] Paise.
	 */
	private static function numbers( $s ) {
		$out = array();
		if ( preg_match_all( '/(?<!\d)(?<!\d\.)(\d{1,3}(?:,\d{2,3})+(?:\.\d{1,2})?|\d+\.\d{1,2})(?![\d])/', (string) $s, $m ) ) {
			foreach ( $m[1] as $n ) {
				$p = self::to_paise( $n );
				if ( $p > 0 ) {
					$out[] = $p;
				}
			}
		}
		// Whole-rupee amounts only count after a currency marker.
		if ( preg_match_all( '/(?:Rs\.?|INR|\x{20B9})\s*(\d[\d,]*)(?![\d.,]*\d)/iu', (string) $s, $m2 ) ) {
			foreach ( $m2[1] as $n ) {
				$p = self::to_paise( $n );
				if ( $p > 0 ) {
					$out[] = $p;
				}
			}
		}
		return $out;
	}

	/**
	 * Suggest matches. Pure function: needs rows and waiting donations only.
	 * A match needs the same amount AND the donor's reference (or DON-id) in the same row,
	 * and the row and the donation must pair up exactly one to one.
	 *
	 * @param array[]  $rows      Parsed rows.
	 * @param object[] $donations Waiting UPI donations (id, amount_paise, utr, review_flag).
	 * @param string[] $used      Row hashes already used.
	 * @return array{matches:array,unclear:array}
	 */
	public static function match( $rows, $donations, $used = array() ) {
		$pairs = array(); // donation id => list of row indexes.
		$rpair = array(); // row index => list of donation ids.
		foreach ( $donations as $d ) {
			$ref = strtoupper( (string) $d->utr );
			foreach ( $rows as $ri => $r ) {
				if ( in_array( $r['hash'], $used, true ) || ! in_array( (int) $d->amount_paise, $r['amounts'], true ) ) {
					continue;
				}
				$up  = strtoupper( $r['text'] );
				$hit = ( strlen( $ref ) >= 8 && false !== strpos( $up, $ref ) ) || (bool) preg_match( '/\bDON-' . (int) $d->id . '\b/', $up );
				if ( $hit ) {
					$pairs[ (int) $d->id ][] = $ri;
					$rpair[ $ri ][]          = (int) $d->id;
				}
			}
		}
		$matches = array();
		$unclear = array();
		foreach ( $pairs as $id => $ris ) {
			if ( 1 === count( $ris ) && 1 === count( $rpair[ $ris[0] ] ) ) {
				$matches[ $id ] = $rows[ $ris[0] ];
			} else {
				$unclear[] = $id; // Several rows, or one row for several donations: a person decides.
			}
		}
		return array( 'matches' => $matches, 'unclear' => $unclear );
	}

	/**
	 * Admin screen.
	 */
	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$res = isset( $_GET['res'] ) ? sanitize_text_field( wp_unslash( $_GET['res'] ) ) : '';
		$err = isset( $_GET['err'] ) ? sanitize_key( wp_unslash( $_GET['err'] ) ) : '';
		// phpcs:enable
		$msgs = array(
			'pdf'   => __( 'That looks like a PDF. Please download the CSV or Excel-as-CSV version of the statement from net banking, or paste the lines below.', 'nonprofit-donations' ),
			'empty' => __( 'Nothing to read. Choose a file or paste some lines.', 'nonprofit-donations' ),
			'big'   => __( 'That file is too large (5 MB limit).', 'nonprofit-donations' ),
		);
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Bank statement', 'nonprofit-donations' ); ?></h1>
			<?php if ( isset( $msgs[ $err ] ) ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $msgs[ $err ] ); ?></p></div>
			<?php endif; ?>
			<?php if ( '' !== $res ) : ?>
				<?php list( $rows, $m, $u ) = array_pad( array_map( 'absint', explode( '-', $res ) ), 3, 0 ); ?>
				<div class="notice notice-success"><p>
					<?php
					/* translators: 1: credit rows read, 2: matches, 3: unclear */
					echo esc_html( sprintf( __( 'Read %1$d credit lines. %2$d donations matched, %3$d need a person to decide.', 'nonprofit-donations' ), $rows, $m, $u ) );
					?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=npd-verify&bank=1' ) ); ?>"><?php echo esc_html__( 'Open the matches', 'nonprofit-donations' ); ?></a>
				</p></div>
			<?php endif; ?>
			<p class="description"><?php echo esc_html__( 'Upload your bank statement (CSV) or paste lines from it. The plugin finds credits with the same amount and the donor\'s UPI reference, and marks them as a bank match. Nothing is confirmed until you tap Confirm. The file is read once and not stored; only the match is saved.', 'nonprofit-donations' ); ?></p>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="npd_bank">
				<?php wp_nonce_field( 'npd_bank' ); ?>
				<p><label><b><?php echo esc_html__( 'Statement file (CSV, TXT)', 'nonprofit-donations' ); ?></b><br><input type="file" name="stmt" accept=".csv,.txt,.tsv"></label></p>
				<p><label><b><?php echo esc_html__( 'Or paste lines from the statement or a bank alert', 'nonprofit-donations' ); ?></b><br><textarea name="paste" rows="8" class="large-text code"></textarea></label></p>
				<p><button class="button button-primary"><?php echo esc_html__( 'Find matches', 'nonprofit-donations' ); ?></button></p>
			</form>
			<?php NPD_Flow::credits_box(); ?>
		</div>
		<?php
	}

	/**
	 * Handle an upload or paste.
	 */
	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'nonprofit-donations' ), 403 );
		}
		check_admin_referer( 'npd_bank' );
		$back = admin_url( 'admin.php?page=npd-bank' );
		$text = '';
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
		if ( ! empty( $_FILES['stmt']['tmp_name'] ) && is_uploaded_file( $_FILES['stmt']['tmp_name'] ) ) {
			if ( (int) $_FILES['stmt']['size'] > self::MAX_BYTES ) {
				wp_safe_redirect( add_query_arg( 'err', 'big', $back ) );
				exit;
			}
			$text = (string) file_get_contents( $_FILES['stmt']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		} elseif ( isset( $_POST['paste'] ) ) {
			$text = substr( (string) wp_unslash( $_POST['paste'] ), 0, self::MAX_BYTES );
		}
		// phpcs:enable
		if ( 0 === strncmp( $text, '%PDF', 4 ) ) {
			wp_safe_redirect( add_query_arg( 'err', 'pdf', $back ) );
			exit;
		}
		if ( '' === trim( $text ) ) {
			wp_safe_redirect( add_query_arg( 'err', 'empty', $back ) );
			exit;
		}
		$rows  = self::parse( $text );
		$dons  = array_filter( NPD_DB::list_donations( array( 'status' => 'pending', 'limit' => 500 ) ), function ( $d ) {
			return 'upi' === $d->mode;
		} );
		$used  = NPD_DB::used_bank_hashes();
		$found = self::match( $rows, $dons, $used );
		// Admin-uploaded file counts as statement evidence; pasted text never auto-confirms.
		$src = ! empty( $_FILES['stmt']['tmp_name'] ) ? 'statement' : 'paste'; // phpcs:ignore WordPress.Security
		NPD_Flow::apply_matches( $found['matches'], $dons, $src );
		NPD_Flow::remember_unknown( $rows, $found['matches'], $used );
		wp_safe_redirect( add_query_arg( 'res', count( $rows ) . '-' . count( $found['matches'] ) . '-' . count( $found['unclear'] ), $back ) );
		exit;
	}
}
