<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use WP_REST_Request;
use WP_REST_Response;

/**
 * Progresso do Academy do RESPONSÁVEL (Onda 1).
 *
 * Persiste "aula concluída / recomendação dispensada" em `wp_usermeta`, por
 * usuário logado — isolamento por família é automático (a meta vive na linha do
 * próprio user; um guardião nunca lê a do outro). Sem tabela nova nesta onda; a
 * tabela dedicada chega na Onda 2, quando houver trilhas.
 *
 * Auth: `RestApi::requireCollaboratorOrAbove` (qualquer guardião ativo tem o seu
 * próprio progresso). Reusa a autenticação existente — não cria login novo.
 */
final class AcademyController
{
    private const META_KEY = 'guardkids_academy_progress';

    /** teto defensivo pra cada lista não crescer sem limite */
    private const MAX_IDS = 100;

    /** GET /academy/progress — devolve { completed, dismissed } do user logado. */
    public function index(): WP_REST_Response
    {
        return \rest_ensure_response($this->read($this->currentUserId()));
    }

    /**
     * POST /academy/progress — marca um lessonId como `completed` ou `dismissed`.
     * Idempotente: repetir não duplica.
     */
    public function update(WP_REST_Request $request): WP_REST_Response
    {
        $userId = $this->currentUserId();
        if ($userId === 0) {
            return new WP_REST_Response(['error' => 'not_authenticated'], 401);
        }

        $lessonId = $this->sanitizeId((string) $request->get_param('lessonId'));
        $kind     = (string) $request->get_param('kind');

        if ($lessonId === '' || ! in_array($kind, ['completed', 'dismissed'], true)) {
            return new WP_REST_Response(['error' => 'invalid_params'], 400);
        }

        $state = $this->read($userId);
        if (! in_array($lessonId, $state[$kind], true)) {
            $state[$kind][] = $lessonId;
            $state[$kind]   = array_slice($state[$kind], -self::MAX_IDS);
        }

        \update_user_meta($userId, self::META_KEY, $state);

        return \rest_ensure_response($state);
    }

    /**
     * Schema de args da rota POST.
     *
     * @return array<string, array<string, mixed>>
     */
    public function updateArgs(): array
    {
        return [
            'lessonId' => [
                'type'     => 'string',
                'required' => true,
            ],
            'kind' => [
                'type'     => 'string',
                'required' => true,
                'enum'     => ['completed', 'dismissed'],
            ],
        ];
    }

    private function currentUserId(): int
    {
        return function_exists('get_current_user_id') ? (int) \get_current_user_id() : 0;
    }

    /**
     * Slug seguro: minúsculas, apenas [a-z0-9-], recortado. Não depende de
     * sanitize_key (que nem sempre está disponível fora do WP).
     */
    private function sanitizeId(string $raw): string
    {
        $slug = strtolower(trim($raw));
        $slug = (string) preg_replace('/[^a-z0-9-]/', '', $slug);

        return substr($slug, 0, 64);
    }

    /**
     * Lê o estado do usuário, normalizando para { completed: string[], dismissed: string[] }.
     *
     * @return array{completed: list<string>, dismissed: list<string>}
     */
    private function read(int $userId): array
    {
        $raw = ($userId > 0 && function_exists('get_user_meta'))
            ? \get_user_meta($userId, self::META_KEY, true)
            : '';

        return [
            'completed' => $this->normalizeList(is_array($raw) ? ($raw['completed'] ?? null) : null),
            'dismissed' => $this->normalizeList(is_array($raw) ? ($raw['dismissed'] ?? null) : null),
        ];
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function normalizeList($value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
