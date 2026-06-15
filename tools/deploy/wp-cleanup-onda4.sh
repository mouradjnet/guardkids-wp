#!/usr/bin/env bash
# Cleanup WP do guardiaokids.site — itens 6, 10 e 14 da auditoria.
#
# Os outros itens da Onda 4 (7-9 SEO meta, 11-13 footer/contato) vão
# ser resolvidos junto com a landing nova (Bucket 1 do checklist).
#
# Uso (no SSH do Hostinger):
#   ssh u217136411@82.25.73.253 -p 65002
#   cd ~/domains/guardiaokids.site/public_html
#   bash <(curl -s https://raw.githubusercontent.com/mouradjnet/guardkids-wp/master/tools/deploy/wp-cleanup-onda4.sh)
#
# Ou cole os comandos individualmente — todos são idempotentes.

set -e

echo "═══ Onda 4 — cleanup WP ═══"
echo

# ────────────────────────────────────────────────────────
# Item 14: apagar post "Hello world!" + sample page
# ────────────────────────────────────────────────────────
echo "[14] Removendo posts placeholder do WP…"
wp post delete $(wp post list --post_type=post --name=hello-world --field=ID --format=ids 2>/dev/null) --force 2>/dev/null && echo "  ✓ Post 'Hello world!' apagado" || echo "  · Post Hello world! já não existe"
wp post delete $(wp post list --post_type=page --name=sample-page --field=ID --format=ids 2>/dev/null) --force 2>/dev/null && echo "  ✓ Page 'Sample Page' apagada" || echo "  · Sample Page já não existe"

# ────────────────────────────────────────────────────────
# Item 6: meta description geral via Site Title + Tagline
# ────────────────────────────────────────────────────────
echo
echo "[6] Configurando Site Title e Tagline (refletem no <meta description> via tema)…"
wp option update blogname "GuardKids — Controle parental WP"
wp option update blogdescription "Ambiente seguro para crianças navegarem na internet com o controle dos pais. PWA instalável, regras de tempo de tela, sites permitidos e localização em tempo real."
echo "  ✓ Site Title: $(wp option get blogname)"
echo "  ✓ Tagline:    $(wp option get blogdescription)"

# Atualiza também o description no REST API root (atualmente vazio)
echo "  ✓ /wp-json/ description preenchido"

# ────────────────────────────────────────────────────────
# Item 10: site_icon (favicon) — exige uploaded media
# ────────────────────────────────────────────────────────
echo
echo "[10] Verificando favicon (site_icon)…"
CURRENT_ICON=$(wp option get site_icon 2>/dev/null || echo "0")
if [ "$CURRENT_ICON" = "0" ]; then
  echo "  ⚠ Sem favicon configurado. Pra resolver:"
  echo "    1) wp-admin → Aparência → Personalizar → Identidade do site → Ícone do site"
  echo "    2) Subir PNG quadrado 512x512+"
  echo "    3) ou via WP-CLI: wp media import /caminho/para/favicon.png && wp option update site_icon <ATTACHMENT_ID>"
else
  echo "  ✓ Já configurado (attachment ID $CURRENT_ICON)"
fi

# ────────────────────────────────────────────────────────
# Limpeza pós-update
# ────────────────────────────────────────────────────────
echo
echo "[housekeeping] Limpando caches…"
wp rewrite flush 2>/dev/null && echo "  ✓ Rewrite rules"
wp cache flush 2>/dev/null && echo "  ✓ Object cache"
wp sitemap regenerate 2>/dev/null 2>&1 || true

echo
echo "═══ Pronto. Validação rápida: ═══"
echo "  curl -s https://guardiaokids.site/wp-json/ | grep description"
echo "  curl -s https://guardiaokids.site/wp-sitemap-posts-post-1.xml"
echo
echo "Sitemap deve estar VAZIO (hello-world removido) ou só com páginas reais quando houver."
