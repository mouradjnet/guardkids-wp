<?php

declare(strict_types=1);

namespace GuardKids\AI;

/**
 * Transforma as agregações de uso da família (as mesmas do
 * {@see \GuardKids\Api\Controllers\ReportsController}) em insights acionáveis
 * para o responsável, via {@see AnthropicClient}.
 *
 * Duas garantias centrais:
 *
 * 1. **Só dado agregado, pseudonimizado.** {@see buildPrompt()} substitui o nome
 *    real de cada criança por "Criança N" e nunca inclui localização/endereço.
 *    O nome real JAMAIS entra no prompt.
 * 2. **Parse defensivo.** {@see parse()} extrai o JSON da resposta (tolerando
 *    cercas markdown e prosa em volta) e valida o shape de cada insight; qualquer
 *    coisa fora do contrato é descartada. Resposta imprestável → lista vazia.
 *
 * Ambos os métodos-núcleo são estáticos e puros (sem I/O) — testáveis isolados.
 */
final class InsightsService
{
    /** Severidades aceitas; qualquer outra vira 'info'. */
    private const SEVERITIES = ['info', 'warning', 'alert'];

    /** Teto de insights devolvidos (o prompt já pede no máximo isto). */
    private const MAX_INSIGHTS = 4;

    public function __construct(private readonly AnthropicClient $client)
    {
    }

    /**
     * Gera os insights a partir das agregações. Degrada para `[]` se a IA está
     * indisponível ou devolveu algo imprestável.
     *
     * @param array<string, mixed> $data
     * @return list<array{title: string, body: string, severity: string, cta: string}>
     */
    public function generate(array $data): array
    {
        $prompt = self::buildPrompt($data);
        $raw    = $this->client->complete($prompt['system'], $prompt['user']);
        if ($raw === null) {
            return [];
        }
        return self::parse($raw);
    }

    /**
     * Monta o par (system, user) do prompt. PSEUDONIMIZA: o nome real da criança
     * é trocado por "Criança N"; nenhum nome real entra na saída.
     *
     * @param array<string, mixed> $data
     * @return array{system: string, user: string}
     */
    public static function buildPrompt(array $data): array
    {
        $range    = ($data['range'] ?? 'week') === 'month' ? 'últimos 30 dias' : 'últimos 7 dias';
        $kpis     = is_array($data['kpis'] ?? null) ? $data['kpis'] : [];
        $perChild = is_array($data['perChild'] ?? null) ? $data['perChild'] : [];
        $topSites = is_array($data['topSites'] ?? null) ? $data['topSites'] : [];

        // Mapa childId -> "Criança N", na ordem em que aparecem.
        $labelById = [];
        $n = 0;
        foreach ($perChild as $c) {
            $cid = (int) ($c['childId'] ?? 0);
            if ($cid > 0 && ! isset($labelById[$cid])) {
                $labelById[$cid] = 'Criança ' . (++$n);
            }
        }

        $safeChildren = [];
        foreach ($perChild as $c) {
            $cid = (int) ($c['childId'] ?? 0);
            $safeChildren[] = [
                'crianca'          => $labelById[$cid] ?? ('Criança ' . (++$n)),
                'totalMinutos'     => (int) ($c['totalMinutes'] ?? 0),
                'mediaMinutosDia'  => (int) ($c['avgMinutesPerDay'] ?? 0),
            ];
        }

        $safeSites = [];
        foreach ($topSites as $s) {
            $topChild = $s['topChildId'] ?? null;
            $safeSites[] = [
                'dominio'      => (string) ($s['domain'] ?? ''),
                'aberturas'    => (int) ($s['opens'] ?? 0),
                'criancaTop'   => is_numeric($topChild) ? ($labelById[(int) $topChild] ?? null) : null,
            ];
        }

        $payload = [
            'periodo'   => $range,
            'resumo'    => [
                'totalMinutos'            => (int) ($kpis['totalMinutes'] ?? 0),
                'mediaMinutosDia'         => (int) ($kpis['avgMinutesPerDay'] ?? 0),
                'percentualDoLimite'      => $kpis['percentOfLimit'] ?? null,
                'variacaoVsPeriodoAnt'    => $kpis['deltaPctVsPrevious'] ?? null,
            ],
            'porCrianca' => $safeChildren,
            'topSites'   => $safeSites,
        ];

        $system = <<<SYS
            Você é um assistente do GuardKids, um app de controle parental. A partir de
            dados AGREGADOS e ANÔNIMOS de uso de tela de uma família, escreva de 2 a 4
            insights curtos, acolhedores e ACIONÁVEIS para o responsável, em português
            do Brasil. Fale com o responsável (não com a criança). Não invente números
            além dos fornecidos. Não faça julgamentos morais nem alarmismo.

            Responda SOMENTE com um objeto JSON válido, sem cercas de markdown e sem
            texto fora do JSON, no formato:
            {"insights":[{"title":"...","body":"...","severity":"info|warning|alert","cta":"..."}]}

            - "title": no máximo ~50 caracteres.
            - "body": 1 a 2 frases explicando o que o dado mostra e o que fazer.
            - "severity": "info" (tudo bem), "warning" (vale atenção) ou "alert" (subiu muito / perto/acima do limite).
            - "cta": um próximo passo curto (ex.: "Definir limite noturno"). Pode ser "".
            - No máximo 4 insights.
            SYS;

        $user = "Dados da família (período: {$range}):\n"
            . wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return ['system' => $system, 'user' => $user];
    }

    /**
     * Extrai e valida a lista de insights da resposta bruta do modelo. Tolera
     * cercas ```json e prosa em volta; descarta itens fora do contrato. Resposta
     * imprestável → [].
     *
     * @return list<array{title: string, body: string, severity: string, cta: string}>
     */
    public static function parse(string $raw): array
    {
        $decoded = self::decodeLenient($raw);
        if (! is_array($decoded) || ! isset($decoded['insights']) || ! is_array($decoded['insights'])) {
            return [];
        }

        $out = [];
        foreach ($decoded['insights'] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $title = trim((string) ($item['title'] ?? ''));
            $body  = trim((string) ($item['body'] ?? ''));
            if ($title === '' || $body === '') {
                continue;
            }
            $severity = (string) ($item['severity'] ?? 'info');
            if (! in_array($severity, self::SEVERITIES, true)) {
                $severity = 'info';
            }
            $out[] = [
                'title'    => $title,
                'body'     => $body,
                'severity' => $severity,
                'cta'      => trim((string) ($item['cta'] ?? '')),
            ];
            if (count($out) >= self::MAX_INSIGHTS) {
                break;
            }
        }

        return $out;
    }

    /**
     * Decodifica o JSON tolerando cercas de markdown e prosa: tenta o texto
     * inteiro; se falhar, recorta do primeiro `{` ao último `}`.
     *
     * @return array<string, mixed>|null
     */
    private static function decodeLenient(string $raw): ?array
    {
        $trimmed = trim($raw);
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($trimmed, '{');
        $end   = strrpos($trimmed, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $decoded = json_decode(substr($trimmed, $start, $end - $start + 1), true);
        return is_array($decoded) ? $decoded : null;
    }
}
