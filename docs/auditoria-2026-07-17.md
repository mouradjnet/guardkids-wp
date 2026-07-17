# Auditoria — Estado Real do GuardKids WP

**Data:** 2026-07-17 · **Auditado:** v1.36.2 / DB v25 (produção) + branch `feat/revocation-cache` · **Baseline:** [auditoria de 2026-07-16](auditoria-2026-07-16.md) (v1.36.0, Health 7.4) · **Sistema:** 2F Quality Audit v2.0

---

## 1. Executive Summary

Desde a última auditoria (um dia antes), o app **melhorou de forma concreta e mensurável**: 5 dos 11 problemas foram fechados nas v1.36.1/v1.36.2, e o problema **nº 1 histórico — a ausência de license server — deixou de ser "não existe" e passou a "construído, aguardando deploy"**.

O veredito de fundo, porém, **não mudou**: *o app está pronto, o negócio ainda não fecha o funil*. O license server existe em código e passa nos testes (inclusive na prova de integração cruzada Signer↔Verifier), mas **não está deployado, nem mergeado, nem ligado a checkout/onboarding**. Trabalho feito ≠ valor realizado enquanto não estiver em produção.

---

## 2. Health Score (vs. 2026-07-16)

| Dimensão | Antes | Agora | Evidência da mudança |
|---|:-:|:-:|---|
| Arquitetura | 8.5 | **8.5** | `RestApi.php` (909) e `Settings.tsx` (902) seguem inchados |
| Código | 8.0 | **8.2** | mockData morto removido; menos duplicação |
| Banco | 9.0 | **9.0** | +migração 025 (normalização de domínios), disciplina mantida |
| Performance | 7.5 | **7.5** | sem mudança de fundo |
| Segurança | 9.0 | **9.0** | reverificado: zero SQL injection, 113 `permission_callback` |
| UX | 7.0 | **7.5** | erro de rede em PT-BR na raiz do `apiFetch`, cópias corrigidas |
| Escalabilidade | 7.5 | **7.5** | modelo 1-família/instalação, inalterado |
| **Comercialização** | 3.0 | **4.0** | license server **construído** — mas não deployado nem integrado |

**Health geral: 7.7** (era 7.4) · **Técnico: ~8.5**. O número mal se move de propósito: a comercialização só sobe de verdade quando o funil estiver **em produção**.

---

## 3. Estado dos 11 problemas da auditoria anterior

| # | Problema | Estado | Verificado |
|:-:|---|---|---|
| 1 | Não existe license server | 🟢 **Construído** (falta deploy/merge) | repo + branch, suítes 17/17 e 594/594, cross-verify 6/6 |
| 4 | Zona promete geofencing inexistente | 🟢 **Resolvido** | promessa removida; `Localizacao.tsx:254` "sem geofencing, o pai OLHA o mapa" |
| 6 | Validação de mime forjável | 🟢 **Resolvido** | `ContentController:286` rotulado "não é segurança" + `wp_check_filetype_and_ext` |
| 9 | `MutationError` duplicado | 🟢 **Resolvido** | extraído pra `components/MutationError.tsx` |
| 11 | `gmdate` no Notifier da criança | 🟢 **Resolvido** | zero `gmdate` no `Notifier.php` |
| 2 | Mutations sem `onError` | 🟠 **Melhorou, persiste** | **72 mutations × 20 onError** (era 83×19); fix da raiz do `apiFetch` mitiga erro de rede |
| 3 | `Upgrade.tsx` planFeatures hardcoded | 🟠 **Persiste** | `import { planFeatures } from '../data/mockData'` linha 3 |
| 5 | Sem onboarding | 🟠 **Persiste** | nenhum trabalho de wizard/demo |
| 7 | `RestApi.php` inchado | 🟡 **Persiste** | 909 linhas confirmadas |
| 8 | `Settings.tsx` god-file | 🟡 **Persiste** | 902 linhas confirmadas |
| 10 | Rotas públicas com padrão divergente | 🟢 **Persiste, seguro** | 6 rotas Companion, auth via token no header (documentada) |

**5 resolvidos · 1 construído (deploy pendente) · 3 persistem (médios) · 2 persistem (refactor/cosmético)**

---

## 4. O license server (foco da sessão)

| Aspecto | Estado |
|---|---|
| Código servidor (`guardkids-license-server`) | Completo, 8 commits, suíte **17/17** |
| Código cliente (branch `feat/revocation-cache`) | 3 commits, suíte **594/594** |
| Prova de integração | Signer↔Verifier cruzados **6/6** (sem deploy) |
| **Deployado / em produção** | **Não** |
| Funil de venda (checkout, self-service, onboarding) | **Não existe** (fatias 2/3, fora de escopo) |

**Leitura honesta:** a peça técnica de *emissão + revogação* está pronta e resolve o achado de segurança (a revogação era decorativa — `Gate.php` lia um wp_option local que ninguém escrevia). Mas sozinha ela **não vende** — falta o deploy e, depois, o checkout e o self-service de ativação. A comercialização sai de 3.0 pra 4.0, não pra 7.

---

## 5. Top riscos atuais

1. **Valor não realizado** — o maior trabalho da sessão (license server) está em branch/repo local. Merge + push + remote fecham esse risco em minutos.
2. **Falha silenciosa residual** — 52 mutations ainda sem `onError` explícito. Mitigado pela raiz do `apiFetch`, mas mutations destrutivas (excluir/revogar/licença) merecem tratamento explícito.
3. **Bus factor = 1** — inalterado. Todo o contexto (junctions, LocalWP, privkey em `~/.guardkids/issuer.key`) vive na cabeça do autor + memória.
4. **`GK_LICENSE_SERVER_BASE` é placeholder** — se a branch for pra prod sem trocar pelo domínio real, o cron bate num host inexistente (falha aberta, inofensivo, mas inútil).

---

## 6. Conclusão estratégica

- **Pronto para produção?** O app, sim (está lá, v1.36.2, 594 testes verdes). O license server, **quase** — falta deploy e smoke E2E.
- **O que impede vender hoje?** A mesma coisa de ontem, um passo adiante: antes faltava *construir* o license server; agora falta **deployá-lo e ligá-lo a um checkout**. O caminho "cliente quer pagar → cliente tem licença" ainda não existe ponta a ponta.
- **Corrigir imediatamente?** Nada quebrado. A ação de maior alavancagem é **consolidar o license server** (merge + push + deploy + smoke).
- **Maior risco?** Não é técnico: é o trabalho pronto ficar parado em branch local sem virar produção.
- **Maturidade:** Produto **maduro**; negócio saindo de **embrionário** para **em montagem** — a primeira peça do funil comercial existe pela primeira vez.

---

*Auditoria conduzida sobre o código em disco (fonte da verdade). Delta reverificado por leitura direta: SQL injection (zero — interpolações são só nomes de tabela/definições/IDs de teste), contagem de mutations/onError, mime, `gmdate`, tamanhos de arquivo e rotas públicas.*
