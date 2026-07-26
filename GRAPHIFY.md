# GRAPHIFY - ML Duplicate Posts & Pages

> Mapa tecnico gerado automaticamente por `node tools/graphify.js`.
> **Nao edite a mao.** Regere apos qualquer alteracao no plugin e antes do commit.

- **Slug (imutavel):** `ml-duplicate-posts-pages`
- **Versao:** `1.5.0` (readme Stable tag: `1.5.0`)
- **Text domain:** `ml-duplicate-posts-pages`
- **Requisitos:** WordPress 5.8+ (testado ate 6.8), PHP 7.4+
- **Gerado em:** 2026-07-26

> :warning: O slug, o nome da pasta raiz e o nome do arquivo principal sao a identidade do plugin
> para o WordPress e para o updater. Alterar qualquer um deles quebra a atualizacao em todas as
> instalacoes existentes. **Nunca mude.**

## 1. Estrutura de arquivos

```
ml-duplicate-posts-pages/
  README.md
  ml-duplicate-posts-pages.php
  readme.txt
  uninstall.php
  assets/css/
    admin.css
  assets/js/
    admin.js
    editor.js
  includes/
    class-ml-duplicate-posts-pages.php
    class-mldpp-github-updater.php
  languages/
    ml-duplicate-posts-pages.pot
```

| Arquivo | Linhas | Classes | Metodos | Hooks |
| --- | --- | --- | --- | --- |
| `ml-duplicate-posts-pages/includes/class-ml-duplicate-posts-pages.php` | 1797 | `Plugin` | 46 | 20 |
| `ml-duplicate-posts-pages/includes/class-mldpp-github-updater.php` | 227 | `GitHub_Updater` | 8 | 4 |
| `ml-duplicate-posts-pages/ml-duplicate-posts-pages.php` | 37 | - | - | - |
| `ml-duplicate-posts-pages/uninstall.php` | 18 | - | - | - |

## 2. Grafo de carregamento

```
WordPress carrega ml-duplicate-posts-pages/ml-duplicate-posts-pages.php
  |
  +-- define() das constantes MLDPP_*
  +-- require includes/class-ml-duplicate-posts-pages.php
  +-- require includes/class-mldpp-github-updater.php
  +-- mldpp_bootstrap() ---> MLDPP\Plugin::instance()  (singleton, registra todos os hooks)
  +-- MLDPP\GitHub_Updater::init()                     (registra os hooks do updater)
  +-- register_activation_hook -> MLDPP\Plugin::activate()
```

## 3. Constantes

| Constante | Valor |
| --- | --- |
| `MLDPP_VERSION` | `'1.5.0'` |
| `MLDPP_FILE` | `__FILE__` |
| `MLDPP_DIR` | `plugin_dir_path(__FILE__)` |
| `MLDPP_URL` | `plugin_dir_url(__FILE__)` |
| `MLDPP_BASENAME` | `plugin_basename(__FILE__)` |
| `MLDPP_GITHUB_OWNER` | `'mlopesdesign'` |
| `MLDPP_GITHUB_REPO` | `'ml-duplicate-posts-pages'` |
| `MLDPP_GITHUB_REPO_URL` | `'https://github.com/mlopesdesign/ml-duplicate-posts-pages'` |

## 4. Hooks registrados

### Actions

| Hook | Callback | Prio | Arquivo |
| --- | --- | --- | --- |
| `init` | `load_textdomain()` | 10 | `class-ml-duplicate-posts-pages.php` |
| `admin_menu` | `register_admin_menu()` | 10 | `class-ml-duplicate-posts-pages.php` |
| `admin_init` | `register_settings()` | 10 | `class-ml-duplicate-posts-pages.php` |
| `admin_init` | `handle_duplicate_request()` | 10 | `class-ml-duplicate-posts-pages.php` |
| `admin_init` | `handle_bulk_duplicate_request()` | 10 | `class-ml-duplicate-posts-pages.php` |
| `admin_init` | `force_check_for_update()` | 10 | `class-ml-duplicate-posts-pages.php` |
| `admin_enqueue_scripts` | `enqueue_assets()` | 10 | `class-ml-duplicate-posts-pages.php` |
| `enqueue_block_editor_assets` | `enqueue_block_editor_assets()` | 10 | `class-ml-duplicate-posts-pages.php` |
| `wp_ajax_mldpp_preview_slug` | `ajax_preview_slug()` | 10 | `class-ml-duplicate-posts-pages.php` |
| `admin_init` | `register_bulk_actions()` | 5 | `class-ml-duplicate-posts-pages.php` |
| `admin_bar_menu` | `add_admin_bar_button()` | 90 | `class-ml-duplicate-posts-pages.php` |
| `admin_bar_menu` | `add_admin_bar_updater_node()` | 95 | `class-ml-duplicate-posts-pages.php` |
| `post_submitbox_misc_actions` | `render_submitbox_button()` | 10 | `class-ml-duplicate-posts-pages.php` |
| `admin_notices` | `render_admin_notices()` | 10 | `class-ml-duplicate-posts-pages.php` |
| `'load-' . $this->screen_hook` | `register_help_tabs()` | 10 | `class-ml-duplicate-posts-pages.php` |
| `'in_plugin_update_message-' . MLDPP_BASENAME` | `render_update_message()` | 10 | `class-mldpp-github-updater.php` |

