<?php

declare(strict_types=1);

namespace Pushizo\Resources;

use Pushizo\Client;

/** Kişi başına en iyi kanal (`analytics:read`; ayar ve hesap `admin`; kullanıcılar `users:read`). */
final class ChannelScores
{
    public function __construct(private readonly Client $client)
    {
    }

    private function base(?string $appId): string
    {
        return $this->client->appPath($appId) . '/channel-scores';
    }

    /** @return array<string, mixed> */
    public function getConfig(?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/config');
    }

    /**
     * @param array{enabled:bool, window_days?:int, min_sends?:int, count_email_opens?:bool} $body
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
     * @param array{channel?:string, limit?:int, cursor?:string} $params
     * @return array<string, mixed>
     */
    public function users(array $params = [], ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/users', $params);
    }

    /** @return array<string, mixed> */
    public function user(string $userId, ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/users/' . rawurlencode($userId));
    }
}
