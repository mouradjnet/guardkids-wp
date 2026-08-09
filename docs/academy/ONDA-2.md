# PLANO — ONDA 2

## Academia navegável: Trilhas + Aulas

**Alvo:** `guardkids-wp` · app-parent · branch `feat/academy-onda-2` (aditivo)
**Base:** Onda 1 (v1.37.0, em prod)

---

## Objetivo

Criar a área **"Academia"** no painel dos pais: uma página no menu onde o
responsável navega por **trilhas**, com **aulas em ordem** e **barra de progresso**.
Complementa a pílula contextual da Onda 1 (que continua funcionando).

## Escopo (decidido com o usuário)

- **2 trilhas completas:** Primeiros Passos · Tempo de Tela.
- **2 trilhas "Em breve":** Segurança Digital · Educação Familiar (aparecem no menu,
  bloqueadas com selo).
- Progresso no **`wp_usermeta`** que já existe (`completed`) — **sem migration**.
- **Quiz/avaliações:** adiados para a Onda 4.

## Decisões de arquitetura

1. **Conteúdo estático versionado** (`lessons.ts` + `tracks.ts`), como na Onda 1. Sem
   tabela nova. As 7 aulas da Onda 1 são reaproveitadas; **os `id` existentes NÃO
   mudam** (a pílula contextual e o progresso em prod referenciam por id).
2. **Trilha é a fonte da ordem:** `Track.lessonIds` é uma lista ordenada de ids de
   aula. A aula não guarda ordem; a trilha guarda.
3. **Progresso reusado:** `%` da trilha = concluídas / total, lido do mesmo
   `completed` (usermeta) da Onda 1. Concluir uma aula pela pílula OU pela Academia
   conta igual.
4. **Reuso de UI:** o `AcademyPanel` da Onda 1 vira o visualizador de aula (com
   `onDismiss` opcional — na Academia não há "dispensar", só "Concluir").

## Modelo de dados

```ts
// tracks.ts
interface Track {
  id: string;
  title: string;
  description: string;
  icon: string;                    // material symbol
  status: 'available' | 'coming-soon';
  lessonIds: string[];             // ordenado; vazio se coming-soon
}

// progresso (puro)
trackProgress(track, completedIds): {
  total: number; done: number; pct: number; nextLessonId: string | null;
}
```

## Arquivos a criar

- `academy/tracks.ts` — as 4 trilhas + `trackProgress()` (puro)
- `academy/tracks.test.ts`
- aulas novas em `academy/lessons.ts` (completar as 2 trilhas; ~16 aulas no total)
- `pages/Academy.tsx` — a página: lista de trilhas (card com progresso + Continuar) e,
  ao abrir uma trilha, a lista de aulas → abre o painel
- `pages/Academy.test.tsx`

## Arquivos a modificar

- `components/AcademyPanel.tsx` — `onDismiss` opcional (esconde "Agora não" quando ausente)
- `data/mockData.ts` — novo `PageId 'academy'` + item de nav "Academia" (ícone `school`)
- `App.tsx` — `case 'academy'` no `PageRenderer`
- `lib/roleAccess.ts` — liberar `'academy'` p/ collaborator (o endpoint já permite)
- `App.test.tsx` — cobre a navegação até a Academia

## Testes

- `trackProgress` puro: 0%, parcial, 100%, próxima aula, trilha coming-soon
- Integridade: todo `lessonId` de trilha existe em `LESSONS`
- Página: renderiza trilhas, barra de progresso, "Em breve" nas bloqueadas, abre aula
- Navegação: item "Academia" leva à página

## Critérios de aceite

1. Item "Academia" no menu → página com as 4 trilhas
2. Trilhas disponíveis mostram % e "Continuar" (vai pra próxima não-concluída)
3. Abrir/concluir uma aula persiste e a barra sobe
4. Trilhas "Em breve" aparecem bloqueadas
5. A pílula contextual da Onda 1 continua funcionando
6. `pnpm test` + PHPUnit verdes + build ok

## Sequência (1 commit por passo)

1. `tracks.ts` + expansão de `lessons.ts` + `trackProgress` + testes
2. `AcademyPanel` (onDismiss opcional) + `pages/Academy.tsx` + testes
3. Nav: PageId + navItem + PageRenderer + roleAccess + App.test
4. `docs/academy/ONDA-2.md` + gate + PR

Fora do escopo: quiz (Onda 4), Academy da criança (Onda 3), XP do responsável, IA (Onda 5).
