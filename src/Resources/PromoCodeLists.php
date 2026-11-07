<?php

declare(strict_types=1);

namespace Pushizo\Resources;

use Pushizo\Client;

/** Promosyon kodu havuzları (`admin`): kişi başına tekil kod atanan listeler. */
final class PromoCodeLists
{
    public function __construct(private readonly Client $client)
    {
    }

    private function base(?string $appId): string
    {
        return $this->client->appPath($appId) . '/promo-code-lists';
    }

    /** @return array<string, mixed> */
    public function list(?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId));
    }

    /**
     * @param array{name:string, description?:string, expires_at?:?string} $body
     * @return array<string, mixed>
     */
    public function create(array $body, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId), [], $body);
    }

    /** @return array<string, mixed> */
    public function get(string $listId, ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/' . rawurlencode($listId));
    }

    /**
     * @param array{description?:?string, expires_at?:?string} $body
     * @return array<string, mixed>
     */
    public function update(string $listId, array $body, ?string $appId = null): array
    {
        return $this->client->request('PATCH', $this->base($appId) . '/' . rawurlencode($listId), [], $body);
    }

    /** @return array<string, mixed> */
    public function delete(string $listId, ?string $appId = null): array
    {
        return $this->client->request('DELETE', $this->base($appId) . '/' . rawurlencode($listId));
    }

    /**
     * Kodlar ve atamaları.
     *
     * @param array{limit?:int, offset?:int, q?:string, status?:'available'|'assigned'} $params
     * @return array<string, mixed>
     */
    public function codes(string $listId, array $params = [], ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/' . rawurlencode($listId) . '/codes', $params);
    }

    /**
     * CSV içeriğinden kod yükler (multipart, UTF-8).
     *
     * @return array<string, mixed>
     */
    public function uploadCodes(string $listId, string $csv, string $filename = 'codes.csv', ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/' . rawurlencode($listId) . '/codes', [], null, [
            'multipart' => [
                'file' => ['filename' => $filename, 'content' => $csv, 'content_type' => 'text/csv'],
            ],
        ]);
    }
}
