# ML Duplicate Posts & Pages

Plugin comercial WordPress para duplicacao profissional de posts, paginas e CPTs
com controle granular do que copiar, acao rapida, acao em massa, logs e painel
administrativo no padrao ML.

## Recursos

- Duplicacao de posts, paginas e custom post types
- Acao rapida "Duplicar" na listagem
- Acao em massa nativa do WordPress
- Botao no editor do conteudo
- Botao no admin bar
- Duplicacao manual em lote com filtros (tipo, status, busca)
- Escolha granular: imagem destacada, taxonomias, meta, comentarios, autor,
  template, ordem de menu
- Preservacao do titulo original
- Versionamento inteligente de slug (incrementa numero final quando existe)
- Logs de duplicacao com autor e timestamp
- Painel administrativo com hero, cards e tabela de logs
- Atualizacao automatica via GitHub Releases

## Estrutura do repositorio

```
ml-duplicate-posts-pages/
├── ml-duplicate-posts-pages.php
├── readme.txt
├── uninstall.php
├── assets/
│   ├── css/admin.css
│   └── js/admin.js
└── includes/
    ├── class-ml-duplicate-posts-pages.php
    └── class-mldpp-github-updater.php
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
em um ZIP padronizado a cada push de tag `v*`, computa SHA-256 e publica como
asset da GitHub Release.