<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\AcademyController;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

/**
 * AcademyController — progresso do responsável em wp_usermeta.
 *
 * O ponto crítico é o ISOLAMENTO: o progresso de um guardião nunca pode vazar
 * para outro. Como a meta é keyada por user id, testamos que dois users têm
 * estados independentes.
 */
final class AcademyControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['gk_current_user_id'] = 0;
        $GLOBALS['gk_user_meta']       = [];
    }

    private function post(string $lessonId, string $kind): WP_REST_Request
    {
        $req = new WP_REST_Request('POST', '/academy/progress');
        $req->set_param('lessonId', $lessonId);
        $req->set_param('kind', $kind);
        return $req;
    }

    public function testEmptyProgressForNewUser(): void
    {
        $GLOBALS['gk_current_user_id'] = 7;
        $data = (new AcademyController())->index()->get_data();
        self::assertSame(['completed' => [], 'dismissed' => []], $data);
    }

    public function testCompleteLessonPersists(): void
    {
        $GLOBALS['gk_current_user_id'] = 7;
        $controller = new AcademyController();

        $controller->update($this->post('primeiros-passos', 'completed'));

        $data = $controller->index()->get_data();
        self::assertSame(['primeiros-passos'], $data['completed']);
        self::assertSame([], $data['dismissed']);
    }

    public function testDismissGoesToDismissedBucket(): void
    {
        $GLOBALS['gk_current_user_id'] = 7;
        $controller = new AcademyController();

        $controller->update($this->post('verificacao-conexao', 'dismissed'));

        $data = $controller->index()->get_data();
        self::assertSame(['verificacao-conexao'], $data['dismissed']);
        self::assertSame([], $data['completed']);
    }

    public function testCompleteIsIdempotent(): void
    {
        $GLOBALS['gk_current_user_id'] = 7;
        $controller = new AcademyController();

        $controller->update($this->post('primeiros-passos', 'completed'));
        $controller->update($this->post('primeiros-passos', 'completed'));

        $data = $controller->index()->get_data();
        self::assertSame(['primeiros-passos'], $data['completed'], 'não pode duplicar');
    }

    public function testProgressIsIsolatedBetweenGuardians(): void
    {
        $controller = new AcademyController();

        $GLOBALS['gk_current_user_id'] = 10;
        $controller->update($this->post('dispositivo-filho', 'completed'));

        $GLOBALS['gk_current_user_id'] = 20;
        $data20 = $controller->index()->get_data();

        // Guardião 20 NÃO vê o progresso do guardião 10.
        self::assertSame([], $data20['completed'], 'progresso vazou entre famílias');

        $GLOBALS['gk_current_user_id'] = 10;
        $data10 = $controller->index()->get_data();
        self::assertSame(['dispositivo-filho'], $data10['completed']);
    }

    public function testRejectsInvalidKind(): void
    {
        $GLOBALS['gk_current_user_id'] = 7;
        $resp = (new AcademyController())->update($this->post('primeiros-passos', 'bogus'));
        self::assertSame(400, $resp->get_status());
    }

    public function testRejectsEmptyLessonId(): void
    {
        $GLOBALS['gk_current_user_id'] = 7;
        $resp = (new AcademyController())->update($this->post('', 'completed'));
        self::assertSame(400, $resp->get_status());
    }

    public function testRejectsAnonymousWrite(): void
    {
        $GLOBALS['gk_current_user_id'] = 0;
        $resp = (new AcademyController())->update($this->post('primeiros-passos', 'completed'));
        self::assertSame(401, $resp->get_status());
    }

    public function testSanitizesLessonIdSlug(): void
    {
        $GLOBALS['gk_current_user_id'] = 7;
        $controller = new AcademyController();

        // caracteres inválidos são removidos; sobra o slug seguro.
        $controller->update($this->post('  Primeiros/Passos!! ', 'completed'));

        $data = $controller->index()->get_data();
        self::assertSame(['primeirospassos'], $data['completed']);
    }
}
