# License Server GuardKids — Fatia 2: Ativação self-service

**Data:** 2026-07-19
**Módulo:** Plugin `guardkids-license-server` (servidor) — **só o servidor**
**Repo:** `guardkids-license-server` (existente; núcleo/fatia 1 já em produção em `licencas.guardiaokids.site`)
**Cliente (`guardkids-wp`):** **sem mudança** — o cliente continua colando a chave no painel; nada muda no `Verifier`/`Gate`/`RevocationCache`
**DB servidor:** sem migração de schema — usa um CPT novo (`gkl_code`) + post meta

## Problema

A fatia 1 (núcleo) resolveu **emitir e revogar**: `wp gkl mint` cunha uma chave Ed25519
travada por domínio (`sub`), o CPT `gkl_license` registra, e `GET /gkl/v1/revoked` propaga
revogações pro cliente. Mas a emissão tem uma fricção estrutural: **a chave é travada no
domínio do cliente (`sub` vs `siteurl`), e no ato da venda não se sabe qual é o domínio.**

Hoje isso obriga um vai-e-vem manual: o cliente compra, você pergunta o domínio dele, roda
`wp gkl mint --domain=... --email=...`, e manda a chave. Cada venda passa pelo seu notebook.

Esta fatia tira você desse loop **sem** ainda integrar plataforma de pagamento (isso é a
fatia 3). A ideia: desacoplar **"vendeu"** de **"ativou"** com um **código de ativação**. Na
venda, emite-se um código (que carrega plano + duração, mas ainda **não** tem domínio). O
cliente vai a uma página, informa **código + e-mail da compra + o domínio dele**, e a chave é
cunhada na hora, travada no domínio que ele acabou de informar.

## Decisões (fechadas no brainstorming)

1. **Prova de compra = código de ativação de uso (re)utilizável.** Emitido na venda; carrega
   plano/duração; sem domínio até o resgate. Desacopla vender de ativar. A fatia 3 só vai
   automatizar a **emissão** do código (webhook → `ActivationCodeIssuer::issue()`).
2. **2º fator: e-mail da compra.** A página exige código **E** e-mail; só ativa se ambos
   baterem. Um código vazado sozinho não ativa. O e-mail já está no payload/CPT da licença.
3. **Re-ativação com limite (default 3).** Cliente erra domínio (staging vs prod, com/sem www)
   ou muda de site. Cada re-ativação cunha a chave pro novo domínio e **revoga a anterior**
   (o `/revoked` já propaga). O limite barra revenda pra múltiplos sites.
4. **Validade conta a partir da ativação.** O código guarda uma **duração** (ex.: 365 dias);
   `exp = instante da 1ª ativação + duração`. **Preservado** nas re-ativações — trocar de
   domínio não estende nem encurta a licença. Mais justo que "conta desde a emissão", já que
   vender e ativar acontecem em momentos diferentes.
5. **Código modelado como CPT próprio (`gkl_code`).** Fronteira limpa: código = direito,
   licença (`gkl_license`) = chave ativada. Reusa `LicenseIssuer::issue()` sem mudança, ganha a
   list table do admin de graça, e não polui o invariante "licença = chave assinada".
6. **Cunha-antes-de-revoga.** No resgate, a chave nova é cunhada **primeiro**; a anterior só é
   revogada **depois** que a cunhagem der certo. Se a cunhagem falha, o cliente nunca fica sem
   chave e o `gkl_code` fica intacto.

## Arquitetura

Tudo no servidor de licenças (`licencas.guardiaokids.site`).

**Componentes novos:**

| Componente | Papel |
|---|---|
| CPT `gkl_code` | O entitlement (código de ativação) — post meta guarda hash, e-mail, plano, duração, limites e o jti ativo |
| `ActivationCodeIssuer` | Cria códigos. Usado pela CLI/admin agora; pelo webhook na fatia 3 (mesma porta) |
| `ActivationService` | Valida código+e-mail, orquestra cunhagem/revogação via `LicenseIssuer`, atualiza o `gkl_code` |
| `ActivateController` | Rota pública `POST /gkl/v1/activate`, rate-limited |
| Shortcode `[gkl_activation_form]` | Form vanilla JS (código, e-mail, domínio) numa página `/ativar`; faz `fetch` no endpoint |
| CLI `wp gkl issue-code` + botão admin | Emissão interina de códigos (até a fatia 3) |

**Reusados sem mudança:** `LicenseIssuer::issue()`, `LicenseRepository` (revoke/lookup),
`Signer`, `RateLimiter`, endpoint `GET /gkl/v1/revoked`.

## Modelo de dados — CPT `gkl_code`

`register_post_type('gkl_code', ['public' => false, 'exclude_from_search' => true, ...])`,
mesmo padrão privado do `gkl_license`. Post meta:

| Meta | Tipo | Papel |
|---|---|---|
| `code_hash` | string | sha256 do código. O código em claro **nunca** é persistido (mesmo padrão dos child tokens do cliente) |
| `email` | string | e-mail da compra — 2º fator |
| `plan` | string | `premium` |
| `features` | list\|null | `null` = todas (`ALL_FEATURES`) |
| `duration_days` | int | ex.: 365 |
| `max_activations` | int | ex.: 3 |
| `activations_used` | int | contador |
| `activated_exp` | int\|null | timestamp; fixado na **1ª** ativação (`iat + duração`), preservado nas re-ativações; `null` enquanto não ativado |
| `current_jti` | string\|null | jti da chave ativa (pra revogar na re-ativação); `null` se nunca ativado |