### Filters

| Hook | Callback | Prio | Arquivo |
| --- | --- | --- | --- |
| `post_row_actions` | `add_row_action()` | 10 | `class-ml-duplicate-posts-pages.php` |
| `page_row_actions` | `add_row_action()` | 10 | `class-ml-duplicate-posts-pages.php` |
| `'plugin_action_links_' . MLDPP_BASENAME` | `plugin_action_links()` | 10 | `class-ml-duplicate-posts-pages.php` |
| `'bulk_actions-edit-' . $post_type` | `register_bulk_action()` | 10 | `class-ml-duplicate-posts-pages.php` |
| `'handle_bulk_actions-edit-' . $post_type` | `handle_native_bulk_action_redirect()` | 10 | `class-ml-duplicate-posts-pages.php` |
| `pre_set_site_transient_update_plugins` | `check_for_update()` | 10 | `class-mldpp-github-updater.php` |
| `plugins_api` | `plugin_info()` | 20 | `class-mldpp-github-updater.php` |
| `plugin_row_meta` | `plugin_row_meta()` | 10 | `class-mldpp-github-updater.php` |

### Hooks dinamicos

| Hook | Callback | Tipo | Observacao |
| --- | --- | --- | --- |
| `bulk_actions-edit-{post_type}` | `register_bulk_action()` | filter | Registrado em runtime para cada post type habilitado |
| `handle_bulk_actions-edit-{post_type}` | `handle_native_bulk_action_redirect()` | filter | Registrado em runtime para cada post type habilitado |
| `plugin_action_links_{basename}` | `plugin_action_links()` | filter | Link "Configuracoes" na listagem de plugins |
| `in_plugin_update_message-{basename}` | `render_update_message()` | action | Notas da versao inline na tela de plugins |

### Hooks de extensao expostos pelo plugin

| Hook | Assinatura |
| --- | --- |
| `mldpp_after_duplicate_post` | `$new_post_id, $source_post_id, $source_post` |

## 5. Fluxo de duplicacao

```
  [Acao rapida "Duplicar"]   [Acao em massa]   [Botao no editor]   [Admin bar]
              |                     |                  |                |
              +---------------------+------------------+----------------+
                                    |
                    admin.php?action=mldpp_duplicate_post&post=ID
                                    |  (nonce mldpp_duplicate_post_{ID})
                                    v
                          handle_duplicate_request()
                                    |
                                    v
                            duplicate_post($post_id)
                                    |
        +---------------------------+---------------------------+
        |                           |                           |
        v                           v                           v
  guard de permissao        generate_versioned_slug()      wp_insert_post()
  - post type habilitado             |                           |
  - current_user_can(edit_post)      |                           v
  - roles_allowed                    |                    copia condicional:
                                     |                    - template
                                     |                    - imagem destacada
                                     |                    - taxonomias
                                     |                    - meta (com wp_slash)
                                     |                    - comentarios
                                     v                           |
                    get_duplicate_slug_base($post)               v
                       = $post->post_name  <-- REGRA             meta de rastreio:
                                     |                    _mldpp_source_post
                                     v                    _mldpp_slug_base
                    strip_slug_tokens() (prefixo/sufixo)  _mldpp_duplicated_at
                                     |                    _mldpp_duplicated_by
                                     v                           |
              modo last_numeric ? increment_last_numeric_token() v
                                : build_with_progressive_number()  write_log()
                                     |                           |
                                     v                           v
                             compose_slug()          do_action(mldpp_after_duplicate_post)
                                     |                           |
                                     v                           v
                      duplicate_slug_exists()?           redirect -> post.php?action=edit
                      (loop, max 1000 tentativas)
```

## 6. Regras de versionamento de slug

**Base = o slug atual do conteudo escolhido.** Nao o do post raiz, nao um meta congelado.

