<?php

declare(strict_types=1);

namespace Pushizo\Resources;

use Pushizo\Client;

/** Apple ve Google Wallet (`users:read`; ayar ve şablon `admin`; kart `notifications:write`): kupon, sadakat kartı, bilet. */
final class Wallet
{
    public function __construct(private readonly Client $client)
    {
    }

    private function base(?string $appId): string
    {
        return $this->client->appPath($appId) . '/wallet';
    }

    /** @return array<string, mixed> */
    public function getConfig(?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/config');
    }

    /**
     * Apple ve Google kimlik bilgileri; değerler şifreli saklanır ve geri okunamaz.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function updateConfig(array $body, ?string $appId = null): array
    {
        return $this->client->request('PUT', $this->base($appId) . '/config', [], $body);
    }

    /** @return array<string, mixed> */
    public function listTemplates(?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/templates');
    }

    /**
     * @param array{slug:string, name:string, kind:string, design:array<string, mixed>} $body
     * @return array<string, mixed>
     */
    public function createTemplate(array $body, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/templates', [], $body);
    }

    /** @return array<string, mixed> */
    public function getTemplate(string $templateId, ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/templates/' . rawurlencode($templateId));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function updateTemplate(string $templateId, array $body, ?string $appId = null): array
    {
        return $this->client->request('PATCH', $this->base($appId) . '/templates/' . rawurlencode($templateId), [], $body);
    }

    /** @return array<string, mixed> */
    public function deleteTemplate(string $templateId, ?string $appId = null): array
    {
        return $this->client->request('DELETE', $this->base($appId) . '/templates/' . rawurlencode($templateId));
    }

    /**
     * @param array{template_id?:string, limit?:int, cursor?:string} $params
     * @return array<string, mixed>
     */
    public function listPasses(array $params = [], ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/passes', $params);
    }

    /**
     * Kart verir; kullanıcılı kart şablon başına tektir.
     *
     * @param array{template_id:string, external_id?:string, barcode_value?:string, values?:array<string, string>} $body
     * @return array<string, mixed>
     */
    public function issuePass(array $body, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/passes', [], $body);
    }

    /** @return array<string, mixed> */
    public function getPass(string $passId, ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/passes/' . rawurlencode($passId));
    }

    /**
     * Kartı günceller; cihazdaki kart arka planda yenilenir.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function updatePass(string $passId, array $body, ?string $appId = null): array
    {
        return $this->client->request('PATCH', $this->base($appId) . '/passes/' . rawurlencode($passId), [], $body);
    }

    /**
     * Kartı iptal eder; geri alınmaz.
     *
     * @return array<string, mixed>
     */
    public function voidPass(string $passId, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/passes/' . rawurlencode($passId) . '/void');
    }
}
