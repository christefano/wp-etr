<?php
namespace Etr;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Top-level "Event Registrations" admin menu and its field-mapping settings page.
 * Placement mirrors ETECF: slotted just after Event Tickets in the admin menu.
 */
class Settings {

	private static $instance = null;

	public static function instance() {
		if ( ! self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu',                   [ $this, 'register_menu' ] );
		add_action( 'admin_post_etr_save_settings', [ $this, 'handle_save' ] );
		add_action( 'admin_post_etr_add_test_registrants',    [ $this, 'handle_add_test_registrants' ] );
		add_action( 'admin_post_etr_remove_test_registrants', [ $this, 'handle_remove_test_registrants' ] );
		add_filter( 'plugin_action_links_' . ETR_BASENAME, [ $this, 'plugin_action_links' ] );
		add_filter( 'plugin_row_meta',              [ $this, 'plugin_row_meta' ], 10, 2 );
		// Demo mode's success/failure notices are hooked to the standard
		// admin_notices action (gated to our own settings screen) so
		// WordPress renders them at the top of the page like every other
		// admin notice, instead of them only being echoed inline, down
		// inside the Demo mode section's own markup, where they're easy
		// to miss.
		add_action( 'admin_notices', [ $this, 'maybe_render_test_mode_notices' ] );
	}

	public function register_menu() {
		// Find Event Tickets' runtime menu position and slot in just after it,
		// after ETECF (which uses +0.1). Fallback keeps us near the ET group.
		global $menu;
		$et_position = 58; // ET fallback
		if ( is_array( $menu ) ) {
			foreach ( $menu as $pos => $item ) {
				if ( isset( $item[2] ) && $item[2] === 'tec-tickets' ) {
					$et_position = (float) $pos;
					break;
				}
			}
		}
		$our_position = $et_position + 0.2;

		add_menu_page(
			__( 'Event Registrations', 'etr' ),
			__( 'Event Registrations', 'etr' ),
			'manage_options',
			'etr-main',
			[ $this, 'render_settings_page' ],
			'dashicons-groups',
			$our_position
		);

		add_submenu_page(
			'etr-main',
			__( 'Settings', 'etr' ),
			__( 'Settings', 'etr' ),
			'manage_options',
			'etr-settings',
			[ $this, 'render_settings_page' ]
		);

		// Drop the duplicate top-level entry WordPress auto-creates.
		remove_submenu_page( 'etr-main', 'etr-main' );
	}

	/**
	 * Plugin action links on plugins.php. Order: Settings | Deactivate | Donate.
	 */
	public function plugin_action_links( $links ) {
		$settings = '<a href="' . esc_url( admin_url( 'admin.php?page=etr-settings' ) ) . '">' . esc_html__( 'Settings', 'etr' ) . '</a>';
		$donate   = '<a href="https://macchess.org/donate" target="_blank" rel="noopener">' . esc_html__( 'Donate', 'etr' ) . '</a>';
		array_unshift( $links, $settings );
		$links[] = $donate;
		return $links;
	}

	/**
	 * Relabel the auto-generated Plugin URI row-meta link to "View details".
	 */
	public function plugin_row_meta( $links, $plugin_file ) {
		if ( $plugin_file !== ETR_BASENAME ) {
			return $links;
		}
		foreach ( $links as &$link ) {
			if ( strpos( $link, 'github.com/christefano/wp-etr' ) !== false ) {
				$link = '<a href="https://github.com/christefano/wp-etr" target="_blank" rel="noopener">' . esc_html__( 'View details', 'etr' ) . '</a>';
			}
		}
		return $links;
	}

	/** Shared plugin header shown at the top of the settings page. */
	public function render_admin_header() {
		?>
		<p class="description">
			<strong><?php esc_html_e( 'Event Tickets Registrations', 'etr' ); ?> v<?php echo esc_html( ETR_VERSION ); ?></strong>:
			<a href="https://github.com/christefano/wp-etr" target="_blank" rel="noopener"><?php esc_html_e( 'GitHub', 'etr' ); ?></a>
			&nbsp;|&nbsp;
			<a href="<?php echo esc_url( ETR_URL . 'README.md' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'README', 'etr' ); ?></a>
			&nbsp;|&nbsp;
			<a href="https://macchess.org/donate" target="_blank" rel="noopener"><?php esc_html_e( 'Donate', 'etr' ); ?></a>
		</p>
		<p class="description">
			<?php esc_html_e( 'Adds a Registrations tab to event pages, grouping Event Tickets attendees into sections from Extra Custom Fields.', 'etr' ); ?>
		</p>
		<?php
	}

	/**
	 * Build <option>s for a field-mapping select from the ETECF attendee fields.
	 * Ensures the currently-saved key is always present even if it no longer
	 * matches the type filter (so a stale mapping stays visible/selectable).
	 */
	private function field_choices( $current, array $only_types = [] ) {
		$fields  = Plugin::instance()->get_fields();
		$choices = [];
		foreach ( $fields as $key => $f ) {
			if ( ( $f['scope'] ?? 'attendee' ) !== 'attendee' ) continue;
			if ( $only_types && ! in_array( $f['type'] ?? '', $only_types, true ) ) continue;
			$label = ! empty( $f['label'] ) ? $f['label'] : $key;
			$choices[ $key ] = $label . ' — ' . $key;
		}
		if ( $current !== '' && ! isset( $choices[ $current ] ) ) {
			$label = ! empty( $fields[ $current ]['label'] ) ? $fields[ $current ]['label'] : $current;
			$choices = [ $current => $label . ' — ' . $current ] + $choices;
		}
		return $choices;
	}

	private function render_select( $name, $current, array $choices ) {
		echo '<select id="etr-' . esc_attr( $name ) . '" name="etr_options[' . esc_attr( $name ) . ']">';
		if ( empty( $choices ) ) {
			echo '<option value="">' . esc_html__( '— no fields available —', 'etr' ) . '</option>';
		}
		foreach ( $choices as $key => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $key ),
				selected( $current, $key, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'etr' ) );
		}

