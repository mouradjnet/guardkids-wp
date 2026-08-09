<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\Academy\AcademyQuiz;
use GuardKids\Auth\ChildAuth;
use GuardKids\Database\ChildAcademyRepository;
use GuardKids\Database\ProgressionRepository;
use GuardKids\Progression\LevelCurve;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Academy da CRIANÇA (Onda 3 + 4): aulas infantis que rendem XP/coins.
 *
 * Auth por token de pareamento (ChildAuth), como toda rota /child/*.
 *
 * Onda 4: a conclusão é PORTEADA pelo quiz — só aprovar (acertar todas as
 * questões, corrigidas no servidor por AcademyQuiz) conclui a aula e credita o
 * bônus. Não há caminho para XP sem passar pelo quiz. O crédito é UMA vez
 * (ledger UNIQUE em ChildAcademyRepository) e soma na carteira que a gamificação
 * já usa (ProgressionRepository), sem mexer no streak — igual ao creditBonus das
 * missões/medalhas.
 *
 * Servidor é dono do valor do XP e do gabarito: o cliente manda a chave da aula
 * e as respostas; o valor, a validade da aula (AcademyQuiz::hasQuiz) e o gabarito
 * não vêm do cliente (evita farm de XP e cola).
 */
final class ChildAcademyController
{
    private const XP_PER_LESSON    = 25;
    private const COINS_PER_LESSON = 15;

    private readonly ChildAcademyRepository $lessons;
    private readonly ProgressionRepository $progression;
    private readonly AcademyQuiz $quiz;
    private readonly ChildAuth $auth;

    public function __construct()
    {
        $this->lessons     = new ChildAcademyRepository();
        $this->progression = new ProgressionRepository();
        $this->quiz        = new AcademyQuiz();
        $this->auth        = new ChildAuth();
    }

    /** GET /child/academy — aulas concluídas + carteira. */
    public function index(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = $this->auth->resolveChildId($req);
        if ($childId === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }

        return rest_ensure_response([
            'completedKeys' => $this->lessons->listCompleted($childId),
            'progression'   => $this->walletJson($childId),
        ]);
    }

    /**
     * POST /child/academy/quiz — envia as respostas do quiz de uma aula.
     *
     * Aprovar (tudo certo) conclui a aula e credita o bônus, UMA vez (idempotente
     * pelo ledger). Reprovar não credita nada — a criança tenta de novo. Refazer
     * o quiz de uma aula já concluída não re-credita (`justCompleted` volta false).
     */
    public function quiz(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = $this->auth->resolveChildId($req);
        if ($childId === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }

        $key = $this->sanitizeKey((string) $req->get_param('lesson_key'));
        if (! $this->quiz->hasQuiz($key)) {
            return new WP_Error('invalid_lesson', 'Aula desconhecida.', ['status' => 400]);
        }

        $answers = $this->sanitizeAnswers($req->get_param('answers'));
        $result  = $this->quiz->grade($key, $answers);

        $justCompleted = false;
        if ($result['passed'] && ! $this->lessons->existsFor($childId, $key)) {
            $this->creditBonus($childId, self::XP_PER_LESSON, self::COINS_PER_LESSON);
            $this->lessons->record($childId, $key, self::XP_PER_LESSON, self::COINS_PER_LESSON);
            $justCompleted = true;
        }

        return rest_ensure_response([
            'passed'        => $result['passed'],
            'correct'       => $result['correct'],
            'total'         => $result['total'],
            'completedKeys' => $this->lessons->listCompleted($childId),
            'progression'   => $this->walletJson($childId),
            'awarded'       => [
                'justCompleted' => $justCompleted,
                'xp'            => $justCompleted ? self::XP_PER_LESSON : 0,
                'coins'         => $justCompleted ? self::COINS_PER_LESSON : 0,
            ],
        ]);
    }

    /** Schema de args da rota POST. */
    public function quizArgs(): array
    {
        return [
            'lesson_key' => [
                'type'     => 'string',
                'required' => true,
            ],
            'answers' => [
                'type'     => 'array',
                'required' => true,
                'items'    => ['type' => 'integer'],
            ],
        ];
    }

    /**
     * Normaliza as respostas para uma lista de inteiros 0-based. Um teto defensivo
     * evita payload gigante — o grade só olha as N primeiras (N = nº de questões).
     *
     * @param mixed $raw
     * @return list<int>
     */
    private function sanitizeAnswers($raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $answers = array_map(static fn ($v): int => (int) $v, array_values($raw));

        return array_slice($answers, 0, 20);
    }

    /**
     * Soma XP/coins na carteira preservando streak e última atividade — mesma
     * convenção do creditBonus de missões/medalhas.
     */
    private function creditBonus(int $childId, int $xp, int $coins): void
    {
        $row    = $this->progression->ensure($childId);
        $streak = (int) ($row['streak_days'] ?? 0);
        $last   = (string) ($row['last_activity_date'] ?? '');
        $this->progression->apply($childId, $xp, $coins, $streak, $last);
    }

    /** Slug seguro: minúsculas, apenas [a-z0-9-], recortado. */
    private function sanitizeKey(string $raw): string
    {
        $slug = strtolower(trim($raw));
        $slug = (string) preg_replace('/[^a-z0-9-]/', '', $slug);

        return substr($slug, 0, 60);
    }

    /**
     * @return array{xp:int, coins:int, level:int, xpIntoLevel:int, xpForNextLevel:int, streakDays:int}
     */
    private function walletJson(int $childId): array
    {
        $row    = $this->progression->findByChild($childId);
        $xp     = $row !== null ? (int) $row['xp'] : 0;
        $coins  = $row !== null ? (int) $row['coins'] : 0;
        $streak = $row !== null ? (int) $row['streak_days'] : 0;
        $p = LevelCurve::progressInLevel($xp);

        return [
            'xp'             => $xp,
            'coins'          => $coins,
            'level'          => $p['level'],
            'xpIntoLevel'    => $p['xpIntoLevel'],
            'xpForNextLevel' => $p['xpForNextLevel'],
            'streakDays'     => $streak,
        ];
    }
}
