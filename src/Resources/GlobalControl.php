<?php

declare(strict_types=1);

namespace Pushizo\Resources;

use Pushizo\Client;

/** Genel kontrol grubu (`analytics:read`; yazma `admin`): mesaj almayan sabit kitle ve kazanç raporu. */
final class GlobalControl
{
    public function __construct(private readonly Client $client)
    {
    }

    private function base(?string $appId): string
    {
        return $this->client->appPath($appId) . '/global-control';
    }

    /** @return array<string, mixed> */
    public function get(?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId));
    }

    /**
     * @param array{enabled:bool, percent:int} $body
     * @return array<string, mixed>
     */
    public function update(array $body, ?string $appId = null): array
    {
        return $this->client->request('PUT', $this->base($appId), [], $body);
    }

    /**
     * Kontrol grubunu yeniden seçer.
     *
     * @return array<string, mixed>
     */
    public function reset(?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/reset');
    }

    /**
     * @param array{days?:int, event?:string} $params
     * @return array<string, mixed>
     */
    public function report(array $params = [], ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/report', $params);
    }
}