| Slug escolhido | Modo | Resultado |
| --- | --- | --- |
| `pagina-205` | `last_numeric` | `pagina-206` |
| `pagina-206` (a copia) | `last_numeric` | `pagina-207` |
| `205` | `last_numeric` | `206` |
| `post-007` | `last_numeric` | `post-008` (preserva zero a esquerda) |
| `pagina-15-historia` | `last_numeric` | `pagina-16-historia` |
| `foo-2-bar-7-baz` | `last_numeric` | `foo-2-bar-8-baz` (so o ultimo bloco) |
| `minha-pagina` | `last_numeric` | `minha-pagina-2` (fallback progressivo) |
| `pagina-205` | `append_suffix` | `pagina-205-2` |
| `pagina-205` + prefixo `copy-of` | `last_numeric` | `copy-of-pagina-206` |
| `copy-of-pagina-206` + prefixo `copy-of` | `last_numeric` | `copy-of-pagina-207` (nao acumula) |

Colisao e resolvida por `duplicate_slug_exists()`, que consulta `wp_posts` por
`post_name` + `post_type` (+ `post_parent` em tipos hierarquicos), ignorando `trash` e `auto-draft`.

## 7. Dados persistidos

### Options

| Option | Autoload | Conteudo |
| --- | --- | --- |
| `mldpp_settings` | sim | Configuracoes do painel (post types, o que copiar, modo de slug, roles, limite de log) |
| `mldpp_logs` | nao | Historico circular de duplicacoes (limitado por `log_limit`) |

### Post meta

| Meta key | Gravado em | Uso |
| --- | --- | --- |
| `_mldpp_source_post` | copia | ID do conteudo de origem |
| `_mldpp_slug_base` | copia | Base de slug usada (auditoria; fallback quando `post_name` esta vazio) |
| `_mldpp_duplicated_at` | copia | Timestamp da duplicacao |
| `_mldpp_duplicated_by` | copia | ID do usuario que duplicou |

### Transients

| Transient | TTL | Uso |
| --- | --- | --- |
| `mldpp_github_release` | 6 horas | Cache da resposta da GitHub Releases API |

Detectados no codigo: `_edit_last`, `_mldpp_duplicated_at`, `_mldpp_duplicated_by`, `_mldpp_slug_base`, `_mldpp_source_post`, `_sku`, `_wp_page_template`, `mldpp_github_release`, `mldpp_logs`, `mldpp_settings`, `update_plugins`

## 8. Superficie de seguranca

| Entrada | Autenticacao |
| --- | --- |
| `admin.php?action=mldpp_duplicate_post` | nonce `mldpp_duplicate_post_{ID}` + `current_user_can(edit_post)` + roles_allowed |
| `admin.php?action=mldpp_force_check` | nonce `mldpp_force_check` + `current_user_can(update_plugins)` |
| AJAX `mldpp_preview_slug` | nonce `mldpp_preview_slug` + roles_allowed + post type habilitado |
| POST `mldpp_manual_bulk_submit` | nonce `mldpp_manual_bulk_nonce` + roles_allowed |
| Acao em massa nativa | nonce do proprio WordPress + roles_allowed |
| `register_setting` / `sanitize_settings` | `manage_options` (capability do menu) + whitelist por campo |

Capabilities usadas: `edit_post`, `manage_options`, `update_plugins`

Consultas diretas ao banco usam `$wpdb->prepare()`. Toda saida passa por `esc_html`, `esc_attr`, `esc_url` ou `wp_kses_post`.

## 9. Ciclo do updater (GitHub -> WordPress)

```
  WP cron / tela de plugins
        |
        v
  pre_set_site_transient_update_plugins -> GitHub_Updater::check_for_update()
        |
        v
  get_latest_release()  --cache 6h-->  transient mldpp_github_release
        |                                       ^
        |  cache miss                           |
        v                                       |
  GET api.github.com/repos/{owner}/{repo}/releases/latest
        |
        v
  tag_name "v1.3.0" -> version "1.3.0"
  find_release_package(): procura o asset por ordem de preferencia
     1. {slug}-{version}.zip
     2. {slug}.zip
     3. primeiro *.zip da release
        |
        v
  version_compare(remote, MLDPP_VERSION)
        |                        |
     remote > local          remote <= local
        |                        |
        v                        v
  $transient->response[]    $transient->no_update[]
  (WP oferece o update)     (WP mostra "atualizado" + toggle de auto-update)
```

Diagnostico manual: barra de admin -> **ML Duplicate** -> "Verificar atualizacao agora"
ou "Diagnostico do updater" (`?mldpp_debug=1` no painel do plugin).

## 10. Pipeline de release

