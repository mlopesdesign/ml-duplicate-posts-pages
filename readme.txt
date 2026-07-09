=== ML Duplicate Posts & Pages ===
Contributors: mlopesdesign
Tags: duplicate, duplicate post, duplicate page, cpt
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Duplicação profissional de posts, páginas e CPTs com painel administrativo, ações rápidas, lote e logs.

== Description ==

ML Duplicate Posts & Pages permite duplicar conteúdos do WordPress com mais controle:

* Duplicar posts e páginas
* Suporte a custom post types habilitáveis
* Ação rápida "Duplicar"
* Ação em massa "Duplicar"
* Botão no editor do conteúdo
* Escolha do que copiar: imagem destacada, taxonomias, metadados, comentários, autor, template e ordem
* Título original preservado na duplicação
* Slug versionado automaticamente na cópia
* Status da nova cópia
* Logs de duplicação
* Painel administrativo clean no padrão ML
* Atualização automática via release do GitHub

== Installation ==

1. Envie a pasta do plugin para `/wp-content/plugins/`
2. Ative o plugin em **Plugins**
3. Acesse **ML Duplicate** no painel
4. Configure os tipos de conteúdo e opções de cópia

== Changelog ==

= 1.1.2 =
* Adicionado: arquivo uninstall.php para limpeza de opcoes e transients quando o plugin e desinstalado pelo WordPress.
* Adicionado: rotina de verificacao de atualizacao registrada no hook in_plugin_update_message para sinalizar a nova versao disponivel no painel de plugins.
* Adicionado: link "Ver changelog completo" no bloco de descricao do plugin.
* Corrigido: a versao interna (MLDPP_VERSION), o cabecalho do plugin e a Stable tag estao sincronizadas em 1.1.2.
* Corrigido: o updater via GitHub Releases aceita corretamente o asset cujo nome segue o padrao slug-versao.zip.
* Compatibilidade: revisada a declaracao Tested up to para 6.8 mantendo o minimo em 5.8 e PHP 7.4.
* Documentacao: changelog e descricao atualizados refletindo o fluxo de duplicacao, painel e atualizacao automatica.

= 1.0.2 =
* Corrigido: a duplicação preserva exatamente o título original, sem adicionar "Cópia" nem qualquer prefixo/sufixo.
* Corrigido: quando o slug termina com número, o número final é incrementado (ex.: samba-2-guimaraes-212 → samba-2-guimaraes-213) em vez de adicionar sufixo -2.
* Mantido: slugs sem número final continuam recebendo numeração progressiva (ex.: minha-pagina → minha-pagina-2).
* Ajustado: painel administrativo descreve as regras de preservação de título e versionamento de slug.
* Sincronizado: versão interna, cabeçalho do plugin e Stable tag definidos como 1.0.2.
* Updater via GitHub Releases apontando para mlopesdesign/ml-duplicate-posts-pages.

= 1.0.0 =
* Primeira versão
