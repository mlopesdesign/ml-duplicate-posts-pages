<?php
namespace MLDPP;

if (!defined('ABSPATH')) {
    exit;
}

class Plugin {
    private static $instance = null;
    private $option_name = 'mldpp_settings';
    private $log_option_name = 'mldpp_logs';
    private $screen_hook = '';
    private $notices = array();

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function activate() {
        $defaults = self::get_default_settings_static();
        $current  = get_option('mldpp_settings', array());
        update_option('mldpp_settings', wp_parse_args($current, $defaults));

        if (get_option('mldpp_logs', null) === null) {
            add_option('mldpp_logs', array(), '', false);
        }
    }

    public function __construct() {
        add_action('init', array($this, 'load_textdomain'));
        add_action('admin_menu', array($this, 'register_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_init', array($this, 'handle_duplicate_request'));
        add_action('admin_init', array($this, 'handle_bulk_duplicate_request'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_ajax_mldpp_preview_slug', array($this, 'ajax_preview_slug'));

        add_filter('post_row_actions', array($this, 'add_row_action'), 10, 2);
        add_filter('page_row_actions', array($this, 'add_row_action'), 10, 2);
        add_filter('bulk_actions-edit-post', array($this, 'register_bulk_action_for_post'));
        add_filter('bulk_actions-edit-page', array($this, 'register_bulk_action_for_page'));
        add_filter('handle_bulk_actions-edit-post', array($this, 'handle_native_bulk_action_redirect'), 10, 3);
        add_filter('handle_bulk_actions-edit-page', array($this, 'handle_native_bulk_action_redirect'), 10, 3);

        add_action('admin_bar_menu', array($this, 'add_admin_bar_button'), 90);
        add_action('post_submitbox_misc_actions', array($this, 'render_submitbox_button'));
        add_action('admin_notices', array($this, 'render_admin_notices'));

        add_filter('plugin_action_links_' . MLDPP_BASENAME, array($this, 'plugin_action_links'));
    }

    public function load_textdomain() {
        load_plugin_textdomain('ml-duplicate-posts-pages', false, dirname(MLDPP_BASENAME) . '/languages');
    }

    public function register_admin_menu() {
        $this->screen_hook = add_menu_page(
            'ML Duplicate',
            'ML Duplicate',
            'manage_options',
            'mldpp-dashboard',
            array($this, 'render_dashboard'),
            'dashicons-admin-page',
            58
        );

        if ($this->screen_hook) {
            add_action('load-' . $this->screen_hook, array($this, 'register_help_tabs'));
        }
    }

    public function register_help_tabs() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen) {
            return;
        }

        $screen->add_help_tab(array(
            'id'      => 'mldpp-how-to-use',
            'title'   => __('Como usar', 'ml-duplicate-posts-pages'),
            'content' => '<p>' . esc_html__('Use a acao rapida "Duplicar" na listagem de posts/paginas, a acao em massa nativa do WordPress, ou o botao "Duplicar este conteudo" no editor classico para criar uma copia.', 'ml-duplicate-posts-pages') . '</p>'
                . '<p>' . esc_html__('Em "ML Duplicate" voce tambem encontra a duplicacao em lote com filtros por tipo, status e busca no conteudo.', 'ml-duplicate-posts-pages') . '</p>',
        ));

        $screen->add_help_tab(array(
            'id'      => 'mldpp-slug-rules',
            'title'   => __('Regras de slug', 'ml-duplicate-posts-pages'),
            'content' => '<p>' . esc_html__('O titulo original e preservado. O slug da copia e versionado automaticamente:', 'ml-duplicate-posts-pages') . '</p>'
                . '<ul>'
                . '<li><code>samba-2-guimaraes-215</code> &rarr; <code>samba-2-guimaraes-216</code></li>'
                . '<li><code>pagina-15-historia</code> &rarr; <code>pagina-16-historia</code></li>'
                . '<li><code>foo-2-bar-7-baz</code> &rarr; <code>foo-2-bar-8-baz</code></li>'
                . '<li><code>post-007</code> &rarr; <code>post-008</code> (preserva zero a esquerda)</li>'
                . '<li><code>minha-pagina</code> &rarr; <code>minha-pagina-2</code></li>'
                . '</ul>'
                . '<p>' . esc_html__('Voce pode usar os campos slug_prefix e slug_suffix para prefixar/sufixar o slug versionado.', 'ml-duplicate-posts-pages') . '</p>'
                . '<p>' . esc_html__('O modo append_suffix usa sempre o sufixo -2, -3... ignorando a deteccao do ultimo numero.', 'ml-duplicate-posts-pages') . '</p>',
        ));

        $screen->add_help_tab(array(
            'id'      => 'mldpp-compat',
            'title'   => __('Compatibilidade', 'ml-duplicate-posts-pages'),
            'content' => '<p>' . esc_html__('WordPress 5.8 ou superior (testado ate 6.8). PHP 7.4 ou superior.', 'ml-duplicate-posts-pages') . '</p>'
                . '<p>' . esc_html__('Atualizacao automatica via GitHub Releases. Configuracoes sao preservadas em todas as atualizacoes.', 'ml-duplicate-posts-pages') . '</p>'
                . '<p>' . esc_html__('Suporte a qualquer custom post type com interface administrativa. Page templates, meta keys (exceto chaves internas do WP) e taxonomias sao copiados.', 'ml-duplicate-posts-pages') . '</p>',
        ));

        $screen->set_help_sidebar('<p><strong>' . esc_html__('ML Duplicate Posts & Pages', 'ml-duplicate-posts-pages') . '</strong></p>'
            . '<p>' . esc_html__('Documentacao e suporte no GitHub do projeto.', 'ml-duplicate-posts-pages') . '</p>'
            . '<p><a href="' . esc_url(MLDPP_GITHUB_REPO_URL) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Abrir repositorio', 'ml-duplicate-posts-pages') . '</a></p>'
            . '<p><a href="' . esc_url(MLDPP_GITHUB_REPO_URL . '/releases') . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Ver changelog', 'ml-duplicate-posts-pages') . '</a></p>');
    }

    public function register_settings() {
        register_setting('mldpp_settings_group', $this->option_name, array($this, 'sanitize_settings'));
    }

    public function enqueue_assets($hook) {
        $is_plugin_screen = ($hook === $this->screen_hook);
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if (!$is_plugin_screen && !$screen) {
            return;
        }

        if (
            !$is_plugin_screen &&
            empty($screen->id) &&
            empty($screen->post_type)
        ) {
            return;
        }

        wp_enqueue_style(
            'mldpp-admin',
            MLDPP_URL . 'assets/css/admin.css',
            array(),
            MLDPP_VERSION
        );

        wp_enqueue_script(
            'mldpp-admin',
            MLDPP_URL . 'assets/js/admin.js',
            array('jquery'),
            MLDPP_VERSION,
            true
        );

        wp_localize_script('mldpp-admin', 'mldppAdmin', array(
            'copiedText'   => __('Link copiado.', 'ml-duplicate-posts-pages'),
            'previewNonce' => wp_create_nonce('mldpp_preview_slug'),
            'previewAction' => 'mldpp_preview_slug',
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'i18n'         => array(
                'previewTitle' => __('Slug previsto:', 'ml-duplicate-posts-pages'),
                'previewError' => __('Nao foi possivel calcular o slug.', 'ml-duplicate-posts-pages'),
            ),
        ));
    }

    public function plugin_action_links($links) {
        array_unshift(
            $links,
            '<a href="' . esc_url(admin_url('admin.php?page=mldpp-dashboard')) . '">' . esc_html__('Configurações', 'ml-duplicate-posts-pages') . '</a>'
        );
        return $links;
    }

    public static function get_default_settings_static() {
        return array(
            'enabled_post_types'       => array('post', 'page'),
            'duplicate_featured_image' => 1,
            'duplicate_taxonomies'     => 1,
            'duplicate_meta'           => 1,
            'duplicate_comments'       => 0,
            'duplicate_menu_order'     => 1,
            'duplicate_template'       => 1,
            'duplicate_author'         => 1,
            'copy_status_mode'         => 'draft',
            'title_prefix'             => '',
            'title_suffix'             => '',
            'slug_prefix'              => '',
            'slug_suffix'              => '',
            'numeric_increment_mode'   => 'last_numeric',
            'roles_allowed'            => array('administrator', 'editor'),
            'log_limit'                => 50,
        );
    }

    private function get_settings() {
        $settings = get_option($this->option_name, array());
        return wp_parse_args($settings, self::get_default_settings_static());
    }

    public function sanitize_settings($input) {
        $defaults = self::get_default_settings_static();
        $output   = $defaults;

        $public_post_types = get_post_types(array('show_ui' => true), 'objects');
        $allowed_post_types = array();

        foreach ($public_post_types as $post_type => $object) {
            if (post_type_supports($post_type, 'editor') || post_type_supports($post_type, 'title')) {
                $allowed_post_types[] = $post_type;
            }
        }

        $input_post_types = isset($input['enabled_post_types']) ? (array) $input['enabled_post_types'] : array();
        $output['enabled_post_types'] = array_values(array_intersect($allowed_post_types, array_map('sanitize_key', $input_post_types)));

        if (empty($output['enabled_post_types'])) {
            $output['enabled_post_types'] = array('post', 'page');
        }

        $checkboxes = array(
            'duplicate_featured_image',
            'duplicate_taxonomies',
            'duplicate_meta',
            'duplicate_comments',
            'duplicate_menu_order',
            'duplicate_template',
            'duplicate_author',
        );

        foreach ($checkboxes as $key) {
            $output[$key] = !empty($input[$key]) ? 1 : 0;
        }

        $allowed_modes = array('draft', 'same');
        $output['copy_status_mode'] = (isset($input['copy_status_mode']) && in_array($input['copy_status_mode'], $allowed_modes, true))
            ? $input['copy_status_mode']
            : $defaults['copy_status_mode'];

        $output['title_prefix'] = '';
        $output['title_suffix'] = '';

        $output['slug_prefix'] = isset($input['slug_prefix']) ? $this->sanitize_slug_token(wp_unslash($input['slug_prefix'])) : '';
        $output['slug_suffix'] = isset($input['slug_suffix']) ? $this->sanitize_slug_token(wp_unslash($input['slug_suffix'])) : '';

        $allowed_numeric_modes = array('last_numeric', 'append_suffix');
        $output['numeric_increment_mode'] = (isset($input['numeric_increment_mode']) && in_array($input['numeric_increment_mode'], $allowed_numeric_modes, true))
            ? $input['numeric_increment_mode']
            : $defaults['numeric_increment_mode'];

        global $wp_roles;
        $valid_roles = !empty($wp_roles->roles) ? array_keys($wp_roles->roles) : array('administrator');
        $roles_input  = isset($input['roles_allowed']) ? (array) $input['roles_allowed'] : array();
        $output['roles_allowed'] = array_values(array_intersect($valid_roles, array_map('sanitize_key', $roles_input)));
        if (empty($output['roles_allowed'])) {
            $output['roles_allowed'] = array('administrator');
        }

        $log_limit = isset($input['log_limit']) ? absint($input['log_limit']) : $defaults['log_limit'];
        $output['log_limit'] = max(10, min(500, $log_limit));

        return $output;
    }

    private function current_user_can_duplicate() {
        if (current_user_can('manage_options')) {
            return true;
        }

        $settings = $this->get_settings();
        $user = wp_get_current_user();

        if (empty($user->roles)) {
            return false;
        }

        return (bool) array_intersect($settings['roles_allowed'], (array) $user->roles);
    }

    private function is_post_type_enabled($post_type) {
        $settings = $this->get_settings();
        return in_array($post_type, (array) $settings['enabled_post_types'], true);
    }

    public function add_row_action($actions, $post) {
        if (!$post || !$this->current_user_can_duplicate()) {
            return $actions;
        }

        if (!$this->is_post_type_enabled($post->post_type)) {
            return $actions;
        }

        if (!current_user_can('edit_post', $post->ID)) {
            return $actions;
        }

        $url = wp_nonce_url(
            admin_url('admin.php?action=mldpp_duplicate_post&post=' . absint($post->ID)),
            'mldpp_duplicate_post_' . absint($post->ID)
        );

        $actions['mldpp_duplicate'] = '<a href="' . esc_url($url) . '">' . esc_html__('Duplicar', 'ml-duplicate-posts-pages') . '</a>';

        return $actions;
    }

    public function register_bulk_action_for_post($bulk_actions) {
        if ($this->is_post_type_enabled('post') && $this->current_user_can_duplicate()) {
            $bulk_actions['mldpp_bulk_duplicate'] = __('Duplicar', 'ml-duplicate-posts-pages');
        }
        return $bulk_actions;
    }

    public function register_bulk_action_for_page($bulk_actions) {
        if ($this->is_post_type_enabled('page') && $this->current_user_can_duplicate()) {
            $bulk_actions['mldpp_bulk_duplicate'] = __('Duplicar', 'ml-duplicate-posts-pages');
        }
        return $bulk_actions;
    }

    public function handle_native_bulk_action_redirect($redirect_to, $doaction, $post_ids) {
        if ($doaction !== 'mldpp_bulk_duplicate') {
            return $redirect_to;
        }

        if (!$this->current_user_can_duplicate()) {
            return add_query_arg(array('mldpp_error' => 1), $redirect_to);
        }

        $created = 0;
        foreach ((array) $post_ids as $post_id) {
            $new_id = $this->duplicate_post(absint($post_id));
            if (!is_wp_error($new_id) && $new_id) {
                $created++;
            }
        }

        return add_query_arg(array(
            'mldpp_bulk_done' => $created,
        ), $redirect_to);
    }

    public function add_admin_bar_button($wp_admin_bar) {
        if (!is_admin() || !is_singular()) {
            return;
        }

        global $post;
        if (!$post || !$this->current_user_can_duplicate() || !$this->is_post_type_enabled($post->post_type)) {
            return;
        }

        if (!current_user_can('edit_post', $post->ID)) {
            return;
        }

        $url = wp_nonce_url(
            admin_url('admin.php?action=mldpp_duplicate_post&post=' . absint($post->ID)),
            'mldpp_duplicate_post_' . absint($post->ID)
        );

        $wp_admin_bar->add_node(array(
            'id'    => 'mldpp-duplicate',
            'title' => __('Duplicar conteúdo', 'ml-duplicate-posts-pages'),
            'href'  => $url,
            'meta'  => array(
                'class' => 'mldpp-duplicate-link',
                'html'  => '<span class="mldpp-preview-slug" data-post-id="' . esc_attr(absint($post->ID)) . '"></span>',
            ),
        ));
    }

    public function render_submitbox_button() {
        global $post;
        if (!$post || !$this->current_user_can_duplicate() || !$this->is_post_type_enabled($post->post_type)) {
            return;
        }

        if (!current_user_can('edit_post', $post->ID)) {
            return;
        }

        $url = wp_nonce_url(
            admin_url('admin.php?action=mldpp_duplicate_post&post=' . absint($post->ID)),
            'mldpp_duplicate_post_' . absint($post->ID)
        );

        echo '<div class="misc-pub-section mldpp-submitbox-wrap">';
        echo '<a class="button button-secondary button-large mldpp-editor-button mldpp-duplicate-link" data-post-id="' . esc_attr(absint($post->ID)) . '" href="' . esc_url($url) . '">' . esc_html__('Duplicar este conteúdo', 'ml-duplicate-posts-pages') . '</a>';
        echo '<span class="mldpp-preview-slug" data-post-id="' . esc_attr(absint($post->ID)) . '"></span>';
        echo '</div>';
    }

    public function ajax_preview_slug() {
        if (!is_admin()) {
            wp_send_json_error(array('message' => __('Contexto invalido.', 'ml-duplicate-posts-pages')), 400);
        }

        if (!$this->current_user_can_duplicate()) {
            wp_send_json_error(array('message' => __('Sem permissao.', 'ml-duplicate-posts-pages')), 403);
        }

        check_ajax_referer('mldpp_preview_slug', 'nonce');

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $post    = $post_id ? get_post($post_id) : null;

        if (!$post || empty($post->ID)) {
            wp_send_json_error(array('message' => __('Conteudo nao encontrado.', 'ml-duplicate-posts-pages')), 404);
        }

        if (!$this->is_post_type_enabled($post->post_type)) {
            wp_send_json_error(array('message' => __('Tipo de conteudo nao habilitado.', 'ml-duplicate-posts-pages')), 400);
        }

        $slug = $this->generate_versioned_slug($post);

        wp_send_json_success(array(
            'slug'      => $slug,
            'source_id' => (int) $post->ID,
        ));
    }

    public function handle_duplicate_request() {
        if (!is_admin() || empty($_GET['action']) || $_GET['action'] !== 'mldpp_duplicate_post') {
            return;
        }

        if (!$this->current_user_can_duplicate()) {
            wp_die(esc_html__('Você não tem permissão para duplicar este conteúdo.', 'ml-duplicate-posts-pages'));
        }

        $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
        if (!$post_id) {
            wp_die(esc_html__('Post inválido.', 'ml-duplicate-posts-pages'));
        }

        check_admin_referer('mldpp_duplicate_post_' . $post_id);

        $new_id = $this->duplicate_post($post_id);

        if (is_wp_error($new_id)) {
            $redirect = admin_url('edit.php?post_type=' . get_post_type($post_id));
            $redirect = add_query_arg('mldpp_error_msg', rawurlencode($new_id->get_error_message()), $redirect);
            wp_safe_redirect($redirect);
            exit;
        }

        $redirect = admin_url('post.php?action=edit&post=' . absint($new_id));
        $redirect = add_query_arg('mldpp_notice', 'duplicated', $redirect);
        wp_safe_redirect($redirect);
        exit;
    }

    public function handle_bulk_duplicate_request() {
        if (!is_admin() || empty($_POST['mldpp_manual_bulk_submit'])) {
            return;
        }

        if (!$this->current_user_can_duplicate()) {
            wp_die(esc_html__('Você não tem permissão para esta ação.', 'ml-duplicate-posts-pages'));
        }

        check_admin_referer('mldpp_manual_bulk_action', 'mldpp_manual_bulk_nonce');

        $post_type = isset($_POST['mldpp_bulk_post_type']) ? sanitize_key($_POST['mldpp_bulk_post_type']) : 'post';
        $limit     = isset($_POST['mldpp_bulk_limit']) ? absint($_POST['mldpp_bulk_limit']) : 10;
        $status    = isset($_POST['mldpp_bulk_filter_status']) ? sanitize_key($_POST['mldpp_bulk_filter_status']) : 'any';
        $search    = isset($_POST['mldpp_bulk_search']) ? sanitize_text_field(wp_unslash($_POST['mldpp_bulk_search'])) : '';
        $override  = isset($_POST['mldpp_bulk_status_override']) ? sanitize_key($_POST['mldpp_bulk_status_override']) : '';

        if (!$this->is_post_type_enabled($post_type)) {
            $this->notices[] = array('type' => 'error', 'message' => __('Este tipo de conteúdo não está habilitado.', 'ml-duplicate-posts-pages'));
            return;
        }

        $args = array(
            'post_type'      => $post_type,
            'posts_per_page' => max(1, min(100, $limit)),
            'post_status'    => ($status === 'any') ? array('publish', 'draft', 'pending', 'future', 'private') : $status,
            's'              => $search,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
        );

        $query = new \WP_Query($args);
        $created = 0;

        foreach ((array) $query->posts as $source_id) {
            $new_id = $this->duplicate_post($source_id, array(
                'copy_status_mode'  => $override ? 'same' : null,
                'force_post_status' => $override ?: null,
            ));

            if (!is_wp_error($new_id) && $new_id) {
                $created++;
            }
        }

        $this->notices[] = array(
            'type'    => 'success',
            'message' => sprintf(
                /* translators: %d: quantity */
                _n('%d cópia criada com sucesso.', '%d cópias criadas com sucesso.', $created, 'ml-duplicate-posts-pages'),
                $created
            ),
        );
    }

    public function render_admin_notices() {
        if (!empty($_GET['mldpp_notice']) && $_GET['mldpp_notice'] === 'duplicated') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Conteúdo duplicado com sucesso.', 'ml-duplicate-posts-pages') . '</p></div>';
        }

        if (!empty($_GET['mldpp_bulk_done'])) {
            $count = absint($_GET['mldpp_bulk_done']);
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(_n('%d cópia criada com sucesso.', '%d cópias criadas com sucesso.', $count, 'ml-duplicate-posts-pages'), $count)) . '</p></div>';
        }

        if (!empty($_GET['mldpp_error_msg'])) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html(rawurldecode(wp_unslash($_GET['mldpp_error_msg']))) . '</p></div>';
        }

