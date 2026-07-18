# Spec — Onboarding de primeiro acesso (checklist no Dashboard)

**Data:** 2026-07-18
**App:** app-parent (`public/app-parent/`) + backend (`api/`, `includes/`, `database/`)
**Origem:** auditoria 2026-07-17 apontou "onboarding" como pendência de ativação/funil.

## 1. Objetivo

Guiar o pai recém-chegado a completar a **ativação mínima** da família: criar 1 filho e parear 1 dispositivo. Hoje as funcionalidades existem (diálogos `AddChildDialog`, `PairDeviceDialog`), mas nada guia o primeiro acesso — o pai cai num Dashboard vazio sem próximo passo claro.

**Critério de sucesso:** ao final, existe ≥1 filho cadastrado e ≥1 filho com dispositivo pareado. O checklist some sozinho quando isso é verdade.

## 2. Escopo

**Dentro:**
- Um card de checklist não-bloqueante no topo do Dashboard, com 2 passos que reutilizam os diálogos existentes.
- Um sinal de backend novo (`paired` por filho) pra o passo 2 saber que foi concluído.

**Fora (YAGNI / decisões tomadas):**
- Sem wizard de tela cheia; sem tour de features; sem passos além de criar+parear (limite/PIN/modo/licença ficam pra o pai fazer depois, pelas telas normais).
- Sem botão "pular/depois" e sem flag de dispensa — o card é derivado do estado real e desaparece ao completar (decisão (a)).
- Sem página nem rota nova; sem mudança no `App.tsx`.

## 3. Design

### 3.1 Backend — campo `paired` por filho

**Problema:** o campo `device` do filho é só um rótulo de texto ("Tablet"), não prova de pareamento. O pareamento real emite um token guardado em `wp_guardkids_settings` com a chave `child_token:<sha256>` e valor JSON `{childId, label, createdAt}` (ver `includes/Auth/ChildAuth.php`). Não há hoje sinal client-side de "esse filho está pareado".

**Solução:**
1. `SettingsRepository::valuesByPrefix(string $prefix): array<string,mixed>` — método novo que faz `SELECT setting_key, value FROM {table} WHERE setting_key LIKE '<prefix>%'` e devolve os valores desserializados. Evita carregar todas as settings via `all()`.
2. `ChildAuth::pairedChildIds(): array<int>` — usa `valuesByPrefix(self::KEY_PREFIX)` (`'child_token:'`), extrai `childId` de cada payload, devolve os IDs **distintos**. É a única fonte da verdade de "pareado" (existe token ⟺ pareado).
3. `ChildController` (serialização do filho, hoje em ~L292) passa a incluir `'paired' => in_array((int)$row['id'], $pairedIds, true)`. Os `$pairedIds` são resolvidos **uma vez** por request (não por filho) — passar o array pronto pro método de serialização.
4. Frontend: tipo `Child` (`api/types.ts`) ganha `paired: boolean`.

**Invariante:** `paired` reflete a existência de token em tempo de leitura. A exclusão de filho hoje (`ChildController::destroy`) **NÃO** limpa os `child_token:*` — mas isso é inofensivo pro checklist: `paired` é serializado só pra filhos que existem, e `children.some(c => c.paired)` só olha filhos da lista, então um token órfão de filho excluído nunca marca o passo 2 como concluído indevidamente. (A limpeza de tokens órfãos na exclusão é uma dívida pré-existente, fora do escopo deste spec.)

### 3.2 Frontend — `components/OnboardingChecklist.tsx`

- Renderizado **no topo do `Dashboard.tsx`**, antes das seções existentes (KPIs, crianças, pendentes).
- Reusa a query `['children']` (`listChildren`) — a mesma que o Dashboard já consome; sem request novo.
- Deriva os 2 passos:
  - **Passo 1 — "Adicione seu primeiro filho":** concluído ⟺ `children.length > 0`.
  - **Passo 2 — "Pareie um dispositivo":** concluído ⟺ `children.some(c => c.paired)`. **Travado** (CTA desabilitado, visual "aguardando") enquanto `children.length === 0`.
