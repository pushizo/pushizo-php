<?php

declare(strict_types=1);

namespace Pushizo\Resources;

use Pushizo\Client;

/** Özellik bayrakları (`analytics:read`; yazma `admin`): yüzdeli ve zamanlanmış kademeli yayın, segment hedefi, uzaktan değerler. */
final class FeatureFlags
{
    public function __construct(private readonly Client $client)
    {
    }

    private function base(?string $appId): string
    {
        return $this->client->appPath($appId) . '/feature-flags';
    }

    /** @return array<string, mixed> */
    public function list(?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId));
    }

    /**
     * @param array{key:string, name:string, description?:?string, enabled?:bool, value_type?:string, on_value?:mixed, off_value?:mixed, rollout_percent?:int, segment_id?:?string, rollout_schedule?:array<string, mixed>} $body
     * @return array<string, mixed>
     */
    public function create(array $body, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId), [], $body);
    }

    /**
     * Bayrakları bir kullanıcı için değerlendirir.
     *
     * @param array{external_id?:string, user_id?:string, subject?:string} $body
     * @return array<string, mixed>
     */
    public function evaluate(array $body, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/evaluate', [], $body);
    }

    /** @return array<string, mixed> */
    public function get(string $flagId, ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/' . rawurlencode($flagId));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function update(string $flagId, array $body, ?string $appId = null): array
    {
        return $this->client->request('PATCH', $this->base($appId) . '/' . rawurlencode($flagId), [], $body);
    }

    /** @return array<string, mixed> */
    public function delete(string $flagId, ?string $appId = null): array
    {
        return $this->client->request('DELETE', $this->base($appId) . '/' . rawurlencode($flagId));
    }
}
