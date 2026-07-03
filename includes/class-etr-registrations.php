<?php
namespace Etr;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Renders the registrations table (one table per section), the
 * [etr_registrations] shortcode, editor-only player cards and no-show toggle
 * (AJAX), and the CSV / pairing exports.
 */
class Registrations {

	private static $instance = null;

	public static function instance() {
		if ( ! self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'etr_registrations', [ $this, 'shortcode' ] );
		add_action( 'admin_post_etr_export',       [ $this, 'handle_export' ] );
		add_action( 'wp_ajax_etr_toggle_noshow',   [ $this, 'ajax_toggle_noshow' ] );
	}

	/**
	 * Full registrations markup for an event: a section heading + table for each
	 * non-empty section, or the configured empty message.
	 */
	public function render( $event_id ) {
		$event_id = (int) $event_id;
		$sections = Plugin::instance()->build_sections( $event_id );
		$opts     = Plugin::instance()->get_opts();

		// Ensure the table CSS + print/tab JS are present, including when the
		// table is embedded via shortcode off the single-event page.
		wp_enqueue_style( 'etr-registrations', ETR_URL . 'assets/etr-registrations.css', [], ETR_VERSION );
		wp_enqueue_script( 'etr-tabs', ETR_URL . 'assets/etr-tabs.js', [], ETR_VERSION, true );

		if ( empty( $sections ) ) {
			return '<div class="etr-registrations etr-registrations--empty"><p>'
				. esc_html( $opts['empty_text'] ) . '</p></div>';
		}

		// Player cards (all of an attendee's fields) are shown to users who can
		// edit the event only, since they surface non-public registration data.
		$can_edit    = current_user_can( 'edit_post', $event_id );
		$card_fields = $can_edit ? Plugin::instance()->get_attendee_card_fields() : [];
		$cards       = '';

		// Editors get the no-show toggle, which posts to admin-ajax with a nonce.
		if ( $can_edit ) {
			// The editor view embeds per-registrant PII and tokenized edit links.
			// Never let a page cache store this response and later serve it to an
			// anonymous visitor — belt-and-suspenders over "don't cache logged-in".
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			wp_localize_script( 'etr-tabs', 'etrData', [
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'etr_status' ),
			] );
		}

		ob_start();
		echo '<div class="etr-registrations">';
		echo $this->render_toolbar( $event_id ); // phpcs:ignore WordPress.Security.EscapeOutput — escaped within

