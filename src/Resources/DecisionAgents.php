<?php

declare(strict_types=1);

namespace Pushizo\Resources;

use Pushizo\Client;

/** Karar ajanları (`analytics:read`; yazma `admin`; karar `notifications:write`): kişi başına en iyi seçeneği öğrenir. */
final class DecisionAgents
{
    public function __construct(private readonly Client $client)
    {
    }

    private function base(?string $appId): string
    {
        return $this->client->appPath($appId) . '/decision-agents';
    }

    /** @return array<string, mixed> */
    public function list(?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId));
    }

    /**
     * @param array{slug:string, name:string, arms:list<array{id:string, label:string}>, reward_events:list<string>, reward_window_hours?:int, holdout_percent?:int, context_prediction?:?string, use_best_channel?:bool} $body
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
     * @param array<string, mixed> $body
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
     * Seçenek başına atama, ödül ve kontrol grubuna karşı kazanç.
     *
     * @return array<string, mixed>
     */
    public function report(string $agentId, ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/' . rawurlencode($agentId) . '/report');
    }

    /**
     * Kullanıcı için seçenek belirler; mesaj göndermez.
     *
     * @return array<string, mixed>
     */
    public function decide(string $agentId, string $externalId, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/' . rawurlencode($agentId) . '/decide', [], ['external_id' => $externalId]);
    }
}
