#!/usr/bin/env node
/**
 * graphify.js - Gerador do mapa tecnico (GRAPHIFY.md) do plugin.
 *
 * Padrao ML Lopes Design: todo plugin modificado tem o GRAPHIFY regerado ANTES
 * do commit, para que o mapa nunca fique defasado em relacao ao codigo.
 *
 * Uso:
 *   node tools/graphify.js               # escreve GRAPHIFY.md na raiz do repo
 *   node tools/graphify.js --check       # falha (exit 1) se o arquivo estiver desatualizado
 *   node tools/graphify.js --stdout      # imprime sem gravar
 *
 * Nao depende de nenhum pacote externo.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const REPO_ROOT = path.resolve(__dirname, '..');
const OUTPUT = path.join(REPO_ROOT, 'GRAPHIFY.md');

// ---------------------------------------------------------------------------
// Descoberta do plugin
// ---------------------------------------------------------------------------

function findPluginDir() {
  const entries = fs.readdirSync(REPO_ROOT, { withFileTypes: true });
  for (const entry of entries) {
    if (!entry.isDirectory() || entry.name.startsWith('.') || entry.name === 'tools') continue;
    const candidate = path.join(REPO_ROOT, entry.name, `${entry.name}.php`);
    if (fs.existsSync(candidate)) return { slug: entry.name, dir: path.join(REPO_ROOT, entry.name), mainFile: candidate };
  }
  throw new Error('Nenhum diretorio de plugin encontrado (esperado <slug>/<slug>.php na raiz do repo).');
}

function walk(dir, acc = []) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    if (entry.name.startsWith('.')) continue;
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) walk(full, acc);
    else acc.push(full);
  }
  return acc;
}

// ---------------------------------------------------------------------------
// Extratores
// ---------------------------------------------------------------------------

const grabAll = (source, regex, map) => {
  const out = [];
  let m;
  regex.lastIndex = 0;
  while ((m = regex.exec(source)) !== null) out.push(map(m));
  return out;
};

function readHeader(mainFile) {
  const src = fs.readFileSync(mainFile, 'utf8');
  const field = name => {
    const m = src.match(new RegExp(`^\\s*\\*\\s*${name}:\\s*(.+)$`, 'm'));
    return m ? m[1].trim() : '';
  };
  return {
    name: field('Plugin Name'),
    description: field('Description'),
    version: field('Version'),
    author: field('Author'),
    textDomain: field('Text Domain'),
    requiresPhp: field('Requires PHP'),
    constants: grabAll(src, /define\(\s*'([A-Z0-9_]+)'\s*,\s*(.+?)\s*\);/g, m => ({ name: m[1], value: m[2] })),
  };
}

function readReadme(pluginDir) {
  const file = path.join(pluginDir, 'readme.txt');
  if (!fs.existsSync(file)) return {};
  const src = fs.readFileSync(file, 'utf8');
  const field = name => {
    const m = src.match(new RegExp(`^${name}:\\s*(.+)$`, 'm'));
    return m ? m[1].trim() : '';
  };
  return {
    stableTag: field('Stable tag'),
    requiresAtLeast: field('Requires at least'),
    testedUpTo: field('Tested up to'),
    requiresPhp: field('Requires PHP'),
    license: field('License'),
  };
}

function analyzePhp(file, rel) {
  const src = fs.readFileSync(file, 'utf8');
  const lines = src.split('\n');

  const classes = grabAll(src, /^\s*(?:final\s+|abstract\s+)?class\s+([A-Za-z0-9_]+)/gm, m => m[1]);
  const namespace = (src.match(/^namespace\s+([A-Za-z0-9_\\]+)\s*;/m) || [])[1] || '';

  const methods = [];
  lines.forEach((line, i) => {
    const m = line.match(/^\s*(public|private|protected)\s+(static\s+)?function\s+([A-Za-z0-9_]+)\s*\(/);
    if (m) methods.push({ visibility: m[1], static: !!m[2], name: m[3], line: i + 1 });
  });

  const hooks = [];
  const hookRe = /add_(action|filter)\(\s*(?:'([^']+)'|"([^"]+)"|([^,]+?))\s*,\s*array\(\s*(\$this|__CLASS__|self::class)\s*,\s*'([A-Za-z0-9_]+)'\s*\)(?:\s*,\s*(\d+))?/g;
  let hm;
  while ((hm = hookRe.exec(src)) !== null) {
    hooks.push({
      kind: hm[1],
      hook: hm[2] || hm[3] || hm[4].trim(),
      callback: hm[6],
      priority: hm[7] || '10',
      line: src.slice(0, hm.index).split('\n').length,
    });
  }

  const ajax = grabAll(src, /wp_ajax(?:_nopriv)?_([a-z0-9_]+)/g, m => m[1]);
  const options = [...new Set(grabAll(src, /(?:get|update|add|delete)_option\(\s*'([a-z0-9_]+)'/g, m => m[1]))];
  const optionProps = [...new Set(grabAll(src, /(?:option_name|log_option_name)\s*=\s*'([a-z0-9_]+)'/g, m => m[1]))];
  const meta = [...new Set(grabAll(src, /(?:get|update|add|delete)_post_meta\(\s*[^,]+,\s*'(_[a-z0-9_]+)'/g, m => m[1]))];
  const transients = [...new Set(grabAll(src, /(?:get|set|delete)_(?:site_)?transient\(\s*'([a-z0-9_]+)'/g, m => m[1]))];
  const capabilities = [...new Set(grabAll(src, /current_user_can\(\s*'([a-z_]+)'/g, m => m[1]))];
  const nonces = [...new Set(grabAll(src, /(?:wp_create_nonce|check_admin_referer|check_ajax_referer|wp_nonce_url)\([^'"]*['"]([a-z0-9_]+)['"]/g, m => m[1]))];
  const customHooks = grabAll(src, /do_action\(\s*'([a-z0-9_]+)'/g, m => m[1]);

  return {
    rel,
    lines: lines.length,
    bytes: Buffer.byteLength(src),
    namespace,
    classes,
    methods,
    hooks,
    ajax: [...new Set(ajax)],
    options: [...new Set([...options, ...optionProps])],
    meta,
    transients,
    capabilities,
    nonces,
    customHooks: [...new Set(customHooks)],
  };
}

// ---------------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------------

function table(headers, rows) {
  if (!rows.length) return '_Nenhum._\n';
  const out = [
    `| ${headers.join(' | ')} |`,
    `| ${headers.map(() => '---').join(' | ')} |`,
    ...rows.map(r => `| ${r.join(' | ')} |`),
  ];
  return out.join('\n') + '\n';
}

function render() {
  const { slug, dir, mainFile } = findPluginDir();
  const header = readHeader(mainFile);
  const readme = readReadme(dir);

  const files = walk(dir).sort();
  const phpFiles = files.filter(f => f.endsWith('.php'));
  const analyses = phpFiles.map(f => analyzePhp(f, path.relative(REPO_ROOT, f).replace(/\\/g, '/')));

  const allHooks = analyses.flatMap(a => a.hooks.map(h => ({ ...h, file: a.rel })));
  const uniq = arr => [...new Set(arr)].sort();

  const out = [];
  const P = s => out.push(s);

  P(`# GRAPHIFY - ${header.name}`);
  P('');
  P('> Mapa tecnico gerado automaticamente por `node tools/graphify.js`.');
  P('> **Nao edite a mao.** Regere apos qualquer alteracao no plugin e antes do commit.');
  P('');
  P(`- **Slug (imutavel):** \`${slug}\``);
  P(`- **Versao:** \`${header.version}\` (readme Stable tag: \`${readme.stableTag || '-'}\`)`);
  P(`- **Text domain:** \`${header.textDomain}\``);
  P(`- **Requisitos:** WordPress ${readme.requiresAtLeast || '?'}+ (testado ate ${readme.testedUpTo || '?'}), PHP ${header.requiresPhp || readme.requiresPhp || '?'}+`);
  P(`- **Gerado em:** ${new Date().toISOString().slice(0, 10)}`);
  P('');
  P('> :warning: O slug, o nome da pasta raiz e o nome do arquivo principal sao a identidade do plugin');
  P('> para o WordPress e para o updater. Alterar qualquer um deles quebra a atualizacao em todas as');
  P('> instalacoes existentes. **Nunca mude.**');
  P('');

  // --- Arvore -------------------------------------------------------------
  P('## 1. Estrutura de arquivos');
  P('');
  P('```');
  P(`${slug}/`);
  const byDir = {};
  for (const f of files) {
    const rel = path.relative(dir, f).replace(/\\/g, '/');
    const d = path.dirname(rel);
    (byDir[d] = byDir[d] || []).push(path.basename(rel));
  }
  const dirs = Object.keys(byDir).sort((a, b) => (a === '.' ? -1 : b === '.' ? 1 : a.localeCompare(b)));
  for (const d of dirs) {
    if (d !== '.') P(`  ${d}/`);
    for (const f of byDir[d].sort()) P(`${d === '.' ? '  ' : '    '}${f}`);
  }
  P('```');
  P('');
  P(table(
    ['Arquivo', 'Linhas', 'Classes', 'Metodos', 'Hooks'],
    analyses.map(a => [
      `\`${a.rel}\``,
      a.lines,
      a.classes.length ? a.classes.map(c => `\`${c}\``).join(', ') : '-',
      a.methods.length || '-',
      a.hooks.length || '-',
    ])
  ));

  // --- Grafo de carregamento ---------------------------------------------
  P('## 2. Grafo de carregamento');
  P('');
  P('```');
  P(`WordPress carrega ${slug}/${slug}.php`);
  P('  |');
  P('  +-- define() das constantes MLDPP_*');
  P('  +-- require includes/class-ml-duplicate-posts-pages.php');
  P('  +-- require includes/class-mldpp-github-updater.php');
  P('  +-- mldpp_bootstrap() ---> MLDPP\\Plugin::instance()  (singleton, registra todos os hooks)');
  P('  +-- MLDPP\\GitHub_Updater::init()                     (registra os hooks do updater)');
  P('  +-- register_activation_hook -> MLDPP\\Plugin::activate()');
  P('```');
  P('');

  // --- Constantes ---------------------------------------------------------
  P('## 3. Constantes');
  P('');
  P(table(['Constante', 'Valor'], header.constants.map(c => [`\`${c.name}\``, `\`${c.value}\``])));

  // --- Hooks --------------------------------------------------------------
  P('## 4. Hooks registrados');
  P('');
  P('### Actions');
  P('');
  P(table(
    ['Hook', 'Callback', 'Prio', 'Arquivo'],
    allHooks.filter(h => h.kind === 'action').map(h => [`\`${h.hook}\``, `\`${h.callback}()\``, h.priority, `\`${path.basename(h.file)}\``])
  ));
  P('### Filters');
  P('');
  P(table(
    ['Hook', 'Callback', 'Prio', 'Arquivo'],
    allHooks.filter(h => h.kind === 'filter').map(h => [`\`${h.hook}\``, `\`${h.callback}()\``, h.priority, `\`${path.basename(h.file)}\``])
  ));

  const dynamic = [
    ['`bulk_actions-edit-{post_type}`', '`register_bulk_action()`', 'filter', 'Registrado em runtime para cada post type habilitado'],
    ['`handle_bulk_actions-edit-{post_type}`', '`handle_native_bulk_action_redirect()`', 'filter', 'Registrado em runtime para cada post type habilitado'],
    ['`plugin_action_links_{basename}`', '`plugin_action_links()`', 'filter', 'Link "Configuracoes" na listagem de plugins'],
    ['`in_plugin_update_message-{basename}`', '`render_update_message()`', 'action', 'Notas da versao inline na tela de plugins'],
  ];
  P('### Hooks dinamicos');
  P('');
  P(table(['Hook', 'Callback', 'Tipo', 'Observacao'], dynamic));

  const custom = uniq(analyses.flatMap(a => a.customHooks));
  P('### Hooks de extensao expostos pelo plugin');
  P('');
  P(table(['Hook', 'Assinatura'], custom.map(h => [`\`${h}\``, '`$new_post_id, $source_post_id, $source_post`'])));

  // --- Fluxo --------------------------------------------------------------
  P('## 5. Fluxo de duplicacao');
  P('');
  P('```');
  P('  [Acao rapida "Duplicar"]   [Acao em massa]   [Botao no editor]   [Admin bar]');
  P('              |                     |                  |                |');
  P('              +---------------------+------------------+----------------+');
  P('                                    |');
  P('                    admin.php?action=mldpp_duplicate_post&post=ID');
  P('                                    |  (nonce mldpp_duplicate_post_{ID})');
  P('                                    v');
  P('                          handle_duplicate_request()');
  P('                                    |');
  P('                                    v');
  P('                            duplicate_post($post_id)');
  P('                                    |');
  P('        +---------------------------+---------------------------+');
  P('        |                           |                           |');
  P('        v                           v                           v');
  P('  guard de permissao        generate_versioned_slug()      wp_insert_post()');
  P('  - post type habilitado             |                           |');
  P('  - current_user_can(edit_post)      |                           v');
  P('  - roles_allowed                    |                    copia condicional:');
  P('                                     |                    - template');
  P('                                     |                    - imagem destacada');
  P('                                     |                    - taxonomias');
  P('                                     |                    - meta (com wp_slash)');
  P('                                     |                    - comentarios');
  P('                                     v                           |');
  P('                    get_duplicate_slug_base($post)               v');
  P('                       = $post->post_name  <-- REGRA             meta de rastreio:');
  P('                                     |                    _mldpp_source_post');
  P('                                     v                    _mldpp_slug_base');
  P('                    strip_slug_tokens() (prefixo/sufixo)  _mldpp_duplicated_at');
  P('                                     |                    _mldpp_duplicated_by');
  P('                                     v                           |');
  P('              modo last_numeric ? increment_last_numeric_token() v');
  P('                                : build_with_progressive_number()  write_log()');
  P('                                     |                           |');
  P('                                     v                           v');
  P('                             compose_slug()          do_action(mldpp_after_duplicate_post)');
  P('                                     |                           |');
  P('                                     v                           v');
  P('                      duplicate_slug_exists()?           redirect -> post.php?action=edit');
  P('                      (loop, max 1000 tentativas)');
  P('```');
  P('');

  // --- Regras de slug -----------------------------------------------------
  P('## 6. Regras de versionamento de slug');
  P('');
  P('**Base = o slug atual do conteudo escolhido.** Nao o do post raiz, nao um meta congelado.');
  P('');
  P(table(
    ['Slug escolhido', 'Modo', 'Resultado'],
    [
      ['`pagina-205`', '`last_numeric`', '`pagina-206`'],
      ['`pagina-206` (a copia)', '`last_numeric`', '`pagina-207`'],
      ['`205`', '`last_numeric`', '`206`'],
      ['`post-007`', '`last_numeric`', '`post-008` (preserva zero a esquerda)'],
      ['`pagina-15-historia`', '`last_numeric`', '`pagina-16-historia`'],
      ['`foo-2-bar-7-baz`', '`last_numeric`', '`foo-2-bar-8-baz` (so o ultimo bloco)'],
      ['`minha-pagina`', '`last_numeric`', '`minha-pagina-2` (fallback progressivo)'],
      ['`pagina-205`', '`append_suffix`', '`pagina-205-2`'],
      ['`pagina-205` + prefixo `copy-of`', '`last_numeric`', '`copy-of-pagina-206`'],
      ['`copy-of-pagina-206` + prefixo `copy-of`', '`last_numeric`', '`copy-of-pagina-207` (nao acumula)'],
    ]
  ));
  P('Colisao e resolvida por `duplicate_slug_exists()`, que consulta `wp_posts` por');
  P('`post_name` + `post_type` (+ `post_parent` em tipos hierarquicos), ignorando `trash` e `auto-draft`.');
  P('');

  // --- Dados --------------------------------------------------------------
  P('## 7. Dados persistidos');
  P('');
  P('### Options');
  P('');
  P(table(['Option', 'Autoload', 'Conteudo'], [
    ['`mldpp_settings`', 'sim', 'Configuracoes do painel (post types, o que copiar, modo de slug, roles, limite de log)'],
    ['`mldpp_logs`', 'nao', 'Historico circular de duplicacoes (limitado por `log_limit`)'],
  ]));
  P('### Post meta');
  P('');
  P(table(['Meta key', 'Gravado em', 'Uso'], [
    ['`_mldpp_source_post`', 'copia', 'ID do conteudo de origem'],
    ['`_mldpp_slug_base`', 'copia', 'Base de slug usada (auditoria; fallback quando `post_name` esta vazio)'],
    ['`_mldpp_duplicated_at`', 'copia', 'Timestamp da duplicacao'],
    ['`_mldpp_duplicated_by`', 'copia', 'ID do usuario que duplicou'],
  ]));
  P('### Transients');
  P('');
  P(table(['Transient', 'TTL', 'Uso'], [
    ['`mldpp_github_release`', '6 horas', 'Cache da resposta da GitHub Releases API'],
  ]));
  P('Detectados no codigo: ' + (uniq(analyses.flatMap(a => [...a.options, ...a.meta, ...a.transients])).map(x => `\`${x}\``).join(', ') || '-'));
  P('');

  // --- Seguranca ----------------------------------------------------------
  P('## 8. Superficie de seguranca');
  P('');
  P(table(['Entrada', 'Autenticacao'], [
    ['`admin.php?action=mldpp_duplicate_post`', 'nonce `mldpp_duplicate_post_{ID}` + `current_user_can(edit_post)` + roles_allowed'],
    ['`admin.php?action=mldpp_force_check`', 'nonce `mldpp_force_check` + `current_user_can(update_plugins)`'],
    ['AJAX `mldpp_preview_slug`', 'nonce `mldpp_preview_slug` + roles_allowed + post type habilitado'],
    ['POST `mldpp_manual_bulk_submit`', 'nonce `mldpp_manual_bulk_nonce` + roles_allowed'],
    ['Acao em massa nativa', 'nonce do proprio WordPress + roles_allowed'],
    ['`register_setting` / `sanitize_settings`', '`manage_options` (capability do menu) + whitelist por campo'],
  ]));
  P('Capabilities usadas: ' + (uniq(analyses.flatMap(a => a.capabilities)).map(c => `\`${c}\``).join(', ') || '-'));
  P('');
  P('Consultas diretas ao banco usam `$wpdb->prepare()`. Toda saida passa por `esc_html`, `esc_attr`, `esc_url` ou `wp_kses_post`.');
  P('');

  // --- Updater ------------------------------------------------------------
  P('## 9. Ciclo do updater (GitHub -> WordPress)');
  P('');
  P('```');
  P('  WP cron / tela de plugins');
  P('        |');
  P('        v');
  P('  pre_set_site_transient_update_plugins -> GitHub_Updater::check_for_update()');
  P('        |');
  P('        v');
  P('  get_latest_release()  --cache 6h-->  transient mldpp_github_release');
  P('        |                                       ^');
  P('        |  cache miss                           |');
  P('        v                                       |');
  P('  GET api.github.com/repos/{owner}/{repo}/releases/latest');
  P('        |');
  P('        v');
  P('  tag_name "v1.3.0" -> version "1.3.0"');
  P('  find_release_package(): procura o asset por ordem de preferencia');
  P('     1. {slug}-{version}.zip');
  P('     2. {slug}.zip');
  P('     3. primeiro *.zip da release');
  P('        |');
  P('        v');
  P('  version_compare(remote, MLDPP_VERSION)');
  P('        |                        |');
  P('     remote > local          remote <= local');
  P('        |                        |');
  P('        v                        v');
  P('  $transient->response[]    $transient->no_update[]');
  P('  (WP oferece o update)     (WP mostra "atualizado" + toggle de auto-update)');
  P('```');
  P('');
  P('Diagnostico manual: barra de admin -> **ML Duplicate** -> "Verificar atualizacao agora"');
  P('ou "Diagnostico do updater" (`?mldpp_debug=1` no painel do plugin).');
  P('');

  // --- Release ------------------------------------------------------------
  P('## 10. Pipeline de release');
  P('');
  P('```');
  P('  git tag vX.Y.Z && git push origin vX.Y.Z');
  P('        |');
  P('        v');
  P('  .github/workflows/release.yml');
  P('        |');
  P('        +-- Setup PHP 7.4');
  P('        +-- Valida versao: header == MLDPP_VERSION == Stable tag == tag do git');
  P('        +-- php -l  em todos os .php');
  P('        +-- node --check  em todos os .js');
  P('        +-- Build ZIP: {slug}-{version}.zip com raiz unica {slug}/');
  P('        +-- Valida estrutura do ZIP:');
  P('        |     1. raiz unica {slug}/');
  P('        |     2. sem pasta dupla {slug}/{slug}/');
  P('        |     3. arquivo principal presente');
  P('        |     4. readme.txt e uninstall.php presentes');
  P('        |     5. sem __MACOSX, .DS_Store, .git, node_modules, vendor');
  P('        +-- SHA-256 + tamanho no summary');
  P('        +-- softprops/action-gh-release -> publica o asset');
  P('        |');
  P('        v');
  P('  GitHub Release com {slug}-{version}.zip');
  P('        |');
  P('        v');
  P('  GitHub_Updater encontra o asset -> WordPress oferece a atualizacao');
  P('```');
  P('');
  P('`workflow_dispatch` roda a validacao completa sem publicar (o ZIP fica como artifact).');
  P('');

  // --- Checklist ----------------------------------------------------------
  P('## 11. Checklist antes de cada release');
  P('');
  P('- [ ] Slug, pasta raiz e arquivo principal **inalterados**');
  P('- [ ] Versao sincronizada: header do plugin, `MLDPP_VERSION`, `Stable tag` do readme.txt');
  P('- [ ] Changelog do readme.txt atualizado com a nova versao');
  P('- [ ] `php -l` (ou equivalente) sem erros em todos os arquivos');
  P('- [ ] `node --check` sem erros em todos os JS');
  P('- [ ] `node tools/graphify.js` regerado e commitado');
  P('- [ ] Nenhuma funcionalidade anterior removida (sem downgrade)');
  P('- [ ] ZIP com raiz unica, sem pasta dupla e sem artefatos de desenvolvimento');
  P('- [ ] Tag `vX.Y.Z` batendo exatamente com a versao do plugin');
  P('- [ ] Release publicada com o asset `{slug}-{version}.zip`');
  P('');

  // --- Indice de metodos --------------------------------------------------
  P('## 12. Indice de metodos');
  P('');
  for (const a of analyses) {
    if (!a.methods.length) continue;
    P(`### \`${a.rel}\`${a.classes.length ? ` - \`${a.namespace ? a.namespace + '\\\\' : ''}${a.classes[0]}\`` : ''}`);
    P('');
    P(table(
      ['Metodo', 'Visibilidade', 'Linha'],
      a.methods.map(m => [`\`${m.name}()\``, m.visibility + (m.static ? ' static' : ''), m.line])
    ));
  }

  P('---');
  P('');
  P('_Regere este arquivo com `node tools/graphify.js` sempre que o plugin mudar._');
  P('');

  return out.join('\n');
}

// ---------------------------------------------------------------------------

function main() {
  const args = process.argv.slice(2);
  const content = render();

  if (args.includes('--stdout')) {
    process.stdout.write(content);
    return;
  }

  if (args.includes('--check')) {
    const current = fs.existsSync(OUTPUT) ? fs.readFileSync(OUTPUT, 'utf8') : '';
    const strip = s => s.replace(/^- \*\*Gerado em:\*\*.*$/m, '');
    if (strip(current) !== strip(content)) {
      console.error('GRAPHIFY.md esta desatualizado. Rode: node tools/graphify.js');
      process.exit(1);
    }
    console.log('GRAPHIFY.md esta atualizado.');
    return;
  }

  fs.writeFileSync(OUTPUT, content, 'utf8');
  console.log(`GRAPHIFY.md gerado (${content.split('\n').length} linhas) em ${OUTPUT}`);
}

main();
