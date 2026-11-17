<?php

declare(strict_types=1);

namespace Pushizo\Resources;

use Pushizo\Client;

/** Channels API (admin kapsamı): kimlik bilgisi yükleme ve doğrulama. */
final class Channels
{
    public function __construct(private readonly Client $client)
    {
    }

    private function base(?string $appId): string
    {
        return '/v1/apps/' . rawurlencode($this->client->appId($appId)) . '/channels';
    }

    /** @return array<string, mixed> */
    public function list(?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId));
    }

    /**
     * @param array<string, mixed> $body ör. ['provider' => 'apns', 'key_id' => …, 'p8' => file_get_contents('AuthKey.p8')]
     * @return array<string, mixed>
     */
    public function upsert(string $channel, array $body, ?string $appId = null): array
    {
        return $this->client->request('PUT', $this->base($appId) . '/' . rawurlencode($channel), [], $body);
    }

    /** @return array<string, mixed> */
    public function validate(string $channel, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/' . rawurlencode($channel) . '/validate');
    }

    /**
     * Telegram/Viber abonelik bağlama derin bağlantısı: kullanıcı bağlantıyı açıp botu başlatınca sohbet kimliği bu
     * kullanıcıya abonelik olarak bağlanır. Belirteç tek kullanımlıktır ve yalnızca bu yanıtta döner.
     *
     * @param array{channel:'telegram'|'viber', user_id?:string, external_id?:string, ttl_seconds?:int} $input
     * @return array<string, mixed>
     */
    public function createMessengerLink(array $input, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->client->appPath($appId) . '/messenger-links', [], $input);
    }

    /**
     * Kanal özeti: yalnız sağlayıcı ve durum (`users:read`).
     *
     * @return array<string, mixed>
     */
    public function status(?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/status');
    }

    /**
     * E-posta gönderici alan adının SPF/DKIM/DMARC kayıtlarını doğrular.
     *
     * @return array<string, mixed>
     */
    public function verifyEmailDns(?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/email/verify-dns');
    }
}
