<?php

declare(strict_types=1);

namespace GuardKids\Academy;

/**
 * Gabarito e correção dos quizzes do Academy da criança (Onda 4). PURO — sem I/O.
 *
 * O SERVIDOR é dono do gabarito: o cliente (public/app-child/src/academy/quizzes.ts)
 * tem só o texto das perguntas e as alternativas; o índice correto NUNCA vai no
 * bundle (a criança inspecionaria). Aqui ficam só os índices corretos, na MESMA
 * ordem de quizzes.ts — um cross-check no vitest quebra se saírem de sincronia.
 *
 * Regra da Onda 4: aprovar = acertar TODAS as 3 questões da aula. Aprovar é o que
 * conclui a aula e libera o XP (o crédito idempotente da Onda 3). Sem tabela nova.
 *
 * Cada entrada é a lista ordenada dos índices corretos (0-based) das 3 questões.
 * O comentário resume a pergunta/opção correta — o texto completo mora em quizzes.ts.
 */
final class AcademyQuiz
{
    /** quantas alternativas cada questão tem (valida o range das respostas) */
    public const OPTIONS_PER_QUESTION = 3;

    /** @var array<string, list<int>> lesson_key => índices corretos das 3 questões */
    private const ANSWERS = [
        // ── Trilha: Meu Mundo Digital Seguro ──
        'seguranca-intro' => [1, 1, 1],
        // Q1 "Ficar seguro na internet é:" → "Saber com quem você fala"
        // Q2 "Se algo te deixar com medo online:" → "Pedir ajuda a um adulto de confiança"
        // Q3 "Seus segredos você conta para:" → "Só quem você conhece e confia"

        'senha-secreta' => [0, 1, 1],
        // Q1 "Sua senha é parecida com:" → "A chave da sua casa"
        // Q2 "Quem pode saber sua senha?" → "Um adulto da família, se precisar ajudar"
        // Q3 "Se pedirem sua senha por mensagem:" → "Diz não e avisa um adulto"

        'falar-com-adulto' => [1, 0, 1],
        // Q1 "Chamar um adulto quando algo dá errado é:" → "Coisa de gente esperta"
        // Q2 "Você deve chamar um adulto se:" → "Um desconhecido quiser conversar"
        // Q3 "Se pedem segredo dos seus pais:" → "Conta pra um adulto de confiança"

        'pistas-de-golpe' => [1, 1, 0],
        // Q1 "Prêmio grátis se clicar. Isso é:" → "Pode ser uma cilada"
        // Q2 "Se pedem nome e senha pra 'ganhar' prêmio:" → "Não manda e avisa um adulto"
        // Q3 "'Bom demais para ser verdade' geralmente:" → "É uma cilada"

        // ── Trilha: Meu Tempo de Tela ──
        'tempo-intro' => [0, 1, 1],
        // Q1 "Usar telas é um pouco como:" → "Comer doce"
        // Q2 "Ter equilíbrio com a tela é:" → "Brincar na tela e também longe dela"
        // Q3 "Quanto tempo de tela é o certo você:" → "Combina com a família"

        'fazer-pausas' => [1, 0, 1],
        // Q1 "Depois de um tempo na tela, seu corpo pede:" → "Um descanso"
        // Q2 "Uma boa pausa é:" → "Levantar e esticar o corpo"
        // Q3 "Quando fazer uma pausa?" → "A cada fase ou capítulo"

        'tela-e-sono' => [1, 0, 1],
        // Q1 "Perto da hora de dormir, a tela:" → "Deixa o cérebro ligado"
        // Q2 "Antes de dormir é bom:" → "Guardar a tela um tempinho"
        // Q3 "Dormir bem te deixa:" → "Mais disposto pra brincar e aprender"

        'brincar-sem-tela' => [1, 0, 1],
        // Q1 "Diversão sem tela:" → "Existe um monte"
        // Q2 "Um exemplo de brincar sem tela:" → "Correr e brincar ao ar livre"
        // Q3 "O desafio da aula é:" → "Escolher uma brincadeira sem tela"
    ];

    /** A aula tem quiz? (também é a fonte de "aula válida" na Onda 4.) */
    public function hasQuiz(string $lessonKey): bool
    {
        return isset(self::ANSWERS[$lessonKey]);
    }

    /** Quantas questões a aula tem (0 se não houver quiz). */
    public function questionCount(string $lessonKey): int
    {
        return count(self::ANSWERS[$lessonKey] ?? []);
    }

    /**
     * Corrige as respostas de uma aula. PURO.
     *
     * Aprova só quando TODAS acertam e a quantidade de respostas bate com a de
     * questões (não dá pra passar mandando menos respostas). Resposta fora do
     * range de alternativas simplesmente não acerta.
     *
     * @param list<int> $answers índices escolhidos (0-based), em ordem das questões
     * @return array{passed: bool, correct: int, total: int}
     */
    public function grade(string $lessonKey, array $answers): array
    {
        $key   = self::ANSWERS[$lessonKey] ?? [];
        $total = count($key);

        $correct = 0;
        foreach ($key as $i => $expected) {
            if (isset($answers[$i]) && (int) $answers[$i] === $expected) {
                $correct++;
            }
        }

        $passed = $total > 0 && $correct === $total && count($answers) === $total;

        return ['passed' => $passed, 'correct' => $correct, 'total' => $total];
    }
}