```
  git tag vX.Y.Z && git push origin vX.Y.Z
        |
        v
  .github/workflows/release.yml
        |
        +-- Setup PHP 7.4
        +-- Valida versao: header == MLDPP_VERSION == Stable tag == tag do git
        +-- php -l  em todos os .php
        +-- node --check  em todos os .js
        +-- Build ZIP: {slug}-{version}.zip com raiz unica {slug}/
        +-- Valida estrutura do ZIP:
        |     1. raiz unica {slug}/
        |     2. sem pasta dupla {slug}/{slug}/
        |     3. arquivo principal presente
        |     4. readme.txt e uninstall.php presentes
        |     5. sem __MACOSX, .DS_Store, .git, node_modules, vendor
        +-- SHA-256 + tamanho no summary
        +-- softprops/action-gh-release -> publica o asset
        |
        v
  GitHub Release com {slug}-{version}.zip
        |
        v
  GitHub_Updater encontra o asset -> WordPress oferece a atualizacao
```

`workflow_dispatch` roda a validacao completa sem publicar (o ZIP fica como artifact).

## 11. Checklist antes de cada release

- [ ] Slug, pasta raiz e arquivo principal **inalterados**
- [ ] Versao sincronizada: header do plugin, `MLDPP_VERSION`, `Stable tag` do readme.txt
- [ ] Changelog do readme.txt atualizado com a nova versao
- [ ] `php -l` (ou equivalente) sem erros em todos os arquivos
- [ ] `node --check` sem erros em todos os JS
- [ ] `node tools/graphify.js` regerado e commitado
- [ ] Nenhuma funcionalidade anterior removida (sem downgrade)
- [ ] ZIP com raiz unica, sem pasta dupla e sem artefatos de desenvolvimento
- [ ] Tag `vX.Y.Z` batendo exatamente com a versao do plugin
- [ ] Release publicada com o asset `{slug}-{version}.zip`

## 12. Indice de metodos

### `ml-duplicate-posts-pages/includes/class-ml-duplicate-posts-pages.php` - `MLDPP\\Plugin`

| Metodo | Visibilidade | Linha |
| --- | --- | --- |
| `instance()` | public static | 24 |
| `activate()` | public static | 31 |
| `__construct()` | public | 41 |
| `load_textdomain()` | public | 64 |
| `register_admin_menu()` | public | 68 |
| `register_help_tabs()` | public | 84 |
| `register_settings()` | public | 127 |
| `enqueue_assets()` | public | 131 |
| `enqueue_block_editor_assets()` | public | 184 |
| `plugin_action_links()` | public | 243 |
| `get_default_settings_static()` | public static | 251 |
| `get_settings()` | private | 273 |
| `sanitize_settings()` | public | 278 |
| `current_user_can_duplicate()` | private | 343 |
| `is_post_type_enabled()` | private | 358 |
| `add_row_action()` | public | 363 |
| `register_bulk_actions()` | public | 390 |
| `register_bulk_action()` | public | 404 |
| `handle_native_bulk_action_redirect()` | public | 411 |
| `add_admin_bar_updater_node()` | public | 433 |
| `force_check_for_update()` | public | 470 |
| `get_contextual_post()` | private | 510 |
| `add_admin_bar_button()` | public | 532 |
| `render_submitbox_button()` | public | 559 |
| `ajax_preview_slug()` | public | 584 |
| `handle_duplicate_request()` | public | 614 |
| `handle_bulk_duplicate_request()` | public | 645 |
| `render_admin_notices()` | public | 701 |
| `generate_versioned_slug()` | private | 750 |
| `strip_slug_tokens()` | private | 788 |
| `compose_slug()` | private | 809 |
| `increment_last_numeric_token()` | private | 833 |
| `build_with_progressive_number()` | private | 862 |
| `sanitize_slug_token()` | private | 880 |
| `get_duplicate_slug_base()` | private | 897 |
| `duplicate_slug_exists()` | private | 928 |
| `duplicate_post()` | private | 948 |
| `duplicate_children()` | private | 1136 |
| `ensure_unique_sku()` | private | 1188 |
| `sku_exists()` | private | 1207 |
| `sync_woocommerce_product()` | private | 1225 |
| `apply_title_tokens()` | private | 1244 |
| `write_log()` | private | 1261 |
| `get_available_post_types()` | private | 1289 |
| `get_logs()` | private | 1304 |
| `render_dashboard()` | public | 1329 |

### `ml-duplicate-posts-pages/includes/class-mldpp-github-updater.php` - `MLDPP\\GitHub_Updater`

| Metodo | Visibilidade | Linha |
| --- | --- | --- |
| `init()` | public static | 12 |
| `check_for_update()` | public static | 19 |
| `plugin_info()` | public static | 84 |
| `render_update_message()` | public static | 110 |
| `plugin_row_meta()` | public static | 127 |
| `get_release_changelog()` | private static | 138 |
| `get_latest_release()` | private static | 151 |
| `find_release_package()` | private static | 200 |

---

_Regere este arquivo com `node tools/graphify.js` sempre que o plugin mudar._
