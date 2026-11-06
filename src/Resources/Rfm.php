<?php

declare(strict_types=1);

namespace Pushizo\Resources;

use Pushizo\Client;

/** RFM segmentasyonu (`analytics:read`; ayar ve hesap `admin`): yakınlık, sıklık ve tutar skorlarıyla 11 segment. */
final class Rfm
{
    public function __construct(private readonly Client $client)
    {
    }

    private function base(?string $appId): string
    {
        return $this->client->appPath($appId) . '/rfm';
    }

    /** @return array<string, mixed> */
    public function getConfig(?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/config');
    }

    /**
     * @param array{enabled:bool, event_names:list<string>, value_property?:?string, use_monetary?:bool, window_days?:int} $body
     * @return array<string, mixed>
     */
    public function updateConfig(array $body, ?string $appId = null): array
    {
        return $this->client->request('PUT', $this->base($appId) . '/config', [], $body);
    }

    /**
     * Skorları yeniden hesaplatır (iş kuyruğa alınır).
     *
     * @return array<string, mixed>
     */
    public function compute(?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/compute');
    }

    /** @return array<string, mixed> */
    public function overview(?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/overview');
    }

    /**
     * İki gün arasındaki segment geçişleri (`YYYY-MM-DD`).
     *
     * @param array{from?:string, to?:string} $params
     * @return array<string, mixed>
     */
    public function transitions(array $params = [], ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/transitions', $params);
    }

    /**
     * Segmentteki kullanıcılar (`users:read`).
     *
     * @param array{segment?:string, limit?:int, cursor?:string} $params
     * @return array<string, mixed>
     */
    public function users(array $params = [], ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/users', $params);
    }
}
