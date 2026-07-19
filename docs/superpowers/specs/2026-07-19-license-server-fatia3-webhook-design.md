# License Server GuardKids — Fatia 3: Webhooks de venda (emissão automática, multi-plataforma)

**Data:** 2026-07-19
**Módulo:** Plugin `guardkids-license-server` (servidor) — **só o servidor**
**Repo:** `guardkids-license-server` (existente; fatias 1 e 2 em produção em `licencas.guardiaokids.site`, v1.1.0)
**Cliente (`guardkids-wp`):** **sem mudança** — já consome `GET /gkl/v1/revoked` desde a fatia 1
**DB servidor:** sem migração de schema — CPT `gkl_code` da fatia 2 ganha 2 meta + 1 status novo

## Problema

A fatia 2 tirou o dev do loop do **resgate**: o cliente ativa sozinho na `/ativar` com
código + e-mail + domínio. Mas a **emissão** do código ainda é manual — o dev roda
`wp gkl issue-code --email=...` a cada venda e manda o código à mão. O funil não fecha
sozinho: **compra → (dev no meio) → código → cliente ativa**.

Esta fatia fecha o funil: a plataforma de venda chama um **webhook** na compra, e o código é
emitido e enviado por e-mail **automaticamente**. O dev sai do fluxo de emissão. A ponte já
estava desenhada na fatia 2: o webhook chama a mesma porta que a CLI — `ActivationCodeIssuer::issue()`.

Duas exigências moldam o design:

1. **Dois planos, o mensal recorrente.** O GuardKids Premium terá mensal (R$ 29,90) e anual
   (R$ 299,00). A chave GuardKids é **offline com `exp` embutido**; estender a validade a cada
   ciclo exigiria re-cunhar a chave e o cliente re-colar — UX inaceitável. Solução: reusar o
   phone-home diário que o cliente já faz no `/revoked` e gerir a assinatura **por revogação**.

2. **Várias plataformas de venda.** O alvo inclui Hotmart, Eduzz, Cakto, Kiwify (e, no futuro,
   Monetizze, Ticto, Spark/Hero, PerfectPay, Lastlink, Braip, Pepper). Cada uma autentica o
   webhook e formata o payload de um jeito diferente — mas a **lógica de negócio é a mesma**.
   O design isola o específico-de-plataforma numa **fronteira de adapter** fina, deixando o
   núcleo agnóstico. **Esta fatia implementa o núcleo + o adapter Hotmart** (validável agora);
   Eduzz/Cakto/Kiwify entram depois, cada um como um adapter isolado validado com webhook real.

## Decisões (fechadas no brainstorming)

1. **Fronteira de adapter, núcleo agnóstico.** A lógica (emitir código, revogar, agendar,
   des-revogar) mora num `SubscriptionService` que só entende **eventos normalizados**
   (`purchase / refund / chargeback / cancel / reactivate`). Cada plataforma tem um **adapter**
   que faz duas coisas: `verify()` (autentica o webhook dela) e `parse()` (traduz o payload dela
   pro evento normalizado). Adicionar uma plataforma = **um arquivo de adapter**, sem tocar no
   núcleo nem nos testes dele.

2. **Dois planos geridos por revogação.** Mensal e anual emitem código com `exp` **teto**
   generoso (default 400 dias) — não a validade real. Enquanto o cliente paga, a licença fica
   **não-revogada**. Cancelou / reembolsou / chargeback → o webhook **revoga** (cai no
   `/revoked`). Voltou a pagar → **des-revoga**. O cliente ativa **uma vez** e nunca re-cola
   chave. O `exp` teto é só rede de segurança se a plataforma falhar em mandar um evento.

3. **Timing de revogação distingue cancelamento de reembolso.**
   - **Reembolso / chargeback** → revoga **imediato** (o dinheiro voltou).
   - **Cancelamento de assinatura** → revoga no **fim do ciclo já pago** (agenda `revoke_at`;
     um cron diário materializa).

4. **Correlação plataforma ↔ código pelo `subscription_id`.** O `gkl_code` guarda o
   `subscription_id`. O webhook de cancelamento/reativação acha o código certo, e a **compra
   distingue 1ª venda (emite código) de reativação (des-revoga, não emite 2º código)**.

5. **Autenticação e idempotência por plataforma.** Cada adapter valida a assinatura da sua
   plataforma com um secret próprio (`GKL_<PLATAFORMA>_WEBHOOK_SECRET`, wp-config, fora do git).
   Idempotência por **`event_id`** (dedup transient; fallback = hash do body) — comum a todas.

6. **Reuso máximo das fatias 1 e 2.** `ActivationCodeIssuer::issue()`, `CodeRepository`,
   `LicenseRepository::revoke/unrevoke`, `RateLimiter` e `/revoked` reusados. O webhook **não
   cunha licença** (não tem o domínio) — só emite o código.

## Arquitetura

Tudo no servidor de licenças, plugin `guardkids-license-server`. **Nenhuma mudança no cliente.**

