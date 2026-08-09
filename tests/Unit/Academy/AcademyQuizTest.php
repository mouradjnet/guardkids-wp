<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Academy;

use GuardKids\Academy\AcademyQuiz;
use PHPUnit\Framework\TestCase;

/**
 * AcademyQuiz — gabarito e correção dos quizzes (Onda 4).
 *
 * O crítico: aprovar SÓ com tudo certo (é o que porteia a conclusão + XP), e o
 * gabarito cobrir exatamente as 8 aulas da Onda 3, com 3 questões cada e índices
 * dentro do range de alternativas.
 */
final class AcademyQuizTest extends TestCase
{
    /** as 8 aulas da Onda 3 — o quiz precisa cobrir exatamente estas */
    private const LESSON_KEYS = [
        'seguranca-intro', 'senha-secreta', 'falar-com-adulto', 'pistas-de-golpe',
        'tempo-intro', 'fazer-pausas', 'tela-e-sono', 'brincar-sem-tela',
    ];

    private AcademyQuiz $quiz;

    protected function setUp(): void
    {
        $this->quiz = new AcademyQuiz();
    }

    public function testTodasAsAulasTemQuizDe3Questoes(): void
    {
        foreach (self::LESSON_KEYS as $key) {
            self::assertTrue($this->quiz->hasQuiz($key), "sem quiz: {$key}");
            self::assertSame(3, $this->quiz->questionCount($key), "questoes != 3: {$key}");
        }
    }

    public function testAulaDesconhecidaNaoTemQuiz(): void
    {
        self::assertFalse($this->quiz->hasQuiz('xp-gratis-farm'));
        self::assertSame(0, $this->quiz->questionCount('xp-gratis-farm'));
        $r = $this->quiz->grade('xp-gratis-farm', [0, 0, 0]);
        self::assertFalse($r['passed']);
        self::assertSame(0, $r['total']);
    }

    public function testAprovaSoComTudoCerto(): void
    {
        // seguranca-intro: gabarito [1,1,1]
        $ok = $this->quiz->grade('seguranca-intro', [1, 1, 1]);
        self::assertTrue($ok['passed']);
        self::assertSame(3, $ok['correct']);
        self::assertSame(3, $ok['total']);

        // um erro → reprova
        $umErro = $this->quiz->grade('seguranca-intro', [1, 1, 0]);
        self::assertFalse($umErro['passed']);
        self::assertSame(2, $umErro['correct']);
    }

    public function testRespostasIncompletasNaoAprovam(): void
    {
        // mandar menos respostas que questões nunca aprova
        $r = $this->quiz->grade('senha-secreta', [0, 1]);
        self::assertFalse($r['passed']);
    }

    public function testRespostaForaDoRangeNaoAcerta(): void
    {
        // alternativa 9 não existe → aquela questão erra
        $r = $this->quiz->grade('senha-secreta', [9, 1, 1]);
        self::assertFalse($r['passed']);
        self::assertSame(2, $r['correct']);
    }

    public function testTodoIndiceCorretoEstaDentroDoRange(): void
    {
        // Guard contra typo no gabarito: respondendo com o índice == nº de opções
        // (fora do range, ex.: 3 quando há 3 alternativas 0..2), NENHUMA questão
        // pode acertar — prova que todo índice correto é < OPTIONS_PER_QUESTION.
        $out = AcademyQuiz::OPTIONS_PER_QUESTION;
        foreach (self::LESSON_KEYS as $key) {
            $r = $this->quiz->grade($key, [$out, $out, $out]);
            self::assertSame(0, $r['correct'], "indice correto fora do range em: {$key}");
        }
    }
}
