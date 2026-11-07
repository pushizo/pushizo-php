<?php

declare(strict_types=1);

namespace Pushizo\Resources;

use Pushizo\Client;

/** Ürün katalogları (okuma `analytics:read` veya `admin`; yazma `admin`): mesajlarda ürün bloğu ve öneriler için. */
final class Catalogs
{
    public function __construct(private readonly Client $client)
    {
    }

    private function base(?string $appId): string
    {
        return $this->client->appPath($appId) . '/catalogs';
    }

    /** @return array<string, mixed> */
    public function list(?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId));
    }

    /**
     * @param array{name:string, description?:string, currency?:string} $body
     * @return array<string, mixed>
     */
    public function create(array $body, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId), [], $body);
    }

    /** @return array<string, mixed> */
    public function get(string $catalogId, ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/' . rawurlencode($catalogId));
    }

    /**
     * @param array{description?:?string, currency?:string} $body
     * @return array<string, mixed>
     */
    public function update(string $catalogId, array $body, ?string $appId = null): array
    {
        return $this->client->request('PATCH', $this->base($appId) . '/' . rawurlencode($catalogId), [], $body);
    }

    /** @return array<string, mixed> */
    public function delete(string $catalogId, ?string $appId = null): array
    {
        return $this->client->request('DELETE', $this->base($appId) . '/' . rawurlencode($catalogId));
    }

    /**
     * @param array{limit?:int, offset?:int, q?:string, category?:string} $params
     * @return array<string, mixed>
     */
    public function items(string $catalogId, array $params = [], ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/' . rawurlencode($catalogId) . '/items', $params);
    }

    /**
     * Ürünleri kimliğe göre ekler veya günceller.
     *
     * @param array{items:list<array<string, mixed>>} $body
     * @return array<string, mixed>
     */
    public function upsertItems(string $catalogId, array $body, ?string $appId = null): array
    {
        return $this->client->request('PUT', $this->base($appId) . '/' . rawurlencode($catalogId) . '/items', [], $body);
    }

    /** @return array<string, mixed> */
    public function getItem(string $catalogId, string $itemId, ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/' . rawurlencode($catalogId) . '/items/' . rawurlencode($itemId));
    }

    /** @return array<string, mixed> */
    public function deleteItem(string $catalogId, string $itemId, ?string $appId = null): array
    {
        return $this->client->request('DELETE', $this->base($appId) . '/' . rawurlencode($catalogId) . '/items/' . rawurlencode($itemId));
    }

    /**
     * CSV içeriğinden ürün yükler (multipart, UTF-8).
     *
     * @return array<string, mixed>
     */
    public function uploadItems(string $catalogId, string $csv, string $filename = 'items.csv', ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/' . rawurlencode($catalogId) . '/items/upload', [], null, [
            'multipart' => [
                'file' => ['filename' => $filename, 'content' => $csv, 'content_type' => 'text/csv'],
            ],
        ]);
    }
}
