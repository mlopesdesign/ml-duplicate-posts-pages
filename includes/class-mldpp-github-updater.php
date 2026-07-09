<?php
namespace MLDPP;

if (!defined('ABSPATH')) {
    exit;
}

class GitHub_Updater {
    private const TRANSIENT_KEY = 'mldpp_github_release';
    private const TRANSIENT_TTL = 6 * HOUR_IN_SECONDS;

    public static function init() {
        add_filter('pre_set_site_transient_update_plugins', array(__CLASS__, 'check_for_update'));
        add_filter('plugins_api', array(__CLASS__, 'plugin_info'), 20, 3);
        add_action('in_plugin_update_message-' . MLDPP_BASENAME, array(__CLASS__, 'render_update_message'), 10, 2);
        add_filter('plugin_row_meta', array(__CLASS__, 'plugin_row_meta'), 10, 2);
    }

    public static function check_for_update($transient) {
        if (empty($transient) || !is_object($transient)) {
            return $transient;
        }

        if (empty($transient->checked) || empty($transient->checked[MLDPP_BASENAME])) {
            return $transient;
        }

        $release = self::get_latest_release();
        if (!$release || empty($release['version']) || empty($release['package'])) {
            return $transient;
        }

        if (version_compare($release['version'], MLDPP_VERSION, '<=')) {
            return $transient;
        }

        $transient->response[MLDPP_BASENAME] = (object) array(
            'id'          => MLDPP_BASENAME,
            'slug'        => dirname(MLDPP_BASENAME),
            'plugin'      => MLDPP_BASENAME,
            'new_version' => $release['version'],
            'url'         => MLDPP_GITHUB_REPO_URL,
            'package'     => $release['package'],
            'tested'      => !empty($release['tested']) ? $release['tested'] : '',
        );

        return $transient;
    }

    public static function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== dirname(MLDPP_BASENAME)) {
            return $result;
        }

        $release = self::get_latest_release();
        $version = (!empty($release['version'])) ? $release['version'] : MLDPP_VERSION;
        $download = (!empty($release['package'])) ? $release['package'] : '';

        return (object) array(
            'name'          => 'ML Duplicate Posts & Pages',
            'slug'          => dirname(MLDPP_BASENAME),
            'version'       => $version,
            'author'        => '<a href="https://mlopesdesign.com.br/">MLopesDesign</a>',
            'homepage'      => MLDPP_GITHUB_REPO_URL,
            'download_link' => $download,
            'requires'      => '5.8',
            'requires_php'  => '7.4',
            'tested'        => !empty($release['tested']) ? $release['tested'] : '',
            'sections'      => array(
                'description' => 'Duplica posts, páginas e tipos de conteúdo personalizados preservando o título original e versionando o slug da cópia.',
                'changelog'   => !empty($release['changelog']) ? $release['changelog'] : 'Consulte o changelog no GitHub.',
            ),
        );
    }

    public static function render_update_message($plugin_data, $response) {
        if (empty($response) || empty($response->new_version)) {
            return;
        }

        $changelog = self::get_release_changelog();
        if (empty($changelog)) {
            return;
        }

        echo '<div class="mldpp-update-message" style="margin-top:8px;padding:10px 12px;background:#f4f9fb;border-left:3px solid #114257;border-radius:6px;">';
        echo '<strong style="display:block;margin-bottom:6px;color:#114257;">' . esc_html__('Notas da versao', 'ml-duplicate-posts-pages') . ' ' . esc_html($response->new_version) . '</strong>';
        echo '<div style="font-size:12px;line-height:1.5;color:#344954;">' . wp_kses_post($changelog) . '</div>';
        echo '<p style="margin:8px 0 0;"><a href="' . esc_url(MLDPP_GITHUB_REPO_URL . '/releases/tag/v' . $response->new_version) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Ver changelog completo no GitHub', 'ml-duplicate-posts-pages') . '</a></p>';
        echo '</div>';
    }

    public static function plugin_row_meta($meta, $plugin_file) {
        if ($plugin_file !== MLDPP_BASENAME) {
            return $meta;
        }

        $meta[] = '<a href="' . esc_url(MLDPP_GITHUB_REPO_URL . '/releases') . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Changelog', 'ml-duplicate-posts-pages') . '</a>';
        $meta[] = '<a href="' . esc_url(MLDPP_GITHUB_REPO_URL) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Repositorio', 'ml-duplicate-posts-pages') . '</a>';

        return $meta;
    }

    private static function get_release_changelog() {
        $cached = get_transient(self::TRANSIENT_KEY);
        if (!is_array($cached)) {
            $release = self::get_latest_release();
            if (is_array($release) && !empty($release['changelog'])) {
                return $release['changelog'];
            }
            return '';
        }

        return !empty($cached['changelog']) ? $cached['changelog'] : '';
    }

    private static function get_latest_release() {
        $cached = get_transient(self::TRANSIENT_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $url = sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            rawurlencode(MLDPP_GITHUB_OWNER),
            rawurlencode(MLDPP_GITHUB_REPO)
        );

        $response = wp_remote_get($url, array(
            'timeout' => 12,
            'headers' => array(
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url('/'),
            ),
        ));

        if (is_wp_error($response)) {
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['tag_name'])) {
            return null;
        }

        $version = ltrim((string) $body['tag_name'], 'vV');
        $package = self::find_release_package($body, $version);

        $release = array(
            'version'   => $version,
            'package'   => $package,
            'changelog' => !empty($body['body']) ? wp_kses_post(wpautop($body['body'])) : '',
            'tested'    => '',
        );

        set_transient(self::TRANSIENT_KEY, $release, self::TRANSIENT_TTL);

        return $release;
    }

    private static function find_release_package(array $release, $version) {
        if (empty($release['assets']) || !is_array($release['assets'])) {
            return '';
        }

        $preferred_names = array(
            dirname(MLDPP_BASENAME) . '-' . $version . '.zip',
            dirname(MLDPP_BASENAME) . '.zip',
        );

        foreach ($preferred_names as $preferred_name) {
            foreach ($release['assets'] as $asset) {
                if (!empty($asset['name']) && $asset['name'] === $preferred_name && !empty($asset['browser_download_url'])) {
                    return esc_url_raw($asset['browser_download_url']);
                }
            }
        }

        foreach ($release['assets'] as $asset) {
            if (!empty($asset['name']) && substr($asset['name'], -4) === '.zip' && !empty($asset['browser_download_url'])) {
                return esc_url_raw($asset['browser_download_url']);
            }
        }

        return '';
    }
}
