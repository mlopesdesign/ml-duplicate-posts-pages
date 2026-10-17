<?php
/**
 * Licença do ML Duplicate Posts & Pages.
 *
 * 1.6.0: a licença passa a ser emitida e conferida pelo site tools.mlopesdesign.com.br
 * (módulo ML License Hub, cliente ML_License_Client_V2). Aceita também a chave do
 * Pacote Agência. Regras do padrão ML: 1 licença = 1 site; teste grátis de 30 dias por
 * site; sem teste/licença válidos, só a ação de duplicar (rápida, em massa, em lote,
 * editor, admin bar) e o salvamento das configurações ficam travados. Nada no site
 * muda: os conteúdos, as cópias já criadas, as configurações e os logs ficam como estão.
 *
 * Quem já usava o plugin recebe o teste grátis automaticamente na primeira visita
 * ao painel (o site não trava de surpresa após a atualização).
 *
 * @package MLDuplicate
 */

namespace MLDPP;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-ml-license-client.php';

class License {
    const API_BASE     = 'https://tools.mlopesdesign.com.br/wp-json/mllh/v1';
    const PUBLIC_KEY   = 'VGZQp2X3RJMuDmQBlIEXS4fqsZD1CnFlIJgfVtY/pHo=';
    const PRODUCT_ID   = 'ml-duplicate-posts-pages';
    const BUY_URL      = 'https://tools.mlopesdesign.com.br/precos/';
    const ACCOUNT_URL  = 'https://tools.mlopesdesign.com.br/minha-conta/';
    const AUTO_TRIAL   = 'mldpp_auto_trial_tried';
    const TAB          = 'licenca';
    const SETTINGS_GRP = 'mldpp_settings_group';

    /** @var \ML_License_Client_V2|null */
    private static $client = null;

    /** @var bool|null Resultado guardado durante a requisição (zera quando a licença muda). */
    private static $locked = null;

    public static function client() {
        if (null === self::$client) {
            self::$client = new \ML_License_Client_V2(array(
                'api_base'   => self::API_BASE,
                'product'    => self::PRODUCT_ID,
                'public_key' => self::PUBLIC_KEY,
                'version'    => MLDPP_VERSION,
            ));
        }
        return self::$client;
    }

    /** Teste ou licença válidos neste site. */
    public static function is_active() {
        return self::client()->is_premium();
    }

    /** Painel travado para gravar (sem teste nem licença). */
    public static function is_locked() {
        if (null === self::$locked) {
            self::$locked = !self::is_active();
        }
        return self::$locked;
    }

    public static function forget() {
        self::$locked = null;
    }

    public static function locked_message() {
        return __('Ative a licença (ou comece o teste grátis) para duplicar conteúdos e salvar as configurações do ML Duplicate. Nada no seu site muda: os conteúdos e as cópias já criadas continuam como estão.', 'ml-duplicate-posts-pages');
    }

    public static function tab_url($args = array()) {
        return add_query_arg(array_merge(array('page' => 'mldpp-dashboard', 'tab' => self::TAB), $args), admin_url('admin.php'));
    }

    /* ------------------------------------------------------------------ ganchos */

    public static function boot() {
        self::client(); // agenda a renovação diária
        add_action('admin_init', array(__CLASS__, 'maybe_auto_trial'), 5);
        add_action('admin_init', array(__CLASS__, 'guard_settings_save'), 1);
        add_filter('pre_update_option_mldpp_settings', array(__CLASS__, 'keep_settings_when_locked'), 10, 2);
        add_action('admin_notices', array(__CLASS__, 'locked_notice'));
        add_action('admin_post_mldpp_activate_license', array(__CLASS__, 'handle_activate'));
        add_action('admin_post_mldpp_sync_license', array(__CLASS__, 'handle_sync'));
        add_action('admin_post_mldpp_remove_license', array(__CLASS__, 'handle_remove'));

        $opt = self::client()->option_name();
        foreach (array('added_option', 'updated_option', 'deleted_option') as $hook) {
            add_action($hook, function ($name) use ($opt) {
                if ($name === $opt) {
                    License::forget();
                }
            });
        }
    }

    /** Primeira visita ao painel: começa o teste grátis sozinho (uma tentativa por site). */
    public static function maybe_auto_trial() {
        if (wp_doing_ajax() || !current_user_can('manage_options') || get_option(self::AUTO_TRIAL)) {
            return;
        }
        update_option(self::AUTO_TRIAL, time(), false);
        $state = self::client()->state();
        if (empty($state['active']) && 'none' === ($state['mode'] ?? 'none') && empty($state['trial_used'])) {
            self::client()->start_trial();
        }
    }

