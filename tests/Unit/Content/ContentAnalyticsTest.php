<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Content;

use GuardKids\Content\ContentAnalytics;
use PHPUnit\Framework\TestCase;

final class ContentAnalyticsTest extends TestCase
{
    public function testComputesMostAccessedCategoriesAndTime(): void
    {
        $items = [
            ['id' => 10, 'title' => 'Roblox', 'category_id' => 1],
            ['id' => 11, 'title' => 'Khan', 'category_id' => 2],
        ];
        $categories = [
            ['id' => 1, 'name' => 'Jogos'],
            ['id' => 2, 'name' => 'Aprender'],
        ];
        $history = [
            ['content_id' => 10, 'action' => 'open', 'duration_seconds' => 120],
            ['content_id' => 10, 'action' => 'open', 'duration_seconds' => 60],
            ['content_id' => 11, 'action' => 'open', 'duration_seconds' => 300],
        ];

        $out = ContentAnalytics::compute($history, $items, $categories);

        self::assertSame(10, $out['mostAccessed'][0]['contentId']);
        self::assertSame('Roblox', $out['mostAccessed'][0]['title']);
        self::assertSame(2, $out['mostAccessed'][0]['opens']);

        self::assertSame('Jogos', $out['favoriteCategories'][0]['category']);
        self::assertSame(2, $out['favoriteCategories'][0]['opens']);

        $byCat = [];
        foreach ($out['timePerCategory'] as $t) {
            $byCat[$t['category']] = $t['minutes'];
        }
        self::assertSame(3, $byCat['Jogos']);
        self::assertSame(5, $byCat['Aprender']);
    }
}