- **Renderiza `null` quando os dois passos estão concluídos** (ou enquanto a query carrega / dá erro — o Dashboard já trata o erro da lista; o checklist não duplica).
- Cada passo pendente tem um CTA que abre o diálogo existente:
  - Passo 1 → abre `AddChildDialog`.
  - Passo 2 → abre `PairDeviceDialog` do **primeiro filho com `paired === false`** (decisão (b): escolha automática, sem pedir pro pai escolher).
- No `onSuccess` dos diálogos a query `['children']` já invalida (comportamento existente) → o checklist recalcula e o passo vira ✓ sem reload.
- **Estado local:** o componente controla apenas qual diálogo está aberto (`useState`); nada persiste — a "conclusão" é sempre derivada dos dados.

**Cópia (PT-BR):**
- Cabeçalho: "Bem-vindo ao GuardKids 👋 — configure sua família em 2 passos" + progresso "X de 2".
- Passo 1 pendente: título "Adicione seu primeiro filho", CTA "Adicionar filho".
- Passo 2 pendente: título "Pareie um dispositivo", CTA "Parear dispositivo"; travado: subtítulo "Adicione um filho primeiro".
- Passo concluído: ícone de check + título em tom "feito".

### 3.3 Contratos

```ts
// api/types.ts — Child ganha:
paired: boolean;

// OnboardingChecklist não recebe props (lê a query ['children'] internamente).
export function OnboardingChecklist(): JSX.Element | null;
```

```php
// SettingsRepository
public function valuesByPrefix(string $prefix): array; // [key => value_desserializado]

// ChildAuth
public function pairedChildIds(): array; // list<int> distintos
```

## 4. Erros

O checklist não tem mutations próprias (só leitura de `['children']`), então nada novo pra falhar mudo. Os diálogos `AddChildDialog`/`PairDeviceDialog` já surfam os próprios erros. Se `listChildren` falhar, o Dashboard já mostra o erro; o checklist simplesmente não renderiza (retorna `null` sem `data`).

## 5. Testes

**Backend (PHPUnit):**
- `SettingsRepository::valuesByPrefix` — filtra por prefixo, ignora outras keys, desserializa.
- `ChildAuth::pairedChildIds` — vazio sem tokens; devolve IDs distintos (2 tokens do mesmo filho → 1 ID); ignora payload sem `childId`.
- `ChildController` — o filho serializado inclui `paired: true` quando há token, `false` quando não há.

**Frontend (Vitest):**
- `OnboardingChecklist`:
  - 0 filhos → passo 1 com CTA "Adicionar filho", passo 2 travado ("Adicione um filho primeiro").
  - 1 filho `paired:false` → passo 1 ✓, passo 2 com CTA "Parear dispositivo".
  - 1 filho `paired:true` → componente não renderiza nada (`null`).
  - clicar no CTA do passo 1 abre o `AddChildDialog`; do passo 2 abre o `PairDeviceDialog` do filho não pareado.
- `Dashboard` — o checklist aparece acima das seções quando a família está incompleta.

## 6. Decisões registradas

- **(a) Sem botão "pular/depois"** — o card é derivado e some ao completar; são só 2 passos e o pai já pode ignorar e usar o app livremente (não-bloqueante).
- **(b) Passo 2 abre o `PairDeviceDialog` do primeiro filho não-pareado** automaticamente — sem pedir pro pai escolher qual filho.
- **Detecção de pareamento = campo `paired` no backend** (existe token ⟺ pareado), preferido a flag de settings (dessincroniza) ou proxy por sync (falso-negativo).

## 7. Fora de escopo / futuro

- Mostrar "pareado" na tela de Filhos (o campo `paired` já habilita isso de graça depois).
- Onboarding do app-filho (este spec é só do app dos pais).
