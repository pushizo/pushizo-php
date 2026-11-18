<?php

declare(strict_types=1);

namespace Pushizo\Resources;

use Pushizo\Client;

/**
 * Müşterinin kendi AI ajanı (ADR-0089): talimat, izinli araçlar, zamanlama, elle çalıştırma, çalışma kayıtları ve
 * taslaklar. Ajan gönderim yapamaz; yazma araçları yalnız taslak üretir ve taslaklar panelde onaylanarak uygulanır.
 */
final class CustomAgents
{
    public function __construct(private readonly Client $client)
    {
    }

    private function base(?string $appId): string
    {
        return $this->client->appPath($appId) . '/custom-agents';
    }

    /** @return array<string, mixed> */
    public function list(?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId));
    }

    /**
     * @param array{
     *   name:string, description?:string, enabled?:bool, instructions?:string,
     *   tools?:list<'list_segments'|'campaign_performance'|'delivery_summary'|'create_segment_draft'|'create_campaign_draft'>,
     *   schedule?:array<string, mixed>|null, limits?:array<string, mixed>
     * } $body
     * @return array<string, mixed>
     */
    public function create(array $body, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId), [], $body);
    }

    /** @return array<string, mixed> */
    public function get(string $agentId, ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/' . rawurlencode($agentId));
    }

    /**
     * @param array<string, mixed> $body name, description, enabled, instructions, tools, schedule, limits
     * @return array<string, mixed>
     */
    public function update(string $agentId, array $body, ?string $appId = null): array
    {
        return $this->client->request('PATCH', $this->base($appId) . '/' . rawurlencode($agentId), [], $body);
    }

    /** @return array<string, mixed> */
    public function delete(string $agentId, ?string $appId = null): array
    {
        return $this->client->request('DELETE', $this->base($appId) . '/' . rawurlencode($agentId));
    }

    /**
     * Şimdi çalıştırır (202; çalıştırma `pending` döner, sonuç `runs` ile izlenir).
     *
     * @return array<string, mixed>
     */
    public function run(string $agentId, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/' . rawurlencode($agentId) . '/run');
    }

    /**
     * Çalışma kayıtları (cursor sayfalı).
     *
     * @param array{agent_id?:string, status?:'pending'|'running'|'succeeded'|'failed'|'skipped', limit?:int, cursor?:string} $params
     * @return array<string, mixed>
     */
    public function runs(array $params = [], ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/runs', $params);
    }

    /**
     * Taslaklar (cursor sayfalı).
     *
     * @param array{agent_id?:string, run_id?:string, status?:'pending'|'applied'|'dismissed', limit?:int, cursor?:string} $params
     * @return array<string, mixed>
     */
    public function drafts(array $params = [], ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/drafts', $params);
    }
}
