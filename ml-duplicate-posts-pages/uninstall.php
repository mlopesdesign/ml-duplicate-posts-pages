<?php
/**
 * Uninstall handler for ML Duplicate Posts & Pages.
 *
 * Executed when the plugin is uninstalled via the WordPress admin
 * (Plugins screen -> Deactivate -> Delete). Removes plugin options
 * and transients created during normal operation.
 *
 * @package MLDuplicate
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('mldpp_settings');
delete_option('mldpp_logs');
delete_transient('mldpp_github_release');