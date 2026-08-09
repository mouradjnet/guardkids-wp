<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\AI\AnthropicClient;
use GuardKids\AI\InsightsService;
use GuardKids\License\Gate;
use GuardKids\Security\RateLimiter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET  /insights?range=week|month&child_id=*   — insights de IA sobre o uso.
 * POST /insights/refresh                       — força regenerar (rate-limited).
 *
 * Reusa EXATAMENTE as agregações do {@see ReportsController} (via
 * {@see reportData()}) — a IA só reescreve, em linguagem natural, o dado que o
 * painel já mostra. Gated no Pro (`ai_insights`); Guardian auth no registro da
 * rota. Degrada leniente: travado/sem chave/erro → `insights: []` com um motivo,
 * nunca 500.
 *
 * Custo controlado: o resultado é cacheado por hash do dado — reabrir a tela com
 * o mesmo uso NÃO re-chama a IA. `refresh` invalida e regenera, com rate-limit.
 *
 * Não é `final` de propósito: {@see reportData()} é o seam sobrescrito nos testes
 * pra não depender de $wpdb (molde {@see \GuardKids\Geo\Geocoder}).
 */
class InsightsController
{
    private const CACHE_PREFIX = 'gk_insights_';
    private const CACHE_TTL    = 43200; // 12h
    private const MODEL_LABEL  = 'claude-opus-4-8';

    private readonly Gate $gate;
    private readonly InsightsService $service;
    private readonly AnthropicClient $client;
    private readonly RateLimiter $limiter;

    public function __construct(
        ?Gate $gate = null,
        ?InsightsService $service = null,
        ?AnthropicClient $client = null,
        ?RateLimiter $limiter = null,
    ) {
        $this->client  = $client ?? new AnthropicClient();
        $this->service = $service ?? new InsightsService($this->client);
        $this->gate    = $gate ?? new Gate();
        // 5 regenerações por 5 min por responsável — generoso pro uso legítimo,
        // barra spam do botão "atualizar".
        $this->limiter = $limiter ?? new RateLimiter(5, 300);
    }

    public function index(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        if (! $this->gate->can('ai_insights')) {
            return $this->unavailable('locked');
        }

        $data    = $this->relevant($this->reportData($req));
        $cacheKey = self::CACHE_PREFIX . md5((string) wp_json_encode($data));

        $cached = get_transient($cacheKey);
        if (is_array($cached)) {
            $cached['fromCache'] = true;
            return rest_ensure_response($cached);
        }

        return rest_ensure_response($this->generate($data, $cacheKey));
    }

    public function refresh(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        if (! $this->gate->can('ai_insights')) {
            return $this->unavailable('locked');
        }

        if (! $this->limiter->allow('insights_refresh', get_current_user_id())) {
            $err = new WP_Error('rate_limited', 'Muitas atualizações. Tente em instantes.', ['status' => 429]);
            return $err;
        }

        $data     = $this->relevant($this->reportData($req));
        $cacheKey = self::CACHE_PREFIX . md5((string) wp_json_encode($data));
        delete_transient($cacheKey);

        return rest_ensure_response($this->generate($data, $cacheKey));
    }

    /**
     * Gera (chamando a IA), cacheia se veio algo, e monta a resposta. Sem chave
     * → `no_key`; erro/vazio da IA → lista vazia sem cache (o próximo refresh
     * tenta de novo).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function generate(array $data, string $cacheKey): array
    {
        if (! $this->client->hasKey()) {
            return $this->unavailablePayload('no_key');
        }

        $insights = $this->service->generate($data);

        $payload = [
            'available'   => true,
            'fromCache'   => false,
            'generatedAt' => current_time('mysql', true),
            'model'       => self::MODEL_LABEL,
            'insights'    => $insights,
        ];

        if ($insights !== []) {
            set_transient($cacheKey, $payload, self::CACHE_TTL);
        }

        return $payload;
    }

    /**
     * Recorta do payload do ReportsController só o que a IA usa. Isola o
     * contrato: mudanças noutras partes do relatório não invalidam o cache nem
     * viram ruído no prompt.
     *
     * @param array<string, mixed> $report
     * @return array{range: mixed, kpis: mixed, perChild: mixed, topSites: mixed}
     */
    private function relevant(array $report): array
    {
        return [
            'range'    => $report['range'] ?? 'week',
            'kpis'     => $report['kpis'] ?? [],
            'perChild' => $report['perChild'] ?? [],
            'topSites' => $report['topSites'] ?? [],
        ];
    }

    private function unavailable(string $reason): WP_REST_Response
    {
        return rest_ensure_response($this->unavailablePayload($reason));
    }

    /**
     * @return array<string, mixed>
     */
    private function unavailablePayload(string $reason): array
    {
        return [
            'available' => false,
            'reason'    => $reason,
            'fromCache' => false,
            'insights'  => [],
        ];
    }

    /**
     * Seam de dados: por padrão reusa o ReportsController. Sobrescrito nos testes
     * pra devolver agregações canned sem tocar em $wpdb.
     *
     * @return array<string, mixed>
     */
    protected function reportData(WP_REST_Request $req): array
    {
        $response = (new ReportsController())->index($req);
        return $response instanceof WP_REST_Response ? (array) $response->get_data() : [];
    }
}
