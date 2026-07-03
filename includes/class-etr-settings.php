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
		add_filter( 'plugin_action_links_' . ETR_BASENAME, [ $this, 'plugin_action_links' ] );
		add_filter( 'plugin_row_meta',              [ $this, 'plugin_row_meta' ], 10, 2 );
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
		</div>
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
}
