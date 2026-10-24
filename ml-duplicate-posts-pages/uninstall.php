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

delete_option('mldpp_free_cleanup');

// 1.7.0: plugin gratuito. Desagenda a renovação diária da licença que a 1.6.0 criava
// (caso o site nunca tenha aberto o painel depois de atualizar).
wp_clear_scheduled_hook('mllc_ml_duplicate_posts_pages_refresh');
