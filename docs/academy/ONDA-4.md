# PLANO — ONDA 4

## Avaliações (quiz) por aula

**Alvo:** `app-child` + backend · branch `feat/academy-onda-4` (aditivo) · **Base:** Onda 3 (v1.39.0, prod)

---

## Objetivo

Ao fim de cada aula, um **quiz curto**. Acertar é o que **conclui a aula e libera
o XP** — a leitura vira aprendizado testado, não só "li e cliquei". Reusa toda a
base da Onda 3.

## Escopo (decidido com o usuário)

- **Quiz porteia a conclusão:** some o "Concluí!"; a aula só conclui (e credita os
  25 XP/15 coins) com o quiz aprovado. Não há mais caminho para XP sem passar pelo quiz.
- **3 questões por aula, acertar todas (100%), tentativas ilimitadas.** Reprovar
  não pune — mensagem gentil "revê e tenta de novo", sem revelar a resposta.
- **Sem tabela nova / sem migration** — aprovar = concluir = a linha que já existe
  no ledger `academy_child_lessons` da Onda 3.

## Decisões de arquitetura (herdando o padrão da Onda 3)

1. **Servidor é dono do gabarito.** As perguntas + alternativas moram no
   `quizzes.ts` (texto), mas **a resposta certa NUNCA vai no bundle** (a criança
   inspecionaria). O gabarito e a correção ficam no PHP (`AcademyQuiz`, com
   `lesson_key → índices corretos`, na mesma ordem de `quizzes.ts`).
2. **Correção no servidor.** `POST /child/academy/quiz { lesson_key, answers[] }` →
   `AcademyQuiz::grade()` corrige; se aprovou, faz o **mesmo `record()` + crédito
   idempotente** da Onda 3 (reusa `ChildAcademyRepository`). Reprovar não credita.
3. **Uma fonte de verdade de aula válida.** `AcademyQuiz::hasQuiz()` substitui o
   `VALID_KEYS` da Onda 3 — chave desconhecida → 400. O `/complete` da Onda 3 foi
   **removido** (era um caminho para XP sem quiz; furava a porteira).
4. **Cross-check cliente×servidor.** O `AcademyQuizTest` (PHP) e o `quizzes.test.ts`
   (vitest) fixam o mesmo contrato — 8 aulas × 3 questões × 3 alternativas. Se um
   lado derivar, o outro quebra.
5. **Reusa o XP da conclusão** (25/15) — o quiz só porteia o que já existe. Idempotência
   mantida: refazer o quiz de uma aula concluída não re-credita.

## Modelo de dados

**Nenhuma tabela nova.** Aprovar = concluir = uma linha em `academy_child_lessons`
(já existe). `GUARDKIDS_DB_VERSION` fica em **27**.

## Endpoints

- `GET  /child/academy` (inalterado)
- `POST /child/academy/quiz { lesson_key, answers:int[] }` → `{ passed, correct, total,
  completedKeys, progression, awarded }` — **substitui** `POST /child/academy/complete`.

## Arquivos

**Backend**
- `includes/Academy/AcademyQuiz.php` — gabarito das 8 aulas + `grade()` puro (aprova só 100%)
- `api/Controllers/ChildAcademyController.php` — `quiz()` no lugar de `complete()`; remove `VALID_KEYS`
- `api/RestApi.php` — rota `/quiz` no lugar de `/complete`

**Frontend (`app-child`)**
- `src/academy/quizzes.ts` — 8 × 3 questões × 3 alternativas (texto, **sem** gabarito)
- `src/api/academy.ts` — `submitQuiz()` (remove `completeLesson`)
- `src/pages/Academy.tsx` — `QuizForm` no lugar do "Concluí!" (responder → aprovar/celebração | reprovar/tentar de novo)

## Testes (definição de "pronto")

- **PHPUnit:** `grade()` (aprova só 100%; reprova por erro/incompleto/fora-de-range;
  guard de range do gabarito; cobre as 8 aulas), `quiz()` (aprovar credita 1x, reprovar
  não credita, resubmit não re-credita, chave inválida → 400, streak preservado, 401).
- **vitest:** cross-check do contrato cliente×servidor; página (habilita ao responder,
  aprovar→celebração, reprovar→"tentar de novo", selo de concluída, erro visível).
- **Falsificação:** afrouxar a aprovação (PHP), furar a porteira do crédito (controller)
  e ignorar a reprovação (UI) foram quebrados de propósito e falharam antes de restaurar.

## Critérios de aceite

1. A aula mostra o quiz no lugar do "Concluí!".
2. Acertar todas → conclui, celebra e credita 25 XP/15 coins (uma vez).
3. Errar → "Quase! …" sem creditar; dá pra tentar de novo à vontade.
4. Aula já concluída mostra o selo, sem pedir o quiz.
5. Não há caminho para XP sem passar pelo quiz (o `/complete` foi removido).
6. `pnpm test` + PHPUnit verdes + build ok. Sem migration.

## Sequência (1 commit por passo) — entregue

1. `AcademyQuiz` (gabarito + `grade` puro) + PHPUnit ✅
2. `quiz()` no controller + rota + PHPUnit (substitui `complete`) ✅
3. `quizzes.ts` + `submitQuiz` + vitest cross-check ✅
4. `QuizForm` na `Academy.tsx` + vitest ✅
5. `docs/academy/ONDA-4.md` + gate + PR ✅

**Fora do escopo:** IA (Onda 5), XP do responsável, banco de questões dinâmico,
registro de nota/tentativas.
