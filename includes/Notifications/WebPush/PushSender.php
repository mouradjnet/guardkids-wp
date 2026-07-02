<?php

declare(strict_types=1);

namespace GuardKids\Notifications\WebPush;

use GuardKids\Database\PushSubscriptionRepository;

/**
 * Envia web-push (síncrono) às subscriptions de um filho. Limpa endpoints
 * mortos (404/410). Falhas nunca propagam pro gatilho.
 */
class PushSender
{
    private readonly PushSubscriptionRepository $subs;
    private readonly Vapid $vapid;
    private readonly Payload $payload;

    public function __construct(
        ?PushSubscriptionRepository $subs = null,
        ?Vapid $vapid = null,
        ?Payload $payload = null
    ) {
        $this->subs    = $subs ?? new PushSubscriptionRepository();
        $this->vapid   = $vapid ?? new Vapid();
        $this->payload = $payload ?? new Payload();
    }

    public function sendToChild(int $childId, string $title, string $body): void
    {
        $data = (string) wp_json_encode([
            'title' => $title,
            'body'  => $body,
            'url'   => '/painel-filho/',
        ]);

        foreach ($this->subs->findByChild($childId) as $sub) {
            try {
                $this->sendOne((string) $sub['endpoint'], (string) $sub['p256dh'], (string) $sub['auth'], $data);
            } catch (\Throwable $e) {
                error_log('[GuardKids] push falhou: ' . $e->getMessage());
            }
        }
    }

    private function sendOne(string $endpoint, string $p256dh, string $auth, string $data): void
    {
        $cipher = $this->payload->encrypt(
            $data,
            Base64Url::decode($p256dh),
            Base64Url::decode($auth),
        );

        $resp = wp_remote_post($endpoint, [
            'headers' => [
                'Authorization'    => $this->vapid->header($endpoint),
                'Content-Encoding' => 'aes128gcm',
                'Content-Type'     => 'application/octet-stream',
                'TTL'              => '2419200',
                'Urgency'          => 'normal',
            ],
            'body'    => $cipher,
            'timeout' => 5,
        ]);

        if (is_wp_error($resp)) {
            error_log('[GuardKids] push WP_Error: ' . $resp->get_error_message());
            return;
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code === 404 || $code === 410) {
            $this->subs->deleteByEndpoint($endpoint);
        }
    }
}
