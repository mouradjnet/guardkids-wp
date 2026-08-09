// Catálogo de aulas do Academy da CRIANÇA (Onda 3).
//
// Conteúdo estático, versionado no bundle — sem tabela nova. Linguagem infantil.
// O `body` é markdown; a página (passo 4) renderiza com um renderizador seguro.
//
// IMPORTANTE: os `id` são o contrato com o servidor — precisam bater EXATAMENTE
// com VALID_KEYS em api/Controllers/ChildAcademyController.php. Concluir uma aula
// grava o `id` no ledger e credita XP; renomear sem alinhar o servidor quebra a
// validação (aula vira "desconhecida").

export type LessonCategory = 'digital-seguro' | 'meu-tempo';

export interface Lesson {
  /** slug estável — chave do progresso e do crédito de XP; ver VALID_KEYS no servidor */
  id: string;
  title: string;
  /** frase única mostrada no card antes de abrir a aula */
  summary: string;
  category: LessonCategory;
  /** corpo em markdown */
  body: string;
}

export const LESSONS: Lesson[] = [
  // ─── Trilha: Meu Mundo Digital Seguro ───────────────────────────────
  {
    id: 'seguranca-intro',
    title: 'O que é ficar seguro?',
    summary: 'Aprenda o que significa se cuidar quando você usa a internet.',
    category: 'digital-seguro',
    body: `# O que é ficar seguro?

A internet é um lugar cheio de coisas legais: jogos, vídeos e histórias. Mas,
assim como na rua, a gente precisa se cuidar.

## Ficar seguro é
- Saber com quem você está falando.
- Não contar seus segredos para estranhos.
- Pedir ajuda quando algo parecer estranho.

## Lembre-se
Se alguma coisa te deixar com medo ou confuso, você **não está sozinho**. Um
adulto de confiança pode te ajudar sempre.`,
  },
  {
    id: 'senha-secreta',
    title: 'Sua senha é um segredo',
    summary: 'Descubra por que a senha é só sua e como guardá-la bem.',
    category: 'digital-seguro',
    body: `# Sua senha é um segredo

Uma senha é como a chave da sua casa. Ela abre as suas coisas — e só você deve ter.

## Regras da senha secreta
- Não conte sua senha para colegas.
- Só um adulto da sua família pode saber, se precisar te ajudar.
- Ninguém de verdade vai pedir sua senha por mensagem.

## Se alguém pedir sua senha
Diga **não** e avise um adulto. Pedir a senha de outra pessoa não é coisa de amigo.`,
  },
  {
    id: 'falar-com-adulto',
    title: 'Quando chamar um adulto',
    summary: 'Saiba a hora de pedir ajuda para alguém da sua família.',
    category: 'digital-seguro',
    body: `# Quando chamar um adulto

Chamar um adulto não é coisa de bebê — é coisa de gente esperta que sabe se cuidar.

## Chame um adulto se
- Alguém que você não conhece quiser conversar.
- Aparecer uma imagem ou mensagem que te deixou mal.
- Alguém pedir para você guardar segredo dos seus pais.

## O jeito certo
Vá até um adulto de confiança e conte o que aconteceu. Você não vai levar bronca
por pedir ajuda.`,
  },
  {
    id: 'pistas-de-golpe',
    title: 'Pistas de cilada',
    summary: 'Aprenda a perceber quando algo é bom demais para ser verdade.',
    category: 'digital-seguro',
    body: `# Pistas de cilada

Às vezes aparece uma mensagem prometendo prêmios ou moedas de graça. Cuidado:
pode ser uma cilada!

## Fique esperto quando
- Prometerem algo grátis se você clicar num link.
- Pedirem seu nome, endereço ou senha para "ganhar" um prêmio.
- Disserem que é urgente e você tem que responder rápido.

## O que fazer
Não clique e não responda. Mostre para um adulto. Se é bom demais para ser
verdade, provavelmente **é uma cilada**.`,
  },

  // ─── Trilha: Meu Tempo de Tela ──────────────────────────────────────
  {
    id: 'tempo-intro',
    title: 'Tempo de tela é como doce',
    summary: 'Entenda por que usar a tela com equilíbrio faz bem.',
    category: 'meu-tempo',
    body: `# Tempo de tela é como doce

Usar telas é divertido, mas é um pouco como comer doce: um tanto faz bem, demais
deixa a gente enjoado.

## Equilíbrio é
- Brincar na tela e também brincar longe dela.
- Ter hora para começar e hora para parar.
- Fazer outras coisas que você ama.

## Combine com a família
Junto com seus pais, escolha quanto tempo é o certo. Assim ninguém precisa brigar.`,
  },
  {
    id: 'fazer-pausas',
    title: 'A hora da pausa',
    summary: 'Descubra por que dar uma paradinha faz bem para o corpo.',
    category: 'meu-tempo',
    body: `# A hora da pausa

Depois de um tempo na tela, seus olhos e seu corpo pedem um descanso.

## Boas pausas
- Levante e estique o corpo.
- Olhe para longe da tela por um pouquinho.
- Beba água e mexa as pernas.

## Uma dica legal
A cada capítulo ou fase, faça uma pausa curtinha. Seu corpo agradece e você volta
com mais energia.`,
  },
  {
    id: 'tela-e-sono',
    title: 'Tela e hora de dormir',
    summary: 'Saiba por que guardar a tela ajuda você a dormir melhor.',
    category: 'meu-tempo',
    body: `# Tela e hora de dormir

Perto da hora de dormir, a tela deixa o cérebro ligado quando ele já quer descansar.

## Antes de dormir
- Guarde a tela um tempinho antes de deitar.
- Escolha algo calmo, como ouvir uma história.
- Deixe o quarto no escurinho.

## Por quê?
Dormir bem te deixa mais disposto para brincar e aprender no dia seguinte.`,
  },
  {
    id: 'brincar-sem-tela',
    title: 'Brincar sem tela',
    summary: 'Lembre-se das brincadeiras incríveis que não precisam de tela.',
    category: 'meu-tempo',
    body: `# Brincar sem tela

Existe um monte de diversão que não cabe dentro da tela!

## Ideias para brincar
- Correr, pular e brincar ao ar livre.
- Desenhar, montar e inventar histórias.
- Brincar com a família e os amigos de perto.

## O desafio de hoje
Escolha uma brincadeira sem tela e divirta-se. Depois conte para alguém o que
você mais gostou!`,
  },
];

/** Busca uma aula pelo id. */
export function findLesson(id: string): Lesson | undefined {
  return LESSONS.find((lesson) => lesson.id === id);
}
