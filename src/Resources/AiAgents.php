<?php

declare(strict_types=1);

namespace Pushizo\Resources;

use Pushizo\Client;

/**
 * Konuşmalı AI ajanı (ADR-0085): tanım, araç imza anahtarı rotasyonu, çalışma kayıtları ve ölçümler. Oluşturma ve
 * rotasyon yanıtındaki `tool_secret` yalnız bir kez döner; loglamayın.
 */
final class AiAgents
{
    public function __construct(private readonly Client $client)
    {
    }

    private function base(?string $appId): string
    {
        return $this->client->appPath($appId) . '/ai-agents';
    }

    /** @return array<string, mixed> */
    public function list(?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId));
    }

    /**
     * @param array{
     *   name:string, enabled?:bool, channels?:list<'whatsapp'|'sms'|'rcs'|'telegram'|'viber'|'chat'>, instructions?:string,
     *   faq?:list<array<string, mixed>>, tools?:list<array<string, mixed>>, handoff?:array<string, mixed>,
     *   limits?:array<string, mixed>, ai_sentiment?:bool
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
     * @param array<string, mixed> $body name, enabled, channels, instructions, faq, tools, handoff, limits, ai_sentiment
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
     * Araç imza anahtarını yeniler; yeni `tool_secret` yalnız bu yanıtta döner.
     *
     * @return array<string, mixed>
     */
    public function rotateSecret(string $agentId, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/' . rawurlencode($agentId) . '/rotate-secret');
    }

    /**
     * Ajan ölçümleri (son 30 gün).
     *
     * @return array<string, mixed>
     */
    public function metrics(string $agentId, ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/' . rawurlencode($agentId) . '/metrics');
    }

    /**
     * Çalışma kayıtları (cursor sayfalı).
     *
     * @param array{agent_id?:string, conversation_id?:string, status?:'pending'|'replied'|'handed_off'|'skipped_cap'|'failed', limit?:int, cursor?:string} $params
     * @return array<string, mixed>
     */
    public function runs(array $params = [], ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/runs', $params);
    }
}
