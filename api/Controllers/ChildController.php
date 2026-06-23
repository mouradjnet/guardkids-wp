<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\Database\ChildRepository;
use GuardKids\License\Gate;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class ChildController
{
    private readonly ChildRepository $repo;
    private readonly Gate $gate;

    public function __construct(?Gate $gate = null)
    {
        $this->repo = new ChildRepository();
        $this->gate = $gate ?? new Gate();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function createArgs(): array
    {
        return [
            'name'          => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
            'slug'          => ['type' => 'string', 'sanitize_callback' => 'sanitize_title'],
            'age'           => ['type' => 'integer', 'minimum' => 0, 'maximum' => 21],
            'avatar_url'    => ['type' => 'string', 'sanitize_callback' => 'esc_url_raw'],
            'device'        => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            'limit_minutes' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1440],
            'daily_limit_enabled' => [
                'type' => 'boolean',
            ],
            'bedtime_enabled' => [
                'type' => 'boolean',
            ],
            'bedtime_start' => [
                'type'              => 'string',
                'pattern'           => '^([01]\\d|2[0-3]):[0-5]\\d$',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'bedtime_end' => [
                'type'              => 'string',
                'pattern'           => '^([01]\\d|2[0-3]):[0-5]\\d$',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'allowed_weekdays' => [
                'type'              => 'string',
                'pattern'           => '^[YN]{7}$',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function updateArgs(): array
    {
        $args = $this->createArgs();
        $args['name']['required'] = false;
        return $args;
    }

    public function index(): WP_REST_Response
    {
        return rest_ensure_response(array_map([$this, 'toJson'], $this->repo->findAll('name')));
    }

    public function show(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $row = $this->repo->findById((int) $req['id']);
        if ($row === null) {
            return new WP_Error('not_found', 'Filho não encontrado.', ['status' => 404]);
        }
        return rest_ensure_response($this->toJson($row));
    }

    public function create(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $name = (string) $req->get_param('name');
        if ($name === '') {
            return new WP_Error('invalid_payload', 'Nome obrigatório.', ['status' => 422]);
        }

        // Free permite 1 filho; premium ilimitado.
        if (! $this->gate->can('unlimited_kids') && \count($this->repo->findAll('id')) >= 1) {
            return new WP_Error(
                'plan_limit',
                'O plano Free permite 1 filho. Faça upgrade pra cadastrar mais.',
                ['status' => 402],
            );
        }

        // Bedtime/schedule precisam de premium.
        if ($this->touchesSchedule($req) && ! $this->gate->can('schedule')) {
            return new WP_Error(
                'plan_limit',
                'Rotina escolar (bedtime/weekdays) é uma feature Premium.',
                ['status' => 402],
            );
        }

        $slug = $this->uniqueSlug((string) ($req->get_param('slug') ?? sanitize_title($name)));

        $id = $this->repo->insert([
            'slug'          => $slug,
            'name'          => $name,
            'age'           => $req->get_param('age'),
            'avatar_url'    => $req->get_param('avatar_url'),
            'device'        => $req->get_param('device'),
            'status'        => 'offline',
            'used_minutes'  => 0,
            'limit_minutes' => $req->get_param('limit_minutes') ?? 60,
            'daily_limit_enabled' => (int) ((bool) ($req->get_param('daily_limit_enabled') ?? false)),
        ]);

        if ($id === 0) {
            return new WP_Error('db_error', 'Não foi possível salvar.', ['status' => 500]);
        }

        $created = $this->repo->findById($id);
        return new WP_REST_Response($this->toJson($created ?? []), 201);
    }

    /**
     * Garante slug único — a coluna `slug` é UNIQUE global. Anexa sufixo
     * numérico (`lucas` → `lucas-2` → `lucas-3`…) enquanto o slug já existir,
     * evitando "Duplicate entry" no insert → 500 ao cadastrar filhos de mesmo
     * nome (inclusive entre responsáveis distintos). Fallback "filho" quando
     * o nome sanitiza pra vazio.
     */
    private function uniqueSlug(string $base): string
    {
        $base = $base !== '' ? $base : 'filho';
        $slug = $base;
        $n    = 2;
        while ($this->repo->findBySlug($slug) !== null) {
            $slug = $base . '-' . $n;
            $n++;
        }
        return $slug;
    }

    public function update(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $id  = (int) $req['id'];
        $row = $this->repo->findById($id);
        if ($row === null) {
            return new WP_Error('not_found', 'Filho não encontrado.', ['status' => 404]);
        }

        if ($this->touchesSchedule($req) && ! $this->gate->can('schedule')) {
            return new WP_Error(
                'plan_limit',
                'Rotina escolar (bedtime/weekdays) é uma feature Premium.',
                ['status' => 402],
            );
        }

        $bedtimeEnabledParam = $req->get_param('bedtime_enabled');
        $bedtimeStartParam   = $req->get_param('bedtime_start');
        $bedtimeEndParam     = $req->get_param('bedtime_end');
        $dailyEnabledParam   = $req->get_param('daily_limit_enabled');

        // Validação: enabled=true exige start e end (na request ou no row atual)
        $futureEnabled = $bedtimeEnabledParam === null
            ? ((int) ($row['bedtime_enabled'] ?? 0) === 1)
            : (bool) $bedtimeEnabledParam;

        if ($futureEnabled) {
            $futureStart = $bedtimeStartParam ?? ($row['bedtime_start'] ?? null);
            $futureEnd   = $bedtimeEndParam   ?? ($row['bedtime_end']   ?? null);
            if (! is_string($futureStart) || ! is_string($futureEnd) || $futureStart === '' || $futureEnd === '') {
                return new WP_Error('invalid_payload', 'bedtime_enabled exige start e end definidos.', ['status' => 422]);
            }
        }

        $patch = array_filter([
            'name'             => $req->get_param('name'),
            'age'              => $req->get_param('age'),
            'avatar_url'       => $req->get_param('avatar_url'),
            'device'           => $req->get_param('device'),
            'limit_minutes'    => $req->get_param('limit_minutes'),
            'daily_limit_enabled' => $dailyEnabledParam === null ? null : ((int) ((bool) $dailyEnabledParam)),
            'bedtime_enabled'  => $bedtimeEnabledParam === null ? null : ((int) ((bool) $bedtimeEnabledParam)),
            'bedtime_start'    => $this->coerceTime($bedtimeStartParam),
            'bedtime_end'      => $this->coerceTime($bedtimeEndParam),
            'allowed_weekdays' => $req->get_param('allowed_weekdays'),
        ], static fn ($v): bool => $v !== null);

        if ($patch !== [] && ! $this->repo->update($id, $patch)) {
            return new WP_Error('db_error', 'Falha ao atualizar.', ['status' => 500]);
        }
        return rest_ensure_response($this->toJson($this->repo->findById($id) ?? []));
    }

    /**
     * Detecta se o request mexe em algum campo de schedule/bedtime.
     */
    private function touchesSchedule(WP_REST_Request $req): bool
    {
        foreach (['bedtime_enabled', 'bedtime_start', 'bedtime_end', 'allowed_weekdays'] as $field) {
            if ($req->get_param($field) !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * Converte 'HH:MM' (validado por pattern) em 'HH:MM:00' pra coluna TIME.
     */
    private function coerceTime(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        return preg_match('/^\d{2}:\d{2}$/', $value) === 1 ? $value . ':00' : null;
    }

    public function destroy(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $id = (int) $req['id'];
        if (! $this->repo->delete($id)) {
            return new WP_Error('db_error', 'Falha ao deletar.', ['status' => 500]);
        }
        return rest_ensure_response(['deleted' => true, 'id' => $id]);
    }

    public function pause(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        return $this->setStatus((int) $req['id'], 'paused');
    }

    public function resume(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        return $this->setStatus((int) $req['id'], 'offline');
    }

    private function setStatus(int $id, string $status): WP_REST_Response|WP_Error
    {
        $row = $this->repo->findById($id);
        if ($row === null) {
            return new WP_Error('not_found', 'Filho não encontrado.', ['status' => 404]);
        }
        if (! $this->repo->update($id, ['status' => $status])) {
            return new WP_Error('db_error', 'Falha ao atualizar.', ['status' => 500]);
        }
        return rest_ensure_response($this->toJson($this->repo->findById($id) ?? []));
    }

    public function issueDeviceToken(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $id   = (int) $req['id'];
        $row  = $this->repo->findById($id);
        if ($row === null) {
            return new WP_Error('not_found', 'Filho não encontrado.', ['status' => 404]);
        }

        $label = $req->get_param('label');
        $label = is_string($label) ? sanitize_text_field($label) : null;

        $issued = (new \GuardKids\Auth\ChildAuth())->issueToken($id, $label);

        return new WP_REST_Response([
            'token'     => $issued['token'],
            'childId'   => $id,
            'label'     => $label,
            'createdAt' => gmdate('c'),
            'notice'    => 'Anote o token agora — ele não é exibido de novo.',
        ], 201);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function toJson(array $row): array
    {
        return [
            'id'           => (int) ($row['id'] ?? 0),
            'slug'         => (string) ($row['slug'] ?? ''),
            'name'         => (string) ($row['name'] ?? ''),
            'age'          => isset($row['age']) ? (int) $row['age'] : null,
            'avatarUrl'    => $row['avatar_url'] ?? null,
            'device'       => $row['device'] ?? null,
            'status'       => (string) ($row['status'] ?? 'offline'),
            'usedMinutes'  => (int) ($row['used_minutes'] ?? 0),
            'limitMinutes' => (int) ($row['limit_minutes'] ?? 60),
            'dailyLimitEnabled' => (int) ($row['daily_limit_enabled'] ?? 0) === 1,
            'bedtimeEnabled'  => (int) ($row['bedtime_enabled'] ?? 0) === 1,
            'bedtimeStart'    => isset($row['bedtime_start']) && is_string($row['bedtime_start'])
                                 ? substr($row['bedtime_start'], 0, 5) : null,
            'bedtimeEnd'      => isset($row['bedtime_end']) && is_string($row['bedtime_end'])
                                 ? substr($row['bedtime_end'], 0, 5) : null,
            'allowedWeekdays' => (string) ($row['allowed_weekdays'] ?? 'YYYYYYY'),
            'createdAt'    => $row['created_at'] ?? null,
            'updatedAt'    => $row['updated_at'] ?? null,
        ];
    }
}
