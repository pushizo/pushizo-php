<?php

declare(strict_types=1);

namespace Pushizo\Resources;

use Pushizo\Client;

/**
 * Hazır entegrasyonlar (ADR-0031, ADR-0095): bağlı entegrasyonların durumu, çalışma geçmişi, bağlantı testi ve cohort
 * senkronu (`admin`). Bağlama/güncelleme (kimlik bilgisi) ve bağlantıyı kesme dashboard'dan yapılır.
 */
final class Integrations
{
    public function __construct(private readonly Client $client)
    {
    }

    private function base(?string $appId): string
    {
        return $this->client->appPath($appId) . '/integrations';
    }

    /**
     * Bağlı entegrasyonlar ve katalog.
     *
     * @return array<string, mixed>
     */
    public function list(?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId));
    }

    /**
     * Entegrasyon ayrıntısı; kimlik bilgisi maskeli döner.
     *
     * @return array<string, mixed>
     */
    public function get(string $type, ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/' . rawurlencode($type));
    }

    /**
     * Son çalışmalar: senkron, olay gönderimi, gelen istekler ve testler.
     *
     * @param array{limit?:int} $params
     * @return array<string, mixed>
     */
    public function runs(string $type, array $params = [], ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/' . rawurlencode($type) . '/runs', $params);
    }

    /**
     * Bağlantı testi.
     *
     * @return array<string, mixed>
     */
    public function test(string $type, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/' . rawurlencode($type) . '/test');
    }

    /**
     * Cohort senkronunu şimdi kuyruğa alır (202).
     *
     * @return array<string, mixed>
     */
    public function sync(string $type, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/' . rawurlencode($type) . '/sync');
    }

    /**
     * Zapier REST Hook abonelikleri.
     *
     * @return array<string, mixed>
     */
    public function zapierHooks(?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/zapier/hooks');
    }
}
