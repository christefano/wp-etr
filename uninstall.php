<?php
/**
 * Uninstall cleanup for Event Tickets Registrations.
 * Removes the plugin option and all of ETR's post meta: per-event settings
 * (_etr_show_registrations, _etr_hidden_sections, _etr_show_photos,
 * _etr_test_used, _etr_test_avatar_ids), the per-attendee no-show status
 * (_etr_status), and the test-registrant marker (_etr_test_registrant, also
 * used on sideloaded test-avatar attachments). ETECF-owned data (fields,
 * attendee custom fields, including the profile photo) is left untouched.
 * Any leftover test-registrant posts (attendees, the placeholder order,
 * sideloaded avatar attachments) are not deleted here (that data lives in
 * Event Tickets' and the media library's own post types, not ETR's own
 * tables) - use "Remove all test registrants" before uninstalling.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

delete_option( 'etr_options' );

global $wpdb;
$wpdb->query(
	"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ( '_etr_show_registrations', '_etr_hidden_sections', '_etr_status', '_etr_show_photos', '_etr_test_used', '_etr_test_avatar_ids', '_etr_test_registrant' )"
);
