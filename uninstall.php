<?php
/**
 * Uninstall cleanup for Event Tickets Registrations.
 * Removes the plugin option and all of ETR's post meta: per-event settings
 * (_etr_show_registrations, _etr_hidden_sections) and the per-attendee no-show
 * status (_etr_status). ETECF-owned data (fields, attendee custom fields) is
 * left untouched.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

delete_option( 'etr_options' );

global $wpdb;
$wpdb->query(
	"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ( '_etr_show_registrations', '_etr_hidden_sections', '_etr_status' )"
);
