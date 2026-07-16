<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\Database\GuardianPushSubscriptionRepository;
use GuardKids\Notifications\WebPush\VapidKeys;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Web Push do guardião: chave pública, subscribe e unsubscribe.
 *
 * Auth é nonce do WP + RestApi::requireCollaboratorOrAbove (no registro da
 * rota) — collaborator também decide pedidos, então também é avisado.
 *
 * As chaves VAPID são as MESMAS da criança: VapidKeys vive em wp_options e não
 * sabe de quem é o push. Nada a gerar aqui.
 */
final class GuardianPushController
{
    private readonly GuardianPushSubscriptionRepository $subs;
    private readonly VapidKeys $vapidKeys;

    public function __construct(?GuardianPushSubscriptionRepository $subs = null)
    {
        $this->subs      = $subs ?? new GuardianPushSubscriptionRepository();
        $this->vapidKeys = new VapidKeys();
    }

    public function pushKey(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        return rest_ensure_response(['publicKey' => $this->vapidKeys->publicKey()]);
    }

    public function pushSubscribe(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $endpoint = (string) ($req->get_param('endpoint') ?? '');
        $keys     = $req->get_param('keys');
        $p256dh   = is_array($keys) ? (string) ($keys['p256dh'] ?? '') : '';
        $auth     = is_array($keys) ? (string) ($keys['auth'] ?? '') : '';

        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            return new WP_Error('invalid_subscription', 'Subscription incompleta.', ['status' => 400]);
        }

        $this->subs->upsertByEndpoint(get_current_user_id(), $endpoint, $p256dh, $auth);

        return rest_ensure_response(['ok' => true]);
    }

    public function pushUnsubscribe(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $endpoint = (string) ($req->get_param('endpoint') ?? '');
        if ($endpoint === '') {
            return new WP_Error('invalid_subscription', 'Endpoint ausente.', ['status' => 400]);
        }

        $this->subs->deleteByEndpoint($endpoint);

        return rest_ensure_response(['ok' => true]);
    }
}
