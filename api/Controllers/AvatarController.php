<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\Auth\ChildAuth;
use GuardKids\Avatars\AvatarCatalog;
use GuardKids\Avatars\AvatarEvaluator;
use GuardKids\Database\MedalUnlockRepository;
use GuardKids\Database\ProgressionRepository;
use GuardKids\Progression\LevelCurve;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Avatares do filho: listar (com desbloqueio derivado de nível+medalhas) e
 * equipar (só se desbloqueado). Cosmético, sem envolvimento dos pais.
 */
final class AvatarController
{
    private readonly ProgressionRepository $progression;
    private readonly MedalUnlockRepository $medals;
    private readonly ChildAuth $auth;

    public function __construct()
    {
        $this->progression = new ProgressionRepository();
        $this->medals      = new MedalUnlockRepository();
        $this->auth        = new ChildAuth();
    }

    public function childAvatars(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = $this->auth->resolveChildId($req);
        if ($childId === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }
        $equipped = $this->equippedKey($childId);
        $avatars  = array_map(
            static function (array $a) use ($equipped): array {
                $a['isEquipped'] = $a['key'] === $equipped;
                return $a;
            },
            AvatarEvaluator::evaluate($this->signals($childId)),
        );
        return rest_ensure_response(['equipped' => $equipped, 'avatars' => $avatars]);
    }

    public function equip(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = $this->auth->resolveChildId($req);
        if ($childId === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }
        $key = (string) $req->get_param('avatarKey');
        if (! in_array($key, array_column(AvatarCatalog::all(), 'key'), true)) {
            return new WP_Error('avatar_not_found', 'Avatar inexistente.', ['status' => 404]);
        }
        $target = null;
        foreach (AvatarEvaluator::evaluate($this->signals($childId)) as $a) {
            if ($a['key'] === $key) {
                $target = $a;
                break;
            }
        }
        if ($target === null || $target['unlocked'] === false) {
            return new WP_Error('avatar_locked', 'Esse avatar ainda está bloqueado.', ['status' => 409]);
        }
        $this->progression->setEquippedAvatar($childId, $key);
        return rest_ensure_response(['equipped' => $key]);
    }

    /**
     * @return array{level:int, unlockedMedals:array<int,string>}
     */
    private function signals(int $childId): array
    {
        $row = $this->progression->findByChild($childId);
        $xp  = $row !== null ? (int) $row['xp'] : 0;
        return [
            'level'          => LevelCurve::levelForXp($xp),
            'unlockedMedals' => $this->medals->unlockedKeys($childId),
        ];
    }

    private function equippedKey(int $childId): string
    {
        $row = $this->progression->findByChild($childId);
        $key = $row !== null ? ($row['equipped_avatar'] ?? null) : null;
        return is_string($key) && $key !== '' ? $key : 'star';
    }
}
