<?php

declare(strict_types=1);

namespace GuardKids\Geo;

/**
 * Geocodificação via Nominatim/OpenStreetMap, no servidor. HTTP isolado em
 * fetch() (sobrescrito nos testes); cache via transient respeita a política de
 * uso (1 req/s) e evita rechamar o mesmo endereço.
 */
class Geocoder
{
    private const ENDPOINT = 'https://nominatim.openstreetmap.org/search';
    private const CACHE_TTL = 604800; // 7 dias

    /**
     * @return array{lat:float, lng:float, displayName:string}|null
     */
    public function geocode(string $query): ?array
    {
        $query = trim($query);
        if ($query === '') {
            return null;
        }

        $cacheKey = 'gk_geocode_' . md5(mb_strtolower($query));
        $cached = $this->cacheGet($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $raw = $this->fetch($query);
        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || $decoded === [] || ! isset($decoded[0]['lat'], $decoded[0]['lon'])) {
            return null;
        }

        $result = [
            'lat'         => (float) $decoded[0]['lat'],
            'lng'         => (float) $decoded[0]['lon'],
            'displayName' => (string) ($decoded[0]['display_name'] ?? ''),
        ];
        $this->cacheSet($cacheKey, $result);
        return $result;
    }

    /**
     * HTTP real. Retorna o corpo (JSON) ou null em erro. Protegido: sobrescrito
     * nos testes.
     */
    protected function fetch(string $query): ?string
    {
        $url = self::ENDPOINT . '?' . http_build_query([
            'q'            => $query,
            'format'       => 'jsonv2',
            'limit'        => 1,
            'countrycodes' => 'br',
        ]);
        $response = wp_remote_get($url, [
            'timeout' => 8,
            'headers' => [
                'User-Agent' => 'GuardKids-WP/1.0 (+https://guardiaokids.site)',
                'Accept'     => 'application/json',
            ],
        ]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        $body = wp_remote_retrieve_body($response);
        return $body !== '' ? $body : null;
    }

    protected function cacheGet(string $key): mixed
    {
        return get_transient($key);
    }

    protected function cacheSet(string $key, mixed $value): void
    {
        set_transient($key, $value, self::CACHE_TTL);
    }
}
