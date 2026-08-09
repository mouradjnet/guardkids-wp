<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\AI;

use GuardKids\AI\AnthropicClient;
use GuardKids\AI\InsightsService;
use PHPUnit\Framework\TestCase;

/**
 * InsightsService — pseudonimização do prompt + parse defensivo da resposta.
 */
final class InsightsServiceTest extends TestCase
{
    /**
     * @param array<string, mixed> $data
     */
    private function sampleData(): array
    {
        return [
            'range' => 'week',
            'kpis'  => [
                'totalMinutes'       => 840,
                'avgMinutesPerDay'   => 120,
                'percentOfLimit'     => 0.75,
                'deltaPctVsPrevious' => 0.4,
            ],
            'perChild' => [
                ['childId' => 7, 'name' => 'Lucas', 'totalMinutes' => 500, 'avgMinutesPerDay' => 71],
                ['childId' => 9, 'name' => 'Sofia', 'totalMinutes' => 340, 'avgMinutesPerDay' => 48],
            ],
            'topSites' => [
                ['domain' => 'youtube.com', 'opens' => 120, 'topChildId' => 7],
            ],
        ];
    }

    // ---- buildPrompt: pseudonimização ----

    public function testPromptNeverContainsRealChildName(): void
    {
        $prompt = InsightsService::buildPrompt($this->sampleData());

        self::assertStringNotContainsString('Lucas', $prompt['user']);
        self::assertStringNotContainsString('Sofia', $prompt['user']);
        self::assertStringContainsString('Criança 1', $prompt['user']);
        self::assertStringContainsString('Criança 2', $prompt['user']);
    }

    public function testPromptIncludesAggregatedKpis(): void
    {
        $prompt = InsightsService::buildPrompt($this->sampleData());

        // números do resumo têm que aparecer no prompt
        self::assertStringContainsString('840', $prompt['user']);
        self::assertStringContainsString('youtube.com', $prompt['user']);
    }

    public function testTopSiteChildIsPseudonymized(): void
    {
        $prompt = InsightsService::buildPrompt($this->sampleData());
        $decoded = json_decode(substr($prompt['user'], (int) strpos($prompt['user'], '{')), true);

        self::assertSame('Criança 1', $decoded['topSites'][0]['criancaTop']);
    }

    // ---- parse: robustez ----

    public function testParsesCleanJson(): void
    {
        $raw = '{"insights":[{"title":"Tempo subiu","body":"Subiu 40%.","severity":"warning","cta":"Definir limite"}]}';
        $out = InsightsService::parse($raw);

        self::assertCount(1, $out);
        self::assertSame('Tempo subiu', $out[0]['title']);
        self::assertSame('warning', $out[0]['severity']);
        self::assertSame('Definir limite', $out[0]['cta']);
    }

    public function testToleratesMarkdownFencesAndProse(): void
    {
        $raw = "Claro! Aqui estão:\n```json\n{\"insights\":[{\"title\":\"Ok\",\"body\":\"Tudo certo.\"}]}\n```\nAbraço!";
        $out = InsightsService::parse($raw);

        self::assertCount(1, $out);
        self::assertSame('Ok', $out[0]['title']);
        self::assertSame('info', $out[0]['severity'], 'severity ausente vira info');
        self::assertSame('', $out[0]['cta'], 'cta ausente vira string vazia');
    }

    public function testDropsItemsWithoutTitleOrBodyAndCoercesSeverity(): void
    {
        $raw = '{"insights":[
            {"title":"","body":"sem titulo"},
            {"title":"sem corpo","body":""},
            {"title":"Valido","body":"corpo","severity":"catastrofe"}
        ]}';
        $out = InsightsService::parse($raw);

        self::assertCount(1, $out);
        self::assertSame('Valido', $out[0]['title']);
        self::assertSame('info', $out[0]['severity'], 'severity inválida vira info');
    }

    public function testReturnsEmptyOnGarbage(): void
    {
        self::assertSame([], InsightsService::parse('desculpe, não consigo ajudar'));
        self::assertSame([], InsightsService::parse(''));
        self::assertSame([], InsightsService::parse('{"outra_coisa":1}'));
    }

    public function testClampsToFourInsights(): void
    {
        $items = array_fill(0, 6, '{"title":"T","body":"B"}');
        $raw   = '{"insights":[' . implode(',', $items) . ']}';

        self::assertCount(4, InsightsService::parse($raw));
    }

    // ---- generate: orquestração + degradação ----

    public function testGenerateReturnsEmptyWhenClientUnavailable(): void
    {
        $client = new class () extends AnthropicClient {
            public function complete(string $system, string $user): ?string
            {
                return null; // sem chave / erro
            }
        };

        self::assertSame([], (new InsightsService($client))->generate($this->sampleData()));
    }

    public function testGenerateParsesClientOutputAndReceivesPseudonymizedPrompt(): void
    {
        $client = new class () extends AnthropicClient {
            public string $seenUser = '';
            public function complete(string $system, string $user): ?string
            {
                $this->seenUser = $user;
                return '{"insights":[{"title":"Insight","body":"Corpo."}]}';
            }
        };

        $out = (new InsightsService($client))->generate($this->sampleData());

        self::assertCount(1, $out);
        self::assertSame('Insight', $out[0]['title']);
        self::assertStringNotContainsString('Lucas', $client->seenUser, 'nome real não pode chegar ao client');
    }
}