		$opts = Plugin::instance()->get_opts();
		$form = admin_url( 'admin-post.php' );

		$has_fields = ! empty( Plugin::instance()->get_fields() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Event Registrations', 'etr' ); ?></h1>
			<?php $this->render_admin_header(); ?>

			<?php if ( isset( $_GET['etr-saved'] ) && $_GET['etr-saved'] === '1' ) : ?>
				<div class="notice notice-success is-dismissible"><p><strong><?php esc_html_e( 'Settings saved.', 'etr' ); ?></strong></p></div>
			<?php endif; ?>

			<?php if ( ! $has_fields ) : ?>
				<div class="notice notice-warning"><p>
					<?php esc_html_e( 'No Extra Custom Fields were found. Define attendee fields in Tickets Extra Custom Fields first.', 'etr' ); ?>
				</p></div>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'Map which Extra Custom Fields the registrations table reads. Sections come from the section field\'s options, defined in Extra Custom Fields.', 'etr' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( $form ); ?>">
				<?php wp_nonce_field( 'etr_save_settings', 'etr_settings_nonce' ); ?>
				<input type="hidden" name="action" value="etr_save_settings">

				<h2><?php esc_html_e( 'Field mapping', 'etr' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="etr-section_field"><?php esc_html_e( 'Section field', 'etr' ); ?></label></th>
						<td>
							<?php $this->render_select( 'section_field', $opts['section_field'], $this->field_choices( $opts['section_field'], [ 'radio', 'select' ] ) ); ?>
							<p class="description"><?php esc_html_e( 'The radio/select field whose options are the tournament sections (e.g. Open, U1800…). Attendees are grouped by this value.', 'etr' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="etr-first_name_field"><?php esc_html_e( 'First name field', 'etr' ); ?></label></th>
						<td><?php $this->render_select( 'first_name_field', $opts['first_name_field'], $this->field_choices( $opts['first_name_field'] ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="etr-last_name_field"><?php esc_html_e( 'Last name field', 'etr' ); ?></label></th>
						<td><?php $this->render_select( 'last_name_field', $opts['last_name_field'], $this->field_choices( $opts['last_name_field'] ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="etr-uscf_id_field"><?php esc_html_e( 'USCF ID field', 'etr' ); ?></label></th>
						<td><?php $this->render_select( 'uscf_id_field', $opts['uscf_id_field'], $this->field_choices( $opts['uscf_id_field'] ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="etr-rating_field"><?php esc_html_e( 'Rating field', 'etr' ); ?></label></th>
						<td>
							<?php $this->render_select( 'rating_field', $opts['rating_field'], $this->field_choices( $opts['rating_field'] ) ); ?>
							<p class="description"><?php esc_html_e( 'Numeric field. Rows sort by this value, highest first; blanks sort last.', 'etr' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Labels', 'etr' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="etr-details_tab_label"><?php esc_html_e( 'Details tab label', 'etr' ); ?></label></th>
						<td><input type="text" id="etr-details_tab_label" class="regular-text" name="etr_options[details_tab_label]" value="<?php echo esc_attr( $opts['details_tab_label'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="etr-registrations_tab_label"><?php esc_html_e( 'Registrations tab label', 'etr' ); ?></label></th>
						<td><input type="text" id="etr-registrations_tab_label" class="regular-text" name="etr_options[registrations_tab_label]" value="<?php echo esc_attr( $opts['registrations_tab_label'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="etr-empty_text"><?php esc_html_e( 'Empty message', 'etr' ); ?></label></th>
						<td>
							<input type="text" id="etr-empty_text" class="regular-text" name="etr_options[empty_text]" value="<?php echo esc_attr( $opts['empty_text'] ); ?>">
							<p class="description"><?php esc_html_e( 'Shown on the Registrations tab when no attendees have a section yet.', 'etr' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Registration count', 'etr' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="etr_options[show_tab_count]" value="1" <?php checked( ! empty( $opts['show_tab_count'] ) ); ?>>
								<?php esc_html_e( 'Show the registration count badge on the Registrations tab', 'etr' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'The count is baked into the cached event page. With a page cache (e.g. W3 Total Cache) enabled, it can briefly show a stale number after someone registers — until that event\'s cache is purged. Turn this off if a temporarily-wrong count would confuse visitors.', 'etr' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Settings', 'etr' ) ); ?>
			</form>

			<hr>
			<p class="description">
				<?php
				printf(
					/* translators: %s: shortcode */
					esc_html__( 'Enable the Registrations tab per event from the Event Registrations meta box on the event edit screen. You can also embed a table anywhere with %s.', 'etr' ),
					'<code>[etr_registrations event="ID"]</code>'
				);
				?>
			</p>

			<hr>
			<?php $this->render_test_mode_section(); ?>
		</div>
		<?php
	}

	// -------------------------------------------------------------------
	// Demo mode
	// -------------------------------------------------------------------

	/**
	 * Hooked to admin_notices, gated to our own settings screen, so these
	 * render in WordPress' standard notices area at the top of the page
	 * instead of inline down inside the Demo mode section's own markup.
	 */
	public function maybe_render_test_mode_notices() {
		if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'etr-settings' ) return;
		if ( ! current_user_can( 'manage_options' ) ) return;
		$this->render_test_mode_notices();
	}

	/**
	 * Success/failure notices for the test-mode admin-post handlers, read from
	 * the redirect query args the same way "Settings saved" is (see
	 * handle_save()). No nonce is needed to read these; they carry counts, not
	 * data to act on.
	 */
	private function render_test_mode_notices() {
		if ( isset( $_GET['etr-test-added'] ) ) {
			$added       = (int) $_GET['etr-test-added'];
			$requested   = isset( $_GET['etr-test-requested'] ) ? (int) $_GET['etr-test-requested'] : $added;
			$remaining   = isset( $_GET['etr-test-remaining'] ) ? (int) $_GET['etr-test-remaining'] : 0;
			$event_id    = isset( $_GET['etr-test-event'] ) ? absint( $_GET['etr-test-event'] ) : 0;
			$tab_enabled = isset( $_GET['etr-test-tab-enabled'] ) && $_GET['etr-test-tab-enabled'] === '1';
			?>
			<div class="notice notice-success is-dismissible"><p>
				<?php if ( $added < $requested ) : ?>
					<?php
					printf(
						/* translators: 1: number added, 2: number requested, 3: unused test players left in the pool */
						esc_html__( 'Added %1$d test registrant(s) - fewer than the %2$d requested because the test player pool is running low. %3$d unused test player(s) remain.', 'etr' ),
						$added,
						$requested,
						$remaining
					);
					?>
				<?php else : ?>
					<?php
					printf(
						/* translators: 1: number added, 2: unused test players left in the pool */
						esc_html__( 'Added %1$d test registrant(s). %2$d unused test player(s) remain.', 'etr' ),
						$added,
						$remaining
					);
					?>
				<?php endif; ?>
				<?php if ( $tab_enabled ) : ?>
					<?php esc_html_e( 'The Registrations tab was switched on for this event.', 'etr' ); ?>
				<?php endif; ?>
				<?php if ( $event_id && get_post_type( $event_id ) === 'tribe_events' ) : ?>
					<a href="<?php echo esc_url( get_permalink( $event_id ) ); ?>"><?php esc_html_e( "View the event's Registrations tab", 'etr' ); ?></a>
				<?php endif; ?>
			</p></div>
			<?php
		}

		if ( isset( $_GET['etr-test-no-ticket'] ) && $_GET['etr-test-no-ticket'] === '1' ) {
			?>
			<div class="notice notice-error"><p>
				<?php esc_html_e( 'This event has no ticket yet. Add a ticket (a free RSVP or $0 ticket works) before adding test registrants, because Event Tickets\' event page rendering requires each attendee to reference a ticket.', 'etr' ); ?>
			</p></div>
			<?php
		}

		if ( isset( $_GET['etr-test-removed'] ) ) {
			$removed = (int) $_GET['etr-test-removed'];
			?>
			<div class="notice notice-success is-dismissible"><p>
				<?php
				printf(
					/* translators: %d: number of test registrants removed */
					esc_html( _n( 'Removed %d test registrant.', 'Removed %d test registrants.', $removed, 'etr' ) ),
					$removed
				);
				?>
			</p></div>
			<?php
		}

		if ( isset( $_GET['etr-test-remove-failed'] ) ) {
			$failed = (int) $_GET['etr-test-remove-failed'];
			if ( $failed > 0 ) {
				?>
				<div class="notice notice-warning"><p>
					<?php
					printf(
						/* translators: %d: number of test registrant/order posts that could not be removed */
						esc_html( _n(
							'%d test post could not be removed automatically - Event Tickets rejected the deletion, likely because it was created before this plugin tagged test attendees with ticket metadata. You can delete it from the Attendees screen instead.',
							'%d test posts could not be removed automatically - Event Tickets rejected the deletion, likely because they were created before this plugin tagged test attendees with ticket metadata. You can delete them from the Attendees screen instead.',
							$failed,
							'etr'
						) ),
						$failed
					);
					?>
				</p></div>
				<?php
			}
		}
	}

	/**
	 * Published events, most recent first, for the test-mode event pickers.
	 * Capped at 30 - test mode is for exercising the Registrations tab on a
	 * current/upcoming event, not for hunting through years of history.
	 */
	private function test_event_choices() {
		$events = get_posts( [
			'post_type'      => 'tribe_events',
			'post_status'    => 'publish',
			'posts_per_page' => 30,
			'meta_key'       => '_EventStartDate',
			'orderby'        => 'meta_value',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		] );

		$choices = [];
		foreach ( $events as $event ) {
			$start = get_post_meta( $event->ID, '_EventStartDate', true );
			$date  = $start ? mysql2date( get_option( 'date_format' ), $start ) : '';
			$label = $date ? $event->post_title . ' - ' . $date : $event->post_title;
			$choices[ $event->ID ] = $label;
		}
		return $choices;
	}

	/**
	 * Render an event <select> for a test-mode form. Both the add and remove
	 * forms post the same field name (`etr_test_event`) to their own
	 * admin-post action, so each needs its own element id to stay valid HTML.
	 */
	private function render_test_event_select( $name, array $choices, $id ) {
		echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" required>';
		if ( empty( $choices ) ) {
			echo '<option value="">' . esc_html__( 'No published events', 'etr' ) . '</option>';
		} else {
			echo '<option value="">' . esc_html__( 'Select an event…', 'etr' ) . '</option>';
		}
		foreach ( $choices as $choice_id => $label ) {
			printf( '<option value="%d">%s</option>', (int) $choice_id, esc_html( $label ) );
		}
		echo '</select>';
	}

	/**
	 * The section field's configured options (e.g. "Open", "U1800"), for the
	 * test-mode section <datalist>. Picks the first attendee-scope radio/select
	 * field whose key or label mentions "section" - a simple heuristic, not the
	 * Settings page's configured section_field mapping, so test mode still
	 * offers useful suggestions even before that mapping has been set. If more
	 * than one field matches (unlikely, but possible with an unusual ETECF
	 * setup) the first one found wins; there's no reliable way to disambiguate
	 * further, and the field degrades to free text either way since this is
	 * just a <datalist> suggestion list, not a hard constraint.
	 */
	private function test_section_options() {
		foreach ( Plugin::instance()->get_fields() as $key => $f ) {
			if ( ( $f['scope'] ?? 'attendee' ) !== 'attendee' ) continue;
			if ( ! in_array( $f['type'] ?? '', [ 'select', 'radio' ], true ) ) continue;
			$label = (string) ( $f['label'] ?? '' );
			if ( stripos( $key, 'section' ) === false && stripos( $label, 'section' ) === false ) continue;
			$options = $f['options'] ?? [];
			return is_array( $options ) ? $options : [];
		}
		return [];
	}

	public function render_test_mode_section() {
		$event_choices = $this->test_event_choices();
		$is_production = function_exists( 'wp_get_environment_type' ) && wp_get_environment_type() === 'production';
		$section_options = $this->test_section_options();
		?>
		<h2><?php esc_html_e( 'Demo mode', 'etr' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Add up to 50 unique test registrants to an event to see the Registrations tab filled in, without needing real ticket sales in Event Tickets.', 'etr' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( 'Test registrants carry ticket metadata referencing the event\'s first ticket, so event pages render them, but they still have no order behind them: Event Tickets\' own Attendees screens list them without an order to link to, and ticket sales, stock, and revenue stay untouched. Remove them with the button below when you are done testing.', 'etr' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( 'The event needs at least one ticket first (a $0 ticket works). Test registrants must reference a real ticket for Event Tickets\' own displays to render them.', 'etr' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( "Adding test registrants switches on the event's Registrations tab automatically if it was off.", 'etr' ); ?>
		</p>

		<?php if ( $is_production ) : ?>
			<div class="notice notice-error inline"><p>
				<strong><?php esc_html_e( 'Warning:', 'etr' ); ?></strong>
				<?php esc_html_e( 'This site is currently reporting a production environment. Test registrants added here will show up next to real registrants on this live Registrations tab until you remove them.', 'etr' ); ?>
			</p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'etr_add_test_registrants', 'etr_test_add_nonce' ); ?>
			<input type="hidden" name="action" value="etr_add_test_registrants">

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="etr-test-event-add"><?php esc_html_e( 'Event', 'etr' ); ?></label></th>
					<td><?php $this->render_test_event_select( 'etr_test_event', $event_choices, 'etr-test-event-add' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><label for="etr-test-section"><?php esc_html_e( 'Section', 'etr' ); ?></label></th>
					<td>
						<input type="text" id="etr-test-section" class="regular-text" name="etr_test_section"
							value="<?php esc_attr_e( 'Demo Section', 'etr' ); ?>"
							<?php echo $section_options ? 'list="etr-test-section-options"' : ''; ?>>
						<?php if ( $section_options ) : ?>
							<datalist id="etr-test-section-options">
								<?php foreach ( $section_options as $option ) : ?>
									<option value="<?php echo esc_attr( $option ); ?>">
								<?php endforeach; ?>
							</datalist>
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'Free text; every test registrant added in one batch gets this same section.', 'etr' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="etr-test-count"><?php esc_html_e( 'Number of test registrants', 'etr' ); ?></label></th>
					<td><input type="number" id="etr-test-count" name="etr_test_count" min="1" max="50" value="5" style="width:5em;"></td>
				</tr>
				<?php if ( $is_production ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Confirm', 'etr' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="etr_test_confirm_production" value="1" required>
								<?php esc_html_e( 'I understand this is a live site', 'etr' ); ?>
							</label>
						</td>
					</tr>
				<?php endif; ?>
			</table>

			<?php submit_button( __( 'Add test registrants', 'etr' ) ); ?>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'etr_remove_test_registrants', 'etr_test_remove_nonce' ); ?>
			<input type="hidden" name="action" value="etr_remove_test_registrants">

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="etr-test-event-remove"><?php esc_html_e( 'Event', 'etr' ); ?></label></th>
					<td>
						<?php $this->render_test_event_select( 'etr_test_event', $event_choices, 'etr-test-event-remove' ); ?>
						<p class="description"><?php esc_html_e( 'Removes test registrants from the selected event only.', 'etr' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Remove all test registrants', 'etr' ), 'delete' ); ?>
		</form>
		<?php
	}

	public function handle_save() {
		if ( ! isset( $_POST['etr_settings_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['etr_settings_nonce'] ) ), 'etr_save_settings' )
		) {
			wp_die( esc_html__( 'Security check failed.', 'etr' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'etr' ) );
		}

		$raw   = isset( $_POST['etr_options'] ) && is_array( $_POST['etr_options'] )
			? wp_unslash( $_POST['etr_options'] ) : [];
		$valid = array_keys( Plugin::instance()->get_fields() );

		$out = get_option( 'etr_options', [] );
		if ( ! is_array( $out ) ) $out = [];

		// Field-key mappings: accept only keys that exist in the ETECF catalog;
		// otherwise leave the previous value untouched.
		foreach ( [ 'section_field', 'first_name_field', 'last_name_field', 'uscf_id_field', 'rating_field' ] as $mk ) {
			$val = sanitize_key( $raw[ $mk ] ?? '' );
			if ( $val !== '' && in_array( $val, $valid, true ) ) {
				$out[ $mk ] = $val;
			}
		}

		// Text labels: fall back to defaults when blanked.
		$defaults = Plugin::DEFAULTS;
		foreach ( [ 'details_tab_label', 'registrations_tab_label', 'empty_text' ] as $tk ) {
			$val = sanitize_text_field( $raw[ $tk ] ?? '' );
			$out[ $tk ] = $val !== '' ? $val : $defaults[ $tk ];
		}

		// Checkbox: unchecked posts nothing, so absence means 0.
		$out['show_tab_count'] = ! empty( $raw['show_tab_count'] ) ? 1 : 0;

		update_option( 'etr_options', $out );
		Plugin::instance()->invalidate_opts_cache();

		wp_safe_redirect( add_query_arg(
			[ 'page' => 'etr-settings', 'etr-saved' => '1' ],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	// -------------------------------------------------------------------
	// Demo mode: handlers
	// -------------------------------------------------------------------

	/**
	 * Resolve which ETECF field key to write each test-registrant value into,
	 * by matching field labels against a few plain-English needles rather than
	 * reusing the Settings page's configured field mapping (get_opts()). That
	 * keeps test mode independent of whatever the field mapping currently
	 * points at (which an admin could have mis-set), and falls back to ETECF's
	 * own conventional literal key when no label matches at all - e.g. ETECF
	 * is inactive, or every field has been relabeled beyond recognition.
	 */
	private function resolve_test_field_keys() {
		$targets = [
			'first_name' => [ 'first name', 'first' ],
			'last_name'  => [ 'last name', 'last' ],
			'uscf_id'    => [ 'uscf', 'member id', 'member' ],
			'rating'     => [ 'rating' ],
			'section'    => [ 'section' ],
		];
		$fallbacks = [
			'first_name' => 'etecf_first_name',
			'last_name'  => 'etecf_last_name',
			'uscf_id'    => 'etecf_uscf_member_id',
			'rating'     => 'etecf_uscf_rating',
			'section'    => 'etecf_section',
		];

		$fields   = Plugin::instance()->get_fields();
		$resolved = [];

		foreach ( $targets as $slot => $needles ) {
			$found = '';
			foreach ( $fields as $key => $f ) {
				$label = strtolower( (string) ( $f['label'] ?? '' ) );
				if ( $label === '' ) continue;
				foreach ( $needles as $needle ) {
					if ( strpos( $label, $needle ) !== false ) {
						$found = $key;
						break 2;
					}
				}
			}
			// Fall back to the literal ETECF key when no label matched.
			$resolved[ $slot ] = $found !== '' ? $found : $fallbacks[ $slot ];
		}

		return $resolved;
	}

	/**
	 * A plausible, clearly non-real email for a test registrant: ascii-folded
	 * (remove_accents) so names with diacritics still produce a valid local
	 * part, then reduced to [a-z0-9.] only. Always at the reserved @example.test
	 * domain (RFC 2606), so nothing is ever actually mailable.
	 */
	private function test_email( $first, $last ) {
		$local = strtolower( remove_accents( $first . '.' . $last ) );
		$local = preg_replace( '/[^a-z0-9.]+/', '', $local );
		$local = trim( $local, '.' );
		if ( $local === '' ) $local = 'test';
		return $local . '@example.test';
	}

	/**
	 * Sideload a test player's portrait (assets/test-avatars/{uscf_id}.jpg,
	 * fetched ahead of time by tools/fetch-test-avatars.php) as a media
	 * attachment for this event's test-registrant batch, or reuse the
	 * attachment already sideloaded for that player on a previous batch.
	 * Reuse is tracked in the event's own '_etr_test_avatar_ids' meta (a
	 * uscf_id => attachment_id map), separate from '_etr_test_used' (which
	 * tracks pool positions, not attachments), so repeatedly adding/removing
	 * test registrants on the same event doesn't pile up duplicate media
	 * library entries for the same player.
	 *
	 * @return int Attachment ID, or 0 when there's no avatar file for this
	 *             player or the sideload failed.
	 */
	private function get_or_sideload_test_avatar( $event_id, $uscf_id ) {
		$cache = get_post_meta( $event_id, '_etr_test_avatar_ids', true );
		$cache = is_array( $cache ) ? $cache : [];

		if ( ! empty( $cache[ $uscf_id ] ) ) {
			$existing = (int) $cache[ $uscf_id ];
			if ( get_post_type( $existing ) === 'attachment' ) {
				return $existing;
			}
			// Cached attachment is gone (e.g. deleted outside test mode); re-sideload below.
		}

		$src = ETR_PATH . 'assets/test-avatars/' . $uscf_id . '.jpg';
		if ( ! file_exists( $src ) ) {
			return 0;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// media_handle_sideload() copies its source file into the uploads
		// directory and then deletes the source, so hand it a scratch copy
		// rather than our own assets/test-avatars/*.jpg - that file needs to
		// survive for the next event's batch and for re-runs of
		// tools/fetch-test-avatars.php.
		$tmp = wp_tempnam( $uscf_id . '.jpg' );
		if ( ! $tmp || ! copy( $src, $tmp ) ) {
			return 0;
		}

		$attachment_id = media_handle_sideload( [
			'name'     => $uscf_id . '.jpg',
			'tmp_name' => $tmp,
		], $event_id );

		if ( is_wp_error( $attachment_id ) ) {
			if ( file_exists( $tmp ) ) wp_delete_file( $tmp );
			return 0;
		}

		update_post_meta( $attachment_id, '_etr_test_registrant', 1 );

		$cache[ $uscf_id ] = (int) $attachment_id;
		update_post_meta( $event_id, '_etr_test_avatar_ids', $cache );

		return (int) $attachment_id;
	}

	/**
	 * admin-post handler: create N test registrants (tec_tc_attendee posts
	 * parented to a shared placeholder test order, with no real ticket sale
	 * behind them) on the selected event, drawn without repeats from the
	 * 50-player test pool (includes/etr-test-players.php) until every player
	 * has been used at least once for that event.
	 */
	public function handle_add_test_registrants() {
		if ( ! isset( $_POST['etr_test_add_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['etr_test_add_nonce'] ) ), 'etr_add_test_registrants' )
		) {
			wp_die( esc_html__( 'Security check failed.', 'etr' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'etr' ) );
		}

		// The checkbox is marked required in the form, but that is only a UX
		// nicety - enforce the confirmation server-side too.
		if ( function_exists( 'wp_get_environment_type' ) && wp_get_environment_type() === 'production'
			&& empty( $_POST['etr_test_confirm_production'] )
		) {
			wp_die( esc_html__( 'Please check "I understand this is a live site" to add test registrants on a production site.', 'etr' ) );
		}

		$event_id = isset( $_POST['etr_test_event'] ) ? absint( $_POST['etr_test_event'] ) : 0;
		if ( ! $event_id || get_post_type( $event_id ) !== 'tribe_events' ) {
			wp_die( esc_html__( 'Please choose a valid event.', 'etr' ) );
		}

		$requested = isset( $_POST['etr_test_count'] ) ? (int) $_POST['etr_test_count'] : 5;
		$requested = max( 1, min( 50, $requested ) );

		$section = isset( $_POST['etr_test_section'] ) ? sanitize_text_field( wp_unslash( $_POST['etr_test_section'] ) ) : '';
		if ( $section === '' ) $section = __( 'Demo Section', 'etr' );

		// The event's first ticket. Every test attendee below is required to
		// carry the same _tec_tickets_commerce_ticket meta a real attendee
		// would: without it, Event Tickets' own
		// Ticket_Model::get_regular_price() type-hints an int ticket id and
		// fatals the event's single page (a 500, not a warning) when it
		// renders these attendees. So if the event has no ticket at all yet,
		// refuse to create any test registrants - there is no way to make
		// them render safely - and send the admin back with an error notice
		// instead.
		$ticket_ids = get_posts( [
			'post_type'   => 'tec_tc_ticket',
			'meta_key'    => ETR_TC_EVENT_KEY,
			'meta_value'  => $event_id,
			'numberposts' => 1,
			'fields'      => 'ids',
		] );
		$ticket_id = ! empty( $ticket_ids ) ? (int) $ticket_ids[0] : 0;

		if ( ! $ticket_id ) {
			wp_safe_redirect( add_query_arg( [
				'page'                => 'etr-settings',
				'etr-test-no-ticket'  => 1,
				'etr-test-event'      => $event_id,
			], admin_url( 'admin.php' ) ) );
			exit;
		}

		$players   = include ETR_PATH . 'includes/etr-test-players.php';
		$pool_size = count( $players );

		$used = get_post_meta( $event_id, '_etr_test_used', true );
		$used = is_array( $used ) ? array_values( array_unique( array_map( 'intval', $used ) ) ) : [];

		// Randomly pick unused players; if fewer remain than requested, use
		// whatever is left (the notice below reports the shortfall).
		$available = array_values( array_diff( range( 0, $pool_size - 1 ), $used ) );
		shuffle( $available );
		$take = array_slice( $available, 0, $requested );

		$field_keys = $this->resolve_test_field_keys();
		$added      = 0;

		// Parent order for the test attendees. Event Tickets' own
		// decreases_inventory() (and other Commerce/Attendee.php code) reads
		// tec_tc_get_order( $attendee->post_parent ) and fatals a notice when
		// that comes back null, so every test attendee needs a real order post
		// as its parent. Status tec-tc-undefined sits outside this site's
		// inventory-decrease statuses (tec-tc-pending, tec-tc-completed), so
		// ticket stock and sales figures are never touched by test data.
		$order_ids = get_posts( [
			'post_type'      => 'tec_tc_order',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => [
				[ 'key' => '_etr_test_registrant', 'value' => 1 ],
				[ 'key' => ETR_TC_EVENT_KEY,        'value' => $event_id ],
			],
		] );
		$order_id = ! empty( $order_ids ) ? (int) $order_ids[0] : 0;

		if ( ! $order_id ) {
			$order_id = wp_insert_post( [
				'post_type'   => 'tec_tc_order',
				'post_status' => 'tec-tc-undefined',
				'post_title'  => __( 'Demo mode order', 'etr' ),
			], true );
			if ( is_wp_error( $order_id ) ) {
				$order_id = 0;
			} else {
				update_post_meta( $order_id, '_etr_test_registrant', 1 );
				update_post_meta( $order_id, ETR_TC_EVENT_KEY, $event_id );
			}
		}

		foreach ( $take as $index ) {
			$p     = $players[ $index ];
			$first = $p['first'];
			$last  = $p['last'];

			$attendee_id = wp_insert_post( [
				'post_type'   => 'tec_tc_attendee',
				'post_status' => 'publish',
				'post_title'  => $last . ', ' . $first,
				'post_parent' => $order_id,
			], true );
			if ( is_wp_error( $attendee_id ) || ! $attendee_id ) continue;

			update_post_meta( $attendee_id, ETR_TC_EVENT_KEY, $event_id );
			update_post_meta( $attendee_id, '_tec_tickets_commerce_full_name', $first . ' ' . $last );
			update_post_meta( $attendee_id, '_tec_tickets_commerce_email', $this->test_email( $first, $last ) );
			update_post_meta( $attendee_id, '_etr_test_registrant', 1 );

			// Every attendee gets the event's real ticket id - see the
			// Ticket_Model fatal note above the get_posts() call. $ticket_id
			// is guaranteed truthy here; the no-ticket case already returned
			// above before any post was created.
			update_post_meta( $attendee_id, '_tec_tickets_commerce_ticket', $ticket_id );
			update_post_meta( $attendee_id, '_tec_tickets_commerce_security_code', substr( md5( 'etr-test-' . $attendee_id ), 0, 10 ) );
			update_post_meta( $attendee_id, '_tec_tickets_commerce_price_paid', 0 );
			update_post_meta( $attendee_id, '_tec_tickets_commerce_currency', 'USD' );
			update_post_meta( $attendee_id, '_tec_tickets_commerce_optout', 1 );

			update_post_meta( $attendee_id, $field_keys['first_name'], $first );
			update_post_meta( $attendee_id, $field_keys['last_name'], $last );
			update_post_meta( $attendee_id, $field_keys['uscf_id'], $p['uscf_id'] );
			update_post_meta( $attendee_id, $field_keys['rating'], $p['rating'] );
			update_post_meta( $attendee_id, $field_keys['section'], $section );

			// Not every test player has a fetched portrait (see
			// tools/fetch-test-avatars.php's coverage report); those without
			// one simply get no value in the image field, and the existing
			// photo column already falls back to a silhouette for that case.
			$avatar_id = $this->get_or_sideload_test_avatar( $event_id, $p['uscf_id'] );
			if ( $avatar_id ) {
				update_post_meta( $attendee_id, Plugin::instance()->photo_field_key(), $avatar_id );
			}

			$used[] = $index;
			$added++;
		}

		update_post_meta( $event_id, '_etr_test_used', array_values( array_unique( $used ) ) );

		// Event Tickets caches the attendee list; direct post writes bypass its invalidation.
		if ( function_exists( 'tribe' ) && class_exists( '\TEC\Tickets\Commerce\Module' ) ) {
			$module = tribe( \TEC\Tickets\Commerce\Module::class );
			if ( $module && method_exists( $module, 'clear_attendees_cache' ) ) {
				$module->clear_attendees_cache( $event_id );
			}
		}

		if ( method_exists( Plugin::instance(), 'purge_event_cache' ) ) {
			Plugin::instance()->purge_event_cache( $event_id );
		}

		$remaining = max( 0, $pool_size - count( array_unique( $used ) ) );

		// The Registrations tab is off by default per-event; adding test
		// registrants only makes sense if you can then see them there, so
		// auto-enable the tab when it isn't already on.
		$tab_enabled = 0;
		if ( $added > 0 && empty( get_post_meta( $event_id, '_etr_show_registrations', true ) ) ) {
			update_post_meta( $event_id, '_etr_show_registrations', 1 );
			$tab_enabled = 1;
		}

		$redirect_args = [
			'page'                  => 'etr-settings',
			'etr-test-added'        => $added,
			'etr-test-requested'    => $requested,
			'etr-test-remaining'    => $remaining,
			'etr-test-event'        => $event_id,
			'etr-test-tab-enabled'  => $tab_enabled,
		];

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * admin-post handler: delete every test registrant (tec_tc_attendee post
	 * marked _etr_test_registrant = 1) on the selected event, along with the
	 * shared placeholder test order those attendees were parented to, and
	 * reset that event's used-player tracking so a future batch can reuse
	 * the pool.
	 */
	public function handle_remove_test_registrants() {
		if ( ! isset( $_POST['etr_test_remove_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['etr_test_remove_nonce'] ) ), 'etr_remove_test_registrants' )
		) {
			wp_die( esc_html__( 'Security check failed.', 'etr' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'etr' ) );
		}

		$event_id = isset( $_POST['etr_test_event'] ) ? absint( $_POST['etr_test_event'] ) : 0;
		if ( ! $event_id || get_post_type( $event_id ) !== 'tribe_events' ) {
			wp_die( esc_html__( 'Please choose a valid event.', 'etr' ) );
		}

		$attendee_ids = get_posts( [
			'post_type'      => 'tec_tc_attendee',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => [
				[ 'key' => '_etr_test_registrant', 'value' => 1 ],
				[ 'key' => ETR_TC_EVENT_KEY,        'value' => $event_id ],
			],
		] );

		// Test attendees created before this plugin started tagging them with
		// ticket metadata (see the Ticket_Model note in
		// handle_add_test_registrants()) are in a legacy, incomplete shape.
		// Event Tickets' own deletion hooks (tribe_tickets/tickets_deleted,
		// decreases_inventory(), etc.) can fatal on that shape - e.g. by
		// type-hinting an int ticket id that legacy test attendees never got.
		// A fatal here would abort the whole batch and leave every remaining
		// attendee undeleted, so each deletion is isolated: one bad post is
		// skipped, not fatal for the rest.
		$removed = 0;
		$failed  = 0;
		foreach ( $attendee_ids as $attendee_id ) {
			try {
				if ( wp_delete_post( $attendee_id, true ) ) {
					$removed++;
				} else {
					$failed++;
				}
			} catch ( \Throwable $e ) {
				$failed++;
				continue;
			}
		}

		// Clean up the parent order created alongside the test attendees; see
		// the note above the order lookup in handle_add_test_registrants().
		$order_ids = get_posts( [
			'post_type'      => 'tec_tc_order',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => [
				[ 'key' => '_etr_test_registrant', 'value' => 1 ],
				[ 'key' => ETR_TC_EVENT_KEY,        'value' => $event_id ],
			],
		] );
		foreach ( $order_ids as $order_id ) {
			try {
				if ( ! wp_delete_post( $order_id, true ) ) {
					$failed++;
				}
			} catch ( \Throwable $e ) {
				$failed++;
				continue;
			}
		}

		// Clean up the sideloaded portrait attachments referenced by the
		// removed test attendees (see get_or_sideload_test_avatar()); each
		// was uploaded with this event as its post_parent and tagged
		// _etr_test_registrant the same way the attendees/order are, so the
		// same isolation applies - one bad attachment shouldn't abort the rest.
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$avatar_ids = get_posts( [
			'post_type'      => 'attachment',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'post_parent'    => $event_id,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => [
				[ 'key' => '_etr_test_registrant', 'value' => 1 ],
			],
		] );
		foreach ( $avatar_ids as $avatar_id ) {
			try {
				if ( ! wp_delete_attachment( $avatar_id, true ) ) {
					$failed++;
				}
			} catch ( \Throwable $e ) {
				$failed++;
				continue;
			}
		}

		delete_post_meta( $event_id, '_etr_test_used' );
		delete_post_meta( $event_id, '_etr_test_avatar_ids' );

		// Event Tickets caches the attendee list; direct post writes bypass its invalidation.
		if ( function_exists( 'tribe' ) && class_exists( '\TEC\Tickets\Commerce\Module' ) ) {
			$module = tribe( \TEC\Tickets\Commerce\Module::class );
			if ( $module && method_exists( $module, 'clear_attendees_cache' ) ) {
				$module->clear_attendees_cache( $event_id );
			}
		}

		if ( method_exists( Plugin::instance(), 'purge_event_cache' ) ) {
			Plugin::instance()->purge_event_cache( $event_id );
		}

		$redirect_args = [ 'page' => 'etr-settings', 'etr-test-removed' => $removed ];
		if ( $failed > 0 ) {
			$redirect_args['etr-test-remove-failed'] = $failed;
		}

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
