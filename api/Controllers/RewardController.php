<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\Auth\ChildAuth;
use GuardKids\Database\ProgressionRepository;
use GuardKids\Database\RewardRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Catálogo de recompensas: CRUD dos pais (admin) + loja do filho (token).
 */
final class RewardController
{
    private readonly RewardRepository $repo;
    private readonly ProgressionRepository $progression;
    private readonly ChildAuth $auth;

    public function __construct()
    {
        $this->repo        = new RewardRepository();
        $this->progression = new ProgressionRepository();
        $this->auth        = new ChildAuth();
    }

    public function index(WP_REST_Request $req): WP_REST_Response
    {
        return rest_ensure_response(array_map([$this, 'toJson'], $this->repo->findAll('id')));
    }

    public function create(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $title = trim((string) $req->get_param('title'));
        $cost  = (int) $req->get_param('costCoins');
        if ($title === '' || $cost < 1) {
            return new WP_Error('invalid_payload', 'Título e custo (≥1) são obrigatórios.', ['status' => 422]);
        }
        $icon = $req->get_param('icon');
        $id = $this->repo->insert([
            'title'      => $title,
            'cost_coins' => $cost,
            'icon'       => is_string($icon) && $icon !== '' ? sanitize_text_field($icon) : null,
            'active'     => 1,
        ]);
        if ($id === 0) {
            return new WP_Error('db_error', 'Não foi possível salvar.', ['status' => 500]);
        }
        return new WP_REST_Response($this->toJson($this->repo->findById($id) ?? []), 201);
    }

    public function update(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $id  = (int) $req['id'];
        $row = $this->repo->findById($id);
        if ($row === null) {
            return new WP_Error('not_found', 'Recompensa não encontrada.', ['status' => 404]);
        }
        $data = [];
        if ($req->get_param('title') !== null) {
            $title = trim((string) $req->get_param('title'));
            if ($title === '') {
                return new WP_Error('invalid_payload', 'Título não pode ser vazio.', ['status' => 422]);
            }
            $data['title'] = $title;
        }
        if ($req->get_param('costCoins') !== null) {
            $cost = (int) $req->get_param('costCoins');
            if ($cost < 1) {
                return new WP_Error('invalid_payload', 'Custo deve ser ≥ 1.', ['status' => 422]);
            }
            $data['cost_coins'] = $cost;
        }
        if ($req->get_param('icon') !== null) {
            $icon = (string) $req->get_param('icon');
            $data['icon'] = $icon !== '' ? sanitize_text_field($icon) : null;
        }
        if ($req->get_param('active') !== null) {
            $data['active'] = $req->get_param('active') ? 1 : 0;
        }
        if ($data !== []) {
            $this->repo->update($id, $data);
        }
        return rest_ensure_response($this->toJson($this->repo->findById($id) ?? []));
    }

    public function destroy(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $id = (int) $req['id'];
        if (! $this->repo->delete($id)) {
            return new WP_Error('db_error', 'Falha ao deletar.', ['status' => 500]);
        }
        return rest_ensure_response(['deleted' => true]);
    }

    public function childRewards(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = $this->auth->resolveChildId($req);
        if ($childId === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }
        $wallet  = $this->progression->findByChild($childId);
        $balance = $wallet !== null ? (int) $wallet['coins'] : 0;
        return rest_ensure_response([
            'balance' => $balance,
            'rewards' => array_map([$this, 'toJson'], $this->repo->findActive()),
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function toJson(array $row): array
    {
        return [
            'id'        => (int) ($row['id'] ?? 0),
            'title'     => (string) ($row['title'] ?? ''),
            'costCoins' => (int) ($row['cost_coins'] ?? 0),
            'icon'      => $row['icon'] ?? null,
            'active'    => (int) ($row['active'] ?? 0) === 1,
        ];
    }
}
