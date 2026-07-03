<?php
namespace Etr;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Core singleton: option access, attendee querying, section grouping/sorting,
 * and front-end tab injection on single event pages.
 */
class Plugin {

	private static $instance = null;
	private $opts_cache     = null;
	private $fields_cache   = null;
	private $sections_cache = [];

	/** Default option values, merged over the saved `etr_options`. */
	const DEFAULTS = [
		'section_field'           => 'etecf_section',
		'first_name_field'        => 'etecf_first_name',
		'last_name_field'         => 'etecf_last_name',
		'uscf_id_field'           => 'etecf_uscf_member_id',
		'rating_field'            => 'etecf_uscf_rating',
		'details_tab_label'       => 'Tournament Details',
		'registrations_tab_label' => 'Registrations',
		'empty_text'              => 'No registrations yet.',
		'show_tab_count'          => true,
	];

	public static function instance() {
		if ( ! self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'the_content',        [ $this, 'inject_tabs' ], 20 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		// Keep the cached event page (roster table + tab count) fresh when
		// registrations change. A new Tickets Commerce attendee doesn't edit the
		// event post, so page caches won't otherwise invalidate on registration.
		add_action( 'tec_tickets_commerce_attendee_meta_save', [ $this, 'purge_on_attendee_save' ], 20 );
		add_action( 'before_delete_post',                       [ $this, 'purge_on_attendee_delete' ], 10, 2 );
	}

	// -----------------------------------------------------------------------
	// Cache invalidation
	// -----------------------------------------------------------------------

	/**
	 * Purge the event page's caches so the roster and tab count refresh.
	 * Runs during registration/checkout, so a cache-plugin error must never
	 * bubble up and break the purchase — swallow any throwable.
	 */
	public function purge_event_cache( $event_id ) {
		$event_id = (int) $event_id;
		if ( ! $event_id ) return;
		try {
			clean_post_cache( $event_id );
			if ( function_exists( 'w3tc_flush_post' ) ) {
				w3tc_flush_post( $event_id );
			}
		} catch ( \Throwable $e ) {
			// Cache invalidation is best-effort; never interrupt checkout.
		}
	}

	/** Attendee created or its registration details saved. */
	public function purge_on_attendee_save( $attendee_id ) {
		$this->purge_event_cache(
			$this->resolve_event_id( (int) $attendee_id, [ ETR_TC_EVENT_KEY ] )
		);
	}

	/** Attendee about to be deleted (e.g. refund/removal). */
	public function purge_on_attendee_delete( $post_id, $post ) {
		if ( ! $post || $post->post_type !== 'tec_tc_attendee' ) return;
		$this->purge_event_cache(
			$this->resolve_event_id( (int) $post_id, [ ETR_TC_EVENT_KEY ] )
		);
	}

	// -----------------------------------------------------------------------
	// Options + field catalog
	// -----------------------------------------------------------------------

	/** Plugin options merged over defaults, cached per-request. */
	public function get_opts() {
		if ( $this->opts_cache === null ) {
			$saved = get_option( 'etr_options', [] );
			$this->opts_cache = wp_parse_args( is_array( $saved ) ? $saved : [], self::DEFAULTS );
		}
		return $this->opts_cache;
	}

	public function invalidate_opts_cache() {
		$this->opts_cache = null;
	}

	/** ETECF field catalog (`etecf_field_config`), cached per-request. */
	public function get_fields() {
		if ( $this->fields_cache === null ) {
			$cfg = get_option( 'etecf_field_config', [] );
			$this->fields_cache = is_array( $cfg ) ? $cfg : [];
		}
		return $this->fields_cache;
	}

	/**
	 * Enabled attendee-scope fields, in ETECF order — the field set shown on a
	 * player card. Keyed by field key, each value the ETECF field definition.
	 */
	public function get_attendee_card_fields() {
		return array_filter(
			$this->get_fields(),
			fn( $f ) => ( $f['scope'] ?? 'attendee' ) === 'attendee' && ! empty( $f['enabled'] )
		);
	}

	/** The configured section field's option values, in ETECF display order. */
	public function get_section_options() {
		$opts   = $this->get_opts();
		$fields = $this->get_fields();
		$opt    = $fields[ $opts['section_field'] ]['options'] ?? [];
		return is_array( $opt ) ? $opt : [];
	}

	// -----------------------------------------------------------------------
	// Attendees
	// -----------------------------------------------------------------------

	/**
	 * Attendee post IDs for an event. Prefers Event Tickets' cross-provider
	 * helper; falls back to a direct Tickets Commerce meta query.
	 */
	public function get_event_attendees( $event_id ) {
		$event_id = (int) $event_id;
		$ids = [];

		if ( function_exists( 'tribe_tickets_get_attendees' ) ) {
			$attendees = tribe_tickets_get_attendees( $event_id );
			if ( is_array( $attendees ) ) {
				foreach ( $attendees as $a ) {
					if ( ! empty( $a['attendee_id'] ) ) $ids[] = (int) $a['attendee_id'];
				}
			}
		}

		if ( empty( $ids ) ) {
			$ids = get_posts( [
				'post_type'              => 'tec_tc_attendee',
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'meta_key'               => ETR_TC_EVENT_KEY,
				'meta_value'             => $event_id,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
			] );
		}

		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	/** Resolve an attendee's event ID from the given meta keys (0 if none). */
	public function resolve_event_id( $post_id, array $meta_keys = [] ) {
		foreach ( $meta_keys as $key ) {
			$id = (int) get_post_meta( (int) $post_id, $key, true );
			if ( $id ) return $id;
		}
		return 0;
	}

	// -----------------------------------------------------------------------
	// Grouping + sorting
	// -----------------------------------------------------------------------

	/**
	 * Build the ordered section => rows structure for an event.
	 * Sections follow the ETECF option order; empty and per-event-hidden
	 * sections are dropped. Within a section, active players sort before
	 * no-shows, then by rating desc, then last/first name.
	 *
	 * @return array<string, array<int, array>> section label => list of row arrays
	 */
	public function build_sections( $event_id ) {
		$event_id = (int) $event_id;
		if ( isset( $this->sections_cache[ $event_id ] ) ) {
			return $this->sections_cache[ $event_id ];
		}
		$opts = $this->get_opts();

		$hidden = get_post_meta( $event_id, '_etr_hidden_sections', true );
		$hidden = is_array( $hidden ) ? $hidden : [];

		$buckets = [];
		foreach ( $this->get_event_attendees( $event_id ) as $aid ) {
			$section = (string) get_post_meta( $aid, $opts['section_field'], true );
			if ( $section === '' ) continue; // unsectioned attendees are skipped

			$first = trim( (string) get_post_meta( $aid, $opts['first_name_field'], true ) );
			$last  = trim( (string) get_post_meta( $aid, $opts['last_name_field'], true ) );
			$name  = trim( $first . ' ' . $last );
			if ( $name === '' ) $name = (string) get_the_title( $aid );

			$rating_raw = (string) get_post_meta( $aid, $opts['rating_field'], true );
			$rating     = is_numeric( $rating_raw ) ? (int) $rating_raw : 0;

			$buckets[ $section ][] = [
				'id'             => $aid,
				'name'           => $name,
				'first'          => $first,
				'last'           => $last,
				'uscf_id'        => $this->normalize_uscf_id( (string) get_post_meta( $aid, $opts['uscf_id_field'], true ) ),
				'rating'         => $rating,
				'rating_display' => $rating > 0 ? (string) $rating : '',
				'noshow'         => get_post_meta( $aid, '_etr_status', true ) === 'noshow',
			];
		}

		$ordered = [];
		$seen    = [];

		// Sections defined in ETECF, in their configured order.
		foreach ( $this->get_section_options() as $label ) {
			$seen[ $label ] = true;
			if ( in_array( $label, $hidden, true ) ) continue;
			if ( empty( $buckets[ $label ] ) ) continue;
			$ordered[ $label ] = $this->sort_rows( $buckets[ $label ] );
		}

		// Any section values present in the data but not (any longer) in the
		// ETECF options — e.g. after a rename — appended after the known ones.
		foreach ( $buckets as $label => $rows ) {
			if ( isset( $seen[ $label ] ) ) continue;
			if ( in_array( $label, $hidden, true ) ) continue;
			$ordered[ $label ] = $this->sort_rows( $rows );
		}

		$this->sections_cache[ $event_id ] = $ordered;
		return $ordered;
	}

	/** Total number of registrations shown across all displayed sections. */
	public function total_registrations( $event_id ) {
		return array_sum( array_map( 'count', $this->build_sections( $event_id ) ) );
	}

	/**
	 * Display-normalize a USCF member ID. ETECF lets registrants paste a full
	 * ratings.uschess.org profile URL; show just the member ID. Non-URL values
	 * (a bare ID, or "Need new ID") pass through unchanged.
	 */
	private function normalize_uscf_id( $value ) {
		$value = trim( $value );
		if ( $value !== '' && stripos( $value, 'uschess.org' ) !== false && preg_match( '/(\d{6,})/', $value, $m ) ) {
			return $m[1];
		}
		return $value;
	}

	/**
	 * Sort a section's rows: active players before no-shows, then rating desc
	 * (blanks last), then last/first name asc.
	 */
	private function sort_rows( array $rows ) {
		usort( $rows, function ( $a, $b ) {
			if ( $a['noshow'] !== $b['noshow'] ) {
				return $a['noshow'] <=> $b['noshow']; // active (false) before no-show (true)
			}
			if ( $a['rating'] !== $b['rating'] ) {
				return $b['rating'] <=> $a['rating'];
			}
			$last = strcasecmp( $a['last'], $b['last'] );
			if ( $last !== 0 ) return $last;
			return strcasecmp( $a['first'], $b['first'] );
		} );
		return $rows;
	}

	// -----------------------------------------------------------------------
	// Front-end display
	// -----------------------------------------------------------------------

	/** Whether the Registrations tab is enabled for this event. */
	public function is_enabled( $event_id ) {
		return (bool) get_post_meta( (int) $event_id, '_etr_show_registrations', true );
	}

	/**
	 * Filter: the_content. On a single enabled event, wrap the event content in
	 * a "Tournament Details" tab and append a "Registrations" tab.
	 */
	public function inject_tabs( $content ) {
		if ( ! is_singular( 'tribe_events' ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		// Re-entrancy / double-filter guard.
		if ( strpos( $content, 'data-etr-tabs' ) !== false ) {
			return $content;
		}

		$event_id = get_the_ID();
		if ( ! $this->is_enabled( $event_id ) ) return $content;

		$opts          = $this->get_opts();
		$details_label = $opts['details_tab_label'];
		$reg_label     = $opts['registrations_tab_label'];
		$reg_total     = $this->total_registrations( $event_id );
		$reg_html      = Registrations::instance()->render( $event_id );

		ob_start();
		?>
		<div class="etr-tabs" data-etr-tabs>
			<div class="etr-tablist" role="tablist" aria-label="<?php esc_attr_e( 'Event sections', 'etr' ); ?>">
				<button type="button" class="etr-tab is-active" role="tab" id="etr-tab-details"
						aria-selected="true" aria-controls="etr-panel-details">
					<?php echo esc_html( $details_label ); ?>
				</button>
				<button type="button" class="etr-tab" role="tab" id="etr-tab-registrations"
						aria-selected="false" aria-controls="etr-panel-registrations" tabindex="-1">
					<?php echo esc_html( $reg_label ); ?>
					<?php if ( $reg_total > 0 && ! empty( $opts['show_tab_count'] ) ) : ?>
						<span class="etr-tab-count"><?php echo esc_html( number_format_i18n( $reg_total ) ); ?></span>
					<?php endif; ?>
				</button>
			</div>
			<div class="etr-tabpanel is-active" id="etr-panel-details" role="tabpanel"
					aria-labelledby="etr-tab-details">
				<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput — already-filtered post content ?>
			</div>
			<div class="etr-tabpanel" id="etr-panel-registrations" role="tabpanel"
					aria-labelledby="etr-tab-registrations" hidden>
				<?php echo $reg_html; // phpcs:ignore WordPress.Security.EscapeOutput — escaped in Registrations::render() ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/** Enqueue tab CSS/JS only on a single enabled event. */
	public function enqueue_assets() {
		if ( ! is_singular( 'tribe_events' ) ) return;
		if ( ! $this->is_enabled( get_queried_object_id() ) ) return;

		wp_enqueue_style( 'etr-registrations', ETR_URL . 'assets/etr-registrations.css', [], ETR_VERSION );
		wp_enqueue_script( 'etr-tabs', ETR_URL . 'assets/etr-tabs.js', [], ETR_VERSION, true );
	}
}