		foreach ( $sections as $label => $rows ) {
			echo '<section class="etr-section">';
			printf(
				'<h3 class="etr-section-title">%s <span class="etr-section-count">(%d)</span></h3>',
				esc_html( $label ),
				count( $rows )
			);
			echo '<table class="etr-table"><thead><tr>';
			echo '<th class="etr-col-num" scope="col">#</th>';
			echo '<th class="etr-col-name" scope="col">' . esc_html__( 'Name', 'etr' ) . '</th>';
			echo '<th class="etr-col-uscf" scope="col">' . esc_html__( 'USCF ID', 'etr' ) . '</th>';
			echo '<th class="etr-col-rating" scope="col">' . esc_html__( 'Rating', 'etr' ) . '</th>';
			echo '</tr></thead><tbody>';

			$seed = 1;
			foreach ( $rows as $r ) {
				$is_ns = ! empty( $r['noshow'] );
				printf(
					'<tr class="etr-row%s" data-etr-row="%d">',
					$is_ns ? ' etr-row--noshow' : '',
					(int) $r['id']
				);
				echo '<td class="etr-col-num">' . ( $is_ns ? '&mdash;' : esc_html( $seed ) ) . '</td>';
				echo '<td class="etr-col-name">' . $this->name_cell( $r, $can_edit ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput — escaped within
				echo '<td class="etr-col-uscf">' . $this->uscf_id_cell( $r ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput — escaped in uscf_id_cell()
				echo '<td class="etr-col-rating">' . $this->rating_cell( $r ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput — escaped in rating_cell()
				echo '</tr>';
				if ( ! $is_ns ) {
					$seed++;
				}

				if ( $can_edit ) {
					$cards .= $this->render_player_card( $r, $card_fields );
				}
			}

			echo '</tbody></table></section>';
		}

		$total  = 0;
		$noshow = 0;
		foreach ( $sections as $rows ) {
			$total += count( $rows );
			foreach ( $rows as $r ) {
				if ( ! empty( $r['noshow'] ) ) $noshow++;
			}
		}
		if ( $noshow > 0 ) {
			$total_text = sprintf(
				/* translators: 1: total registrations, 2: active count, 3: no-show count */
				__( 'Total registrations: %1$s (%2$s active · %3$s no-show)', 'etr' ),
				number_format_i18n( $total ),
				number_format_i18n( $total - $noshow ),
				number_format_i18n( $noshow )
			);
		} else {
			$total_text = sprintf(
				/* translators: %s: total number of registrations */
				__( 'Total registrations: %s', 'etr' ),
				number_format_i18n( $total )
			);
		}
		printf( '<p class="etr-total">%s</p>', esc_html( $total_text ) );

		echo $cards; // phpcs:ignore WordPress.Security.EscapeOutput — escaped in render_player_card()
		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * Name table cell. For event editors, the name is a button that opens the
	 * player card; for everyone else it is plain text.
	 */
	private function name_cell( array $r, $can_edit ) {
		if ( ! $can_edit ) {
			return esc_html( $r['name'] );
		}
		// Native Popover API: the button controls the card by id, no JS needed.
		return sprintf(
			'<button type="button" class="etr-name-btn" popovertarget="etr-card-%d">%s</button>',
			(int) $r['id'],
			esc_html( $r['name'] )
		);
	}

	/**
	 * Hidden player-card dialog listing all of an attendee's custom fields.
	 * Rendered only for event editors (gated by the caller).
	 */
	private function render_player_card( array $r, array $card_fields ) {
		$aid = (int) $r['id'];
		ob_start();
		?>
		<div class="etr-card" id="etr-card-<?php echo $aid; ?>" popover
				aria-label="<?php esc_attr_e( 'Player details', 'etr' ); ?>">
			<button type="button" class="etr-card-close" popovertarget="etr-card-<?php echo $aid; ?>"
					popovertargetaction="hide" aria-label="<?php esc_attr_e( 'Close', 'etr' ); ?>">&times;</button>
			<h4 class="etr-card-name"><?php echo esc_html( $r['name'] ); ?></h4>
			<dl class="etr-card-fields">
				<?php foreach ( $card_fields as $key => $f ) :
					$raw  = (string) get_post_meta( $aid, $key, true );
					$disp = ( $f['type'] ?? '' ) === 'checkbox'
						? ( $raw === '1' ? __( 'Yes', 'etr' ) : __( 'No', 'etr' ) )
						: $raw;
					?>
					<dt><?php echo esc_html( ! empty( $f['label'] ) ? $f['label'] : $key ); ?></dt>
					<dd><?php echo $disp !== '' ? esc_html( $disp ) : '&mdash;'; ?></dd>
				<?php endforeach; ?>
			</dl>
			<?php $ns = ! empty( $r['noshow'] ); $edit_url = $this->etecf_edit_url( $aid ); ?>
			<div class="etr-card-actions">
				<?php if ( $edit_url ) : ?>
					<a class="etr-btn" href="<?php echo esc_url( $edit_url ); ?>" target="_blank" rel="noopener">
						<?php esc_html_e( 'Edit registration details', 'etr' ); ?>
					</a>
				<?php endif; ?>
				<button type="button" class="etr-btn etr-status-toggle" data-etr-toggle
						data-etr-id="<?php echo $aid; ?>"
						data-etr-status="<?php echo $ns ? 'noshow' : ''; ?>"
						data-label-mark="<?php esc_attr_e( 'Mark no-show', 'etr' ); ?>"
						data-label-clear="<?php esc_attr_e( 'Clear no-show', 'etr' ); ?>">
					<?php echo $ns ? esc_html__( 'Clear no-show', 'etr' ) : esc_html__( 'Mark no-show', 'etr' ); ?>
				</button>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Toolbar above the tables: a public Print button, plus CSV / pairing
	 * exports for users who can edit the event.
	 */
	private function render_toolbar( $event_id ) {
		ob_start();
		echo '<div class="etr-toolbar no-print">';
		printf(
			'<button type="button" class="etr-btn etr-print" data-etr-print>%s</button>',
			esc_html__( 'Print', 'etr' )
		);

		if ( current_user_can( 'edit_post', $event_id ) ) {
			$nonce = wp_create_nonce( 'etr_export_' . $event_id );
			$base  = admin_url( 'admin-post.php' );
			$csv   = add_query_arg( [ 'action' => 'etr_export', 'format' => 'csv',     'event' => $event_id, '_wpnonce' => $nonce ], $base );
			$pair  = add_query_arg( [ 'action' => 'etr_export', 'format' => 'pairing', 'event' => $event_id, '_wpnonce' => $nonce ], $base );
			printf( ' <a class="etr-btn" href="%s">%s</a>', esc_url( $csv ),  esc_html__( 'Download CSV', 'etr' ) );
			printf( ' <a class="etr-btn" href="%s">%s</a>', esc_url( $pair ), esc_html__( 'Pairing export', 'etr' ) );
		}
		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * USCF ID table cell. Links a numeric member ID to the player's
	 * ratings.uschess.org profile; non-numeric values ("Need new ID") stay plain.
	 */
	private function uscf_id_cell( array $r ) {
		$id = $r['uscf_id'];
		if ( ! ctype_digit( $id ) ) {
			return esc_html( $id );
		}
		return '<a href="' . esc_url( 'https://ratings.uschess.org/player/' . $id )
			. '" target="_blank" rel="noopener">' . esc_html( $id ) . '</a>';
	}

	/**
	 * Rating table cell. When a rating is present and the USCF ID is numeric,
	 * link the rating to the player's ratings.uschess.org profile; otherwise
	 * show the plain rating (or an empty cell for unrated players).
	 */
	private function rating_cell( array $r ) {
		$rating = esc_html( $r['rating_display'] );
		if ( $r['rating_display'] === '' || ! ctype_digit( $r['uscf_id'] ) ) {
			return $rating;
		}
		$url = 'https://ratings.uschess.org/player/' . $r['uscf_id'];
		return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . $rating . '</a>';
	}

	/**
	 * Shortcode: [etr_registrations event="123"].
	 * Falls back to the current event, then the most recently created event.
	 */
	public function shortcode( $atts ) {
		$atts     = shortcode_atts( [ 'event' => 0 ], $atts, 'etr_registrations' );
		$event_id = (int) $atts['event'];

		// No explicit event: use the current event when on one, otherwise the
		// most recently created published event.
		if ( ! $event_id && get_post_type( get_the_ID() ) === 'tribe_events' ) {
			$event_id = (int) get_the_ID();
		}
		if ( ! $event_id ) {
			$recent = get_posts( [
				'post_type'      => 'tribe_events',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			] );
			$event_id = $recent ? (int) $recent[0] : 0;
		}

		if ( ! $event_id || get_post_type( $event_id ) !== 'tribe_events' ) return '';

		return $this->render( $event_id );
	}

	/**
	 * admin-post handler: stream the registrations as a CSV download. Both
	 * formats end with a Status column ("No-show" or blank).
	 * format=csv     → #, name, USCF ID, rating, section, status (mirrors the table)
	 * format=pairing → last, first, USCF ID, rating, section, status (SwissSys/WinTD)
	 */
	public function handle_export() {
		$event_id = isset( $_GET['event'] ) ? (int) $_GET['event'] : 0;
		$format   = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : 'csv';
		$nonce    = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! $event_id || ! wp_verify_nonce( $nonce, 'etr_export_' . $event_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'etr' ) );
		}
		if ( ! current_user_can( 'edit_post', $event_id ) ) {
			wp_die( esc_html__( 'You do not have permission to export registrations.', 'etr' ) );
		}

		$sections = Plugin::instance()->build_sections( $event_id );
		$slug     = sanitize_title( get_the_title( $event_id ) ) ?: ( 'event-' . $event_id );
		$suffix   = $format === 'pairing' ? '-pairing' : '';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="registrations-' . $slug . $suffix . '.csv"' );

		$out = fopen( 'php://output', 'w' );

		if ( $format === 'pairing' ) {
			$this->put_row( $out, [ 'Last Name', 'First Name', 'USCF ID', 'Rating', 'Section', 'Status' ] );
			foreach ( $sections as $label => $rows ) {
				foreach ( $rows as $r ) {
					$this->put_row( $out, [ $r['last'], $r['first'], $r['uscf_id'], $r['rating'] > 0 ? $r['rating'] : '', $label, $this->status_label( $r ) ] );
				}
			}
		} else {
			$this->put_row( $out, [ '#', 'Name', 'USCF ID', 'Rating', 'Section', 'Status' ] );
			foreach ( $sections as $label => $rows ) {
				$seed = 1;
				foreach ( $rows as $r ) {
					$is_ns = ! empty( $r['noshow'] );
					$this->put_row( $out, [ $is_ns ? '' : $seed, $r['name'], $r['uscf_id'], $r['rating'] > 0 ? $r['rating'] : '', $label, $this->status_label( $r ) ] );
					if ( ! $is_ns ) {
						$seed++;
					}
				}
			}
		}

		fclose( $out );
		exit;
	}

	/**
	 * AJAX: toggle an attendee's no-show status. Editor-only, nonce-checked.
	 * Body: attendee (id), status ('noshow' to set, anything else clears).
	 */
	public function ajax_toggle_noshow() {
		check_ajax_referer( 'etr_status' );

		$attendee = isset( $_POST['attendee'] ) ? (int) $_POST['attendee'] : 0;
		$status   = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';

		if ( ! $attendee || get_post_type( $attendee ) !== 'tec_tc_attendee' ) {
			wp_send_json_error( [ 'message' => 'invalid_attendee' ], 400 );
		}

		$event_id = Plugin::instance()->resolve_event_id( $attendee, [ ETR_TC_EVENT_KEY ] );
		if ( ! $event_id || ! current_user_can( 'edit_post', $event_id ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}

		if ( $status === 'noshow' ) {
			update_post_meta( $attendee, '_etr_status', 'noshow' );
		} else {
			delete_post_meta( $attendee, '_etr_status' );
			$status = '';
		}

		Plugin::instance()->purge_event_cache( $event_id );
		wp_send_json_success( [ 'status' => $status ] );
	}

	/**
	 * ETECF's front-end "Edit registration details" URL for an attendee's order.
	 * The URL carries a token derived from the site's salt (wp_hash), so it must
	 * be built by ETECF on this same site — reimplementing the token here would
	 * break whenever the salt or ETECF's scheme differs. Guarded so the button
	 * simply doesn't render if ETECF is absent or changes its API.
	 */
	private function etecf_edit_url( $attendee_id ) {
		if ( ! class_exists( '\Etecf\Plugin' ) ) return '';
		$order_id = wp_get_post_parent_id( (int) $attendee_id );
		if ( ! $order_id ) return '';
		$etecf = \Etecf\Plugin::instance();
		if ( ! method_exists( $etecf, 'registration_url_for_order' ) ) return '';
		return (string) $etecf->registration_url_for_order( $order_id );
	}

	/** Human-readable status for an export row. */
	private function status_label( array $r ) {
		return ! empty( $r['noshow'] ) ? __( 'No-show', 'etr' ) : '';
	}

	/** Write one CSV row, neutralizing spreadsheet formula injection. */
	private function put_row( $handle, array $cells ) {
		$cells = array_map( function ( $v ) {
			$v = (string) $v;
			if ( $v !== '' && in_array( $v[0], [ '=', '+', '-', '@' ], true ) ) {
				$v = "'" . $v;
			}
			return $v;
		}, $cells );
		fputcsv( $handle, $cells );
	}
}
