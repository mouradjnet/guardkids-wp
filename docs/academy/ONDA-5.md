# PLANO — ONDA 5

## Insights de IA para o responsável

**Alvo:** `app-parent` + backend · branch `feat/academy-onda-5` (aditivo) · **Base:** Onda 4 (v1.40.0, prod)

---

## Objetivo

Fechar a Academy com o toque de **IA**: o painel do responsável ganha um card de
**Insights** que lê o uso real da família (o mesmo dado que o `ReportsController` já
agrega) e devolve, em linguagem natural, **o que mudou e o que fazer** — ex.: "O tempo
de tela subiu 40% esta semana, concentrado à noite. Considere um limite de horário no
período da noite." Fecha o ciclo Academy: ensinar (Ondas 1–2) → praticar (Onda 3) →
testar (Onda 4) → **agir sobre o próprio dado (Onda 5)**.

## Escopo (decidido com o usuário)

- **Parent-facing, não kid-facing.** Sem chat livre exposto à criança. Zero risco de
  moderação/segurança infantil — a IA fala só com o responsável, sobre dados agregados.
- **Recurso Pro.** Gated por uma nova feature `ai_insights` no `Gate`. Free vê o card
  travado com CTA de upgrade (degrada, não derruba a tela).
- **Sem tabela nova / sem migration.** Cache do insight em `transient`; gating em
  `wp_options` (licença, já existe). `GUARDKIDS_DB_VERSION` fica em **27**.

## Decisões de arquitetura (à luz do código real auditado)

1. **A IA só é chamada no servidor.** Novo namespace `includes/AI/`. O HTTP fica isolado
   num `fetch()` **protegido e sobrescrevível nos testes** — molde idêntico ao
   `includes/Geo/Geocoder.php`. A **chave de API nunca vai no bundle** nem sai por REST.
2. **A IA recebe SÓ dado agregado, nunca PII bruta.** Reusa as agregações do
   `UsageEventRepository` (minutos, % do limite, delta vs período anterior, top domínios,
   bloqueios recentes). **Nomes de crianças são pseudonimizados** ("Criança 1", "Criança 2")
   antes do prompt; **nenhuma localização/endereço** é enviada; nunca dado de outra família.
3. **Custo determinístico.** O insight é **cacheado por `(família + range + hash do dado)`**
   em `transient` (TTL ~12h). Se o dado não mudou, **não re-chama a IA** (lê do cache). Um
   `POST /insights/refresh` força regenerar, com **rate-limit por família**.
4. **Degradação leniente (padrão do Gate).** Sem chave, sem crédito, timeout ou JSON
   malformado da IA → devolve estado vazio `200` com `insights: []` + `available:false` e
   uma mensagem — **nunca 500**. O dashboard não quebra.
5. **Contrato estruturado, não texto solto.** A IA devolve **JSON** (`insights: [{title,
   body, severity, cta}]`), pedido no prompt e **parseado defensivamente**. Modelo
   `claude-opus-4-8`. Gotchas conhecidos honrados: **sem `temperature`/`top_p`** (dão 400),
   **sem prefill**, `thinking` deixado no default. Ver `[[reference-claude-api-opus-48-gotchas]]`.
6. **Chave via constante do `wp-config`.** `GUARDKIDS_ANTHROPIC_KEY` definida no `wp-config.php`
   (não vaza em dump de DB, não editável pela UI). Fallback: `wp_options` pra quem não tem
   acesso ao `wp-config`. Ausente → recurso indisponível (degrada).

## Modelo de dados

**Nenhuma tabela nova.** Insight vive em `transient` (cache efêmero); licença em `wp_options`
(já existe). `GUARDKIDS_DB_VERSION` permanece **27**.

## Endpoints

- `GET  /insights?range=week|month&child_id=*` → `{ available, generatedAt, model, fromCache,
  insights: [{ title, body, severity, cta }] }`. Gate `ai_insights`; Guardian auth.
- `POST /insights/refresh { range, child_id? }` → força regenerar (rate-limited por família).

Ambos reusam **exatamente** as mesmas agregações do `ReportsController`
(`kpisForRange` / `aggregateDailyMinutes` / `topDomains`).

