<?php

declare(strict_types=1);

namespace Pushizo\Resources;

use Pushizo\Client;

/** Kayıtlı raporlar (`analytics:read`; yazma `admin`): rapor oluşturucu tanımları. */
final class SavedReports
{
    public function __construct(private readonly Client $client)
    {
    }

    private function base(?string $appId): string
    {
        return $this->client->appPath($appId) . '/analytics/reports';
    }

    /** @return array<string, mixed> */
    public function list(?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId));
    }

    /**
     * @param array{name:string, definition:array<string, mixed>} $body
     * @return array<string, mixed>
     */
    public function create(array $body, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId), [], $body);
    }

    /** @return array<string, mixed> */
    public function get(string $reportId, ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/' . rawurlencode($reportId));
    }

    /**
     * @param array{name?:string, definition?:array<string, mixed>} $body
     * @return array<string, mixed>
     */
    public function update(string $reportId, array $body, ?string $appId = null): array
    {
        return $this->client->request('PATCH', $this->base($appId) . '/' . rawurlencode($reportId), [], $body);
    }

    /** @return array<string, mixed> */
    public function delete(string $reportId, ?string $appId = null): array
    {
        return $this->client->request('DELETE', $this->base($appId) . '/' . rawurlencode($reportId));
    }
}
