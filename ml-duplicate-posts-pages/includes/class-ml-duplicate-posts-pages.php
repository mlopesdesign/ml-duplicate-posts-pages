<?php
namespace MLDPP;

if (!defined('ABSPATH')) {
    exit;
}

class Plugin {
    /** Limite de tentativas ao procurar um slug livre (evita loop infinito). */
    const SLUG_MAX_ATTEMPTS = 1000;

    /** Profundidade maxima da arvore de descendentes duplicados. */
    const MAX_CHILD_DEPTH = 5;

    /** Teto de filhos processados por nivel, para nao travar o request. */
    const MAX_CHILDREN_PER_LEVEL = 200;

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
        add_action('admin_init', array($this, 'force_check_for_update'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('enqueue_block_editor_assets', array($this, 'enqueue_block_editor_assets'));
        add_action('wp_ajax_mldpp_preview_slug', array($this, 'ajax_preview_slug'));

        add_filter('post_row_actions', array($this, 'add_row_action'), 10, 2);
        add_filter('page_row_actions', array($this, 'add_row_action'), 10, 2);
        add_action('admin_init', array($this, 'register_bulk_actions'), 5);

        add_action('admin_bar_menu', array($this, 'add_admin_bar_button'), 90);
        add_action('admin_bar_menu', array($this, 'add_admin_bar_updater_node'), 95);
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
            'content' => '<p>' . esc_html__('O titulo original e preservado. A base do versionamento e SEMPRE o slug atual do conteudo que voce escolheu duplicar:', 'ml-duplicate-posts-pages') . '</p>'
                . '<ul>'
                . '<li><code>samba-2-guimaraes-215</code> &rarr; <code>samba-2-guimaraes-216</code></li>'
                . '<li><code>pagina-15-historia</code> &rarr; <code>pagina-16-historia</code></li>'
                . '<li><code>foo-2-bar-7-baz</code> &rarr; <code>foo-2-bar-8-baz</code></li>'
                . '<li><code>post-007</code> &rarr; <code>post-008</code> (preserva zero a esquerda)</li>'
                . '<li><code>minha-pagina</code> &rarr; <code>minha-pagina-2</code></li>'
                . '</ul>'
                . '<p>' . esc_html__('Duplicar a copia continua a sequencia: pagina-205 gera pagina-206, e duplicar a pagina-206 gera pagina-207. Se voce renomear o slug manualmente, a proxima copia parte do nome novo.', 'ml-duplicate-posts-pages') . '</p>'
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

        // Carrega apenas onde o plugin realmente atua: painel proprio, listagens e editor.
        $allowed_hooks = array('edit.php', 'post.php', 'post-new.php');

        if (!$is_plugin_screen && !in_array($hook, $allowed_hooks, true)) {
            return;
        }

        if (!$is_plugin_screen) {
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            $post_type = ($screen && !empty($screen->post_type)) ? $screen->post_type : '';

            if ($post_type === '' || !$this->is_post_type_enabled($post_type)) {
                return;
            }
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

    /**
     * Painel no editor de blocos.
     *
     * O botao do editor classico usa `post_submitbox_misc_actions`, hook que nao
     * dispara no Gutenberg. Sem este metodo o plugin fica invisivel dentro do
     * editor em qualquer site moderno.
     */
    public function enqueue_block_editor_assets() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if (!$screen || $screen->base !== 'post') {
            return;
        }

        $post = get_post();

        if (!$post instanceof \WP_Post || empty($post->ID)) {
            return;
        }

        if (!$this->current_user_can_duplicate() || !$this->is_post_type_enabled($post->post_type)) {
            return;
        }

        if (!current_user_can('edit_post', $post->ID) || get_post_status($post->ID) === 'auto-draft') {
            return;
        }

        wp_enqueue_script(
            'mldpp-editor',
            MLDPP_URL . 'assets/js/editor.js',
            array('wp-plugins', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n'),
            MLDPP_VERSION,
            true
        );

        wp_enqueue_style(
            'mldpp-admin',
            MLDPP_URL . 'assets/css/admin.css',
            array(),
            MLDPP_VERSION
        );

        $duplicate_url = wp_nonce_url(
            admin_url('admin.php?action=mldpp_duplicate_post&post=' . absint($post->ID)),
            'mldpp_duplicate_post_' . absint($post->ID)
        );

        wp_localize_script('mldpp-editor', 'mldppEditor', array(
            'postId'         => (int) $post->ID,
            'duplicateUrl'   => $duplicate_url,
            'ajaxUrl'        => admin_url('admin-ajax.php'),
            'previewNonce'   => wp_create_nonce('mldpp_preview_slug'),
            'previewAction'  => 'mldpp_preview_slug',
            'unsavedWarning' => true,
            'i18n'           => array(
                'panelTitle'     => __('ML Duplicate', 'ml-duplicate-posts-pages'),
                'buttonLabel'    => __('Duplicar este conteúdo', 'ml-duplicate-posts-pages'),
                'previewTitle'   => __('Slug previsto:', 'ml-duplicate-posts-pages'),
                'previewError'   => __('Não foi possível calcular o slug.', 'ml-duplicate-posts-pages'),
                'loading'        => __('Calculando o slug…', 'ml-duplicate-posts-pages'),
                'unsavedWarning' => __('A cópia parte da última versão salva. Salve antes de duplicar para incluir alterações recentes.', 'ml-duplicate-posts-pages'),
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
            'duplicate_children'       => 1,
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
            'duplicate_children',
        );

        foreach ($checkboxes as $key) {
            $output[$key] = !empty($input[$key]) ? 1 : 0;
        }

        $allowed_modes = array('draft', 'same');
        $output['copy_status_mode'] = (isset($input['copy_status_mode']) && in_array($input['copy_status_mode'], $allowed_modes, true))
            ? $input['copy_status_mode']
            : $defaults['copy_status_mode'];

        $output['title_prefix'] = isset($input['title_prefix']) ? sanitize_text_field(wp_unslash($input['title_prefix'])) : '';
        $output['title_suffix'] = isset($input['title_suffix']) ? sanitize_text_field(wp_unslash($input['title_suffix'])) : '';

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

    /**
     * Registra a acao em massa para TODOS os post types habilitados.
     * Ate a 1.2.2 apenas 'post' e 'page' eram cobertos, deixando CPTs sem acao em massa.
     */
    public function register_bulk_actions() {
        $settings = $this->get_settings();

        foreach ((array) $settings['enabled_post_types'] as $post_type) {
            $post_type = sanitize_key($post_type);
            if ($post_type === '') {
                continue;
            }

            add_filter('bulk_actions-edit-' . $post_type, array($this, 'register_bulk_action'));
            add_filter('handle_bulk_actions-edit-' . $post_type, array($this, 'handle_native_bulk_action_redirect'), 10, 3);
        }
    }

    public function register_bulk_action($bulk_actions) {
        if ($this->current_user_can_duplicate()) {
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

    public function add_admin_bar_updater_node($wp_admin_bar) {
        if (!is_user_logged_in() || !current_user_can('update_plugins')) {
            return;
        }

        $force_url = wp_nonce_url(
            admin_url('admin.php?action=mldpp_force_check'),
            'mldpp_force_check'
        );

        $debug_url = add_query_arg('mldpp_debug', '1', admin_url('admin.php?page=mldpp-dashboard'));

        $wp_admin_bar->add_node(array(
            'id'    => 'mldpp-updater',
            'title' => '<span class="ab-icon dashicons-update" aria-hidden="true"></span><span class="ab-label">' . esc_html__('ML Duplicate', 'ml-duplicate-posts-pages') . '</span>',
            'href'  => $force_url,
            'meta'  => array(
                'title' => esc_attr__('ML Duplicate - forcar verificacao de atualizacao', 'ml-duplicate-posts-pages'),
                'class' => 'mldpp-admin-bar-updater',
            ),
        ));

        $wp_admin_bar->add_node(array(
            'id'     => 'mldpp-updater-check',
            'parent' => 'mldpp-updater',
            'title'  => esc_html__('Verificar atualizacao agora', 'ml-duplicate-posts-pages'),
            'href'   => $force_url,
        ));

        $wp_admin_bar->add_node(array(
            'id'     => 'mldpp-updater-debug',
            'parent' => 'mldpp-updater',
            'title'  => esc_html__('Diagnostico do updater', 'ml-duplicate-posts-pages'),
            'href'   => $debug_url,
        ));
    }

    public function force_check_for_update() {
        if (!is_admin() || empty($_GET['action']) || $_GET['action'] !== 'mldpp_force_check') {
            return;
        }

        if (!current_user_can('update_plugins')) {
            wp_die(esc_html__('Voce nao tem permissao para verificar atualizacoes.', 'ml-duplicate-posts-pages'));
        }

        check_admin_referer('mldpp_force_check');

        delete_transient('mldpp_github_release');
        delete_site_transient('update_plugins');

        if (function_exists('wp_update_plugins')) {
            wp_update_plugins();
        }

        $update_plugins = get_site_transient('update_plugins');
        $has_update     = is_object($update_plugins) && !empty($update_plugins->response[MLDPP_BASENAME]);

        $redirect = wp_get_referer();
        if (!$redirect) {
            $redirect = admin_url('plugins.php');
        }

        $redirect = add_query_arg(array(
            'mldpp_force_checked' => 1,
            'mldpp_has_update'    => $has_update ? 1 : 0,
        ), $redirect);

        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Resolve o conteudo em contexto tanto no front (is_singular) quanto no wp-admin
     * (post.php / post-new.php). Ate a 1.2.2 o guard exigia is_singular() DENTRO do
     * wp-admin, condicao sempre falsa, o que deixava o botao permanentemente invisivel.
     */
    private function get_contextual_post() {
        if (is_admin()) {
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            if (!$screen || $screen->base !== 'post') {
                return null;
            }

            $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
            if (!$post_id && isset($GLOBALS['post']) && !empty($GLOBALS['post']->ID)) {
                $post_id = absint($GLOBALS['post']->ID);
            }

            return $post_id ? get_post($post_id) : null;
        }

        if (!is_singular()) {
            return null;
        }

        return get_queried_object();
    }

    public function add_admin_bar_button($wp_admin_bar) {
        $post = $this->get_contextual_post();

        if (!$post instanceof \WP_Post || !$this->current_user_can_duplicate() || !$this->is_post_type_enabled($post->post_type)) {
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
        if (!$post instanceof \WP_Post || !$this->current_user_can_duplicate() || !$this->is_post_type_enabled($post->post_type)) {
            return;
        }

        if (empty($post->ID) || get_post_status($post->ID) === 'auto-draft') {
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

        if (!empty($_GET['mldpp_force_checked'])) {
            $has_update = !empty($_GET['mldpp_has_update']);
            $type       = $has_update ? 'success' : 'info';
            $version    = MLDPP_VERSION;

            if ($has_update) {
                $update_plugins = get_site_transient('update_plugins');
                $new_version    = '';
                if (is_object($update_plugins) && !empty($update_plugins->response[MLDPP_BASENAME]->new_version)) {
                    $new_version = $update_plugins->response[MLDPP_BASENAME]->new_version;
                }
                $message = sprintf(
                    /* translators: 1: current version, 2: new version */
                    esc_html__('Atualizacao disponivel para ML Duplicate: %1$s -> %2$s. Acesse a pagina de atualizacoes para instalar.', 'ml-duplicate-posts-pages'),
                    $version,
                    $new_version ?: '?'
                );
            } else {
                $message = sprintf(
                    /* translators: %s: current version */
                    esc_html__('Rechecagem concluida. ML Duplicate %s ja esta na versao mais recente.', 'ml-duplicate-posts-pages'),
                    $version
                );
            }

            echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . $message . '</p></div>';
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

        $prefix_token = isset($settings['slug_prefix']) ? $this->sanitize_slug_token($settings['slug_prefix']) : '';
        $suffix_token = isset($settings['slug_suffix']) ? $this->sanitize_slug_token($settings['slug_suffix']) : '';

        // Evita acumulo de tokens ao duplicar uma copia que ja recebeu prefixo/sufixo.
        $base = $this->strip_slug_tokens($base, $prefix_token, $suffix_token);

        if ($base === '') {
            $base = 'conteudo-' . absint($post->ID);
        }

        $mode = !empty($settings['numeric_increment_mode']) ? $settings['numeric_increment_mode'] : 'last_numeric';
        $candidate = '';

        if ($mode === 'last_numeric') {
            $candidate = $this->increment_last_numeric_token($base, $post, $prefix_token, $suffix_token);
        }

        if ($candidate === '') {
            $candidate = $this->build_with_progressive_number($base, $post, $prefix_token, $suffix_token);
        }

        return $candidate;
    }

    /**
     * Remove prefixo/sufixo configurados do inicio/fim da base, para que o versionamento
     * atue sobre o nucleo do slug e nao sobre o token fixo.
     */
    private function strip_slug_tokens($base, $prefix_token, $suffix_token) {
        if ($prefix_token !== '') {
            $needle = sanitize_title($prefix_token) . '-';
            if ($needle !== '-' && strpos($base, $needle) === 0) {
                $base = substr($base, strlen($needle));
            }
        }

        if ($suffix_token !== '') {
            $needle = '-' . sanitize_title($suffix_token);
            if ($needle !== '-' && $needle !== '' && substr($base, -strlen($needle)) === $needle) {
                $base = substr($base, 0, -strlen($needle));
            }
        }

        return trim((string) $base, '-');
    }

    /**
     * Compoe o slug final aplicando prefixo/sufixo sobre o nucleo ja versionado.
     */
    private function compose_slug($core, $prefix_token, $suffix_token) {
        $parts = array();

        if ($prefix_token !== '') {
            $parts[] = $prefix_token;
        }

        $parts[] = $core;

        if ($suffix_token !== '') {
            $parts[] = $suffix_token;
        }

        $composed = sanitize_title(implode('-', $parts));

        return ($composed !== '') ? $composed : sanitize_title($core);
    }

    /**
     * Incrementa o ultimo bloco numerico do slug preservando o contexto e os zeros a esquerda.
     * "samba-2-guimaraes-215" -> "samba-2-guimaraes-216"
     * "post-007"              -> "post-008"
     * "205"                   -> "206"
     */
    private function increment_last_numeric_token($base, $post, $prefix_token = '', $suffix_token = '') {
        $tokens = explode('-', $base);

        for ($i = count($tokens) - 1; $i >= 0; $i--) {
            if ($tokens[$i] === '' || !ctype_digit($tokens[$i])) {
                continue;
            }

            $width = strlen($tokens[$i]);
            $next  = (int) $tokens[$i];
            $guard = 0;

            do {
                $next++;
                $guard++;
                $tokens[$i] = str_pad((string) $next, $width, '0', STR_PAD_LEFT);
                $candidate  = $this->compose_slug(implode('-', $tokens), $prefix_token, $suffix_token);
            } while ($this->duplicate_slug_exists($candidate, $post) && $guard < self::SLUG_MAX_ATTEMPTS);

            if ($this->duplicate_slug_exists($candidate, $post)) {
                return '';
            }

            return $candidate;
        }

        return '';
    }

    private function build_with_progressive_number($base, $post, $prefix_token = '', $suffix_token = '') {
        $index     = 2;
        $candidate = $this->compose_slug($base . '-' . $index, $prefix_token, $suffix_token);
        $guard     = 0;

        while ($this->duplicate_slug_exists($candidate, $post) && $guard < self::SLUG_MAX_ATTEMPTS) {
            $index++;
            $guard++;
            $candidate = $this->compose_slug($base . '-' . $index, $prefix_token, $suffix_token);
        }

        if ($this->duplicate_slug_exists($candidate, $post)) {
            $candidate = $this->compose_slug($base . '-' . $index . '-' . wp_generate_password(6, false, false), $prefix_token, $suffix_token);
        }

        return $candidate;
    }

    private function sanitize_slug_token($token) {
        $cleaned = strtolower(trim((string) $token));
        $cleaned = preg_replace('/[^a-z0-9\-_]/', '', $cleaned);
        $cleaned = trim($cleaned, '-_');
        return $cleaned;
    }

    /**
     * Base de versionamento do slug.
     *
     * Regra canonica (v1.3.0): a base e SEMPRE o slug atual do conteudo escolhido.
     * Escolheu "pagina-205" -> gera "pagina-206". Escolheu a copia "pagina-206" -> gera "pagina-207".
     *
     * Ate a 1.2.2 a base vinha do meta _mldpp_slug_base (congelado no post raiz), o que ignorava
     * o slug realmente selecionado e o slug editado manualmente. O meta agora e apenas fallback
     * de auditoria para conteudos sem post_name (rascunhos nunca salvos).
     */
    private function get_duplicate_slug_base($post) {
        if (!empty($post->post_name)) {
            return sanitize_title($post->post_name);
        }

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

        $sql = "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = %s AND ID <> %d AND post_status NOT IN ('trash', 'auto-draft')" . $where_parent . ' LIMIT 1';

        return (bool) $wpdb->get_var($wpdb->prepare($sql, $params));
    }

    private function duplicate_post($post_id, $args = array()) {
        $post = get_post($post_id);

        if (!$post || empty($post->ID)) {
            return new \WP_Error('mldpp_invalid_post', __('Conteúdo original não encontrado.', 'ml-duplicate-posts-pages'));
        }

        $settings = $this->get_settings();
        $override = wp_parse_args($args, array(
            'copy_status_mode'  => null,
            'force_post_status' => null,
            'post_parent'       => null,
            'is_child'          => false,
            'depth'             => 0,
        ));

        // Filhos (paginas subordinadas, variacoes de produto) nao precisam estar
        // habilitados nas configuracoes: eles acompanham o pai por definicao.
        if (empty($override['is_child']) && !$this->is_post_type_enabled($post->post_type)) {
            return new \WP_Error('mldpp_post_type_disabled', __('Este tipo de conteúdo não está habilitado para duplicação.', 'ml-duplicate-posts-pages'));
        }

        if (!current_user_can('edit_post', $post->ID)) {
            return new \WP_Error('mldpp_no_cap', __('Sem permissão para duplicar este conteúdo.', 'ml-duplicate-posts-pages'));
        }

        $target_status = 'draft';
        if (!empty($override['force_post_status'])) {
            $target_status = $override['force_post_status'];
        } elseif (($override['copy_status_mode'] ?: $settings['copy_status_mode']) === 'same') {
            $target_status = $post->post_status;
        }

        // Variacoes de produto precisam manter o proprio status, senao o produto
        // duplicado nasce sem variacoes utilizaveis.
        if ($post->post_type === 'product_variation') {
            $target_status = $post->post_status;
        }

        $new_title = $this->apply_title_tokens($post->post_title, $settings, $override);
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
            'post_parent'           => ($override['post_parent'] !== null) ? absint($override['post_parent']) : $post->post_parent,
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

        // Uma variacao de produto sem metadados nao tem atributo, preco nem estoque:
        // seria um registro inutil. Por isso os metadados dela sao sempre copiados,
        // independentemente da opcao global.
        $must_copy_meta = ($post->post_type === 'product_variation');

        if (!empty($settings['duplicate_meta']) || $must_copy_meta) {
            $meta = get_post_meta($post->ID);
            $skip_keys = array(
                '_edit_lock',
                '_edit_last',
                '_wp_old_slug',
                '_wp_trash_meta_status',
                '_wp_trash_meta_time',
                '_mldpp_source_post',
                '_mldpp_slug_base',
                // CSS gerado pelo Elementor fica em cache por ID do post: copiar
                // faria a copia herdar o estilo do original. Removido para regerar.
                '_elementor_css',
            );

            foreach ($meta as $meta_key => $values) {
                if (in_array($meta_key, $skip_keys, true)) {
                    continue;
                }

                foreach ((array) $values as $value) {
                    // wp_slash e obrigatorio: add_post_meta faz unslash internamente e,
                    // sem isso, barras invertidas legitimas do valor original sao perdidas.
                    add_post_meta($new_post_id, $meta_key, wp_slash(maybe_unserialize($value)));
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

        $this->ensure_unique_sku($new_post_id);

        $children_created = 0;
        if (!empty($settings['duplicate_children'])) {
            $children_created = $this->duplicate_children($post, $new_post_id, $override, $target_status);
        }

        $this->sync_woocommerce_product($new_post_id, $post->post_type);

        if (empty($override['is_child'])) {
            $this->write_log($post->ID, $new_post_id, $post->post_type, $target_status, $new_slug, $children_created);
        }

        do_action('mldpp_after_duplicate_post', $new_post_id, $post->ID, $post);

        return $new_post_id;
    }

    /**
     * Duplica os filhos diretos e, recursivamente, os netos.
     *
     * Cobre paginas subordinadas (post types hierarquicos) e variacoes de produto
     * WooCommerce, que sao posts `product_variation` filhos do `product`. Sem isso
     * um produto variavel duplicado nasce sem nenhuma variacao utilizavel.
     *
     * Anexos ficam de fora de proposito: a midia e compartilhada entre original e
     * copia, duplicar criaria entradas redundantes na biblioteca.
     *
     * @return int Total de descendentes criados.
     */
    private function duplicate_children($source_post, $new_parent_id, $override, $target_status) {
        $depth = isset($override['depth']) ? absint($override['depth']) : 0;

        if ($depth >= self::MAX_CHILD_DEPTH) {
            return 0;
        }

        global $wpdb;

        $child_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_parent = %d
               AND post_status NOT IN ('trash', 'auto-draft')
               AND post_type NOT IN ('attachment', 'revision', 'nav_menu_item')
             ORDER BY menu_order ASC, ID ASC
             LIMIT %d",
            absint($source_post->ID),
            self::MAX_CHILDREN_PER_LEVEL
        ));

        if (empty($child_ids)) {
            return 0;
        }

        $created = 0;

        foreach ($child_ids as $child_id) {
            $child_id = absint($child_id);

            if ($child_id === absint($source_post->ID)) {
                continue;
            }

            $new_child_id = $this->duplicate_post($child_id, array(
                'post_parent'       => $new_parent_id,
                'is_child'          => true,
                'depth'             => $depth + 1,
                'force_post_status' => $target_status,
            ));

            if (!is_wp_error($new_child_id) && $new_child_id) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * WooCommerce exige SKU unico. Sem tratar, a copia herda o SKU do original e o
     * produto duplicado fica invalido no painel da loja.
     */
    private function ensure_unique_sku($post_id) {
        $sku = get_post_meta($post_id, '_sku', true);

        if ($sku === '' || $sku === false || $sku === null) {
            return;
        }

        $base  = (string) $sku;
        $index = 1;
        $candidate = $base . '-' . $index;

        while ($this->sku_exists($candidate, $post_id) && $index < self::SLUG_MAX_ATTEMPTS) {
            $index++;
            $candidate = $base . '-' . $index;
        }

        update_post_meta($post_id, '_sku', $candidate);
    }

    private function sku_exists($sku, $exclude_id) {
        global $wpdb;

        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_sku' AND meta_value = %s AND post_id <> %d
             LIMIT 1",
            $sku,
            absint($exclude_id)
        ));

        return (bool) $found;
    }

    /**
     * Limpa caches e ressincroniza o produto duplicado para que preco, estoque e
     * lista de variacoes reflitam a copia, e nao o original.
     */
    private function sync_woocommerce_product($post_id, $post_type) {
        if ($post_type !== 'product') {
            return;
        }

        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($post_id);
        }

        if (class_exists('\WC_Product_Variable')) {
            \WC_Product_Variable::sync($post_id);
        }
    }

    /**
     * Aplica prefixo/sufixo configurados ao titulo da copia.
     * Ate a 1.3.0 os campos existiam nos defaults mas eram forcados a string vazia
     * pelo sanitize, sem interface: eram codigo morto.
     */
    private function apply_title_tokens($title, $settings, $override) {
        if (!empty($override['is_child'])) {
            return $title;
        }

        $prefix = isset($settings['title_prefix']) ? trim((string) $settings['title_prefix']) : '';
        $suffix = isset($settings['title_suffix']) ? trim((string) $settings['title_suffix']) : '';

        if ($prefix === '' && $suffix === '') {
            return $title;
        }

        $composed = trim($prefix . ' ' . $title . ' ' . $suffix);

        return ($composed !== '') ? $composed : $title;
    }

    private function write_log($source_id, $new_id, $post_type, $new_status, $new_slug = '', $children = 0) {
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
            'children'   => absint($children),
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

        $force_check_url = wp_nonce_url(admin_url('admin.php?action=mldpp_force_check'), 'mldpp_force_check');
        $is_debug        = !empty($_GET['mldpp_debug']);
        $current_tab     = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'dashboard';
        $base_url        = admin_url('admin.php?page=mldpp-dashboard');

        $tabs = array(
            'dashboard'     => __('Dashboard', 'ml-duplicate-posts-pages'),
            'configuracoes' => __('Configurações', 'ml-duplicate-posts-pages'),
            'lote'          => __('Duplicação em lote', 'ml-duplicate-posts-pages'),
            'logs'          => __('Logs', 'ml-duplicate-posts-pages'),
        );

        if (!array_key_exists($current_tab, $tabs)) {
            $current_tab = 'dashboard';
        }

        $total_logs = count($logs);
        $enabled_types = count((array) $settings['enabled_post_types']);

        ?>
        <div class="wrap mldpp-admin-wrap">
            <div class="mldpp-hero">
                <div class="mldpp-hero__left">
                    <span class="mldpp-badge">ML Lopes Design</span>
                    <div class="mldpp-eyebrow"><?php esc_html_e('Painel profissional', 'ml-duplicate-posts-pages'); ?></div>
                    <h1>ML Duplicate Posts &amp; Pages <span class="mldpp-version">v<?php echo esc_html(MLDPP_VERSION); ?></span></h1>
                    <p>Duplicação profissional de conteúdos do WordPress com controle do que copiar, compatibilidade com posts, páginas e CPTs, ação em massa e registro de atividades.</p>
                </div>
                <div class="mldpp-hero__right">
                    <a class="button button-secondary mldpp-force-check" href="<?php echo esc_url($force_check_url); ?>">
                        <span class="dashicons dashicons-update" aria-hidden="true"></span>
                        <?php esc_html_e('Verificar atualizacao', 'ml-duplicate-posts-pages'); ?>
                    </a>
                    <a class="button button-primary" href="<?php echo esc_url(admin_url('edit.php')); ?>"><?php esc_html_e('Abrir listagem de conteúdos', 'ml-duplicate-posts-pages'); ?></a>
                </div>
            </div>

            <nav class="mldpp-tabs">
                <?php foreach ($tabs as $tab_key => $tab_label) : ?>
                    <a class="mldpp-tab<?php echo ($current_tab === $tab_key) ? ' is-active' : ''; ?>"
                       href="<?php echo esc_url(add_query_arg('tab', $tab_key, $base_url)); ?>"><?php echo esc_html($tab_label); ?></a>
                <?php endforeach; ?>
            </nav>

            <?php if ($current_tab === 'dashboard') : ?>
            <div class="mldpp-grid">
                <div class="mldpp-card">
                    <h2><?php esc_html_e('Resumo operacional', 'ml-duplicate-posts-pages'); ?></h2>
                    <div class="mldpp-kpi-grid">
                        <div class="mldpp-kpi">
                            <strong><?php echo esc_html($enabled_types); ?></strong>
                            <span><?php esc_html_e('Tipos habilitados', 'ml-duplicate-posts-pages'); ?></span>
                        </div>
                        <div class="mldpp-kpi">
                            <strong><?php echo esc_html($total_logs); ?></strong>
                            <span><?php esc_html_e('Duplicações registradas', 'ml-duplicate-posts-pages'); ?></span>
                        </div>
                    </div>
                    <div class="mldpp-note"><?php esc_html_e('O slug da cópia é versionado a partir do slug do conteúdo escolhido. O título é preservado, salvo se você definir prefixo ou sufixo.', 'ml-duplicate-posts-pages'); ?></div>
                </div>

                <div class="mldpp-card">
                    <h2><?php esc_html_e('Fluxo recomendado', 'ml-duplicate-posts-pages'); ?></h2>
                    <ol class="mldpp-list">
                        <li><?php esc_html_e('Habilite os tipos de conteúdo em Configurações.', 'ml-duplicate-posts-pages'); ?></li>
                        <li><?php esc_html_e('Defina o que deve ser copiado na duplicação.', 'ml-duplicate-posts-pages'); ?></li>
                        <li><?php esc_html_e('Use a ação rápida "Duplicar" na listagem do WordPress.', 'ml-duplicate-posts-pages'); ?></li>
                        <li><?php esc_html_e('No editor, use o painel ML Duplicate na barra lateral.', 'ml-duplicate-posts-pages'); ?></li>
                        <li><?php esc_html_e('Audite quem duplicou e quando na aba Logs.', 'ml-duplicate-posts-pages'); ?></li>
                    </ol>
                </div>

                <div class="mldpp-card">
                    <h2><?php esc_html_e('Atalhos rápidos', 'ml-duplicate-posts-pages'); ?></h2>
                    <p><a class="button button-primary" href="<?php echo esc_url(add_query_arg('tab', 'configuracoes', $base_url)); ?>"><?php esc_html_e('Abrir configurações', 'ml-duplicate-posts-pages'); ?></a></p>
                    <p><a class="button button-secondary" href="<?php echo esc_url(add_query_arg('tab', 'lote', $base_url)); ?>"><?php esc_html_e('Duplicar em lote', 'ml-duplicate-posts-pages'); ?></a></p>
                    <p><a class="button button-secondary" href="<?php echo esc_url(add_query_arg('tab', 'logs', $base_url)); ?>"><?php esc_html_e('Ver logs', 'ml-duplicate-posts-pages'); ?></a></p>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($current_tab === 'configuracoes') : ?>
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
                                        <label><input type="checkbox" name="<?php echo esc_attr($this->option_name); ?>[duplicate_children]" value="1" <?php checked(!empty($settings['duplicate_children'])); ?>> <span>Conteúdos filhos</span></label>
                                    </div>
                                    <p class="description">"Conteúdos filhos" duplica a árvore subordinada junto com o conteúdo escolhido: páginas filhas de uma página e variações de um produto WooCommerce. Sem isso, um produto variável duplicado nasce sem nenhuma variação utilizável. Anexos ficam de fora de propósito — a mídia é compartilhada entre original e cópia. Profundidade máxima de <?php echo absint(self::MAX_CHILD_DEPTH); ?> níveis, até <?php echo absint(self::MAX_CHILDREN_PER_LEVEL); ?> filhos por nível.</p>
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
                                    <p class="description">O título original é preservado por padrão. O slug da cópia recebe versionamento inteligente baseado no slug do conteúdo escolhido.</p>
                                    <p class="description">Detecção do último bloco numérico: <code>samba-2-guimaraes-212</code> → <code>samba-2-guimaraes-213</code>, <code>pagina-15-historia</code> → <code>pagina-16-historia</code>, <code>post-007</code> → <code>post-008</code>. Quando não há número, numeração progressiva: <code>minha-pagina</code> → <code>minha-pagina-2</code>, <code>minha-pagina-3</code>.</p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row"><label for="mldpp-title-prefix">Prefixo do título (opcional)</label></th>
                                <td>
                                    <input type="text" id="mldpp-title-prefix" class="regular-text" name="<?php echo esc_attr($this->option_name); ?>[title_prefix]" value="<?php echo esc_attr($settings['title_prefix']); ?>" placeholder="ex: Cópia de">
                                    <p class="description">Texto adicionado antes do título da cópia. Vazio mantém o título original intacto. Ex.: <code>Cópia de</code> + <code>Minha página</code> = <code>Cópia de Minha página</code>. Não afeta o slug nem os conteúdos filhos.</p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row"><label for="mldpp-title-suffix">Sufixo do título (opcional)</label></th>
                                <td>
                                    <input type="text" id="mldpp-title-suffix" class="regular-text" name="<?php echo esc_attr($this->option_name); ?>[title_suffix]" value="<?php echo esc_attr($settings['title_suffix']); ?>" placeholder="ex: (rascunho)">
                                    <p class="description">Texto adicionado depois do título da cópia. Ex.: <code>Minha página</code> + <code>(rascunho)</code> = <code>Minha página (rascunho)</code>.</p>
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
            </div>
            <?php endif; ?>

            <?php if ($current_tab === 'lote') : ?>
            <div class="mldpp-grid">
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
            <?php endif; ?>

            <?php if ($current_tab === 'logs') : ?>
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
                                        <th>Filhos</th>
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
                                            <td><?php echo !empty($log['children']) ? esc_html(absint($log['children'])) : '&mdash;'; ?></td>
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
            <?php endif; ?>

            <?php if (!empty($_GET['mldpp_debug'])) : ?>
                <?php
                $debug_force_url = wp_nonce_url(admin_url('admin.php?action=mldpp_force_check'), 'mldpp_force_check');
                $debug_api_url   = sprintf('https://api.github.com/repos/%s/%s/releases/latest', rawurlencode(MLDPP_GITHUB_OWNER), rawurlencode(MLDPP_GITHUB_REPO));

                $debug_response = wp_remote_get($debug_api_url, array(
                    'timeout' => 12,
                    'headers' => array(
                        'Accept'     => 'application/vnd.github+json',
                        'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url('/'),
                    ),
                ));

                $debug_remote_version   = '-';
                $debug_remote_published = '-';
                $debug_remote_url       = '-';
                $debug_remote_assets    = array();
                $debug_api_status       = is_wp_error($debug_response) ? 'erro: ' . $debug_response->get_error_message() : wp_remote_retrieve_response_code($debug_response);
                $debug_api_body         = '';

                if (!is_wp_error($debug_response) && (int) $debug_api_status >= 200 && (int) $debug_api_status < 300) {
                    $debug_api_body         = wp_remote_retrieve_body($debug_response);
                    $debug_api_decoded      = json_decode($debug_api_body, true);
                    $debug_remote_version   = is_array($debug_api_decoded) && !empty($debug_api_decoded['tag_name']) ? ltrim((string) $debug_api_decoded['tag_name'], 'vV') : '-';
                    $debug_remote_published = is_array($debug_api_decoded) && !empty($debug_api_decoded['published_at']) ? $debug_api_decoded['published_at'] : '-';
                    $debug_remote_url       = is_array($debug_api_decoded) && !empty($debug_api_decoded['html_url']) ? $debug_api_decoded['html_url'] : '-';
                    $debug_remote_assets    = is_array($debug_api_decoded) && !empty($debug_api_decoded['assets']) ? $debug_api_decoded['assets'] : array();
                }

                $debug_cached_release = get_transient('mldpp_github_release');
                $debug_wp_transient   = get_site_transient('update_plugins');
                $debug_wp_response    = is_object($debug_wp_transient) && !empty($debug_wp_transient->response[MLDPP_BASENAME]) ? $debug_wp_transient->response[MLDPP_BASENAME] : null;
                $debug_wp_checked     = is_object($debug_wp_transient) && !empty($debug_wp_transient->checked) ? $debug_wp_transient->checked : array();
                $debug_has_basename   = !empty($debug_wp_checked[MLDPP_BASENAME]);
                ?>
                <div class="mldpp-card mldpp-debug-card">
                    <h2><?php esc_html_e('Diagnostico do updater', 'ml-duplicate-posts-pages'); ?></h2>
                    <p class="description"><?php esc_html_e('Informacoes em tempo real sobre o mecanismo de atualizacao. Use quando o painel do WordPress nao exibir a nova versao.', 'ml-duplicate-posts-pages'); ?></p>
                    <table class="widefat striped" style="margin-top:12px;">
                        <tbody>
                            <tr>
                                <th style="width:35%;"><?php esc_html_e('Versao local (MLDPP_VERSION)', 'ml-duplicate-posts-pages'); ?></th>
                                <td><code><?php echo esc_html(MLDPP_VERSION); ?></code></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Slug do plugin (MLDPP_BASENAME)', 'ml-duplicate-posts-pages'); ?></th>
                                <td><code><?php echo esc_html(MLDPP_BASENAME); ?></code></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Versao remota (GitHub latest)', 'ml-duplicate-posts-pages'); ?></th>
                                <td><code><?php echo esc_html($debug_remote_version); ?></code> <small>(publicada em <?php echo esc_html($debug_remote_published); ?>)</small></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Comparacao version_compare', 'ml-duplicate-posts-pages'); ?></th>
                                <td>
                                    <?php
                                    if ($debug_remote_version !== '-' && MLDPP_VERSION !== '-') {
                                        $cmp = version_compare($debug_remote_version, MLDPP_VERSION, '<=');
                                        echo $cmp
                                            ? '<span style="color:#1e8cbe;">igual ou inferior - nenhum update sera exibido</span>'
                                            : '<span style="color:#1a7a3c;font-weight:600;">update disponivel</span>';
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Status HTTP GitHub API', 'ml-duplicate-posts-pages'); ?></th>
                                <td><code><?php echo esc_html($debug_api_status); ?></code></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Transient do updater (mldpp_github_release)', 'ml-duplicate-posts-pages'); ?></th>
                                <td>
                                    <?php if (is_array($debug_cached_release)) : ?>
                                        <pre style="margin:0;white-space:pre-wrap;word-break:break-all;"><?php echo esc_html(wp_json_encode($debug_cached_release, JSON_PRETTY_PRINT)); ?></pre>
                                    <?php else : ?>
                                        <em><?php esc_html_e('vazio ou expirado', 'ml-duplicate-posts-pages'); ?></em>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Transient do WP (update_plugins)', 'ml-duplicate-posts-pages'); ?></th>
                                <td>
                                    <strong><?php esc_html_e('checked[MLDPP_BASENAME]:', 'ml-duplicate-posts-pages'); ?></strong>
                                    <?php if ($debug_has_basename) : ?>
                                        <code><?php echo esc_html($debug_wp_checked[MLDPP_BASENAME]); ?></code>
                                    <?php else : ?>
                                        <em><?php esc_html_e('ausente - WP nao reconhece o plugin no momento da checagem', 'ml-duplicate-posts-pages'); ?></em>
                                    <?php endif; ?>
                                    <br>
                                    <strong><?php esc_html_e('response[MLDPP_BASENAME]:', 'ml-duplicate-posts-pages'); ?></strong>
                                    <?php if ($debug_wp_response) : ?>
                                        <pre style="margin:0;white-space:pre-wrap;word-break:break-all;"><?php echo esc_html(wp_json_encode($debug_wp_response, JSON_PRETTY_PRINT)); ?></pre>
                                    <?php else : ?>
                                        <em><?php esc_html_e('ausente - nenhum update reportado para o plugin', 'ml-duplicate-posts-pages'); ?></em>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Assets da release remota', 'ml-duplicate-posts-pages'); ?></th>
                                <td>
                                    <?php if (empty($debug_remote_assets)) : ?>
                                        <em><?php esc_html_e('nenhum asset encontrado', 'ml-duplicate-posts-pages'); ?></em>
                                    <?php else : ?>
                                        <ul style="margin:0;">
                                            <?php foreach ($debug_remote_assets as $asset) : ?>
                                                <li>
                                                    <code><?php echo esc_html(!empty($asset['name']) ? $asset['name'] : '?'); ?></code>
                                                    (<?php echo esc_html(!empty($asset['size']) ? size_format($asset['size']) : '?'); ?>)
                                                    &mdash;
                                                    <a href="<?php echo esc_url(!empty($asset['browser_download_url']) ? $asset['browser_download_url'] : '#'); ?>" target="_blank" rel="noopener noreferrer">download</a>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('URL da release', 'ml-duplicate-posts-pages'); ?></th>
                                <td><a href="<?php echo esc_url($debug_remote_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($debug_remote_url); ?></a></td>
                            </tr>
                        </tbody>
                    </table>
                    <p style="margin-top:14px;">
                        <a class="button button-secondary" href="<?php echo esc_url($debug_force_url); ?>"><?php esc_html_e('Forcar rechecagem agora', 'ml-duplicate-posts-pages'); ?></a>
                        <a class="button button-link" href="<?php echo esc_url(remove_query_arg('mldpp_debug')); ?>"><?php esc_html_e('Fechar diagnostico', 'ml-duplicate-posts-pages'); ?></a>
                    </p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