## Arquivos a **criar**

**Backend**
- `includes/AI/AnthropicClient.php` — client isolado; `fetch()` protegido (molde Geocoder);
  chave via `GUARDKIDS_ANTHROPIC_KEY` ↦ option; degrada pra `null` em qualquer falha.
- `includes/AI/InsightsService.php` — **função pura testável**: recebe as agregações →
  monta o prompt (com pseudonimização) → parseia o JSON estruturado de volta (guard de shape).
- `api/Controllers/InsightsController.php` — `index()`/`refresh()`; `Gate('ai_insights')`;
  cache `transient` por hash do dado; rate-limit; degradação leniente.
- PHPUnit: `AnthropicClientTest`, `InsightsServiceTest`, `InsightsControllerTest`.

**Frontend (`public/app-parent/src/`)**
- `api/insights.ts` — `getInsights()` / `refreshInsights()` (usa o `apiFetch` existente).
- `components/InsightsCard.tsx` — card no dashboard; estados **loading / vazio / erro /
  travado-Pro / lista de insights** + botão "atualizar".
- `.test.ts`/`.test.tsx` de cada.

## Arquivos a **modificar** (aditivo)

- `includes/License/Gate.php` — adiciona `'ai_insights'` em `PREMIUM_FEATURES`.
- `public/app-parent/src/hooks/useLicense.ts` — espelha `'ai_insights'` em `PREMIUM_FEATURES`.
- `public/app-parent/src/data/planCatalog.ts` — linha de marketing do recurso.
- `api/RestApi.php` — registra as rotas `/insights` (segue o padrão dos controllers existentes).
- `App.tsx` (ou a tela de Relatórios) — monta o `<InsightsCard />` (1 ponto de montagem).
- **Sem** bump de `GUARDKIDS_DB_VERSION` (sem migration).

## Testes (definição de "pronto" ≠ "o card apareceu")

- **PHPUnit:** `AnthropicClient` (degrada pra null sem chave / em erro HTTP / em JSON ruim;
  parseia resposta boa); `InsightsService` (prompt inclui os KPIs certos; **nome real nunca
  aparece no prompt** — só "Criança N"; parse do JSON estruturado; guard de shape rejeita
  malformado); `InsightsController` (**Free → travado, não gera**; guardião só vê a própria
  família; **cache hit NÃO re-chama a IA**; `refresh` rate-limited; sem chave → `available:false`
  200; 401 sem auth).
- **vitest:** `InsightsCard` (loading / vazio / erro / **travado-Pro com CTA** / lista
  renderiza / "atualizar" dispara refresh); `api/insights.ts`.
- **Falsificação:** cada teste novo falha antes da implementação. São quebrados de propósito
  e re-verdes: **furar o gate** (Free gerar insight), **vazar PII** (nome real no prompt) e
  **re-chamar a IA no cache hit**.

## Critérios de aceite

1. Responsável Pro no dashboard → card lista 2–4 insights acionáveis sobre o uso real.
2. Free → card travado com CTA de upgrade; a IA **não** é chamada.
3. Sem chave / erro da IA → card mostra "indisponível", dashboard intacto (nunca 500).
4. Reabrir a tela com o mesmo dado → lê do cache, **sem** nova chamada à IA (custo controlado).
5. "Atualizar" regenera; rate-limit impede abuso.
6. Nenhum nome real de criança, endereço ou dado de outra família chega ao prompt.
7. `pnpm test` (vitest) + `vendor/bin/phpunit` verdes + build ok. Sem migration.

## Sequência de trabalho (1 commit por passo)

1. `AnthropicClient` + `InsightsService` (puros) + PHPUnit → commit
2. `InsightsController` + rota + Gate `ai_insights` + PHPUnit → commit
3. `api/insights.ts` + `useLicense`/`planCatalog` (espelhos) → commit
4. `InsightsCard` + montagem + vitest → commit
5. `docs/academy/ONDA-5.md` + gate final (`pnpm test` + PHPUnit verdes) → PR

**Fora do escopo:** chat kid-facing, tutor de IA da criança, quiz gerado por IA,
geração de conteúdo de aula, XP do responsável.
