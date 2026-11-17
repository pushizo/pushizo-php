<?php

declare(strict_types=1);

namespace Pushizo\Resources;

use Pushizo\Client;

/**
 * Zero-copy ambar segmenti (ADR-0091): BigQuery, Snowflake veya Databricks üzerinde çalışan sorgudan yalnız kişi
 * kimlikleriyle statik segment. Kimlik bilgileri (`credentials`) şifreli saklanır ve yanıtlarda dönmez; loglamayın.
 * Yenileme arka planda çalışır: oluşturma ve `refreshSegment` yenilemeyi kuyruğa alır.
 */
final class WarehouseSegments
{
    public function __construct(private readonly Client $client)
    {
    }

    private function base(?string $appId): string
    {
        return $this->client->appPath($appId) . '/warehouse-segments';
    }

    /**
     * Motorlar, kimlik türleri, Snowflake ambar boyutları, yenileme aralıkları ve sınırlar.
     *
     * @return array<string, mixed>
     */
    public function options(?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/options');
    }

    /** @return array<string, mixed> */
    public function listConnections(?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/connections');
    }

    /**
     * Bağlantı oluşturur; yanıtta kimlik bilgisinin yalnız ipucu döner.
     *
     * @param array{name:string, type:'bigquery'|'snowflake'|'databricks', config:array<string, mixed>, credentials:array<string, mixed>} $body
     * @return array<string, mixed>
     */
    public function createConnection(array $body, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/connections', [], $body);
    }

    /** @return array<string, mixed> */
    public function getConnection(string $connectionId, ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/connections/' . rawurlencode($connectionId));
    }

    /**
     * @param array{name?:string, config?:array<string, mixed>, credentials?:array<string, mixed>} $body
     * @return array<string, mixed>
     */
    public function updateConnection(string $connectionId, array $body, ?string $appId = null): array
    {
        return $this->client->request('PATCH', $this->base($appId) . '/connections/' . rawurlencode($connectionId), [], $body);
    }

    /** @return array<string, mixed> */
    public function deleteConnection(string $connectionId, ?string $appId = null): array
    {
        return $this->client->request('DELETE', $this->base($appId) . '/connections/' . rawurlencode($connectionId));
    }

    /**
     * Kayıtlı bağlantıyı dener.
     *
     * @return array<string, mixed>
     */
    public function testConnection(string $connectionId, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/connections/' . rawurlencode($connectionId) . '/test');
    }

    /**
     * `dry_run`: maliyet tahmini; `sample`: en çok 20 örnek kimlik ve eşleşme sayısı.
     *
     * @param array{connection_id:string, query:string, id_column:string, id_type?:'external_id'|'user_id', limits?:array<string, mixed>, mode?:'dry_run'|'sample'} $body
     * @return array<string, mixed>
     */
    public function preview(array $body, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/preview', [], $body);
    }

    /** @return array<string, mixed> */
    public function listSegments(?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/segments');
    }

    /**
     * @param array{
     *   name:string, description?:string|null, connection_id:string, query:string, id_column:string,
     *   id_type?:'external_id'|'user_id', refresh_interval_minutes?:int|null, limits?:array<string, mixed>
     * } $body
     * @return array<string, mixed>
     */
    public function createSegment(array $body, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/segments', [], $body);
    }

    /**
     * @param array<string, mixed> $body name, description, connection_id, query, id_column, id_type, refresh_interval_minutes, limits
     * @return array<string, mixed>
     */
    public function updateSegment(string $segmentId, array $body, ?string $appId = null): array
    {
        return $this->client->request('PATCH', $this->base($appId) . '/segments/' . rawurlencode($segmentId), [], $body);
    }

    /**
     * Segmenti yenileme kuyruğuna alır.
     *
     * @return array<string, mixed>
     */
    public function refreshSegment(string $segmentId, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/segments/' . rawurlencode($segmentId) . '/refresh');
    }

    /**
     * Ambar sorgu kaydı (cursor sayfalı).
     *
     * @param array{segment_id?:string, connection_id?:string, status?:'ok'|'rejected'|'timeout'|'failed', limit?:int, cursor?:string} $params
     * @return array<string, mixed>
     */
    public function runs(array $params = [], ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/runs', $params);
    }
}
