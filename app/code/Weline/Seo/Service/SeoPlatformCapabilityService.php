<?php

declare(strict_types=1);

namespace Weline\Seo\Service;

class SeoPlatformCapabilityService
{
    private const INDEXNOW_PLATFORMS = [
        'indexnow',
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
     * 后台账户表单目录。含各平台配置项文案。
     *
     * @return array<string, array<string, mixed>>
     */
    public function getCapabilities(): array
    {
        $platforms = [];
        foreach ($this->sitemapAdapterRegistry->getPlatformInfo() as $code => $info) {
            $code = strtolower((string)$code);
            $capability = $this->getCapability($code);
            if ($capability !== null) {
                $platforms[$code] = $capability;
            }
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
            if (!in_array($type, ['text', 'password', 'url', 'website_url', 'textarea', 'json', 'checkbox', 'section'], true)) {
                $type = 'text';
            }
            $normalized[] = [
                'key' => $key,
                'label' => (string)($field['label'] ?? $key),
                'type' => $type,
                'required' => $type !== 'section' && !empty($field['required']),
                'placeholder' => (string)($field['placeholder'] ?? ''),
                'hint' => (string)($field['hint'] ?? ''),
                'accept' => (string)($field['accept'] ?? ''),
                'group' => (string)($field['group'] ?? ''),
                'sensitive' => !empty($field['sensitive']) || $type === 'password' || $key === 'service_account',
            ];
        }

        return $this->specializeIndexNowFieldsForPlatform($platform, $normalized);
    }

    /**
     * Yandex / 其它 IndexNow 参与方：端点占位与分区文案按平台收紧（适配器类共用）。
     *
     * @param list<array<string, mixed>> $fields
     * @return list<array<string, mixed>>
     */
    private function specializeIndexNowFieldsForPlatform(string $platform, array $fields): array
    {
        $platform = SeoPlatformCode::canonicalize($platform);
        if (str_starts_with($platform, 'yandex')) {
            foreach ($fields as &$field) {
                $key = (string)($field['key'] ?? '');
                if ($key === '__section_endpoint') {
                    $field['hint'] = (string)__('Yandex 账户请将 Endpoint 设为 https://yandex.com/indexnow（可与 IndexNow 中枢共用同一 Key 文件）。');
                }
                if ($key === 'indexnow_endpoint') {
                    $field['placeholder'] = 'https://yandex.com/indexnow';
                    $field['hint'] = (string)__('建议显式填写 https://yandex.com/indexnow；勿填 api.indexnow.org（那是 IndexNow 中枢账户用的）。须为 HTTPS。留空时部分路径会回退平台默认端点，仍建议填上以免混淆。');
                }
                if ($key === '__section_key') {
                    $field['hint'] = (string)__('与 IndexNow / Bing 共用同一 Key 时，只需一份生产 pub/{key}.txt。key_location 须与推送 URL 同主机。只开 URL 推送、关 Sitemap 定时提交。');
                }
            }
            unset($field);
        }

        return $fields;
    }

    /**
     * 单个平台的后台账户能力，含该平台自己的配置项文案。
     *
     * @return array<string, mixed>|null
     */
    public function getCapability(string $platform): ?array
    {
        $flags = $this->getSubmissionFlags($platform);
        if ($flags === null) {
            return null;
        }
        $flags['config_fields'] = $this->resolveAccountConfigFields((string)$flags['code']);

        return $flags;
    }

    /**
     * 入队、推送开关。不含账户表单文案。
     * 商品保存的事务里词典缓存不生效，整表重译会把这次保存拖到响应超时。
     *
     * @return array<string, mixed>|null
     */
    public function getSubmissionFlags(string $platform): ?array
    {
        $platform = strtolower(trim($platform));
        if ($platform === '') {
            return null;
        }

        $info = null;
        foreach ($this->sitemapAdapterRegistry->getPlatformInfo() as $code => $row) {
            if (strtolower((string)$code) !== $platform) {
                continue;
            }
            $info = is_array($row) ? $row : [];
            break;
        }
        if ($info === null) {
            return null;
        }

        $supportsUrlPush = $this->supportsUrlPush($platform);
        $supportsSitemapSubmit = !empty($info['supports_submit']);

        return [
            'code' => $platform,
            'name' => (string)($info['name'] ?? ucfirst($platform)),
            'color' => (string)($info['color'] ?? '#64748b'),
            'supports_sitemap' => true,
            'supports_sitemap_submit' => $supportsSitemapSubmit,
            'supports_submit' => $supportsSitemapSubmit,
            'supports_url_push' => $supportsUrlPush,
            'supports_indexnow' => in_array($platform, self::INDEXNOW_PLATFORMS, true),
            'supports_stats' => !empty($info['supports_stats']),
            'catalog_only' => !$supportsUrlPush && !$supportsSitemapSubmit,
        ];
    }

    public function supportsUrlPush(string $platform): bool
    {
        $platform = SeoPlatformCode::canonicalize($platform);
        if ($platform === '') {
            return false;
        }

        if (SeoPlatformCode::isGoogle($platform)) {
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
        $capability = $this->getSubmissionFlags($platform);
        return $capability !== null && !empty($capability['catalog_only']);
    }
}