    /** Sem licença: o formulário de Configurações (options.php) não grava; volta para a aba Licença com aviso. */
    public static function guard_settings_save() {
        global $pagenow;
        if ('options.php' !== $pagenow || 'POST' !== ($_SERVER['REQUEST_METHOD'] ?? '')) {
            return;
        }
        $page = isset($_POST['option_page']) ? sanitize_key(wp_unslash($_POST['option_page'])) : '';
        if (self::SETTINGS_GRP === $page && self::is_locked()) {
            self::redirect_with_notice('error', self::locked_message());
        }
    }

    /** Segunda barreira: qualquer gravação das configurações vinda do painel mantém o valor atual. */
    public static function keep_settings_when_locked($value, $old_value) {
        if (is_admin() && !wp_doing_cron() && self::is_locked() && false !== $old_value) {
            return $old_value;
        }
        return $value;
    }

    /**
     * Para as ações de gravação do painel (duplicar e duplicar em lote).
     * Redireciona para a aba Licença com o aviso.
     */
    public static function guard_write() {
        if (self::is_locked()) {
            self::redirect_with_notice('error', self::locked_message());
        }
    }

    /** Aviso discreto nas telas do plugin (menos na própria aba Licença). */
    public static function locked_notice() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !current_user_can('manage_options') || false === strpos((string) $screen->id, 'mldpp-dashboard')) {
            return;
        }
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
        if (self::TAB === $tab || !self::is_locked()) {
            return;
        }
        printf(
            '<div class="notice notice-warning"><p><strong>ML Duplicate:</strong> %s <a href="%s">%s</a></p></div>',
            esc_html__('sem licença ativa — a duplicação e o salvamento das configurações estão pausados. Nada no seu site muda.', 'ml-duplicate-posts-pages'),
            esc_url(self::tab_url()),
            esc_html__('Ativar licença', 'ml-duplicate-posts-pages')
        );
    }

    /* ------------------------------------------------------------------ tela */

    /** Estado pronto para a tela. */
    public static function summary() {
        $state  = self::client()->state();
        $active = !empty($state['active']);
        $mode   = (string) ($state['mode'] ?? 'none');
        $plan   = strtolower((string) ($state['plan'] ?? ''));
        $days   = $state['days_left'] ?? null;

        $plans = array(
            'trial'    => 'Teste grátis',
            'monthly'  => 'Mensal',
            'annual'   => 'Anual',
            'yearly'   => 'Anual',
            'lifetime' => 'Vitalícia',
            'manual'   => 'Cortesia',
            'courtesy' => 'Cortesia',
        );
        $plan_label = $plans[$plan] ?? ('' !== $plan ? ucfirst($plan) : '—');

        if ($active && 'trial' === $mode) {
            $label   = 'Teste grátis';
            $tone    = 'trial';
            $message = null === $days ? 'Teste grátis ativo neste site.' : sprintf(1 === (int) $days ? 'Teste grátis ativo: falta %d dia.' : 'Teste grátis ativo: faltam %d dias.', (int) $days);
        } elseif ($active) {
            $label   = 'Ativa';
            $tone    = 'full';
            $message = 'Licença ativa neste site.';
        } else {
            $label = 'Sem licença';
            $tone  = 'free';
            if ('trial_expired' === ($state['status'] ?? '')) {
                $message = 'O teste grátis terminou. Ative uma licença para voltar a duplicar conteúdos. Nada no seu site muda.';
            } elseif ('' !== (string) ($state['message'] ?? '')) {
                $message = (string) $state['message'];
            } else {
                $message = 'Comece o teste grátis de 30 dias ou ative a sua licença.';
            }
        }

        $expires_label = '';
        if (!empty($state['expires_at'])) {
            $ts = strtotime((string) $state['expires_at']);
            if ($ts) {
                $expires_label = wp_date(get_option('date_format', 'd/m/Y'), $ts);
            }
        }

        return array(
            'active'          => $active,
            'mode'            => $mode,
            'plan'            => $plan,
            'is_trial'        => $active && 'trial' === $mode,
            'label'           => $label,
            'tone'            => $tone,
            'message'         => $message,
            'plan_label'      => $plan_label,
            'expires_label'   => $expires_label,
            'days_left'       => $days,
            'key_masked'      => (string) ($state['key_masked'] ?? ''),
            'can_start_trial' => !$active && empty($state['trial_used']) && 'license' !== $mode,
            'domain'          => (string) wp_parse_url(home_url(), PHP_URL_HOST),
        );
    }

    /** Aba Licença (no estilo do painel ML Duplicate). */
    public static function render_tab() {
        $license     = self::summary();
        $notice      = isset($_GET['mldpp_lic_notice']) ? sanitize_text_field(rawurldecode(wp_unslash($_GET['mldpp_lic_notice']))) : '';
        $notice_type = isset($_GET['mldpp_lic_type']) && 'error' === $_GET['mldpp_lic_type'] ? 'error' : 'success';
        $post_url    = admin_url('admin-post.php');
        $days_left   = $license['days_left'];

        if ($license['active'] && 'lifetime' === $license['plan']) {
            $valid_until = 'Sem vencimento';
        } elseif ($license['active'] && $license['expires_label']) {
            $valid_until = $license['expires_label'];
        } else {
            $valid_until = '—';
        }
        $days_label = ($license['active'] && null !== $days_left && $license['expires_label'] && 'lifetime' !== $license['plan']) ? (string) (int) $days_left : '—';
        ?>
        <?php if ($notice) : ?>
            <div class="notice notice-<?php echo esc_attr($notice_type); ?> is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
        <?php endif; ?>

        <div class="mldpp-grid">
            <div class="mldpp-card mldpp-license-card">
                <h2>
                    <?php esc_html_e('Licença', 'ml-duplicate-posts-pages'); ?>
                    <span class="mldpp-license-status is-<?php echo esc_attr($license['tone']); ?>"><?php echo esc_html($license['label']); ?></span>
                </h2>

                <div class="mldpp-kpi-grid">
                    <div class="mldpp-kpi"><strong class="mldpp-kpi-text"><?php echo esc_html($license['label']); ?></strong><span>Situação</span></div>
                    <div class="mldpp-kpi"><strong class="mldpp-kpi-text"><?php echo esc_html($license['plan_label']); ?></strong><span>Plano</span></div>
                    <div class="mldpp-kpi"><strong class="mldpp-kpi-text"><?php echo esc_html($valid_until); ?></strong><span>Válida até</span></div>
                    <div class="mldpp-kpi"><strong class="mldpp-kpi-text"><?php echo esc_html($days_label); ?></strong><span>Dias restantes</span></div>
                </div>

                <div class="mldpp-note"><?php echo esc_html($license['message']); ?> <span class="mldpp-license-domain"><?php echo esc_html($license['domain']); ?></span></div>

                <?php if ('license' === $license['mode'] && '' !== $license['key_masked']) : ?>
                    <p class="mldpp-license-key"><?php esc_html_e('Chave neste site:', 'ml-duplicate-posts-pages'); ?> <code><?php echo esc_html($license['key_masked']); ?></code></p>
                    <div class="mldpp-license-actions">
                        <form method="post" action="<?php echo esc_url($post_url); ?>">
                            <?php wp_nonce_field('mldpp_sync_license'); ?>
                            <input type="hidden" name="action" value="mldpp_sync_license">
                            <input type="hidden" name="mldpp_op" value="check">
                            <button type="submit" class="button button-primary"><?php esc_html_e('Conferir agora', 'ml-duplicate-posts-pages'); ?></button>
                        </form>
                        <form method="post" action="<?php echo esc_url($post_url); ?>">
                            <?php wp_nonce_field('mldpp_remove_license'); ?>
                            <input type="hidden" name="action" value="mldpp_remove_license">
                            <button type="submit" class="button button-secondary"><?php esc_html_e('Remover deste site', 'ml-duplicate-posts-pages'); ?></button>
                        </form>
                    </div>
                <?php else : ?>
                    <form method="post" action="<?php echo esc_url($post_url); ?>" class="mldpp-license-form">
                        <?php wp_nonce_field('mldpp_activate_license'); ?>
                        <input type="hidden" name="action" value="mldpp_activate_license">
                        <label for="mldpp-license-key"><strong><?php esc_html_e('Chave da licença', 'ml-duplicate-posts-pages'); ?></strong></label>
                        <div class="mldpp-license-field">
                            <input type="text" id="mldpp-license-key" class="regular-text" name="license_key" value="" placeholder="Cole aqui a sua chave" autocomplete="off" spellcheck="false">
                            <button type="submit" class="button button-primary"><?php esc_html_e('Ativar licença', 'ml-duplicate-posts-pages'); ?></button>
                        </div>
                    </form>
                    <div class="mldpp-license-actions">
                        <?php if ($license['can_start_trial']) : ?>
                            <form method="post" action="<?php echo esc_url($post_url); ?>">
                                <?php wp_nonce_field('mldpp_sync_license'); ?>
                                <input type="hidden" name="action" value="mldpp_sync_license">
                                <input type="hidden" name="mldpp_op" value="trial">
                                <button type="submit" class="button button-secondary"><?php esc_html_e('Começar teste grátis de 30 dias', 'ml-duplicate-posts-pages'); ?></button>
                            </form>
                        <?php else : ?>
                            <form method="post" action="<?php echo esc_url($post_url); ?>">
                                <?php wp_nonce_field('mldpp_sync_license'); ?>
                                <input type="hidden" name="action" value="mldpp_sync_license">
                                <input type="hidden" name="mldpp_op" value="check">
                                <button type="submit" class="button button-secondary"><?php esc_html_e('Conferir agora', 'ml-duplicate-posts-pages'); ?></button>
                            </form>
                        <?php endif; ?>
                        <a class="button button-secondary" href="<?php echo esc_url(self::BUY_URL); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Comprar licença', 'ml-duplicate-posts-pages'); ?></a>
                        <a class="button button-secondary" href="<?php echo esc_url(self::ACCOUNT_URL); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Minha conta', 'ml-duplicate-posts-pages'); ?></a>
                    </div>
                <?php endif; ?>
            </div>

            <div class="mldpp-card">
                <h2><?php esc_html_e('Como funciona', 'ml-duplicate-posts-pages'); ?></h2>
                <table class="widefat striped mldpp-license-table">
                    <tbody>
                        <tr><td><strong>Teste grátis</strong></td><td>30 dias com tudo liberado, uma vez por site. Reinstalar não reinicia.</td></tr>
                        <tr><td><strong>Licença anual</strong></td><td>Vale em 1 site. Para usar em outro, remova deste site.</td></tr>
                        <tr><td><strong>Pacote Agência</strong></td><td>Uma chave para todos os plugins ML em 5, 10 ou 50 sites.</td></tr>
                        <tr><td><strong>Sem licença</strong></td><td>Nada no seu site muda: conteúdos, cópias já criadas, configurações e logs ficam como estão. Só a duplicação e o salvamento das configurações ficam pausados.</td></tr>
                    </tbody>
                </table>
                <p class="description"><?php esc_html_e('A licença é conferida uma vez por dia em tools.mlopesdesign.com.br. Sem internet, o plugin continua liberado por até 7 dias.', 'ml-duplicate-posts-pages'); ?></p>
            </div>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------ ações */

    public static function handle_activate() {
        self::guard_admin_request('mldpp_activate_license');
        $key    = isset($_POST['license_key']) ? sanitize_text_field(wp_unslash($_POST['license_key'])) : '';
        $result = self::client()->activate($key);
        if (is_wp_error($result)) {
            self::redirect_with_notice('error', $result->get_error_message());
        }
        self::redirect_with_notice('success', __('Licença ativada neste site.', 'ml-duplicate-posts-pages'));
    }

    /** Começa o teste grátis ou confere a licença agora. */
    public static function handle_sync() {
        self::guard_admin_request('mldpp_sync_license');
        $op     = isset($_POST['mldpp_op']) ? sanitize_key(wp_unslash($_POST['mldpp_op'])) : 'check';
        $result = 'trial' === $op ? self::client()->start_trial() : self::client()->refresh();
        if (is_wp_error($result)) {
            self::redirect_with_notice('error', $result->get_error_message());
        }
        self::redirect_with_notice('success', 'trial' === $op ? __('Teste grátis de 30 dias ativo neste site.', 'ml-duplicate-posts-pages') : __('Licença conferida.', 'ml-duplicate-posts-pages'));
    }

    public static function handle_remove() {
        self::guard_admin_request('mldpp_remove_license');
        self::client()->deactivate();
        self::redirect_with_notice('success', __('Licença removida deste site. A chave pode ser usada em outro site.', 'ml-duplicate-posts-pages'));
    }

    private static function guard_admin_request($nonce_action) {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permissão insuficiente.', 'ml-duplicate-posts-pages'));
        }
        check_admin_referer($nonce_action);
    }

    public static function redirect_with_notice($type, $message) {
        wp_safe_redirect(self::tab_url(array(
            'mldpp_lic_type'   => 'error' === $type ? 'error' : 'success',
            'mldpp_lic_notice' => rawurlencode($message),
        )));
        exit;
    }
}