Status registrados: `gkl_code_open` (ativável) e `gkl_code_used` (esgotou as ativações).

**Ciclo de vida:**

```
emitido (open, used=0, exp=null, jti=null)
  → 1ª ativação: cunha chave; used=1; activated_exp = now + duration; current_jti = jti_novo
  → re-ativação: cunha chave (novo domínio); revoga current_jti antigo; used++; mesmo activated_exp; current_jti = jti_novo
  → esgotado: used == max_activations → status = gkl_code_used (não ativa mais)
```

## Fluxo de ativação

1. Cliente recebe o código por e-mail na compra (você emite via CLI/admin).
2. Acessa `/ativar` no servidor de licenças.
3. Preenche **código + e-mail + domínio** (ex.: `https://guardiaokids.site`).
4. Form → `POST /gkl/v1/activate` com `{code, email, domain}`.
5. Servidor (`ActivateController` → `ActivationService`):
   1. RateLimiter por IP (reusa o do `/revoked`).
   2. Normaliza o domínio (`rtrim('/')`, valida formato `https?://host`).
   3. Acha o `gkl_code` por `code_hash`.
   4. Valida: existe **e** `email` bate **e** status `open` **e** `activations_used < max_activations`.
      A comparação do e-mail é **normalizada** (trim + `strtolower` nos dois lados) pra não
      falhar por maiúscula/espaço.
   5. Calcula `exp`: se `activated_exp` é `null` → `now + duration_days*86400`; senão preserva.
   6. **Cunha primeiro:** `LicenseIssuer::issue(email, domain, exp, plan, features, sendEmail=true)`
      → cria `gkl_license` travada no domínio + dispara o e-mail com a chave.
   7. **Revoga depois:** se `current_jti != null`, `LicenseRepository::revoke(current_jti)`.
   8. Atualiza o `gkl_code`: `current_jti = jti_novo`, `activations_used++`, fixa `activated_exp`,
      status → `gkl_code_used` se esgotou.
   9. Responde `{ ok: true, license_key, sub, exp }`.
6. A página mostra a chave (botão copiar) + instrução de colar no GuardKids → Licença; avisa que
   também foi enviada por e-mail.

## Tratamento de erros

| Situação | Resposta |
|---|---|
| Código não existe **ou** e-mail não bate | `422` **genérico** "Código ou e-mail inválido." (mesma msg pros dois — anti-enumeração) |
| Código esgotou as ativações | `409` "Este código já atingiu o limite de ativações." |
| Domínio malformado | `422` "Informe o domínio completo, ex.: `https://seusite.com`" |
| Rate limit estourado | `429` "Muitas tentativas. Tente de novo em alguns minutos." |
| Cunhagem (`LicenseIssuer`) falha | `500`; o `gkl_code` **não** é alterado; como cunha-antes-de-revoga, a chave anterior segue válida |

## Segurança

- **`code_hash` (sha256)** no banco; o código em claro só existe no e-mail / mão do cliente.
- **2º fator e-mail** obrigatório.
- **RateLimiter** (reusado) no endpoint público, por IP.
- **Resposta genérica** anti-enumeração de códigos (não revela se o código existe).
- **Limite de re-ativações** barra revenda pra múltiplos sites.
- **CPT privado** (`public => false`, `exclude_from_search`) — códigos não vazam pelo site.
- A página é **pública por design** (o cliente não está logado no servidor de licenças). Só o
  **resgate** é público; a **emissão** (`issue-code`) é só CLI/admin autenticado.

## Critérios de sucesso (verificáveis)

Harness standalone `tests/run.php` (como o núcleo):

- `issue-code` cria um `gkl_code` com `code_hash` correto e status `gkl_code_open`.
- Ativação com código+e-mail válidos cunha uma chave travada no domínio que o **`Verifier` do
  cliente aceita** (teste cruzado com a pubkey embarcada, como no núcleo).
- Ativação rejeita, cada uma com a resposta certa: e-mail errado, código inexistente, código
  esgotado, domínio malformado.
- Re-ativação **revoga a `jti` anterior** (passa a aparecer no `/revoked`) e cunha a nova pro
  novo domínio; `activated_exp` **preservado** entre 1ª e 2ª ativação.
- `exp` da 1ª ativação = `now + duração`; a 2ª ativação **não** muda o `exp`.
- **Cunha-antes-de-revoga:** se a cunhagem falha, a chave anterior continua válida e o `gkl_code`
  fica intacto (nenhum contador mexido).
- Rate limit dispara no N+1 request.

## Fora de escopo (fatia 3 e além)

- **Webhook da plataforma de venda** (Hotmart ou outra) que auto-emite códigos. A ponte já está
  desenhada: o webhook só chama `ActivationCodeIssuer::issue()` — a mesma porta que a CLI usa.
- **Checkout / página de compra.**
- **Renovação / cobrança recorrente / grace period.** Renovar hoje = emitir novo código.
- **Qualquer mudança no cliente `guardkids-wp`.** O cliente já sabe colar a chave e já consome o
  `/revoked` desde a fatia 1.
