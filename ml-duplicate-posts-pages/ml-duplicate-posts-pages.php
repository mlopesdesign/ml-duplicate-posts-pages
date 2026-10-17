<?php
/**
 * Plugin Name: ML Duplicate Posts & Pages
 * Plugin URI: https://mlopesdesign.com.br/
 * Description: Duplica posts, páginas e tipos de conteúdo personalizados com controle do que copiar, ações em massa, logs e painel administrativo no padrão ML.
 * Version: 1.6.0
 * Author: MLopesDesign
 * Author URI: https://mlopesdesign.com.br/
 * Text Domain: ml-duplicate-posts-pages
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MLDPP_VERSION', '1.6.0');
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
require_once MLDPP_DIR . 'includes/class-mldpp-license.php';
require_once MLDPP_DIR . 'includes/class-mldpp-github-updater.php';

function mldpp_bootstrap() {
    return \MLDPP\Plugin::instance();
}

mldpp_bootstrap();
\MLDPP\License::boot();
\MLDPP\GitHub_Updater::init();

register_activation_hook(__FILE__, array('\MLDPP\Plugin', 'activate'));
