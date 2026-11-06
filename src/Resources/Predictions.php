<?php

declare(strict_types=1);

namespace Pushizo\Resources;

use Pushizo\Client;

/** Öngörü modelleri (`analytics:read`; yazma `admin`): kayıp riski ve olay olasılığı. */
final class Predictions
{
    public function __construct(private readonly Client $client)
    {
    }

    private function base(?string $appId): string
    {
        return $this->client->appPath($appId) . '/predictions';
    }

    /** @return array<string, mixed> */
    public function list(?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId));
    }

    /**
     * @param array{slug:string, name:string, kind:string, event_names?:list<string>, horizon_days?:int} $body
     * @return array<string, mixed>
     */
    public function create(array $body, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId), [], $body);
    }

    /** @return array<string, mixed> */
    public function get(string $predictionId, ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/' . rawurlencode($predictionId));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function update(string $predictionId, array $body, ?string $appId = null): array
    {
        return $this->client->request('PATCH', $this->base($appId) . '/' . rawurlencode($predictionId), [], $body);
    }

    /** @return array<string, mixed> */
    public function delete(string $predictionId, ?string $appId = null): array
    {
        return $this->client->request('DELETE', $this->base($appId) . '/' . rawurlencode($predictionId));
    }

    /**
     * Modeli yeniden eğitir ve skorlar (iş kuyruğa alınır).
     *
     * @return array<string, mixed>
     */
    public function compute(string $predictionId, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/' . rawurlencode($predictionId) . '/compute');
    }

    /**
     * Skorlanan kullanıcılar (`users:read`).
     *
     * @param array{bucket?:'low'|'medium'|'high', limit?:int, cursor?:string} $params
     * @return array<string, mixed>
     */
    public function users(string $predictionId, array $params = [], ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/' . rawurlencode($predictionId) . '/users', $params);
    }
}
