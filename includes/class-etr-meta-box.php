<?php
namespace Etr;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * "Event Registrations" meta box on the event edit screen: toggles the
 * Registrations tab for the event and optionally hides individual sections.
 */
class Meta_Box {

	private static $instance = null;

	public static function instance() {
		if ( ! self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action( 'add_meta_boxes', [ $this, 'register' ] );
		add_action( 'save_post',      [ $this, 'save' ], 10, 2 );
	}

	public function register() {
		add_meta_box(
			'etr-meta-box',
			__( 'Event Registrations', 'etr' ),
			[ $this, 'render' ],
			'tribe_events',
			'side',
			'default'
		);
	}

	public function render( $post ) {
		wp_nonce_field( 'etr_meta_box_save_' . $post->ID, 'etr_meta_box_nonce' );

		$show        = (bool) get_post_meta( $post->ID, '_etr_show_registrations', true );
		$show_photos = (bool) get_post_meta( $post->ID, '_etr_show_photos', true );

		$hidden = get_post_meta( $post->ID, '_etr_hidden_sections', true );
		$hidden = is_array( $hidden ) ? $hidden : [];

		$section_options = Plugin::instance()->get_section_options();
		?>
		<p>
			<label>
				<input type="checkbox" name="etr_show_registrations" value="1" <?php checked( $show ); ?>>
				<?php esc_html_e( 'Show the Registrations tab on this event', 'etr' ); ?>
			</label>
		</p>

		<p>
			<label>
				<input type="checkbox" name="etr_show_photos" value="1" <?php checked( $show_photos ); ?>>
				<?php esc_html_e( 'Show profile pictures', 'etr' ); ?>
			</label>
			<br>
			<span class="description"><?php esc_html_e( 'Adds a photo column to the registrations tables. Attendees without a saved photo show a placeholder silhouette.', 'etr' ); ?></span>
		</p>

		<?php if ( ! empty( $section_options ) ) : ?>
			<p class="description" style="margin-top:12px;">
				<?php esc_html_e( 'Hide specific sections on this event (optional):', 'etr' ); ?>
			</p>
			<?php foreach ( $section_options as $label ) : ?>
				<label style="display:block;margin:2px 0;">
					<input type="checkbox" name="etr_hidden_sections[]" value="<?php echo esc_attr( $label ); ?>"
						<?php checked( in_array( $label, $hidden, true ) ); ?>>
					<?php echo esc_html( $label ); ?>
				</label>
			<?php endforeach; ?>
		<?php else : ?>
			<p class="description">
				<?php esc_html_e( 'No section options are defined yet in Extra Custom Fields.', 'etr' ); ?>
			</p>
		<?php endif; ?>
		<?php
	}

	public function save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( wp_is_post_revision( $post_id ) ) return;
		if ( $post->post_type !== 'tribe_events' ) return;

		if ( ! isset( $_POST['etr_meta_box_nonce'] )
			|| ! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['etr_meta_box_nonce'] ) ),
				'etr_meta_box_save_' . $post_id
			)
		) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) return;

		if ( ! empty( $_POST['etr_show_registrations'] ) ) {
			update_post_meta( $post_id, '_etr_show_registrations', 1 );
		} else {
			delete_post_meta( $post_id, '_etr_show_registrations' );
		}

		if ( ! empty( $_POST['etr_show_photos'] ) ) {
			update_post_meta( $post_id, '_etr_show_photos', 1 );
		} else {
			delete_post_meta( $post_id, '_etr_show_photos' );
		}

		// Store only section values that currently exist in the ETECF options.
		$valid_sections = Plugin::instance()->get_section_options();
		if ( ! empty( $_POST['etr_hidden_sections'] ) && is_array( $_POST['etr_hidden_sections'] ) ) {
			$submitted = array_map( 'sanitize_text_field', wp_unslash( $_POST['etr_hidden_sections'] ) );
			$clean     = array_values( array_intersect( $submitted, $valid_sections ) );
		} else {
			$clean = [];
		}

		if ( $clean ) {
			update_post_meta( $post_id, '_etr_hidden_sections', $clean );
		} else {
			delete_post_meta( $post_id, '_etr_hidden_sections' );
		}
	}
}
