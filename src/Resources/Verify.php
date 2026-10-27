<?php

declare(strict_types=1);

namespace Pushizo\Resources;

use Pushizo\Client;

/** SMS doğrulama. Yanıtta kod yoktur. */
final class Verify
{
    public function __construct(private readonly Client $client)
    {
    }

    private function base(?string $appId): string
    {
        return $this->client->appPath($appId) . '/verify/sms';
    }

    /**
     * @param array{phone:string, locale?:string} $input
     * @return array<string, mixed>
     */
    public function start(array $input, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId), [], $input);
    }

    /**
     * @param array{id:string, code:string} $input
     * @return array<string, mixed>
     */
    public function check(array $input, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/check', [], $input);
    }
}
