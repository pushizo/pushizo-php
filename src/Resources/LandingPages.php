<?php

declare(strict_types=1);

namespace Pushizo\Resources;

use Pushizo\Client;

/** Landing sayfaları (ADR-0039): liste, detay ve yayın durumu. Sayfa düzenleme dashboard'dan yapılır. */
final class LandingPages
{
    public function __construct(private readonly Client $client)
    {
    }

    private function base(?string $appId): string
    {
        return $this->client->appPath($appId) . '/landing-pages';
    }

    /**
     * @param array{limit?:int, cursor?:string, status?:'draft'|'published'|'archived'} $params
     * @return array<string, mixed>
     */
    public function list(array $params = [], ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId), $params);
    }

    /** @return array<string, mixed> */
    public function get(string $pageId, ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/' . rawurlencode($pageId));
    }

    /**
     * Taslağı yayınlar.
     *
     * @return array<string, mixed>
     */
    public function publish(string $pageId, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/' . rawurlencode($pageId) . '/publish');
    }

    /**
     * Yayından kaldırır.
     *
     * @return array<string, mixed>
     */
    public function unpublish(string $pageId, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/' . rawurlencode($pageId) . '/unpublish');
    }

    /**
     * Sayfa taslak olarak oluşur; yayın için `publish`.
     *
     * @param array<string, mixed> $input name, slug, document, seo, settings, custom_domain_id
     * @return array<string, mixed>
     */
    public function create(array $input, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId), [], $input);
    }

    /**
     * Taslağı düzenler; yayındaki sürüm değişmez.
     *
     * @param array<string, mixed> $input name, slug, document, seo, settings, custom_domain_id
     * @return array<string, mixed>
     */
    public function update(string $pageId, array $input, ?string $appId = null): array
    {
        return $this->client->request('PATCH', $this->base($appId) . '/' . rawurlencode($pageId), [], $input);
    }

    /**
     * Arşivler: yayından kalkar ve kotadan düşer.
     *
     * @return array<string, mixed>
     */
    public function archive(string $pageId, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/' . rawurlencode($pageId) . '/archive');
    }

    /**
     * Yayın sürümleri (en yeni önce).
     *
     * @return array<string, mixed>
     */
    public function versions(string $pageId, ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/' . rawurlencode($pageId) . '/versions');
    }

    /**
     * Yayın sürümünü taslağa geri yükler.
     *
     * @return array<string, mixed>
     */
    public function restoreVersion(string $pageId, int $version, ?string $appId = null): array
    {
        $number = (string) $version;
        return $this->client->request('POST', $this->base($appId) . '/' . rawurlencode($pageId) . '/versions/' . rawurlencode($number) . '/restore');
    }

    /** @return array<string, mixed> */
    public function delete(string $pageId, ?string $appId = null): array
    {
        return $this->client->request('DELETE', $this->base($appId) . '/' . rawurlencode($pageId));
    }
}
