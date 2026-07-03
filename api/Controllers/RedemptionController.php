<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\Auth\ChildAuth;
use GuardKids\Database\ChildRepository;
use GuardKids\Database\ProgressionRepository;
use GuardKids\Database\RewardRedemptionRepository;
use GuardKids\Database\RewardRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Resgates de recompensa: filho pede (token), pai aprova/nega (admin). A
 * aprovação deduz o snapshot de coins de forma atômica; a negação não mexe.
 */
final class RedemptionController
{
    private readonly RewardRedemptionRepository $repo;
    private readonly RewardRepository $rewards;
    private readonly ProgressionRepository $progression;
    private readonly ChildRepository $children;
    private readonly ChildAuth $auth;

    public function __construct()
    {
        $this->repo        = new RewardRedemptionRepository();
        $this->rewards     = new RewardRepository();
        $this->progression = new ProgressionRepository();
        $this->children    = new ChildRepository();
        $this->auth        = new ChildAuth();
    }

    public function childCreate(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = $this->auth->resolveChildId($req);
        if ($childId === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }
        $rewardId = (int) $req->get_param('rewardId');
        $reward   = $this->rewards->findById($rewardId);
        if ($reward === null || (int) ($reward['active'] ?? 0) !== 1) {
            return new WP_Error('reward_unavailable', 'Recompensa indisponível.', ['status' => 404]);
        }
        if ($this->repo->hasPendingFor($childId, $rewardId)) {
            return new WP_Error('already_pending', 'Você já tem um pedido pendente dessa recompensa.', ['status' => 409]);
        }
        $cost   = (int) $reward['cost_coins'];
        $wallet = $this->progression->findByChild($childId);
        $balance = $wallet !== null ? (int) $wallet['coins'] : 0;
        if ($balance < $cost) {
            return new WP_Error('insufficient_funds', 'Coins insuficientes.', ['status' => 409]);
        }
        $id = $this->repo->create($childId, $rewardId, $cost);
        if ($id === 0) {
            return new WP_Error('db_error', 'Não foi possível salvar.', ['status' => 500]);
        }
        return new WP_REST_Response($this->toChildJson($this->repo->findById($id) ?? []), 201);
    }

    public function childIndex(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = $this->auth->resolveChildId($req);
        if ($childId === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }
        return rest_ensure_response(array_map([$this, 'toChildJson'], $this->repo->findByChild($childId)));
    }

    public function index(WP_REST_Request $req): WP_REST_Response
    {
        $status = (string) ($req->get_param('status') ?? 'pending');
        $rows = $status === 'all' || $status === ''
            ? $this->repo->findByStatus('pending')
            : $this->repo->findByStatus($status);
        return rest_ensure_response(array_map([$this, 'toParentJson'], $rows));
    }

    public function approve(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $id  = (int) $req['id'];
        $row = $this->repo->findById($id);
        if ($row === null) {
            return new WP_Error('not_found', 'Resgate não encontrado.', ['status' => 404]);
        }
        if ($row['status'] !== 'pending') {
            return new WP_Error('already_decided', 'Resgate já foi decidido.', ['status' => 409]);
        }
        if (! $this->progression->spend((int) $row['child_id'], (int) $row['cost_coins'])) {
            return new WP_Error('insufficient_funds', 'Saldo insuficiente para aprovar.', ['status' => 409]);
        }
        if (! $this->repo->decide($id, 'approved', get_current_user_id())) {
            return new WP_Error('db_error', 'Falha ao salvar.', ['status' => 500]);
        }
        return rest_ensure_response($this->toParentJson($this->repo->findById($id) ?? []));
    }

    public function deny(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $id  = (int) $req['id'];
        $row = $this->repo->findById($id);
        if ($row === null) {
            return new WP_Error('not_found', 'Resgate não encontrado.', ['status' => 404]);
        }
        if ($row['status'] !== 'pending') {
            return new WP_Error('already_decided', 'Resgate já foi decidido.', ['status' => 409]);
        }
        if (! $this->repo->decide($id, 'denied', get_current_user_id())) {
            return new WP_Error('db_error', 'Falha ao salvar.', ['status' => 500]);
        }
        return rest_ensure_response($this->toParentJson($this->repo->findById($id) ?? []));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function toChildJson(array $row): array
    {
        $reward = $this->rewards->findById((int) ($row['reward_id'] ?? 0));
        return [
            'id'        => (int) ($row['id'] ?? 0),
            'rewardId'  => (int) ($row['reward_id'] ?? 0),
            'title'     => (string) ($reward['title'] ?? '—'),
            'icon'      => $reward['icon'] ?? null,
            'costCoins' => (int) ($row['cost_coins'] ?? 0),
            'status'    => (string) ($row['status'] ?? 'pending'),
            'createdAt' => $row['created_at'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function toParentJson(array $row): array
    {
        $reward = $this->rewards->findById((int) ($row['reward_id'] ?? 0));
        $child  = $this->children->findById((int) ($row['child_id'] ?? 0));
        return [
            'id'         => (int) ($row['id'] ?? 0),
            'childId'    => (int) ($row['child_id'] ?? 0),
            'childName'  => (string) ($child['name'] ?? 'Filho'),
            'rewardId'   => (int) ($row['reward_id'] ?? 0),
            'title'      => (string) ($reward['title'] ?? '—'),
            'costCoins'  => (int) ($row['cost_coins'] ?? 0),
            'status'     => (string) ($row['status'] ?? 'pending'),
            'decidedAt'  => $row['decided_at'] ?? null,
            'createdAt'  => $row['created_at'] ?? null,
        ];
    }
}
