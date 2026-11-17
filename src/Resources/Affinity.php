<?php

declare(strict_types=1);

namespace Pushizo\Resources;

use Pushizo\Client;

/** İlgi etiketleri (ADR-0081): model ayarı, hesap, özellik başına yaygın ilgiler ve kişinin ilgileri. */
final class Affinity
{
    public function __construct(private readonly Client $client)
    {
    }

    private function base(?string $appId): string
    {
        return $this->client->appPath($appId) . '/affinity';
    }

    /** @return array<string, mixed> */
    public function getConfig(?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/config');
    }

    /**
     * Model açıksa ilgiler kayıttan sonra yeniden hesaplanır.
     *
     * @param array{enabled:bool, attributes:list<string>, events:array<string, mixed>, window_days?:int, half_life_days?:int} $body
     * @return array<string, mixed>
     */
    public function updateConfig(array $body, ?string $appId = null): array
    {
        return $this->client->request('PUT', $this->base($appId) . '/config', [], $body);
    }

    /**
     * Hesabı kuyruğa alır; ilerleme `status` alanından izlenir.
     *
     * @return array<string, mixed>
     */
    public function compute(?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/compute');
    }

    /**
     * Özellik başına en yaygın ilgiler.
     *
     * @return array<string, mixed>
     */
    public function overview(?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/overview');
    }

    /**
     * Kişinin ilgileri ve kişisel haftalık sıklık sınırı.
     *
     * @return array<string, mixed>
     */
    public function user(string $userId, ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/users/' . rawurlencode($userId));
    }
}
