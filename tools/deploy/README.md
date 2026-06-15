# Deploy artifacts — guardiaokids.site

Snippets prontos pra apply via SSH no servidor Hostinger.

## Pré-requisito

Acesso SSH: `u217136411@82.25.73.253:65002`.
WP root: `~/domains/guardiaokids.site/public_html`.

## Deploy do plugin (release)

Passo-a-passo pra subir uma nova versão do plugin (zip gerado por
`php scripts/build-release-zip.php`, que sai em
`~/OneDrive/Documentos/guardkids-wp/guardkids-wp-<versão>.zip`).

> **Migrations:** se a release **não** bumpa `GUARDKIDS_DB_VERSION`, não há schema
> novo — "substituir via WP Admin" é seguro mesmo sem disparar o activation hook.
> Se bumpou, confira `wp option get guardkids_db_version` após o deploy (o
> fallback de `on_init` roda a migration no primeiro request — ver
> `Plugin::maybeRunMigrations`).

### 1. Backup (obrigatório)

```bash
ssh u217136411@82.25.73.253 -p 65002
cd ~/domains/guardiaokids.site/public_html/wp-content/plugins
cp -r guardkids-wp guardkids-wp.bak-$(date +%Y%m%d-%H%M)
```

### 2. Subir o zip — escolha um caminho

**Caminho A — WP Admin:** Plugins → Adicionar novo → Enviar plugin → selecione o
zip → "Substituir o atual pelo enviado" (mantém ativado).

**Caminho B — SSH:**

```bash
# do seu lado, suba o zip via scp:
#   scp -P 65002 "<zip>" u217136411@82.25.73.253:~/domains/guardiaokids.site/public_html/wp-content/plugins/
cd ~/domains/guardiaokids.site/public_html/wp-content/plugins
rm -rf guardkids-wp                 # backup já feito no passo 1
unzip -q guardkids-wp-*.zip         # o zip já carrega o prefixo guardkids-wp/
rm guardkids-wp-*.zip
```

### 3. Verificar

```bash
cd ~/domains/guardiaokids.site/public_html
wp plugin get guardkids-wp --field=version    # confirma a versão nova

# Security headers nas respostas dinâmicas (6 esperados em HTTPS)
curl -sI https://guardiaokids.site/ | grep -iE 'strict-transport|content-security|x-content|x-frame|referrer|permissions'

# Smoke
curl -s -o /dev/null -w "home:%{http_code}\n" https://guardiaokids.site/
curl -s -o /dev/null -w "painel:%{http_code}\n" https://guardiaokids.site/painel-pais
```

Conferir também o **editor de blocos** no wp-admin (a CSP libera `unsafe-eval`
pro Gutenberg) — sem erros de CSP no console.

### 4. Rollback

```bash
cd ~/domains/guardiaokids.site/public_html/wp-content/plugins
rm -rf guardkids-wp
mv guardkids-wp.bak-YYYYMMDD-HHMM guardkids-wp
```

### 5. Limpeza (após validar, ~1 dia)

```bash
rm -rf ~/domains/guardiaokids.site/public_html/wp-content/plugins/guardkids-wp.bak-*
```

## 1) Security headers globais

Cobre itens 15–20 da auditoria do site público (HSTS / CSP / nosniff /
X-Frame-Options / Referrer-Policy / Permissions-Policy).

> **O plugin já aplica estes headers automaticamente** (`GuardKids\Security\SecurityHeaders`,
> via hook `send_headers`) em todas as respostas renderizadas pelo WordPress —
> não é mais necessário editar o `.htaccess` por SSH a cada ambiente.
>
> O snippet abaixo (`htaccess-security.txt`) continua útil como **reforço para
> assets estáticos** servidos direto pelo Apache (imagens/CSS/JS), que não
> passam pelo PHP e portanto não recebem os headers do plugin. Em servidor
> dedicado/Hostinger vale aplicá-lo; em hospedagem onde editar `.htaccess` não
> é viável, o plugin sozinho já cobre as respostas dinâmicas.

```bash
ssh u217136411@82.25.73.253 -p 65002
cd ~/domains/guardiaokids.site/public_html

# Backup do .htaccess atual
cp .htaccess .htaccess.bak-$(date +%Y%m%d)

# Prepend do snippet (cola o conteúdo de htaccess-security.txt antes de "# BEGIN WordPress")
# Opção A: editor
nano .htaccess
# Opção B: via curl puxando direto do repo (cole o bloco manualmente após o download)
curl -s https://raw.githubusercontent.com/mouradjnet/guardkids-wp/master/tools/deploy/htaccess-security.txt
```

### Verificar headers após o deploy

```bash
curl -sI https://guardiaokids.site/ | grep -iE 'strict-transport|content-security|x-content|x-frame|referrer|permissions'
```

Esperado: 6 headers nas respostas.

## 2) `security.txt` (responsible disclosure)

Item 42 da auditoria.

```bash
mkdir -p .well-known
cd .well-known
# Cole o conteúdo de security.txt deste diretório
nano security.txt

# Validar
curl -s https://guardiaokids.site/.well-known/security.txt
```

Esperado: 5 linhas (Contact, Expires, Preferred-Languages, Canonical, Policy).

## 3) Cleanup WP content (Onda 4 — itens 6, 10, 14)

Apaga "Hello world!" + sample page, configura Site Title/Tagline (refletem em
`<meta description>` via tema), prepara favicon.

Os outros itens da Onda 4 (7-9 SEO meta, 11-13 footer/contato) ficam pra
serem resolvidos junto com a landing real, porque dependem do tema novo.

```bash
ssh u217136411@82.25.73.253 -p 65002
cd ~/domains/guardiaokids.site/public_html
bash <(curl -s https://raw.githubusercontent.com/mouradjnet/guardkids-wp/master/tools/deploy/wp-cleanup-onda4.sh)
```

Ou cole os comandos do script diretamente — todos idempotentes.

## 4) Rollback

```bash
# Se algo quebrar, reverter o .htaccess
cd ~/domains/guardiaokids.site/public_html
mv .htaccess.bak-YYYYMMDD .htaccess
```
