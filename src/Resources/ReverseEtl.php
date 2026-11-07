<?php

declare(strict_types=1);

namespace Pushizo\Resources;

use Pushizo\Client;

/**
 * Depodan içeri aktarım. Kimlik bilgileri yalnızca yazılır; loglamayın.
 */
final class ReverseEtl
{
    public function __construct(private readonly Client $client)
    {
    }

    private function base(?string $appId): string
    {
        return $this->client->appPath($appId) . '/reverse-etl';
    }

    private function one(string $syncId, ?string $appId): string
    {
        return $this->base($appId) . '/' . rawurlencode($syncId);
    }

    /** @return array<string, mixed> */
    public function list(?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId));
    }

    /**
     * @param array{source:string, config:array<string, mixed>, credentials?:array<string, mixed>} $input
     * @return array<string, mixed>
     */
    public function testConnection(array $input, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/test-connection', [], $input);
    }

    /**
     * @param array{name:string, target:'event'|'user', schedule:'hourly'|'daily', source:string, config:array<string, mixed>, credentials?:array<string, mixed>, enabled?:bool} $input
     * @return array<string, mixed>
     */
    public function create(array $input, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId), [], $input);
    }

    /** @return array<string, mixed> */
    public function get(string $syncId, ?string $appId = null): array
    {
        return $this->client->request('GET', $this->one($syncId, $appId));
    }

    /**
     * @param array{name?:string, schedule?:string, enabled?:bool} $input
     * @return array<string, mixed>
     */
    public function update(string $syncId, array $input, ?string $appId = null): array
    {
        return $this->client->request('PATCH', $this->one($syncId, $appId), [], $input);
    }

    /** @return array<string, mixed> */
    public function delete(string $syncId, ?string $appId = null): array
    {
        return $this->client->request('DELETE', $this->one($syncId, $appId));
    }

    /** @return array<string, mixed> */
    public function test(string $syncId, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->one($syncId, $appId) . '/test');
    }

    /** @return array<string, mixed> */
    public function run(string $syncId, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->one($syncId, $appId) . '/run');
    }

    /**
     * @param array{limit?:int, cursor?:string} $params
     * @return array<string, mixed>
     */
    public function runs(string $syncId, array $params = [], ?string $appId = null): array
    {
        return $this->client->request('GET', $this->one($syncId, $appId) . '/runs', $params);
    }
}
