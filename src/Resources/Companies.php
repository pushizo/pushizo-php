<?php

declare(strict_types=1);

namespace Pushizo\Resources;

use Pushizo\Client;

/** Şirket/hesap nesneleri (ADR-0090): şirket CRUD, toplu upsert ve kişi ↔ şirket üyeliği. */
final class Companies
{
    public function __construct(private readonly Client $client)
    {
    }

    private function base(?string $appId): string
    {
        return $this->client->appPath($appId) . '/companies';
    }

    /**
     * Liste; en yeni önce.
     *
     * @param array{limit?:int, cursor?:string, q?:string, external_id?:string, domain?:string} $params
     * @return array<string, mixed>
     */
    public function list(array $params = [], ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId), $params);
    }

    /**
     * `external_id` ile oluşturur veya günceller; özellikler merge-patch (null siler).
     *
     * @param array{external_id:string, name?:string|null, domain?:string|null, properties?:array<string, mixed>} $body
     * @return array<string, mixed>
     */
    public function upsert(array $body, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId), [], $body);
    }

    /**
     * İstek başına en çok 1000 şirket.
     *
     * @param array{companies:list<array<string, mixed>>} $body
     * @return array<string, mixed>
     */
    public function batchUpsert(array $body, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/batch', [], $body);
    }

    /** @return array<string, mixed> */
    public function get(string $companyId, ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/' . rawurlencode($companyId));
    }

    /**
     * @param array{external_id?:string, name?:string|null, domain?:string|null, properties?:array<string, mixed>} $body
     * @return array<string, mixed>
     */
    public function update(string $companyId, array $body, ?string $appId = null): array
    {
        return $this->client->request('PATCH', $this->base($appId) . '/' . rawurlencode($companyId), [], $body);
    }

    /**
     * Şirketi siler; üyelikler silinir, kişiler kalır.
     *
     * @return array<string, mixed>
     */
    public function delete(string $companyId, ?string $appId = null): array
    {
        return $this->client->request('DELETE', $this->base($appId) . '/' . rawurlencode($companyId));
    }

    /**
     * Şirketin kişileri (cursor sayfalı).
     *
     * @param array{limit?:int, cursor?:string, role?:string} $params
     * @return array<string, mixed>
     */
    public function members(string $companyId, array $params = [], ?string $appId = null): array
    {
        return $this->client->request('GET', $this->base($appId) . '/' . rawurlencode($companyId) . '/members', $params);
    }

    /**
     * Kişiyi şirkete bağlar veya rolünü değiştirir (rol verilmezse mevcut rol korunur, yeni üyelik `member`).
     *
     * @param array{role?:string} $body
     * @return array<string, mixed>
     */
    public function setMember(string $companyId, string $userId, array $body = [], ?string $appId = null): array
    {
        // Boş gövde JSON nesnesi (`{}`) olarak gönderilir.
        return $this->client->request('PUT', $this->base($appId) . '/' . rawurlencode($companyId) . '/members/' . rawurlencode($userId), [], (object) $body);
    }

    /**
     * Kişiler `user_id` veya kişinin `external_id` değeriyle; bulunamayanlar `not_found` listesinde döner.
     *
     * @param array{members:list<array<string, mixed>>} $body
     * @return array<string, mixed>
     */
    public function batchMembers(string $companyId, array $body, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/' . rawurlencode($companyId) . '/members/batch', [], $body);
    }

    /** @return array<string, mixed> */
    public function removeMember(string $companyId, string $userId, ?string $appId = null): array
    {
        return $this->client->request('DELETE', $this->base($appId) . '/' . rawurlencode($companyId) . '/members/' . rawurlencode($userId));
    }

    /**
     * Kişinin şirketleri (en son bağlanan önce).
     *
     * @return array<string, mixed>
     */
    public function forUser(string $userId, ?string $appId = null): array
    {
        return $this->client->request('GET', $this->client->appPath($appId) . '/users/' . rawurlencode($userId) . '/companies');
    }

    /**
     * Kişinin şirketleri; kişi `external_id` veya alias etiketiyle.
     *
     * @return array<string, mixed>
     */
    public function forUserBy(string $label, string $value, ?string $appId = null): array
    {
        return $this->client->request('GET', $this->client->appPath($appId) . '/users/by/' . rawurlencode($label) . '/' . rawurlencode($value) . '/companies');
    }
}
