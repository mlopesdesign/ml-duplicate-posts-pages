<?php
/**
 * Plugin Name: ML Duplicate Posts & Pages
 * Plugin URI: https://mlopesdesign.com.br/
 * Description: Duplica posts, páginas e tipos de conteúdo personalizados com controle do que copiar, ações em massa, logs e painel administrativo no padrão ML.
 * Version: 1.7.0
 * Author: MLopesDesign
 * Author URI: https://mlopesdesign.com.br/
 * Text Domain: ml-duplicate-posts-pages
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MLDPP_VERSION', '1.7.0');
define('MLDPP_FILE', __FILE__);
define('MLDPP_DIR', plugin_dir_path(__FILE__));
define('MLDPP_URL', plugin_dir_url(__FILE__));
define('MLDPP_BASENAME', plugin_basename(__FILE__));
define('MLDPP_GITHUB_OWNER', 'mlopesdesign');
// Atualizacoes vem do repositorio publico de releases: o codigo-fonte passou a ser privado
// e a API do GitHub responde 404 para repositorio privado sem token (o updater pararia).
define('MLDPP_GITHUB_REPO', 'ml-duplicate-posts-pages-releases');
define('MLDPP_GITHUB_REPO_URL', 'https://github.com/mlopesdesign/ml-duplicate-posts-pages-releases');

require_once MLDPP_DIR . 'includes/class-ml-duplicate-posts-pages.php';
require_once MLDPP_DIR . 'includes/class-mldpp-github-updater.php';

function mldpp_bootstrap() {
    return \MLDPP\Plugin::instance();
}

mldpp_bootstrap();

/**
 * 1.7.0: o plugin passou a ser gratuito (sem licença, sem teste, sem chamada ao site tools).
 * Sites que vieram da 1.6.0 ainda têm a renovação diária da licença agendada no cron:
 * desagenda uma vez. Nenhuma configuração, log ou dado do usuário é apagado.
 */
function mldpp_free_cleanup() {
    if (get_option('mldpp_free_cleanup') === MLDPP_VERSION && !wp_next_scheduled('mllc_ml_duplicate_posts_pages_refresh')) {
        return;
    }
    wp_clear_scheduled_hook('mllc_ml_duplicate_posts_pages_refresh');
    update_option('mldpp_free_cleanup', MLDPP_VERSION, false);
}
add_action('admin_init', 'mldpp_free_cleanup');
\MLDPP\GitHub_Updater::init();

register_activation_hook(__FILE__, array('\MLDPP\Plugin', 'activate'));
