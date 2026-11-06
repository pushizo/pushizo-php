<?php

declare(strict_types=1);

namespace Pushizo\Resources;

use Pushizo\Client;

/** Bölgeler (`analytics:read`; yazma `admin`): daire biçimli alanlar, giriş ve çıkış olayları. */
final class Geofences
{
    public function __construct(private readonly Client $client)
    {
    }

    private function base(?string $appId): string
    {
        return $this->client->appPath($appId) . '/geofences';
    }

    /** @return array<string, mixed> */
    public function list(?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId));
    }

    /**
     * @param array{name:string, lat:float, lng:float, radius_m:int, cooldown_minutes?:int, enabled?:bool} $body
     * @return array<string, mixed>
     */
    public function create(array $body, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId), [], $body);
    }

    /** @return array<string, mixed> */
    public function get(string $geofenceId, ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/' . rawurlencode($geofenceId));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function update(string $geofenceId, array $body, ?string $appId = null): array
    {
        return $this->client->request('PATCH', $this->base($appId) . '/' . rawurlencode($geofenceId), [], $body);
    }

    /** @return array<string, mixed> */
    public function delete(string $geofenceId, ?string $appId = null): array
    {
        return $this->client->request('DELETE', $this->base($appId) . '/' . rawurlencode($geofenceId));
    }
}
