<?php

declare(strict_types=1);

namespace Pushizo\Resources;

use Pushizo\Client;

/** MCP onaylı mod: taslaklar ve yalnızca test cihazlarına test gönderimi (`mcp:write`). */
final class Mcp
{
    public function __construct(private readonly Client $client)
    {
    }

    private function base(?string $appId): string
    {
        return $this->client->appPath($appId) . '/mcp';
    }

    /**
     * Kampanya taslağı (gövde `campaigns->create` ile aynıdır; gönderim yapılmaz).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createCampaignDraft(array $input, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/campaign-drafts', [], $input);
    }

    /**
     * Dinamik segment taslağı (gövde `segments->create` ile aynıdır).
     *
     * @param array<string, mixed> $input name, definition, description…
     * @return array<string, mixed>
     */
    public function createSegmentDraft(array $input, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/segment-drafts', [], $input);
    }

    /**
     * Şablon oluşturur (gönderim yapmaz; `mcp` etiketi eklenir).
     *
     * @param array<string, mixed> $input name, content, channel?, tags?
     * @return array<string, mixed>
     */
    public function createTemplateDraft(array $input, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/template-drafts', [], $input);
    }

    /**
     * Journey taslağı (`draft`; yayımlanmaz).
     *
     * @param array<string, mixed> $input name, description?, graph?, settings?
     * @return array<string, mixed>
     */
    public function createJourneyDraft(array $input, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/journey-drafts', [], $input);
    }

    /**
     * In-app mesaj taslağı (`draft`; etkinleştirilmez).
     *
     * @param array<string, mixed> $input name, triggers, display, audience?, frequency?, priority?
     * @return array<string, mixed>
     */
    public function createInAppDraft(array $input, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/in-app-drafts', [], $input);
    }

    /**
     * Web kampanyası `draft` oluşur; yayımlanmaz.
     *
     * @param array<string, mixed> $input name, display, trigger, conditions, targeting, frequency, variants, priority
     * @return array<string, mixed>
     */
    public function createWebCampaignDraft(array $input, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/web-campaign-drafts', [], $input);
    }

    /**
     * Web kişiselleştirme deneyimi `draft` oluşur; yayımlanmaz (Growth+).
     *
     * @param array<string, mixed> $input name, changes, variants, conditions, targeting, goal, priority
     * @return array<string, mixed>
     */
    public function createWebPersonalizationDraft(array $input, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/web-personalization-drafts', [], $input);
    }

    /**
     * Anket `draft` oluşur; etkinleştirilmez.
     *
     * @param array<string, mixed> $input name, questions, settings
     * @return array<string, mixed>
     */
    public function createSurveyDraft(array $input, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/survey-drafts', [], $input);
    }

    /**
     * Landing sayfası `draft` oluşur; yayımlanmaz.
     *
     * @param array<string, mixed> $input name, slug, document, seo, settings
     * @return array<string, mixed>
     */
    public function createLandingPageDraft(array $input, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/landing-page-drafts', [], $input);
    }

    /**
     * Yalnızca test cihazlarına test push'u (seçilen abonelikler test cihazı olmalıdır).
     *
     * @param array<string, mixed> $input contents, headings?, url?, data?, subscription_ids?
     * @return array<string, mixed>
     */
    public function sendTest(array $input, ?string $appId = null): array
    {
        return $this->client->request('POST', $this->base($appId) . '/test-sends', [], $input);
    }
}