        foreach ($this->notices as $notice) {
            $type = !empty($notice['type']) ? $notice['type'] : 'success';
            $message = !empty($notice['message']) ? $notice['message'] : '';
            echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }
    }

    private function generate_versioned_slug($post) {
        $settings = $this->get_settings();

        $base = $this->get_duplicate_slug_base($post);
        $base = sanitize_title($base);

        if ($base === '') {
            $base = 'conteudo-' . absint($post->ID);
        }

        $mode = !empty($settings['numeric_increment_mode']) ? $settings['numeric_increment_mode'] : 'last_numeric';
        $candidate = '';

        if ($mode === 'last_numeric') {
            $candidate = $this->increment_last_numeric_token($base, $post);
        }

        if ($candidate === '') {
            $candidate = $this->build_with_progressive_number($base, $post);
        }

        $prefix_token = isset($settings['slug_prefix']) ? (string) $settings['slug_prefix'] : '';
        $suffix_token = isset($settings['slug_suffix']) ? (string) $settings['slug_suffix'] : '';

        if ($prefix_token !== '' || $suffix_token !== '') {
            $candidate = $this->apply_slug_tokens($candidate, $prefix_token, $suffix_token, $post);
        }

        return $candidate;
    }

    private function increment_last_numeric_token($base, $post) {
        $tokens = explode('-', $base);
        for ($i = count($tokens) - 1; $i >= 0; $i--) {
            if ($tokens[$i] !== '' && ctype_digit($tokens[$i])) {
                $width = strlen($tokens[$i]);
                $next  = (int) $tokens[$i];

                do {
                    $next++;
                    $tokens[$i] = str_pad((string) $next, $width, '0', STR_PAD_LEFT);
                    $candidate  = implode('-', $tokens);
                } while ($this->duplicate_slug_exists($candidate, $post));

                return $candidate;
            }
        }

        return '';
    }

    private function build_with_progressive_number($base, $post) {
        $index = 2;
        $candidate = $base . '-' . $index;

        while ($this->duplicate_slug_exists($candidate, $post)) {
            $index++;
            $candidate = $base . '-' . $index;
        }

        return $candidate;
    }

    private function apply_slug_tokens($slug, $prefix_token, $suffix_token, $post) {
        $composed = sanitize_title($prefix_token . $slug . $suffix_token);

        if ($composed === '') {
            return $slug;
        }

        if (!$this->duplicate_slug_exists($composed, $post)) {
            return $composed;
        }

        $index = 2;
        $candidate = $composed . '-' . $index;

        while ($this->duplicate_slug_exists($candidate, $post)) {
            $index++;
            $candidate = $composed . '-' . $index;
        }

        return $candidate;
    }

    private function sanitize_slug_token($token) {
        $cleaned = strtolower(trim((string) $token));
        $cleaned = preg_replace('/[^a-z0-9\-_]/', '', $cleaned);
        $cleaned = trim($cleaned, '-_');
        return $cleaned;
    }

    private function get_duplicate_slug_base($post) {
        $stored_base = get_post_meta($post->ID, '_mldpp_slug_base', true);
        if (!empty($stored_base)) {
            return sanitize_title($stored_base);
        }

        $source_id = absint(get_post_meta($post->ID, '_mldpp_source_post', true));
        if ($source_id && $source_id !== absint($post->ID)) {
            $source_post = get_post($source_id);
            if ($source_post && $source_post->post_type === $post->post_type) {
                if (!empty($source_post->post_name)) {
                    return sanitize_title($source_post->post_name);
                }

                if (!empty($source_post->post_title)) {
                    return sanitize_title($source_post->post_title);
                }
            }
        }

        if (!empty($post->post_name)) {
            return sanitize_title($post->post_name);
        }

        if (!empty($post->post_title)) {
            return sanitize_title($post->post_title);
        }

        return 'conteudo-' . absint($post->ID);
    }

    private function duplicate_slug_exists($slug, $post) {
        global $wpdb;

        $where_parent = '';
        $params = array(
            $slug,
            $post->post_type,
            absint($post->ID),
        );

        if (is_post_type_hierarchical($post->post_type)) {
            $where_parent = ' AND post_parent = %d';
            $params[] = absint($post->post_parent);
        }

        $sql = "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = %s AND ID <> %d AND post_status <> 'trash'" . $where_parent . ' LIMIT 1';

        return (bool) $wpdb->get_var($wpdb->prepare($sql, $params));
    }

    private function duplicate_post($post_id, $args = array()) {
        $post = get_post($post_id);

        if (!$post || empty($post->ID)) {
            return new \WP_Error('mldpp_invalid_post', __('Conteúdo original não encontrado.', 'ml-duplicate-posts-pages'));
        }

        if (!$this->is_post_type_enabled($post->post_type)) {
            return new \WP_Error('mldpp_post_type_disabled', __('Este tipo de conteúdo não está habilitado para duplicação.', 'ml-duplicate-posts-pages'));
        }

        if (!current_user_can('edit_post', $post->ID)) {
            return new \WP_Error('mldpp_no_cap', __('Sem permissão para duplicar este conteúdo.', 'ml-duplicate-posts-pages'));
        }

        $settings = $this->get_settings();
        $override = wp_parse_args($args, array(
            'copy_status_mode'  => null,
            'force_post_status' => null,
        ));

        $target_status = 'draft';
        if (!empty($override['force_post_status'])) {
            $target_status = $override['force_post_status'];
        } elseif (($override['copy_status_mode'] ?: $settings['copy_status_mode']) === 'same') {
            $target_status = $post->post_status;
        }

        $new_title = $post->post_title;
        $new_slug  = $this->generate_versioned_slug($post);

        $new_post_data = array(
            'post_type'             => $post->post_type,
            'post_title'            => $new_title,
            'post_content'          => $post->post_content,
            'post_excerpt'          => $post->post_excerpt,
            'post_status'           => $target_status,
            'comment_status'        => $post->comment_status,
            'ping_status'           => $post->ping_status,
            'post_password'         => $post->post_password,
            'post_parent'           => $post->post_parent,
            'menu_order'            => !empty($settings['duplicate_menu_order']) ? (int) $post->menu_order : 0,
            'post_author'           => !empty($settings['duplicate_author']) ? (int) $post->post_author : get_current_user_id(),
            'post_content_filtered' => $post->post_content_filtered,
            'to_ping'               => $post->to_ping,
            'pinged'                => $post->pinged,
        );

        $new_post_data['post_name'] = $new_slug;

        $new_post_id = wp_insert_post(wp_slash($new_post_data), true);

        if (is_wp_error($new_post_id)) {
            return $new_post_id;
        }

        if (!empty($settings['duplicate_template'])) {
            $page_template = get_post_meta($post->ID, '_wp_page_template', true);
            if ($page_template) {
                update_post_meta($new_post_id, '_wp_page_template', $page_template);
            }
        }

        if (!empty($settings['duplicate_featured_image'])) {
            $thumbnail_id = get_post_thumbnail_id($post->ID);
            if ($thumbnail_id) {
                set_post_thumbnail($new_post_id, $thumbnail_id);
            }
        }

        if (!empty($settings['duplicate_taxonomies'])) {
            $taxonomies = get_object_taxonomies($post->post_type);
            foreach ($taxonomies as $taxonomy) {
                $term_ids = wp_get_object_terms($post->ID, $taxonomy, array('fields' => 'ids'));
                if (!is_wp_error($term_ids)) {
                    wp_set_object_terms($new_post_id, $term_ids, $taxonomy, false);
                }
            }
        }

        if (!empty($settings['duplicate_meta'])) {
            $meta = get_post_meta($post->ID);
            $skip_keys = array(
                '_edit_lock',
                '_edit_last',
                '_wp_old_slug',
                '_wp_trash_meta_status',
                '_wp_trash_meta_time',
                '_mldpp_source_post',
                '_mldpp_slug_base',
            );

            foreach ($meta as $meta_key => $values) {
                if (in_array($meta_key, $skip_keys, true)) {
                    continue;
                }

                foreach ((array) $values as $value) {
                    add_post_meta($new_post_id, $meta_key, maybe_unserialize($value));
                }
            }
        }

        if (!empty($settings['duplicate_comments'])) {
            $comments = get_comments(array(
                'post_id' => $post->ID,
                'status'  => 'all',
                'orderby' => 'comment_ID',
                'order'   => 'ASC',
            ));

            foreach ($comments as $comment) {
                $comment_data = array(
                    'comment_post_ID'      => $new_post_id,
                    'comment_author'       => $comment->comment_author,
                    'comment_author_email' => $comment->comment_author_email,
                    'comment_author_url'   => $comment->comment_author_url,
                    'comment_author_IP'    => $comment->comment_author_IP,
                    'comment_date'         => $comment->comment_date,
                    'comment_date_gmt'     => $comment->comment_date_gmt,
                    'comment_content'      => $comment->comment_content,
                    'comment_karma'        => $comment->comment_karma,
                    'comment_approved'     => $comment->comment_approved,
                    'comment_agent'        => $comment->comment_agent,
                    'comment_type'         => $comment->comment_type,
                    'comment_parent'       => 0,
                    'user_id'              => $comment->user_id,
                );
                wp_insert_comment($comment_data);
            }
        }

        update_post_meta($new_post_id, '_mldpp_source_post', $post->ID);
        update_post_meta($new_post_id, '_mldpp_slug_base', $this->get_duplicate_slug_base($post));
        update_post_meta($new_post_id, '_mldpp_duplicated_at', current_time('mysql'));
        update_post_meta($new_post_id, '_mldpp_duplicated_by', get_current_user_id());

        $this->write_log($post->ID, $new_post_id, $post->post_type, $target_status, $new_slug);

        do_action('mldpp_after_duplicate_post', $new_post_id, $post->ID, $post);

        return $new_post_id;
    }

    private function write_log($source_id, $new_id, $post_type, $new_status, $new_slug = '') {
        $settings = $this->get_settings();
        $logs = get_option($this->log_option_name, array());
        if (!is_array($logs)) {
            $logs = array();
        }

        $user = wp_get_current_user();
        $logs[] = array(
            'time'       => current_time('mysql'),
            'source_id'  => (int) $source_id,
            'new_id'     => (int) $new_id,
            'post_type'  => sanitize_key($post_type),
            'new_status' => sanitize_key($new_status),
            'new_slug'   => sanitize_title($new_slug),
            'user_id'    => get_current_user_id(),
            'user_name'  => $user && !empty($user->display_name) ? $user->display_name : '',
        );

        $limit = !empty($settings['log_limit']) ? absint($settings['log_limit']) : 50;
        if (count($logs) > $limit) {
            $logs = array_slice($logs, -1 * $limit);
        }

        update_option($this->log_option_name, $logs, false);
    }

    private function get_available_post_types() {
        $objects = get_post_types(array('show_ui' => true), 'objects');
        $result = array();

        foreach ($objects as $slug => $object) {
            if (in_array($slug, array('attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset'), true)) {
                continue;
            }

            $result[$slug] = $object->labels->singular_name . ' (' . $slug . ')';
        }

        return $result;
    }

    private function get_logs() {
        $logs = get_option($this->log_option_name, array());
        if (!is_array($logs)) {
            return array();
        }

        $migrated = false;
        foreach ($logs as $i => $log) {
            if (!is_array($log)) {
                continue;
            }
            if (!array_key_exists('new_slug', $log)) {
                $new_post = !empty($log['new_id']) ? get_post((int) $log['new_id']) : null;
                $logs[$i]['new_slug'] = ($new_post && !empty($new_post->post_name)) ? sanitize_title($new_post->post_name) : '';
                $migrated = true;
            }
        }

        if ($migrated) {
            update_option($this->log_option_name, $logs, false);
        }

        return array_reverse($logs);
    }

    public function render_dashboard() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Você não tem permissão para acessar esta página.', 'ml-duplicate-posts-pages'));
        }

        $settings = $this->get_settings();
        $post_types = $this->get_available_post_types();
        $logs = $this->get_logs();

        ?>
        <div class="wrap mldpp-admin-wrap">
            <div class="mldpp-hero">
                <div class="mldpp-hero__left">
                    <span class="mldpp-badge">ML Lopes Design</span>
                    <h1>ML Duplicate Posts &amp; Pages <span>v<?php echo esc_html(MLDPP_VERSION); ?></span></h1>
                    <p>Duplicação profissional de conteúdos do WordPress com controle do que copiar, compatibilidade com posts, páginas e CPTs, ação em massa e registro de atividades.</p>
                </div>
                <div class="mldpp-hero__right">
                    <a class="button button-primary button-hero" href="<?php echo esc_url(admin_url('edit.php')); ?>">Abrir listagem de conteúdos</a>
                </div>
            </div>

            <div class="mldpp-grid">
                <div class="mldpp-card">
                    <h2>Configurações gerais</h2>
                    <form method="post" action="options.php">
                        <?php settings_fields('mldpp_settings_group'); ?>

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row">Tipos de conteúdo habilitados</th>
                                <td>
                                    <div class="mldpp-check-grid">
                                        <?php foreach ($post_types as $slug => $label) : ?>
                                            <label>
                                                <input type="checkbox" name="<?php echo esc_attr($this->option_name); ?>[enabled_post_types][]" value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, (array) $settings['enabled_post_types'], true)); ?>>
                                                <span><?php echo esc_html($label); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">O que duplicar</th>
                                <td>
                                    <div class="mldpp-check-grid">
                                        <label><input type="checkbox" name="<?php echo esc_attr($this->option_name); ?>[duplicate_featured_image]" value="1" <?php checked(!empty($settings['duplicate_featured_image'])); ?>> <span>Imagem destacada</span></label>
                                        <label><input type="checkbox" name="<?php echo esc_attr($this->option_name); ?>[duplicate_taxonomies]" value="1" <?php checked(!empty($settings['duplicate_taxonomies'])); ?>> <span>Taxonomias</span></label>
                                        <label><input type="checkbox" name="<?php echo esc_attr($this->option_name); ?>[duplicate_meta]" value="1" <?php checked(!empty($settings['duplicate_meta'])); ?>> <span>Metadados</span></label>
                                        <label><input type="checkbox" name="<?php echo esc_attr($this->option_name); ?>[duplicate_comments]" value="1" <?php checked(!empty($settings['duplicate_comments'])); ?>> <span>Comentários</span></label>
                                        <label><input type="checkbox" name="<?php echo esc_attr($this->option_name); ?>[duplicate_menu_order]" value="1" <?php checked(!empty($settings['duplicate_menu_order'])); ?>> <span>Ordem de menu</span></label>
                                        <label><input type="checkbox" name="<?php echo esc_attr($this->option_name); ?>[duplicate_template]" value="1" <?php checked(!empty($settings['duplicate_template'])); ?>> <span>Template da página</span></label>
                                        <label><input type="checkbox" name="<?php echo esc_attr($this->option_name); ?>[duplicate_author]" value="1" <?php checked(!empty($settings['duplicate_author'])); ?>> <span>Autor original</span></label>
                                    </div>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row"><label for="mldpp-copy-status-mode">Status da nova cópia</label></th>
                                <td>
                                    <select id="mldpp-copy-status-mode" name="<?php echo esc_attr($this->option_name); ?>[copy_status_mode]">
                                        <option value="draft" <?php selected($settings['copy_status_mode'], 'draft'); ?>>Sempre como rascunho</option>
                                        <option value="same" <?php selected($settings['copy_status_mode'], 'same'); ?>>Manter status original</option>
                                    </select>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">Título e slug da cópia</th>
                                <td>
                                    <p class="description">O título original é preservado automaticamente. O slug da cópia recebe versionamento inteligente baseado no slug original.</p>
                                    <p class="description">Detecção do último bloco numérico: <code>samba-2-guimaraes-212</code> → <code>samba-2-guimaraes-213</code>, <code>pagina-15-historia</code> → <code>pagina-16-historia</code>, <code>post-007</code> → <code>post-008</code>. Quando não há número, numeração progressiva: <code>minha-pagina</code> → <code>minha-pagina-2</code>, <code>minha-pagina-3</code>.</p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row"><label for="mldpp-slug-prefix">Prefixo do slug (opcional)</label></th>
                                <td>
                                    <input type="text" id="mldpp-slug-prefix" class="regular-text" name="<?php echo esc_attr($this->option_name); ?>[slug_prefix]" value="<?php echo esc_attr($settings['slug_prefix']); ?>" placeholder="ex: copy-of">
                                    <p class="description">Texto fixo adicionado antes do slug versionado. Use letras, números, hífens ou underscore. Ex.: <code>copy-of</code> + <code>minha-pagina-2</code> = <code>copy-of-minha-pagina-2</code>.</p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row"><label for="mldpp-slug-suffix">Sufixo do slug (opcional)</label></th>
                                <td>
                                    <input type="text" id="mldpp-slug-suffix" class="regular-text" name="<?php echo esc_attr($this->option_name); ?>[slug_suffix]" value="<?php echo esc_attr($settings['slug_suffix']); ?>" placeholder="ex: -copy">
                                    <p class="description">Texto fixo adicionado depois do slug versionado. Ex.: <code>minha-pagina-2</code> + <code>-copy</code> = <code>minha-pagina-2-copy</code>.</p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row"><label for="mldpp-numeric-mode">Modo de incremento numérico</label></th>
                                <td>
                                    <select id="mldpp-numeric-mode" name="<?php echo esc_attr($this->option_name); ?>[numeric_increment_mode]">
                                        <option value="last_numeric" <?php selected($settings['numeric_increment_mode'], 'last_numeric'); ?>>Incrementar último número (recomendado)</option>
                                        <option value="append_suffix" <?php selected($settings['numeric_increment_mode'], 'append_suffix'); ?>>Sempre sufixo -2, -3…</option>
                                    </select>
                                    <p class="description">"Incrementar último número" preserva contexto (ex.: <code>pagina-15-historia</code> → <code>pagina-16-historia</code>). "Sempre sufixo" usa <code>-2</code> no fim da slug original.</p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">Perfis autorizados</th>
                                <td>
                                    <div class="mldpp-check-grid">
                                        <?php
                                        global $wp_roles;
                                        $roles = !empty($wp_roles->roles) ? $wp_roles->roles : array();
                                        foreach ($roles as $role_key => $role_data) :
                                        ?>
                                            <label>
                                                <input type="checkbox" name="<?php echo esc_attr($this->option_name); ?>[roles_allowed][]" value="<?php echo esc_attr($role_key); ?>" <?php checked(in_array($role_key, (array) $settings['roles_allowed'], true)); ?>>
                                                <span><?php echo esc_html($role_data['name']); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row"><label for="mldpp-log-limit">Máximo de logs salvos</label></th>
                                <td><input type="number" min="10" max="500" id="mldpp-log-limit" name="<?php echo esc_attr($this->option_name); ?>[log_limit]" value="<?php echo esc_attr($settings['log_limit']); ?>"></td>
                            </tr>
                        </table>

                        <?php submit_button('Salvar configurações'); ?>
                    </form>
                </div>

                <div class="mldpp-card">
                    <h2>Duplicação em lote</h2>
                    <form method="post">
                        <?php wp_nonce_field('mldpp_manual_bulk_action', 'mldpp_manual_bulk_nonce'); ?>

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="mldpp-bulk-post-type">Tipo de conteúdo</label></th>
                                <td>
                                    <select id="mldpp-bulk-post-type" name="mldpp_bulk_post_type">
                                        <?php foreach ($post_types as $slug => $label) : ?>
                                            <?php if (!$this->is_post_type_enabled($slug)) { continue; } ?>
                                            <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="mldpp-bulk-limit">Quantidade</label></th>
                                <td><input type="number" min="1" max="100" id="mldpp-bulk-limit" name="mldpp_bulk_limit" value="10"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="mldpp-bulk-filter-status">Filtrar por status</label></th>
                                <td>
                                    <select id="mldpp-bulk-filter-status" name="mldpp_bulk_filter_status">
                                        <option value="any">Todos</option>
                                        <option value="publish">Publicado</option>
                                        <option value="draft">Rascunho</option>
                                        <option value="pending">Pendente</option>
                                        <option value="future">Agendado</option>
                                        <option value="private">Privado</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="mldpp-bulk-search">Buscar no título/conteúdo</label></th>
                                <td><input type="text" id="mldpp-bulk-search" class="regular-text" name="mldpp_bulk_search" value=""></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="mldpp-bulk-status-override">Forçar status nesta operação</label></th>
                                <td>
                                    <select id="mldpp-bulk-status-override" name="mldpp_bulk_status_override">
                                        <option value="">Usar configuração global</option>
                                        <option value="draft">Rascunho</option>
                                        <option value="publish">Publicado</option>
                                        <option value="pending">Pendente</option>
                                        <option value="future">Agendado</option>
                                        <option value="private">Privado</option>
                                    </select>
                                </td>
                            </tr>
                        </table>

                        <p><button type="submit" name="mldpp_manual_bulk_submit" class="button button-primary">Executar duplicação em lote</button></p>
                    </form>
                </div>
            </div>

            <div class="mldpp-grid mldpp-grid--bottom">
                <div class="mldpp-card">
                    <h2>Como usar</h2>
                    <ol class="mldpp-list">
                        <li>Habilite os tipos de conteúdo desejados.</li>
                        <li>Defina o que deve ser copiado na duplicação.</li>
                        <li>Na listagem do WordPress, use a ação rápida <strong>Duplicar</strong> ou a ação em massa.</li>
                        <li>No editor do conteúdo, use o botão <strong>Duplicar este conteúdo</strong>.</li>
                        <li>Consulte os logs para auditar quem duplicou e quando.</li>
                    </ol>
                </div>

                <div class="mldpp-card">
                    <h2>Logs recentes</h2>
                    <?php if (empty($logs)) : ?>
                        <p>Nenhuma duplicação registrada ainda.</p>
                    <?php else : ?>
                        <div class="mldpp-log-table-wrap">
                            <table class="widefat striped">
                                <thead>
                                    <tr>
                                        <th>Data/Hora</th>
                                        <th>Tipo</th>
                                        <th>Origem</th>
                                        <th>Nova cópia</th>
                                        <th>Slug gerado</th>
                                        <th>Status</th>
                                        <th>Usuário</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($logs as $log) : ?>
                                        <tr>
                                            <td><?php echo esc_html($log['time']); ?></td>
                                            <td><?php echo esc_html($log['post_type']); ?></td>
                                            <td><a href="<?php echo esc_url(get_edit_post_link($log['source_id'])); ?>">#<?php echo esc_html($log['source_id']); ?></a></td>
                                            <td><a href="<?php echo esc_url(get_edit_post_link($log['new_id'])); ?>">#<?php echo esc_html($log['new_id']); ?></a></td>
                                            <td><code class="mldpp-slug-cell"><?php echo esc_html(!empty($log['new_slug']) ? $log['new_slug'] : '-'); ?></code></td>
                                            <td><?php echo esc_html($log['new_status']); ?></td>
                                            <td><?php echo esc_html($log['user_name']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }
}
