<?php
/**
 * Removes the plugin's settings when it is deleted.
 *
 * @package Hibira
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'hibira_settings' );
