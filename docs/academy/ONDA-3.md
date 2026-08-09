# PLANO — ONDA 3

## Academy da criança: trilhas que rendem XP

**Alvo:** `guardkids-wp` · **app-child** · branch `feat/academy-onda-3` (aditivo)
**Base:** Onda 2 (v1.38.0, em prod)

---

## Objetivo

Criar a área **"Academia"** dentro do app da criança: trilhas com aulas em
linguagem infantil onde **concluir uma aula rende XP + moedas** pela gamificação
que já existe. Complementa o Academy do responsável (Ondas 1–2), que continua
igual. Sem quiz (Onda 4) e sem IA (Onda 5).

## Escopo (decidido com o usuário)

- **2 trilhas × 4 aulas** = 8 aulas: *Meu Mundo Digital Seguro* · *Meu Tempo de Tela*.
- Concluir aula credita **25 XP + 15 moedas**, **uma vez por aula**.
- Progresso e crédito no **servidor** (tabela nova); o cliente só lê e conclui.

## Decisões de arquitetura

1. **Servidor é dono do valor do XP e do allowlist.** O cliente manda só a chave
   da aula; o valor (25/15) e a lista de aulas válidas (`VALID_KEYS`) vivem no
   `ChildAcademyController`. Uma chave forjada é rejeitada com 400 — não dá pra
   farmar XP. `VALID_KEYS` **espelha** os `id` de `app-child/src/academy/lessons.ts`
   (há um teste vitest que falha se os dois saírem de sincronia).
2. **Idempotência por ledger.** `guardkids_academy_child_lessons` tem UNIQUE
   `(child_id, lesson_key)` — molde do `mission_completions`. Concluir a mesma
   aula de novo não credita (`justCompleted=false`).
3. **Reuso da carteira.** O crédito soma na `progression` que missões/medalhas/loja
   já usam (`ProgressionRepository::apply`), **preservando o streak** (mesma
   convenção do `creditBonus`). O XP aparece na Home/Mundo sem código novo lá.
4. **Conteúdo estático versionado** (`lessons.ts` + `tracks.ts`), como nas Ondas
   1–2. Sem tabela de conteúdo; markdown renderizado por um renderizador **seguro**
   (constrói React, nunca `innerHTML`) portado do painel.
5. **Entrada sem lotar a nav.** A `BottomNav` já tem 6 itens; a Academia entra por
   um **card na Home** (mesmo padrão do card da Loja), não por uma 7ª aba.

## Modelo de dados

```
guardkids_academy_child_lessons
  id, child_id, lesson_key (UNIQUE com child_id), xp, coins, created_at
```

Migration `027` · `GUARDKIDS_DB_VERSION` 26 → 27 (bump no mesmo commit).

## Endpoints (`guardkids/v1`, auth por token de pareamento)

- `GET  /child/academy` → `{ completedKeys[], progression }`
- `POST /child/academy/complete { lesson_key }` → idempotente; devolve
  `{ completedKeys, progression, awarded: { justCompleted, xp, coins } }`

## Arquivos

**Backend**
- `database/migrations/027_academy_child_lessons.php`
- `database/ChildAcademyRepository.php` — `existsFor` / `record` / `listCompleted`
- `api/Controllers/ChildAcademyController.php` — `index` / `complete` + `creditBonus`
- `api/RestApi.php` — `registerChildAcademyRoutes()`

**Frontend (`app-child`)**
- `src/academy/lessons.ts` (8 aulas) · `src/academy/tracks.ts` (2 trilhas + `trackProgress`)
- `src/academy/markdown.tsx` (renderizador seguro, portado do painel)
- `src/api/academy.ts` (`getAcademy` / `completeLesson`)
- `src/pages/Academy.tsx` (trilhas → aulas → visualizador + celebração de XP)
- Nav: `data/mockData.ts` (PageId `academy`) · `App.tsx` (case) · `Header.tsx` (título) · `pages/Home.tsx` (card)

## Testes (definição de "pronto")

- **PHPUnit:** idempotência (2º complete não credita), chave inválida não credita,
  isolamento por filho, streak preservado no crédito, 401 sem token.
- **vitest:** `trackProgress` (0/parcial/100%/ordem), integridade das trilhas,
  **cross-check catálogo × allowlist do servidor**, página (listagem, conclusão →
  celebração, selo de concluída, erro visível).
- **Falsificação:** os testes-chave (idempotência do servidor, celebração da
  página) foram quebrados de propósito e falharam antes de restaurar.

## Critérios de aceite

1. Card "Academia" na Home → página com as 2 trilhas e progresso.
2. Abrir aula, ler, "Concluí!" → +25 XP / +15 moedas, uma vez só.
3. Repetir a aula não credita de novo; o selo "Aula concluída" aparece.
4. O XP creditado aparece na carteira (Home/Mundo/Loja).
5. `pnpm test` (vitest) + PHPUnit verdes + build ok.

## Sequência (1 commit por passo) — entregue

1. migration 027 + DB bump + `ChildAcademyRepository` + PHPUnit ✅
2. endpoints + registro no `RestApi.php` + PHPUnit ✅
3. `lessons.ts` + `tracks.ts` + `trackProgress` + vitest ✅
4. `api/academy.ts` + `pages/Academy.tsx` + nav + vitest ✅
5. `docs/academy/ONDA-3.md` + gate + PR ✅

Fora do escopo: quiz (Onda 4), XP do responsável, IA (Onda 5).