```
POST /gkl/v1/webhook/{plataforma}
   → WebhookController
       → AdapterRegistry: acha o adapter do slug (404 se desconhecido)
       → adapter.verify(request, secret)        // auth da plataforma → 401
       → dedup por event_id (transient)          // replay → 200 {dedup}
       → adapter.parse(body) : ?NormalizedEvent  // não-JSON → 400; sem match → 200 {ignored}
       → SubscriptionService.handle(event)       // lógica agnóstica
```

**Componentes novos:**

| Componente | Papel |
|---|---|
| `NormalizedEvent` | Struct: `type` (`purchase`/`refund`/`chargeback`/`cancel`/`reactivate`), `email`, `plan_key`, `subscription_id`, `event_id`, `cycle_end_at?` (ts, só em `cancel`). |
| `PlatformAdapter` (interface) | `verify(WP_REST_Request, string $secret): bool` + `parse(array $body): ?NormalizedEvent`. |
| `AdapterRegistry` | slug de plataforma → `[adapter, constante-do-secret]`. Slug desconhecido → 404. |
| `SubscriptionService` | Núcleo agnóstico: consome `NormalizedEvent` e executa os fluxos A/B/C (abaixo). |
| `Api\WebhookController` | Rota `POST /gkl/v1/webhook/{platform}`. Orquestra registry → verify → dedup → parse → service. Rate-limited. |
| `HotmartAdapter` | `verify` HMAC-SHA256 `x-hotmart-signature`; `parse` payload Hotmart → `NormalizedEvent`; mapa `product.id → plan_key`. |
| Cron `gkl_process_scheduled_revocations` | Diário: revoga `gkl_code` com `revoke_at <= now` e licença ainda ativa. Materializa o "fim do ciclo". |

**Config central de planos** (agnóstica de plataforma), por `plan_key`:
```
PLANS = ['premium' => ['exp_days' => 400, 'max' => 3]]
```
Cada adapter mapeia o `product.id` **da sua plataforma** → `plan_key`. A política (exp teto, max)
é central. Mensal e anual mapeiam pra `premium` (a validade real é por revogação; `max=3`
re-ativações vale pros dois).

**Reusados sem mudança:** `LicenseRepository` (revoke/lookup), `RateLimiter`,
`GET /gkl/v1/revoked`, `Signer`, `LicenseIssuer` (indireto via `ActivationCodeIssuer`).

**Estendidos (mudanças cirúrgicas nas fatias 1 e 2, cada uma com teste):**
- `LicenseRepository::unrevoke(jti)` — método **novo** (`gkl_revoked → gkl_active`). A fatia 1
  des-revogava na mão via `wp eval`; a reativação automática (Fluxo C) precisa do método.
- `gkl_code` ganha meta `subscription_id` (string) e `revoke_at` (int|null).
- Status novo `gkl_code_disabled` (código reembolsado **antes** do resgate — não ativa mais).
- `ActivationCodeIssuer::issue()` passa a aceitar `subscription_id` e `exp_days` (hoje já recebe
  `durationDays`).
- `CodeRepository::findBySubscriptionId()`.
- `ActivationService::activate()` passa a exigir `status === 'gkl_code_open'` **explícito** — hoje
  só rejeita `used`/esgotado; sem isso um `gkl_code_disabled` seria ativável (corrigido junto).

## Modelo de dados — meta novas no `gkl_code`

| Meta | Tipo | Papel |
|---|---|---|
| `subscription_id` | string | id da assinatura na plataforma — correlação; `''` se emitido via CLI/admin |
| `revoke_at` | int\|null | timestamp agendado de revogação (fim do ciclo); `0`/ausente = sem agendamento |

Status do `gkl_code`: `gkl_code_open` · `gkl_code_used` (esgotou) · **`gkl_code_disabled`**
(reembolso/chargeback antes do resgate — bloqueado).

## Fluxos (no `SubscriptionService`, em termos de evento normalizado)

### Fluxo A — `purchase`
1. `subscription_id` já tem `gkl_code`?
   - **Sim** → reativação: executa **Fluxo C** (des-revoga), **não** emite 2º código.
   - **Não** → `ActivationCodeIssuer::issue(email, PLANS[plan_key].exp_days, PLANS[plan_key].max, 'premium', subscription_id)`.
2. E-mail com o **código** + link `https://licencas.guardiaokids.site/ativar/` (não a chave).
3. Retorna `{issued:true, code_id}`.

### Fluxo B — `refund` / `chargeback` / `cancel`
Acha o `gkl_code` por `subscription_id` (não achou → `{ignored, reason:not_found}`).
- **`refund` / `chargeback`** → imediato:
  - resgatado (`current_jti != ''`) → `LicenseRepository::revoke(current_jti)` (cai no `/revoked`).
  - não resgatado → `post_status` → `gkl_code_disabled`.
- **`cancel`** → fim do ciclo: grava `revoke_at = event.cycle_end_at`; **não** revoga agora.

