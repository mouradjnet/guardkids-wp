# Deploy artifacts — guardiaokids.site

Snippets prontos pra apply via SSH no servidor Hostinger.

## Pré-requisito

Acesso SSH: `u217136411@82.25.73.253:65002`.
WP root: `~/domains/guardiaokids.site/public_html`.

## 1) Security headers globais

Cobre itens 15–20 da auditoria do site público (HSTS / CSP / nosniff /
X-Frame-Options / Referrer-Policy / Permissions-Policy).

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

## 3) Rollback

```bash
# Se algo quebrar, reverter o .htaccess
cd ~/domains/guardiaokids.site/public_html
mv .htaccess.bak-YYYYMMDD .htaccess
```
