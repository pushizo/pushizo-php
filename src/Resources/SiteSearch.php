<?php

declare(strict_types=1);

namespace Pushizo\Resources;

use Pushizo\Client;

/**
 * Site içi AI arama (ADR-0093): ayarlar, arama kaynakları (site haritası / adres listesi), belge dizini ve sorgu
 * raporu. Ziyaretçi araması Web SDK ile yapılır; bu kaynak dizini ve ayarları sunucudan yönetir.
 */
final class SiteSearch
{
    public function __construct(private readonly Client $client)
    {
    }

    private function base(?string $appId): string
    {
        return $this->client->appPath($appId) . '/site-search';
    }

    /**
     * Ayarlar, dizin durumu ve bu ayki AI token kullanımı.
     *
     * @return array<string, mixed>
     */
    public function get(?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId));
    }

    /**
     * @param array{enabled?:bool, ai_answers?:bool, allowed_origins?:list<string>, language?:string, monthly_token_cap?:int, query_retention_days?:int} $body
     * @return array<string, mixed>
     */
    public function updateSettings(array $body, ?string $appId = null): array
    {
        return $this->client->request('PATCH', $this->base($appId) . '/settings', [], $body);
    }

    /** @return array<string, mixed> */
    public function listSources(?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/sources');
    }

    /**
     * @param array{name:string, kind:'sitemap'|'urls', url?:string, urls?:list<string>, max_pages?:int, refresh_hours?:int|null, enabled?:bool} $body
     * @return array<string, mixed>
     */
    public function createSource(array $body, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/sources', [], $body);
    }

    /**
     * @param array<string, mixed> $body name, kind, url, urls, max_pages, refresh_hours, enabled
     * @return array<string, mixed>
     */
    public function updateSource(string $sourceId, array $body, ?string $appId = null): array
    {
        return $this->client->request('PATCH', $this->base($appId) . '/sources/' . rawurlencode($sourceId), [], $body);
    }

    /**
     * Kaynağı ve dizindeki sayfalarını siler.
     *
     * @return array<string, mixed>
     */
    public function deleteSource(string $sourceId, ?string $appId = null): array
    {
        return $this->client->request('DELETE', $this->base($appId) . '/sources/' . rawurlencode($sourceId));
    }

    /**
     * Kaynağı şimdi yeniden tarar; bekleyen tarama varsa `queued: false` döner.
     *
     * @return array<string, mixed>
     */
    public function crawlSource(string $sourceId, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/sources/' . rawurlencode($sourceId) . '/crawl');
    }

    /**
     * Dizindeki belgeler; `source_id` yalnız API ile yüklenen belgeler için `api` olabilir.
     *
     * @param array{source_id?:string, q?:string, limit?:int, cursor?:string} $params
     * @return array<string, mixed>
     */
    public function listDocuments(array $params = [], ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/documents', $params);
    }

    /**
     * Belgeyi yükler veya değiştirir; anahtar app içinde tekildir (harf, rakam, `. _ : -`).
     *
     * @param array{url:string, title?:string, content?:string, html?:string, language?:string} $body
     * @return array<string, mixed>
     */
    public function putDocument(string $documentKey, array $body, ?string $appId = null): array
    {
        return $this->client->request('PUT', $this->base($appId) . '/documents/' . rawurlencode($documentKey), [], $body);
    }

    /**
     * Belgeyi kimliği veya anahtarıyla siler.
     *
     * @return array<string, mixed>
     */
    public function deleteDocument(string $documentKey, ?string $appId = null): array
    {
        return $this->client->request('DELETE', $this->base($appId) . '/documents/' . rawurlencode($documentKey));
    }

    /**
     * Arama raporu (`days`, varsayılan 30).
     *
     * @param array{days?:int} $params
     * @return array<string, mixed>
     */
    public function report(array $params = [], ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/report', $params);
    }
}
