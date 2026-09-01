<?php

declare(strict_types=1);

namespace Weline\Seo\Service;

class SeoPlatformCapabilityService
{
    private const INDEXNOW_PLATFORMS = [
        'bing',
        'yandex',
        'naver',
        'seznam',
        'yep',
        'internetarchive',
        'amazonbot',
    ];

    public function __construct(
        private readonly SitemapAdapterRegistry $sitemapAdapterRegistry,
        private readonly SearchEngineAdapterRegistry $searchEngineAdapterRegistry
    ) {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getCapabilities(): array
    {
        $platforms = [];
        foreach ($this->sitemapAdapterRegistry->getPlatformInfo() as $code => $info) {
            $code = strtolower((string)$code);
            $supportsUrlPush = $this->supportsUrlPush($code);
            $supportsSitemapSubmit = !empty($info['supports_submit']);

            $platforms[$code] = [
                'code' => $code,
                'name' => (string)($info['name'] ?? ucfirst($code)),
                'color' => (string)($info['color'] ?? '#64748b'),
                'supports_sitemap' => true,
                'supports_sitemap_submit' => $supportsSitemapSubmit,
                'supports_submit' => $supportsSitemapSubmit,
                'supports_url_push' => $supportsUrlPush,
                'supports_indexnow' => in_array($code, self::INDEXNOW_PLATFORMS, true),
                'supports_stats' => !empty($info['supports_stats']),
                'catalog_only' => !$supportsUrlPush && !$supportsSitemapSubmit,
                'config_fields' => $this->resolveAccountConfigFields($code),
            ];
        }

        return $platforms;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function resolveAccountConfigFields(string $platform): array
    {
        $platform = strtolower(trim($platform));
        if ($platform === '') {
            return [];
        }

        $adapter = $this->searchEngineAdapterRegistry->getAdapter($platform);
        if ($adapter === null) {
            return [];
        }

        $fields = $adapter->getAccountConfigFields();
        if (!is_array($fields)) {
            return [];
        }

        $normalized = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $key = trim((string)($field['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $type = strtolower(trim((string)($field['type'] ?? 'text')));
            if (!in_array($type, ['text', 'password', 'url', 'website_url', 'textarea', 'json', 'checkbox'], true)) {
                $type = 'text';
            }
            $normalized[] = [
                'key' => $key,
                'label' => (string)($field['label'] ?? $key),
                'type' => $type,
                'required' => !empty($field['required']),
                'placeholder' => (string)($field['placeholder'] ?? ''),
                'hint' => (string)($field['hint'] ?? ''),
                'accept' => (string)($field['accept'] ?? ''),
            ];
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCapability(string $platform): ?array
    {
        $platform = strtolower(trim($platform));
        if ($platform === '') {
            return null;
        }

        $capabilities = $this->getCapabilities();
        return $capabilities[$platform] ?? null;
    }

    public function supportsUrlPush(string $platform): bool
    {
        $platform = strtolower(trim($platform));
        if ($platform === '') {
            return false;
        }

        if ($this->searchEngineAdapterRegistry->hasProvider($platform)) {
            return true;
        }

        foreach ($this->searchEngineAdapterRegistry->getProviderCodes() as $provider) {
            $resolved = $this->sitemapAdapterRegistry->extractPlatformFromProvider($provider);
            if ($resolved === $platform) {
                return true;
            }
        }

        return false;
    }

    public function isCatalogOnly(string $platform): bool
    {
        $capability = $this->getCapability($platform);
        return $capability !== null && !empty($capability['catalog_only']);
    }
}
