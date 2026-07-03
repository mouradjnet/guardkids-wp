<?php

declare(strict_types=1);

namespace GuardKids\Medals;

/**
 * Catálogo das medalhas permanentes (puro, sem $wpdb). Cada medalha carrega
 * qual `signal` (eixo acumulado) ela lê; ajuste alvos/bônus só aqui.
 */
final class MedalCatalog
{
    /**
     * @return array<int, array{key:string, title:string, description:string, icon:string, signal:string, target:int, xpReward:int, coinsReward:int}>
     */
    public static function all(): array
    {
        return [
            [
                'key'         => 'explorer_10',
                'title'       => 'Explorador',
                'description' => 'Abriu 10 conteúdos',
                'icon'        => 'explore',
                'signal'      => 'totalContentOpened',
                'target'      => 10,
                'xpReward'    => 30,
                'coinsReward' => 20,
            ],
            [
                'key'         => 'devourer_50',
                'title'       => 'Devorador',
                'description' => 'Abriu 50 conteúdos',
                'icon'        => 'auto_stories',
                'signal'      => 'totalContentOpened',
                'target'      => 50,
                'xpReward'    => 60,
                'coinsReward' => 40,
            ],
            [
                'key'         => 'achiever_10',
                'title'       => 'Cumpridor',
                'description' => 'Completou 10 missões',
                'icon'        => 'task_alt',
                'signal'      => 'totalMissionsCompleted',
                'target'      => 10,
                'xpReward'    => 40,
                'coinsReward' => 25,
            ],
            [
                'key'         => 'faithful_7',
                'title'       => 'Fiel',
                'description' => '7 dias de sequência',
                'icon'        => 'local_fire_department',
                'signal'      => 'streakDays',
                'target'      => 7,
                'xpReward'    => 40,
                'coinsReward' => 25,
            ],
            [
                'key'         => 'veteran_10',
                'title'       => 'Veterano',
                'description' => 'Alcançou o nível 10',
                'icon'        => 'military_tech',
                'signal'      => 'level',
                'target'      => 10,
                'xpReward'    => 50,
                'coinsReward' => 30,
            ],
            [
                'key'         => 'curious_master_5',
                'title'       => 'Curioso Master',
                'description' => 'Explorou 5 categorias',
                'icon'        => 'category',
                'signal'      => 'distinctCategoriesAllTime',
                'target'      => 5,
                'xpReward'    => 40,
                'coinsReward' => 25,
            ],
        ];
    }
}
