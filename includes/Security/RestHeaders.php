<?php

declare(strict_types=1);

namespace GuardKids\Security;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Headers seguros nas respostas do namespace `guardkids/v1`. Hookado em
 * `rest_post_dispatch` (filtro que roda depois da rota responder e antes
 * do servidor escrever os headers).
 *
 * Seção 7 do spec: nosniff + Referrer-Policy + X-Frame-Options.
 * X-Robots-Tag adicionado pra evitar indexação acidental dos endpoints.
 * Cache-Control: no-store impede que edge/CDN (LiteSpeed/hcdn) cacheiem
 * respostas autenticadas — estado mutável por site nunca deve ser cacheado.
 */
final class RestHeaders
{
    /**
     * Headers a aplicar. Lista curta, fixa, sem deps externas.
     *
     * @var array<string, string>
     */
    private const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy'        => 'strict-origin-when-cross-origin',
        'X-Frame-Options'        => 'DENY',
        'X-Robots-Tag'           => 'noindex, nofollow',
        'Cache-Control'          => 'no-store, no-cache, must-revalidate, max-age=0',
        'Pragma'                 => 'no-cache',
    ];

    public function register(): void
    {
        add_filter('rest_post_dispatch', [$this, 'addHeaders'], 10, 3);
    }

    /**
     * @param mixed $result
     * @return mixed
     */
    public function addHeaders($result, WP_REST_Server $server, WP_REST_Request $request)
    {
        unset($server);

        if (! $result instanceof WP_REST_Response) {
            return $result;
        }

        $route = (string) $request->get_route();
        if (! str_starts_with($route, '/' . \GuardKids\Api\RestApi::NAMESPACE . '/')) {
            return $result;
        }

        foreach (self::HEADERS as $name => $value) {
            $result->header($name, $value);
        }

        return $result;
    }
}
