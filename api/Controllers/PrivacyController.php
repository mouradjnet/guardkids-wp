<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\Maintenance\Purger;
use GuardKids\Privacy\PrivacyEraser;
use GuardKids\Privacy\PrivacyExporter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Seção Privacidade: exportar dados, limpar histórico antigo e excluir
 * os dados da família. Todas as rotas exigem admin (manage_options).
 */
final class PrivacyController
{
    private readonly PrivacyExporter $exporter;
    private readonly PrivacyEraser $eraser;
    private readonly Purger $purger;

    public function __construct(
        ?PrivacyExporter $exporter = null,
        ?PrivacyEraser $eraser = null,
        ?Purger $purger = null,
    ) {
        $this->exporter = $exporter ?? new PrivacyExporter();
        $this->eraser   = $eraser ?? new PrivacyEraser();
        $this->purger   = $purger ?? new Purger();
    }

    public function export(): WP_REST_Response
    {
        return rest_ensure_response($this->exporter->collect());
    }

    public function clearHistory(): WP_REST_Response
    {
        return rest_ensure_response([
            'usage_events' => $this->purger->purgeOldUsageEvents(Purger::USAGE_EVENTS_DAYS),
            'locations'    => $this->purger->purgeOldLocations(Purger::LOCATIONS_DAYS),
            'requests'     => $this->purger->purgeOldDecidedRequests(Purger::DECIDED_REQUESTS_DAYS),
        ]);
    }

    public function deleteAll(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $params  = $req->get_json_params();
        $confirm = is_array($params) ? ($params['confirm'] ?? null) : null;
        if ($confirm !== 'EXCLUIR') {
            return new WP_Error('invalid_confirm', 'Confirmação inválida.', ['status' => 400]);
        }
        return rest_ensure_response(['tables' => $this->eraser->wipeAll()]);
    }
}