### Fluxo C — Reativação
Acionado por **dois gatilhos** com a mesma rotina: um `purchase` de `subscription_id` já
conhecida (detectado no Fluxo A) **ou** um `reactivate` explícito. O HotmartAdapter **não**
emite `reactivate` — reativa via `purchase` recorrente; o tipo `reactivate` fica disponível pras
plataformas futuras que tenham um evento próprio de reativação de assinatura.
Do `gkl_code` do `subscription_id`:
- `current_jti` revogado → `LicenseRepository::unrevoke(current_jti)` (`gkl_revoked → gkl_active`).
- limpa `revoke_at` (cancela agendamento pendente).
- se `gkl_code_disabled` → volta `gkl_code_open`.

### Cron `gkl_process_scheduled_revocations` (diário)
Varre `gkl_code` com `revoke_at > 0 && revoke_at <= now` e `current_jti` ativo →
`revoke(current_jti)`; zera `revoke_at`. Preserva os de `revoke_at` futuro.

## Adapter Hotmart (o único implementado nesta fatia)

- **`verify`**: `hash_equals(hash_hmac('sha256', rawBody, secret), header 'x-hotmart-signature')`.
  Secret em `GKL_HOTMART_WEBHOOK_SECRET`. Secret ou header vazio → falha.
- **`parse`**: lê `event` + `data`; traduz:
  - `PURCHASE_COMPLETE` / `PURCHASE_APPROVED` → `purchase`
  - `PURCHASE_REFUNDED` → `refund` · `CHARGEBACK` → `chargeback`
  - `SUBSCRIPTION_CANCELLATION` → `cancel` (`cycle_end_at` = `data.subscription.date_next_charge`)
  - outros → `null` (ignora)
  - campos: `email=data.buyer.email`, `subscription_id=data.subscription.id`,
    `event_id=data.id`, `plan_key = PRODUCT_PLAN_MAP[data.product.id] ?? null` (fora do mapa → ignora)

*(O fork `fluxomestre-license-server` fazia HMAC mas lia do header errado `X-Hotmart-Hottok`;
aqui usa o `x-hotmart-signature` do webhook 2.0.)*

## Segurança

- **Assinatura por plataforma** obrigatória; secret em constante do wp-config, `hash_equals`.
- **Rate-limit** reusado por IP.
- **Idempotência** por `event_id`.
- **Allowlist de `product.id`** no adapter — só processa produtos mapeados.
- **Rota pública por design**, mas só age com assinatura válida. Emissão manual (`issue-code`)
  segue CLI/admin autenticado.
- Slug de plataforma desconhecido → **404** (não vaza rota).

## Tratamento de erros

| Situação | Resposta |
|---|---|
| Slug de plataforma desconhecido | **404** |
| Assinatura ausente/inválida ou secret vazio | **401** `invalid_signature` |
| Body não-JSON | **400** `invalid_body` |
| Evento/produto sem match no adapter (`parse` → null) | **200** `{ok:true, ignored:true}` |
| `subscription_id` não encontrado (cancel/reactivate) | **200** `{ok:true, ignored:true, reason}` |
| Replay (event_id repetido) | **200** `{ok:true, dedup:true}` |
| Falha ao emitir código (`issue` lança) | **500** — deixa a plataforma re-tentar |

## Critérios de sucesso (verificáveis)

Harness standalone `tests/run.php` (como as fatias 1 e 2):

**Núcleo (`SubscriptionService`, agnóstico — testado com eventos normalizados):**
- `purchase` (1ª): emite `gkl_code` com `exp_days`/`max` do plano, guarda `subscription_id`,
  dispara e-mail com o **código**, e **não** cunha `gkl_license`.
- `purchase`/`reactivate` de subscription conhecida: des-revoga; **não** emite 2º código.
- `refund`/`chargeback` resgatado: revoga `current_jti` (aparece no `/revoked`).
- `refund`/`chargeback` não-resgatado: código vira `gkl_code_disabled` e **deixa de ativar**.
- `cancel`: grava `revoke_at`, **não** revoga imediato.
- Cron: revoga `revoke_at` vencido; preserva futuro.

**Adapter Hotmart:**
- `verify`: HMAC válido passa; inválido → false; secret vazio → false.
- `parse`: cada evento Hotmart → o tipo normalizado certo; produto fora do `PRODUCT_PLAN_MAP` → null.

**Controller/registry:**
- Idempotência: mesmo `event_id` 2x → **1** código (2ª = dedup).
- Slug desconhecido → 404; assinatura inválida → 401.

## Fora de escopo (fatias seguintes)

- **Adapters Eduzz, Cakto, Kiwify** — próxima leva, cada um: doc do webhook + secret + `parse`
  + `verify` + validação com evento real. A fronteira já os comporta sem tocar no núcleo.
- **Monetizze, Ticto, Spark/Hero, PerfectPay, Lastlink, Braip, Pepper** — depois, mesma fronteira.
- **Checkout / página de compra.**
- **Grace period fino / dunning** multi-tentativa (cobrança falha definitiva cai como cancel/refund).
- **Troca de plano** (`SWITCH_PLAN` mensal↔anual).
- **Qualquer mudança no cliente `guardkids-wp`.**
