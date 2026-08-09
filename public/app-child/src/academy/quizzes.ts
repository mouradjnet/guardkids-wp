// Quizzes do Academy da criança (Onda 4).
//
// Aqui moram só o TEXTO das perguntas e as ALTERNATIVAS. A resposta certa NÃO
// vive no cliente — o gabarito e a correção são do servidor
// (includes/Academy/AcademyQuiz.php). A ordem das questões e das alternativas
// tem que bater EXATAMENTE com o gabarito lá (índices 0-based); um teste vitest
// (quizzes.test.ts) garante nº de questões/opções e cobertura das 8 aulas.
//
// Regra da Onda 4: aprovar = acertar TODAS as questões da aula → conclui e libera XP.

export interface QuizQuestion {
  prompt: string;
  /** alternativas em ordem; o índice correto só o servidor conhece */
  options: string[];
}

export const QUIZZES: Record<string, QuizQuestion[]> = {
  // ── Trilha: Meu Mundo Digital Seguro ──
  'seguranca-intro': [
    {
      prompt: 'Ficar seguro na internet é:',
      options: ['Falar com qualquer um', 'Saber com quem você fala', 'Nunca usar a internet'],
    },
    {
      prompt: 'Se algo te deixar com medo online, você deve:',
      options: ['Guardar segredo', 'Pedir ajuda a um adulto de confiança', 'Continuar sozinho'],
    },
    {
      prompt: 'Seus segredos você conta para:',
      options: ['Estranhos da internet', 'Só quem você conhece e confia', 'Todo mundo'],
    },
  ],
  'senha-secreta': [
    {
      prompt: 'Sua senha é parecida com:',
      options: ['A chave da sua casa', 'Um brinquedo pra emprestar', 'Um adesivo'],
    },
    {
      prompt: 'Quem pode saber a sua senha?',
      options: ['Seus colegas', 'Um adulto da família, se precisar ajudar', 'Qualquer um que pedir'],
    },
    {
      prompt: 'Se alguém pedir sua senha por mensagem, você:',
      options: ['Manda na hora', 'Diz não e avisa um adulto', 'Troca por outra'],
    },
  ],
  'falar-com-adulto': [
    {
      prompt: 'Chamar um adulto quando algo dá errado é:',
      options: ['Coisa de bebê', 'Coisa de gente esperta', 'Errado'],
    },
    {
      prompt: 'Você deve chamar um adulto se:',
      options: ['Um desconhecido quiser conversar', 'Ganhar uma fase no jogo', 'Achar um vídeo legal'],
    },
    {
      prompt: 'Se alguém pede pra guardar segredo dos seus pais, você:',
      options: ['Guarda o segredo', 'Conta pra um adulto de confiança', 'Não faz nada'],
    },
  ],
  'pistas-de-golpe': [
    {
      prompt: 'Uma mensagem promete prêmio grátis se você clicar. Isso é:',
      options: ['Sempre verdade', 'Pode ser uma cilada', 'Um presente certo'],
    },
    {
      prompt: "Se pedirem seu nome e senha pra 'ganhar' um prêmio, você:",
      options: ['Manda os dados', 'Não manda e avisa um adulto', 'Responde rápido'],
    },
    {
      prompt: "Quando algo é 'bom demais para ser verdade', geralmente:",
      options: ['É uma cilada', 'É sempre real', 'Não importa'],
    },
  ],

  // ── Trilha: Meu Tempo de Tela ──
  'tempo-intro': [
    {
      prompt: 'Usar telas é um pouco como:',
      options: ['Comer doce', 'Respirar', 'Dormir'],
    },
    {
      prompt: 'Ter equilíbrio com a tela é:',
      options: ['Ficar o dia todo nela', 'Brincar na tela e também longe dela', 'Nunca usar'],
    },
    {
      prompt: 'Quanto tempo de tela é o certo, você:',
      options: ['Decide sozinho', 'Combina com a família', 'Nunca combina'],
    },
  ],
  'fazer-pausas': [
    {
      prompt: 'Depois de um tempo na tela, seu corpo pede:',
      options: ['Mais tela', 'Um descanso', 'Comida'],
    },
    {
      prompt: 'Uma boa pausa é:',
      options: ['Levantar e esticar o corpo', 'Abrir outro app', 'Aumentar o brilho'],
    },
    {
      prompt: 'Quando fazer uma pausa?',
      options: ['Nunca', 'A cada fase ou capítulo', 'Só no fim do dia'],
    },
  ],
  'tela-e-sono': [
    {
      prompt: 'Perto da hora de dormir, a tela:',
      options: ['Ajuda a dormir', 'Deixa o cérebro ligado', 'Não muda nada'],
    },
    {
      prompt: 'Antes de dormir é bom:',
      options: ['Guardar a tela um tempinho', 'Jogar bastante', 'Ver vídeos agitados'],
    },
    {
      prompt: 'Dormir bem te deixa:',
      options: ['Mais cansado', 'Mais disposto pra brincar e aprender', 'Com sono o dia todo'],
    },
  ],
  'brincar-sem-tela': [
    {
      prompt: 'Diversão sem tela:',
      options: ['Não existe', 'Existe um monte', 'É chata'],
    },
    {
      prompt: 'Um exemplo de brincar sem tela:',
      options: ['Correr e brincar ao ar livre', 'Assistir TV', 'Jogar no tablet'],
    },
    {
      prompt: 'O desafio da aula é:',
      options: ['Ficar mais na tela', 'Escolher uma brincadeira sem tela', 'Não brincar'],
    },
  ],
};

/** As perguntas de uma aula (ou undefined se não houver quiz). */
export function findQuiz(lessonKey: string): QuizQuestion[] | undefined {
  return QUIZZES[lessonKey];
}
