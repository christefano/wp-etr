<?php
/**
 * Plugin Name: Event Tickets Registrations
 * Description: Adds a public Registrations tab to The Events Calendar event pages, grouping Event Tickets attendees into sections (defined in Extra Custom Fields) as a seeded table of Name / USCF ID / Rating.
 * Version: 5.2.2
 * Author: Christefano Reyes
 * Plugin URI: https://github.com/christefano/wp-etr
 * Requires at least: 6.7
 * Tested up to: 6.9
 * Requires Plugins: the-events-calendar, event-tickets, wp-etecf
 * Text Domain: etr
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'ETR_VERSION', '5.2.2' );
define( 'ETR_PATH', plugin_dir_path( __FILE__ ) );
define( 'ETR_URL',  plugin_dir_url( __FILE__ ) );

// Tickets Commerce meta key linking an attendee post to its event.
define( 'ETR_TC_EVENT_KEY', '_tec_tickets_commerce_event' );

require_once ETR_PATH . 'includes/class-etr-plugin.php';
require_once ETR_PATH . 'includes/class-etr-registrations.php';
require_once ETR_PATH . 'includes/class-etr-settings.php';
require_once ETR_PATH . 'includes/class-etr-meta-box.php';

/**
 * Boot the plugin. ETECF supplies the field catalog (the `etecf_field_config`
 * option); this plugin reads that option and attendee post meta directly rather
 * than calling ETECF's PHP classes, so it survives ETECF internal refactors.
 */
add_action( 'plugins_loaded', function () {
	\Etr\Plugin::instance();
	\Etr\Registrations::instance();
	\Etr\Settings::instance();
	\Etr\Meta_Box::instance();
} );

/** Nudge the admin if ETECF (the field source) is not present. */
add_action( 'admin_notices', function () {
	if ( get_option( 'etecf_field_config', null ) !== null ) return;
	if ( ! current_user_can( 'activate_plugins' ) ) return;
	echo '<div class="notice notice-warning"><p>'
		. esc_html__( 'Event Tickets Registrations needs Event Tickets Extra Custom Fields (wp-etecf) active to supply attendee fields.', 'etr' )
		. '</p></div>';
} );

// The plugin base file, exposed so the Settings class can hook its
// plugin_action_links / plugin_row_meta filters to the right plugin row.
define( 'ETR_BASENAME', plugin_basename( __FILE__ ) );
