<?php

declare(strict_types=1);

namespace GuardKids\Content;

/**
 * Calcula analytics da biblioteca em PHP puro (escala de família, sem JOIN).
 * Recebe linhas cruas de history/items/categories e devolve os 3 blocos.
 */
final class ContentAnalytics
{
    /**
     * @param array<int, array<string, mixed>> $history
     * @param array<int, array<string, mixed>> $items
     * @param array<int, array<string, mixed>> $categories
     * @return array{
     *   mostAccessed: array<int, array{contentId:int,title:string,opens:int}>,
     *   favoriteCategories: array<int, array{category:string,opens:int}>,
     *   timePerCategory: array<int, array{category:string,minutes:int}>
     * }
     */
    public static function compute(array $history, array $items, array $categories): array
    {
        $titleOf = [];
        $catOfContent = [];
        foreach ($items as $it) {
            $titleOf[(int) $it['id']] = (string) ($it['title'] ?? '');
            $catOfContent[(int) $it['id']] = isset($it['category_id']) ? (int) $it['category_id'] : 0;
        }
        $catName = [];
        foreach ($categories as $c) {
            $catName[(int) $c['id']] = (string) ($c['name'] ?? '');
        }

        $opensByContent = [];
        $opensByCat = [];
        $secondsByCat = [];
        foreach ($history as $h) {
            $cid = (int) ($h['content_id'] ?? 0);
            $catId = $catOfContent[$cid] ?? 0;
            if (($h['action'] ?? '') === 'open') {
                $opensByContent[$cid] = ($opensByContent[$cid] ?? 0) + 1;
                $opensByCat[$catId] = ($opensByCat[$catId] ?? 0) + 1;
            }
            $secondsByCat[$catId] = ($secondsByCat[$catId] ?? 0) + (int) ($h['duration_seconds'] ?? 0);
        }

        arsort($opensByContent);
        $mostAccessed = [];
        foreach (array_slice($opensByContent, 0, 5, true) as $cid => $opens) {
            $mostAccessed[] = ['contentId' => $cid, 'title' => $titleOf[$cid] ?? '', 'opens' => $opens];
        }

        arsort($opensByCat);
        $favoriteCategories = [];
        foreach (array_slice($opensByCat, 0, 5, true) as $catId => $opens) {
            $favoriteCategories[] = ['category' => $catName[$catId] ?? '—', 'opens' => $opens];
        }

        arsort($secondsByCat);
        $timePerCategory = [];
        foreach ($secondsByCat as $catId => $sec) {
            $timePerCategory[] = ['category' => $catName[$catId] ?? '—', 'minutes' => (int) round($sec / 60)];
        }

        return [
            'mostAccessed'       => $mostAccessed,
            'favoriteCategories' => $favoriteCategories,
            'timePerCategory'    => $timePerCategory,
        ];
    }
}
