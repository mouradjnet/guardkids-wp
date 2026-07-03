<?php

declare(strict_types=1);

namespace GuardKids\Missions;

/**
 * Catálogo das missões diárias (puro, sem $wpdb). As definições vivem no
 * código — não há tabela de definição. Ajuste alvos/bônus só aqui.
 */
final class MissionCatalog
{
    /**
     * @return array<int, array{key:string, title:string, description:string, icon:string, target:int, xpReward:int, coinsReward:int}>
     */
    public static function all(): array
    {
        return [
            [
                'key'         => 'explore_3',
                'title'       => 'Explorador do dia',
                'description' => 'Abra 3 conteúdos hoje',
                'icon'        => 'explore',
                'target'      => 3,
                'xpReward'    => 15,
                'coinsReward' => 10,
            ],
            [
                'key'         => 'categories_2',
                'title'       => 'Curioso',
                'description' => 'Explore 2 categorias diferentes hoje',
                'icon'        => 'category',
                'target'      => 2,
                'xpReward'    => 15,
                'coinsReward' => 10,
            ],
            [
                'key'         => 'streak_today',
                'title'       => 'Presença',
                'description' => 'Volte e mantenha sua sequência hoje',
                'icon'        => 'local_fire_department',
                'target'      => 1,
                'xpReward'    => 10,
                'coinsReward' => 5,
            ],
        ];
    }
}
