<?php

declare(strict_types=1);

namespace Pushizo\Resources;

use Pushizo\Client;

/**
 * Veri dönüştürme ve kaynak boru hattı (ADR-0088): olay/kişi dönüştürmeleri (kural veya sandbox'lı JavaScript),
 * önizleme, webhook ve HTTP pull kaynakları, karantina. Webhook kaynağının `secret` değeri yalnız oluşturma ve
 * yenileme yanıtında döner; loglamayın.
 */
final class DataPipelines
{
    public function __construct(private readonly Client $client)
    {
    }

    private function base(?string $appId): string
    {
        return $this->client->appPath($appId) . '/data-pipelines';
    }

    /**
     * @param array{target?:'event'|'person'} $params
     * @return array<string, mixed>
     */
    public function listTransforms(array $params = [], ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/transforms', $params);
    }

    /**
     * @param array{
     *   name:string, target:'event'|'person', kind:'rules'|'javascript', description?:string, enabled?:bool, position?:int,
     *   rules?:list<array<string, mixed>>, code?:string,
     *   sources?:list<'sdk'|'api'|'import'|'system'|'webhook_source'|'pull_source'>, event_names?:list<string>
     * } $body
     * @return array<string, mixed>
     */
    public function createTransform(array $body, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/transforms', [], $body);
    }

    /**
     * Örnek kayıtlarla kuru çalıştırma; hiçbir şey yazılmaz.
     *
     * @param array{
     *   target:'event'|'person', records:list<array<string, mixed>>,
     *   source?:'sdk'|'api'|'import'|'system'|'webhook_source'|'pull_source', transform?:array<string, mixed>, transform_id?:string
     * } $body
     * @return array<string, mixed>
     */
    public function previewTransform(array $body, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/transforms/preview', [], $body);
    }

    /** @return array<string, mixed> */
    public function getTransform(string $transformId, ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/transforms/' . rawurlencode($transformId));
    }

    /**
     * @param array<string, mixed> $body name, description, enabled, position, rules, code, sources, event_names
     * @return array<string, mixed>
     */
    public function updateTransform(string $transformId, array $body, ?string $appId = null): array
    {
        return $this->client->request('PATCH', $this->base($appId) . '/transforms/' . rawurlencode($transformId), [], $body);
    }

    /** @return array<string, mixed> */
    public function deleteTransform(string $transformId, ?string $appId = null): array
    {
        return $this->client->request('DELETE', $this->base($appId) . '/transforms/' . rawurlencode($transformId));
    }

    /**
     * Veri kaynakları (webhook ve HTTP pull).
     *
     * @return array<string, mixed>
     */
    public function listSources(?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/sources');
    }

    /**
     * Webhook kaynağında `secret` yalnız bu yanıtta döner.
     *
     * @param array{
     *   name:string, kind:'webhook'|'http_pull', target:'event'|'person', enabled?:bool, auth_mode?:'signature'|'token',
     *   mapping?:array<string, mixed>, config?:array<string, mixed>, auth_value?:string
     * } $body
     * @return array<string, mixed>
     */
    public function createSource(array $body, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/sources', [], $body);
    }

    /** @return array<string, mixed> */
    public function getSource(string $sourceId, ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/sources/' . rawurlencode($sourceId));
    }

    /**
     * @param array{name?:string, enabled?:bool, auth_mode?:'signature'|'token', mapping?:array<string, mixed>, config?:array<string, mixed>, auth_value?:string} $body
     * @return array<string, mixed>
     */
    public function updateSource(string $sourceId, array $body, ?string $appId = null): array
    {
        return $this->client->request('PATCH', $this->base($appId) . '/sources/' . rawurlencode($sourceId), [], $body);
    }

    /** @return array<string, mixed> */
    public function deleteSource(string $sourceId, ?string $appId = null): array
    {
        return $this->client->request('DELETE', $this->base($appId) . '/sources/' . rawurlencode($sourceId));
    }

    /**
     * Webhook kaynağı anahtarını yeniler; yeni `secret` yalnız bu yanıtta döner.
     *
     * @return array<string, mixed>
     */
    public function rotateSourceSecret(string $sourceId, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/sources/' . rawurlencode($sourceId) . '/rotate-secret');
    }

    /**
     * HTTP pull kaynağını şimdi çalıştırır.
     *
     * @return array<string, mixed>
     */
    public function runSource(string $sourceId, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/sources/' . rawurlencode($sourceId) . '/run');
    }

    /**
     * Karantinadaki kayıtlar (cursor sayfalı).
     *
     * @param array{status?:'pending'|'reprocessed'|'discarded', target?:'event'|'person', source_id?:string, transform_id?:string, limit?:int, cursor?:string} $params
     * @return array<string, mixed>
     */
    public function listQuarantine(array $params = [], ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/quarantine', $params);
    }

    /**
     * Karantinadaki kayıtları yeniden işler; `ids` verilmezse bekleyen en eski 500 kayıt güncel dönüştürmelerle işlenir.
     *
     * @param array{ids?:list<string>} $body
     * @return array<string, mixed>
     */
    public function reprocessQuarantine(array $body = [], ?string $appId = null): array
    {
        // Boş gövde JSON nesnesi (`{}`) olarak gönderilir.
        return $this->client->request('POST', $this->base($appId) . '/quarantine/reprocess', [], (object) $body);
    }

    /**
     * Karantinadaki kayıtları yeniden işlemeden kapatır.
     *
     * @param array{ids:list<string>} $body
     * @return array<string, mixed>
     */
    public function discardQuarantine(array $body, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/quarantine/discard', [], $body);
    }
}
