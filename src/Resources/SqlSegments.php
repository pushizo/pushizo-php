<?php

declare(strict_types=1);

namespace Pushizo\Resources;

use Pushizo\Client;

/**
 * SQL sorgu ve SQL segment (ADR-0087): izinli görünümler üzerinde salt okunur SELECT, sorgu kaydı ve sorgu
 * sonucundaki `user_id` kolonundan statik segment.
 */
final class SqlSegments
{
    public function __construct(private readonly Client $client)
    {
    }

    private function base(?string $appId): string
    {
        return $this->client->appPath($appId) . '/sql';
    }

    /**
     * İzinli görünümler, kolonlar ve sınırlar.
     *
     * @return array<string, mixed>
     */
    public function schema(?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/schema');
    }

    /**
     * Sorgu çalıştırır; satırlar kolon sırasıyla dizi olarak döner.
     *
     * @param array{query:string, row_limit?:int, timeout_ms?:int} $body
     * @return array<string, mixed>
     */
    public function query(array $body, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/query', [], $body);
    }

    /**
     * Sorgu kaydı (cursor sayfalı).
     *
     * @param array{segment_id?:string, status?:'ok'|'rejected'|'timeout'|'failed', limit?:int, cursor?:string} $params
     * @return array<string, mixed>
     */
    public function runs(array $params = [], ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/runs', $params);
    }

    /** @return array<string, mixed> */
    public function listSegments(?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/segments');
    }

    /**
     * Sorgu sonucunu segment olarak kaydeder.
     *
     * @param array{name:string, description?:string|null, query:string, refresh_interval_minutes?:int|null} $body
     * @return array<string, mixed>
     */
    public function createSegment(array $body, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/segments', [], $body);
    }

    /**
     * @param array{name?:string, description?:string|null, query?:string, refresh_interval_minutes?:int|null} $body
     * @return array<string, mixed>
     */
    public function updateSegment(string $segmentId, array $body, ?string $appId = null): array
    {
        return $this->client->request('PATCH', $this->base($appId) . '/segments/' . rawurlencode($segmentId), [], $body);
    }

    /**
     * Segmenti şimdi yeniler.
     *
     * @return array<string, mixed>
     */
    public function refreshSegment(string $segmentId, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/segments/' . rawurlencode($segmentId) . '/refresh');
    }
}
