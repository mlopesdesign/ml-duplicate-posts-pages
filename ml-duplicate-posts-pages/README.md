# ML Duplicate Posts & Pages

Plugin comercial WordPress para duplicacao profissional de posts, paginas e CPTs
com controle granular do que copiar, acao rapida, acao em massa, logs e painel
administrativo no padrao ML.

## Ultima release

**v1.3.0** - correcao critica do versionamento de slug (a base passa a ser o slug do
conteudo escolhido), botao da admin bar reativado, acao em massa para todos os CPTs
habilitados, prefixo/sufixo sem acumulo, `wp_slash` nos metadados copiados e CI com
validacao de versao e de estrutura do ZIP.

Veja a release: https://github.com/mlopesdesign/ml-duplicate-posts-pages/releases/tag/v1.3.0

## Mapa tecnico

O arquivo [GRAPHIFY.md](../GRAPHIFY.md) na raiz do repositorio contem o mapa tecnico
completo e gerado automaticamente: estrutura de arquivos, grafo de carregamento, todos
os hooks, fluxo de duplicacao, regras de slug, dados persistidos, superficie de
seguranca, ciclo do updater e pipeline de release.

Regere apos qualquer alteracao no plugin:

```bash
node tools/graphify.js          # regera o GRAPHIFY.md
node tools/graphify.js --check  # falha se estiver desatualizado (rodado no CI)
```

## Recursos (v1.3.x)

- Duplicacao de posts, paginas e custom post types
- Acao rapida "Duplicar" na listagem
- Acao em massa nativa do WordPress
- Botao no editor classico e item no admin bar
- Duplicacao manual em lote com filtros (tipo, status, busca)
- Escolha granular: imagem destacada, taxonomias, meta, comentarios, autor,
  template, ordem de menu
- Preservacao do titulo original
- **Versionamento inteligente de slug** com deteccao do ULTIMO bloco numerico:
  - `samba-2-guimaraes-215` -> `samba-2-guimaraes-216`
  - `pagina-15-historia` -> `pagina-16-historia`
  - `foo-2-bar-7-baz` -> `foo-2-bar-8-baz`
  - `post-007` -> `post-008` (preservando zero a esquerda)
- **Modo alternativo** `append_suffix` (sempre sufixo `-2`) para quem prefere o
  comportamento tradicional
- **Tokens customizados** `slug_prefix` e `slug_suffix` (ex.: `copy-of-{slug}`,
  `{slug}-copy`, `cloned-{slug}-v2`)
- **Preview via AJAX** com tooltip no botao do editor e no admin bar
- **Coluna "Slug gerado"** na tabela de logs
- **Help tabs** no painel admin (Como usar / Slug / Compatibilidade)
- **Arquivo `.pot`** pronto para traducoes
- Painel administrativo com hero, cards e tabela de logs
- Atualizacao automatica via GitHub Releases
- `uninstall.php` para limpeza completa na desinstalacao

## Estrutura do repositorio

```
ml-duplicate-posts-pages/
├── ml-duplicate-posts-pages.php
├── readme.txt
├── uninstall.php
├── README.md
├── assets/
│   ├── css/admin.css
│   └── js/admin.js
├── includes/
│   ├── class-ml-duplicate-posts-pages.php
│   └── class-mldpp-github-updater.php
└── languages/
    └── ml-duplicate-posts-pages.pot
```

## Instalacao local

1. Clone o repositorio
2. Comprima a pasta `ml-duplicate-posts-pages/` em `ml-duplicate-posts-pages-<versao>.zip`
3. Envie o ZIP via **Plugins > Adicionar novo > Enviar plugin**
4. Ative e configure em **ML Duplicate**

## Atualizacao automatica

O updater consulta `api.github.com/repos/mlopesdesign/ml-duplicate-posts-pages/releases/latest`
e prefere o asset cujo nome segue o padrao `ml-duplicate-posts-pages-<versao>.zip`.
A instalacao ocorre sem perder configuracoes.

## CI / Release

O workflow em `.github/workflows/release.yml` re-empacota automaticamente o source
em um ZIP padronizado a cada push de tag `v*`, valida sintaxe PHP/JS, computa
SHA-256 e publica como asset da GitHub Release (com `overwrite_files: true`).

### Permissoes do CI

O workflow usa `permissions: contents: write` no job e o `GITHUB_TOKEN` automatico
para criar/atualizar o asset. Em caso de erro `Resource not accessible by integration`
(o `GITHUB_TOKEN` automatico nao tem permissao de update de release existente),
configure um Personal Access Token (PAT) com escopo `contents: write` como secret
`GH_RELEASE_TOKEN` no repositorio. Para usar o PAT, troque a referencia
`secrets.GITHUB_TOKEN` por `secrets.GH_RELEASE_TOKEN` no step `Upload release asset`.