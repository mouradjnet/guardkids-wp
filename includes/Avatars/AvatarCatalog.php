<?php

declare(strict_types=1);

namespace GuardKids\Avatars;

/**
 * Catálogo dos avatares (puro, sem $wpdb). Cada um tem um `gate`
 * (free/level/medal) com `threshold` (nível) ou `medalKey` (medalha da 3c).
 */
final class AvatarCatalog
{
    /**
     * @return array<int, array{key:string, emoji:string, label:string, gate:string, threshold:int, medalKey:?string}>
     */
    public static function all(): array
    {
        return [
            ['key' => 'star',   'emoji' => '⭐', 'label' => 'Estrela', 'gate' => 'free',  'threshold' => 0,  'medalKey' => null],
            ['key' => 'heart',  'emoji' => '❤️', 'label' => 'Coração', 'gate' => 'free',  'threshold' => 0,  'medalKey' => null],
            ['key' => 'rocket', 'emoji' => '🚀', 'label' => 'Foguete', 'gate' => 'level', 'threshold' => 5,  'medalKey' => null],
            ['key' => 'crown',  'emoji' => '👑', 'label' => 'Coroa',   'gate' => 'level', 'threshold' => 10, 'medalKey' => null],
            ['key' => 'fire',   'emoji' => '🔥', 'label' => 'Chama',   'gate' => 'medal', 'threshold' => 0,  'medalKey' => 'faithful_7'],
            ['key' => 'book',   'emoji' => '📚', 'label' => 'Livro',   'gate' => 'medal', 'threshold' => 0,  'medalKey' => 'devourer_50'],
            ['key' => 'trophy', 'emoji' => '🏅', 'label' => 'Troféu',  'gate' => 'medal', 'threshold' => 0,  'medalKey' => 'veteran_10'],
        ];
    }
}
