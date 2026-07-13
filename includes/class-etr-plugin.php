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

	/**
	 * Per-request guard so the tabs (or the fallback's Registrations section)
	 * render at most once. inject_tabs() and render_tabs_fallback() are two
	 * independent hooks that can both fire on the same request depending on
	 * which template path an event uses; without this flag a theme/template
	 * combination that triggers both would double the output.
	 */
	private $tabs_rendered_this_request = false;

	/**
	 * Resolved photo_field_key() result, cached per-request. Null means "not
	 * resolved yet"; a string (possibly the PHOTO_FIELD fallback) means it has.
	 */
	private $photo_field_key_cache = null;

	/**
	 * Fallback ETECF Image-field meta key for a registrant's profile photo,
	 * used only when no Image field exists in the ETECF field catalog. Not
	 * part of the configurable field mapping (unlike section/name/USCF
	 * ID/rating) because ETECF only ever has one Image field intended for
	 * this purpose; read directly per the decoupling rule (ETECF's option +
	 * post meta, never ETECF's PHP classes).
	 */
	const PHOTO_FIELD = 'etecf_profile_photo';

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
		// Fallback for The Events Calendar's FSE/block single-event template
		// (views/single-event-blocks.php, used on block themes — confirmed on
		// this install by instrumenting every tribe_template_after_include:*
		// hook fired while loading an event that renders via that template).
		// That template calls `$this->template( 'single-event/content' )` to
		// echo the event body directly, rather than going through the_content
		// inside the singular/in-loop/main-query conditions inject_tabs()
		// requires — that hook name resolves to
		// "events/single-event/content", not "events/v2/single/content" (no
		// views/v2/single/ directory exists in this TEC version at all; that
		// path was carried over from a sibling plugin's fix on a different
		// TEC template layout and does not fire here). See
		// render_tabs_fallback() below for why this path can only offer a
		// reduced (non-tabbed) Registrations section.
		add_action( 'tribe_template_after_include:events/single-event/content', [ $this, 'render_tabs_fallback' ] );
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
				'photo_id'       => $this->attendee_photo_id( $aid ),
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
	 * An attendee's ETECF profile photo attachment ID, or 0 when none is set.
	 * Reads the Image field's meta directly (see photo_field_key()) rather
	 * than calling into ETECF, so a missing/renamed field just yields no
	 * photo.
	 */
	private function attendee_photo_id( $aid ) {
		$raw = get_post_meta( (int) $aid, $this->photo_field_key(), true );
		return ( is_numeric( $raw ) && (int) $raw > 0 ) ? (int) $raw : 0;
	}

	/**
	 * The ETECF meta key holding a registrant's profile photo, cached
	 * per-request. ETECF only ever has one Image-type field, but its key is
	 * admin-defined (e.g. a site could set it up as etecf_profile_picture
	 * instead of the conventional etecf_profile_photo) — never hardcode it.
	 * Scans the field catalog for the first field of type 'image' and uses
	 * its key; falls back to the PHOTO_FIELD constant when the catalog has
	 * no Image field at all (e.g. ETECF inactive, or none configured yet).
	 * Public so other parts of the plugin - e.g. Settings::handle_add_test_registrants(),
	 * which needs to write a test avatar into the same field a real registrant's
	 * ETECF photo lives in - can resolve the same key without duplicating this scan.
	 */
	public function photo_field_key() {
		if ( $this->photo_field_key_cache !== null ) {
			return $this->photo_field_key_cache;
		}
		foreach ( $this->get_fields() as $key => $f ) {
			if ( ( $f['type'] ?? '' ) === 'image' ) {
				return $this->photo_field_key_cache = $key;
			}
		}
		return $this->photo_field_key_cache = self::PHOTO_FIELD;
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

	/** Whether the profile-picture column is enabled for this event. */
	public function is_photos_enabled( $event_id ) {
		return (bool) get_post_meta( (int) $event_id, '_etr_show_photos', true );
	}

	/**
	 * Filter: the_content. On a single enabled event, wrap the event content in
	 * a "Tournament Details" tab and append a "Registrations" tab, plus
	 * whatever extra tabs other plugins add via the 'etr_event_tabs' filter
	 * (see render_tab_markup() and the filter's docblock below).
	 */
	public function inject_tabs( $content ) {
		// Already rendered (here or via the fallback below) this request.
		if ( $this->tabs_rendered_this_request ) {
			return $content;
		}
		if ( ! is_singular( 'tribe_events' ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		// Re-entrancy / double-filter guard.
		if ( strpos( $content, 'data-etr-tabs' ) !== false ) {
			return $content;
		}

		$event_id = get_the_ID();
		if ( ! $this->is_enabled( $event_id ) ) return $content;

		$opts      = $this->get_opts();
		$reg_total = $this->total_registrations( $event_id );
		$reg_html  = Registrations::instance()->render( $event_id );

		$tabs = [
			[
				'id'    => 'details',
				'label' => $opts['details_tab_label'],
				'html'  => $content,
			],
			[
				'id'     => 'registrations',
				'label'  => $opts['registrations_tab_label'],
				'html'   => $reg_html,
				// Internal only (not part of the filter's documented tab
				// shape below): the live registrant count badge, kept out
				// of 'label' so every tab's label stays plain text.
				'_badge' => ( $reg_total > 0 && ! empty( $opts['show_tab_count'] ) ) ? number_format_i18n( $reg_total ) : '',
			],
		];

		$tabs = $this->apply_event_tabs_filter( $tabs, $event_id );

		$this->tabs_rendered_this_request = true;
		return '<div class="etr-tabs" data-etr-tabs>' . $this->render_tab_markup( $tabs ) . '</div>';
	}

	/**
	 * Filter: 'etr_event_tabs'. Lets other plugins (e.g. a tournament
	 * results/standings plugin) add their own tab(s) to a single event
	 * page's tab UI, alongside the core "Tournament Details" /
	 * "Registrations" pair — applied from both inject_tabs() above and
	 * render_tabs_fallback() below, so a consumer only has to hook once to
	 * cover both TEC template paths.
	 *
	 * Each tab is array( 'id' => slug, 'label' => string, 'html' => string ):
	 * 'id' becomes the DOM id suffix (etr-tab-{id} / etr-panel-{id}) and the
	 * canonical '#tab-{id}' deep-link hash (assets/etr-tabs.js — the older
	 * '#etr-tab-{id}' form still opens the tab on load, but is no longer
	 * what activate() writes to the address bar); 'label' is plain text,
	 * escaped by render_tab_markup() when printed; 'html' is emitted
	 * unescaped, so it is the producer's responsibility to output only
	 * already-safe markup — the same contract the_content itself uses.
	 * Core tabs are always first; extras from this filter append after them.
	 *
	 * @param array $tabs     Ordered list of tab definitions built so far.
	 * @param int   $event_id The event (tribe_events) post ID.
	 * @return array
	 */
	private function apply_event_tabs_filter( array $tabs, $event_id ) {
		/**
		 * Filters the tabs shown on a single event page. See
		 * apply_event_tabs_filter()'s docblock above for the tab shape.
		 *
		 * @param array $tabs     Ordered list of tab definitions.
		 * @param int   $event_id The event (tribe_events) post ID.
		 */
		$tabs = apply_filters( 'etr_event_tabs', $tabs, $event_id );
		return is_array( $tabs ) ? $tabs : [];
	}

	/**
	 * Renders a list of tabs (see apply_event_tabs_filter()'s docblock for
	 * the tab shape) as one tablist plus its matching panels, the first tab
	 * active by default. Shared by inject_tabs() (which passes the
	 * "Tournament Details" tab first) and render_tabs_fallback() (which has
	 * no Details tab to offer), so both paths produce identical markup and
	 * are driven by the same tab-switching JS (assets/etr-tabs.js).
	 *
	 * @param array $tabs
	 * @return string
	 */
	private function render_tab_markup( array $tabs ) {
		if ( empty( $tabs ) ) return '';
		ob_start();
		?>
		<div class="etr-tablist" role="tablist" aria-label="<?php esc_attr_e( 'Event sections', 'etr' ); ?>">
			<?php foreach ( $tabs as $i => $tab ) : ?>
				<button type="button" class="etr-tab<?php echo 0 === $i ? ' is-active' : ''; ?>" role="tab"
						id="etr-tab-<?php echo esc_attr( $tab['id'] ); ?>"
						aria-selected="<?php echo 0 === $i ? 'true' : 'false'; ?>"
						aria-controls="etr-panel-<?php echo esc_attr( $tab['id'] ); ?>"
						<?php echo 0 === $i ? '' : 'tabindex="-1"'; ?>>
					<?php echo esc_html( $tab['label'] ); ?>
					<?php if ( ! empty( $tab['_badge'] ) ) : ?>
						<span class="etr-tab-count"><?php echo esc_html( $tab['_badge'] ); ?></span>
					<?php endif; ?>
				</button>
			<?php endforeach; ?>
		</div>
		<?php foreach ( $tabs as $i => $tab ) : ?>
			<div class="etr-tabpanel<?php echo 0 === $i ? ' is-active' : ''; ?>" id="etr-panel-<?php echo esc_attr( $tab['id'] ); ?>" role="tabpanel"
					aria-labelledby="etr-tab-<?php echo esc_attr( $tab['id'] ); ?>" <?php echo 0 === $i ? '' : 'hidden'; ?>>
				<?php echo $tab['html']; // phpcs:ignore WordPress.Security.EscapeOutput — see apply_event_tabs_filter()'s docblock: each tab's html is the producer's own already-escaped markup. ?>
			</div>
		<?php endforeach;
		return ob_get_clean();
	}

	/**
	 * Fallback for The Events Calendar's FSE/block single-event template
	 * ('events/single-event/content'), which prints the event description
	 * directly rather than running it through the_content inside a
	 * singular + in-the-loop + main-query context — the combination
	 * inject_tabs() requires. On such templates inject_tabs() never fires,
	 * so without this fallback the Registrations tab silently never
	 * appears even when _etr_show_registrations is enabled.
	 *
	 * IMPORTANT: this hook fires *after* the template has already echoed
	 * the event body, so — unlike inject_tabs() — there is no $content
	 * string here to capture and wrap into a "Tournament Details" panel.
	 * Reproducing that one tab would mean re-deriving or re-fetching
	 * content TEC already printed, which risks drifting from whatever TEC
	 * actually rendered there (blocks, embeds, etc.). So this omits the
	 * Details tab and renders a real tablist of just Registrations plus
	 * whatever the 'etr_event_tabs' filter adds, via the same
	 * render_tab_markup() inject_tabs() uses — same markup/classes, driven
	 * by the same tab-switching JS — appended after the event body the
	 * template already printed. (Before the etr_event_tabs filter existed
	 * this rendered a single plain Registrations section with no tabs at
	 * all; the '.etr-registrations-fallback' wrapper class is kept for
	 * back-compat with any site CSS still targeting it.)
	 */
	public function render_tabs_fallback() {
		// Already rendered (here or via inject_tabs() above) this request.
		if ( $this->tabs_rendered_this_request ) return;
		if ( ! is_singular( 'tribe_events' ) ) return;

		$event_id = get_the_ID();
		if ( ! $this->is_enabled( $event_id ) ) return;

		$opts      = $this->get_opts();
		$reg_total = $this->total_registrations( $event_id );
		$reg_html  = Registrations::instance()->render( $event_id );

		$tabs = [
			[
				'id'     => 'registrations',
				'label'  => $opts['registrations_tab_label'],
				'html'   => $reg_html,
				'_badge' => ( $reg_total > 0 && ! empty( $opts['show_tab_count'] ) ) ? number_format_i18n( $reg_total ) : '',
			],
		];

		$tabs = $this->apply_event_tabs_filter( $tabs, $event_id );

		$this->tabs_rendered_this_request = true;
		?>
		<section class="etr-registrations-fallback">
			<div class="etr-tabs" data-etr-tabs>
				<?php echo $this->render_tab_markup( $tabs ); // phpcs:ignore WordPress.Security.EscapeOutput — see render_tab_markup() ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Enqueue tab CSS/JS only on a single enabled event. Hooked on
	 * wp_enqueue_scripts, so it fires the same way regardless of whether the
	 * event body ends up rendered via inject_tabs()'s the_content filter or
	 * render_tabs_fallback()'s TEC v2 template hook — both paths only need
	 * is_singular( 'tribe_events' ) + is_enabled(), already checked here.
	 */
	public function enqueue_assets() {
		if ( ! is_singular( 'tribe_events' ) ) return;
		if ( ! $this->is_enabled( get_queried_object_id() ) ) return;

		wp_enqueue_style( 'etr-registrations', ETR_URL . 'assets/etr-registrations.css', [], ETR_VERSION );
		wp_enqueue_script( 'etr-tabs', ETR_URL . 'assets/etr-tabs.js', [], ETR_VERSION, true );
	}
}
