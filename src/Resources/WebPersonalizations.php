<?php

declare(strict_types=1);

namespace Pushizo\Resources;

use Pushizo\Client;

/**
 * Web sayfası kişiselleştirme (ADR-0083): sayfa öğelerini (CSS seçici) kişiye göre değiştiren deneyimler; taslak
 * oluşturma, düzenleme, durum geçişi ve günlük maruz kalma/dönüşüm raporu.
 */
final class WebPersonalizations
{
    public function __construct(private readonly Client $client)
    {
    }

    private function base(?string $appId): string
    {
        return $this->client->appPath($appId) . '/web-personalizations';
    }

    /**
     * @param array{limit?:int, cursor?:string, status?:'draft'|'active'|'paused'|'archived'} $params
     * @return array<string, mixed>
     */
    public function list(array $params = [], ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId), $params);
    }

    /**
     * Deneyim taslak olarak oluşur; yayın için `setStatus($id, 'active')`.
     *
     * @param array<string, mixed> $body name (zorunlu), changes, variants, conditions, targeting, goal, priority, start_at, end_at
     * @return array<string, mixed>
     */
    public function create(array $body, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId), [], $body);
    }

    /** @return array<string, mixed> */
    public function get(string $personalizationId, ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/' . rawurlencode($personalizationId));
    }

    /**
     * @param array<string, mixed> $body name, changes, variants, conditions, targeting, goal, priority, start_at, end_at
     * @return array<string, mixed>
     */
    public function update(string $personalizationId, array $body, ?string $appId = null): array
    {
        return $this->client->request('PATCH', $this->base($appId) . '/' . rawurlencode($personalizationId), [], $body);
    }

    /**
     * Son `days` günün maruz kalan ve dönüşen tekil ziyaretçi serisi.
     *
     * @param array{days?:int} $params
     * @return array<string, mixed>
     */
    public function report(string $personalizationId, array $params = [], ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/' . rawurlencode($personalizationId) . '/report', $params);
    }

    /**
     * Yayınla (`active`), duraklat (`paused`), arşivle (`archived`) veya taslağa al (`draft`).
     *
     * @param 'draft'|'active'|'paused'|'archived' $status
     * @return array<string, mixed>
     */
    public function setStatus(string $personalizationId, string $status, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/' . rawurlencode($personalizationId) . '/status', [], ['status' => $status]);
    }

    /**
     * A/B kazananını seçer; SDK bundan sonra yalnız kazananın değişikliklerini uygular.
     *
     * @return array<string, mixed>
     */
    public function selectWinner(string $personalizationId, string $variantId, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/' . rawurlencode($personalizationId) . '/winner', [], ['variant_id' => $variantId]);
    }

    /**
     * Deneyimi olaylarıyla birlikte siler.
     *
     * @return array<string, mixed>
     */
    public function delete(string $personalizationId, ?string $appId = null): array
    {
        return $this->client->request('DELETE', $this->base($appId) . '/' . rawurlencode($personalizationId));
    }
}
