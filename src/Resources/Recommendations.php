<?php

declare(strict_types=1);

namespace Pushizo\Resources;

use Pushizo\Client;

/** Ürün önerileri (`analytics:read`; ayar ve hesap `admin`): katalog başına yedi strateji. */
final class Recommendations
{
    public function __construct(private readonly Client $client)
    {
    }

    private function base(?string $appId): string
    {
        return $this->client->appPath($appId) . '/catalogs';
    }

    /**
     * @param array{strategy:string, limit?:int, user_id?:string, external_id?:string, item_id?:string, category?:string} $params
     * @return array<string, mixed>
     */
    public function list(string $catalogId, array $params = [], ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/' . rawurlencode($catalogId) . '/recommendations', $params);
    }

    /** @return array<string, mixed> */
    public function getConfig(string $catalogId, ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/' . rawurlencode($catalogId) . '/recommendations/config');
    }

    /**
     * @param array{enabled:bool, view_events?:list<string>, purchase_events?:list<string>, item_property?:string, window_days?:int} $body
     * @return array<string, mixed>
     */
    public function updateConfig(string $catalogId, array $body, ?string $appId = null): array
    {
        return $this->client->request('PUT', $this->base($appId) . '/' . rawurlencode($catalogId) . '/recommendations/config', [], $body);
    }

    /**
     * Önerileri yeniden hesaplatır (iş kuyruğa alınır).
     *
     * @return array<string, mixed>
     */
    public function compute(string $catalogId, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/' . rawurlencode($catalogId) . '/recommendations/compute');
    }
}
