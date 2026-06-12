<?php

declare(strict_types=1);

namespace Weline\AppStore\Service;

use GuzzleHttp\Client;
use Weline\AppStore\Model\AppStoreInstalledModule;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\MarketplaceMeta\MarketplaceMetaI18nSubmitter;
use Weline\Framework\MarketplaceMeta\MarketplaceMetaReader;

class InstalledModuleMetaService
{
    private const CACHE_DIR = BP . 'var' . DS . 'appstore' . DS . 'meta-cache';
    private const TAG_CACHE_FILE = self::CACHE_DIR . DS . 'platform-tags.json';

    public function __construct(
        private readonly ?MarketplaceMetaReader $reader = null
    ) {
    }

    /**
     * @param array<string, mixed> $apiSnapshot
     * @param array{meta:?array,hash?:string,path?:string,warnings?:string[]}|null $packageMeta
     */
    public function syncOnInstall(string $moduleName, string $moduleDir, array $apiSnapshot = [], ?array $packageMeta = null): void
    {
        $metaResult = $packageMeta;
        if (!is_array($metaResult) || !is_array($metaResult['meta'] ?? null)) {
            $metaResult = $this->reader()->readFromModuleDir($moduleDir, $moduleName);
        }

        $meta = is_array($metaResult['meta'] ?? null) ? $metaResult['meta'] : $this->metaFromApiSnapshot($moduleName, $apiSnapshot);
        if ($meta === []) {
            return;
        }

        $hash = trim((string)($metaResult['hash'] ?? ''));
        if ($hash === '') {
            $hash = $this->reader()->hash($meta);
        }

        /** @var AppStoreInstalledModule $installedModule */
        $installedModule = ObjectManager::getInstance(AppStoreInstalledModule::class);
        $installedModule = $installedModule->clear()
            ->where(AppStoreInstalledModule::schema_fields_module_name, $moduleName)
            ->find()
            ->fetch();

        if (!$installedModule instanceof AppStoreInstalledModule || !$installedModule->getInstallId()) {
            return;
        }

        $localized = $this->localizedInfoFromMeta($meta, $this->currentLocale());
        if (!empty($localized['display_name'])) {
            $installedModule->setDisplayName((string)$localized['display_name']);
        }
        if (!empty($localized['description'])) {
            $installedModule->setDescription((string)$localized['description']);
        }

        $installedModule
            ->setMarketplaceMetaJson($meta)
            ->setMarketplaceMetaHash($hash)
            ->setMarketplaceMetaLocale($this->currentLocale())
            ->setPrimaryTagCode($this->primaryTagCode($meta))
            ->setSurfaceCodes($this->surfaceCodes($meta));

        $installedModule->setData(AppStoreInstalledModule::schema_fields_updated_at, date('Y-m-d H:i:s'));
        $installedModule->save();
        $this->submitI18nWords($moduleName, $meta);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTags(string $moduleName, ?string $locale = null): array
    {
        $meta = $this->loadInstalledMeta($moduleName);
        if ($meta === []) {
            return [];
        }

        return $this->resolveTags($meta, $locale ?: $this->currentLocale());
    }

    /**
     * @return array<string, mixed>
     */
    public function getLocalizedInfo(string $moduleName, ?string $locale = null): array
    {
        $meta = $this->loadInstalledMeta($moduleName);
        if ($meta === []) {
            return [];
        }

        return $this->localizedInfoFromMeta($meta, $locale ?: $this->currentLocale()) + [
            'tags' => $this->resolveTags($meta, $locale ?: $this->currentLocale()),
            'surfaces' => $this->surfaceCodes($meta),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function refreshFromPlatform(string $moduleName): array
    {
        /** @var AppStoreInstalledModule $module */
        $module = ObjectManager::getInstance(AppStoreInstalledModule::class);
        $module->clear()->load(AppStoreInstalledModule::schema_fields_module_name, $moduleName);
        if (!$module->getInstallId() || $module->getPlatformModuleId() <= 0) {
            return ['success' => false, 'message' => (string)__('缺少平台模块 ID，无法刷新 Meta')];
        }

        /** @var AccountBindService $accountService */
        $accountService = ObjectManager::getInstance(AccountBindService::class);
        $token = $accountService->getApiToken();
        if (!$accountService->isBound() || !$token) {
            return ['success' => false, 'message' => (string)__('请先绑定官网账户后刷新 Meta')];
        }

        $client = new Client($accountService->getHttpClientOptions(['timeout' => 30]));
        $response = $client->get(
            $accountService->getPlatformApiUrl('/api/v1/platform/module/' . $module->getPlatformModuleId()),
            ['headers' => ['Authorization' => 'Bearer ' . $token]]
        );
        $data = json_decode($response->getBody()->getContents(), true);
        if (!is_array($data) || empty($data['success'])) {
            return ['success' => false, 'message' => (string)($data['message'] ?? __('平台未返回有效 Meta'))];
        }

        $snapshot = is_array($data['data'] ?? null) ? $data['data'] : [];
        $moduleDir = $this->moduleDir($moduleName);
        $this->syncOnInstall($moduleName, $moduleDir, $snapshot);

        return ['success' => true, 'message' => (string)__('Meta 已从平台刷新')];
    }

    /**
     * @return array<string, mixed>
     */
    public function syncPlatformTags(?string $locale = null): array
    {
        /** @var AccountBindService $accountService */
        $accountService = ObjectManager::getInstance(AccountBindService::class);
        $token = $accountService->getApiToken();
        if (!$accountService->isBound() || !$token) {
            return ['success' => false, 'message' => (string)__('请先绑定官网账户后同步标签注册表')];
        }

        $locale = $locale ?: $this->currentLocale();
        $client = new Client($accountService->getHttpClientOptions(['timeout' => 30]));
        $response = $client->get(
            $accountService->getPlatformApiUrl('/api/v1/platform/tags?locale=' . rawurlencode($locale)),
            ['headers' => ['Authorization' => 'Bearer ' . $token]]
        );
        $data = json_decode($response->getBody()->getContents(), true);
        if (!is_array($data) || empty($data['success'])) {
            return ['success' => false, 'message' => (string)($data['message'] ?? __('平台未返回有效标签注册表'))];
        }

        $payload = is_array($data['data'] ?? null) ? $data['data'] : $data;
        if (!is_dir(self::CACHE_DIR)) {
            mkdir(self::CACHE_DIR, 0755, true);
        }
        $payload['updated_at'] = date('c');
        file_put_contents(self::TAG_CACHE_FILE, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return [
            'success' => true,
            'message' => (string)__('标签注册表已同步'),
            'count' => count((array)($payload['tags'] ?? [])),
            'path' => self::TAG_CACHE_FILE,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function resolveTags(array $meta, string $locale): array
    {
        $registry = $this->platformTagRegistry();
        $sourceLocale = (string)($meta['i18n']['source_locale'] ?? 'zh_Hans_CN');
        $result = [];

        foreach ((array)($meta['tags'] ?? []) as $tag) {
            if (!is_array($tag)) {
                continue;
            }
            $code = (string)($tag['code'] ?? '');
            if ($code === '') {
                continue;
            }

            $label = $this->tagLabel($tag, $code, $locale, $sourceLocale, $registry);
            $result[] = [
                'code' => $code,
                'type' => (string)($tag['type'] ?? ''),
                'primary' => !empty($tag['primary']),
                'label' => $label,
                'seo_slug' => $this->tagSeoSlug($tag, $code, $registry),
            ];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function metaFromApiSnapshot(string $moduleName, array $apiSnapshot): array
    {
        $marketplaceMeta = is_array($apiSnapshot['marketplace_meta'] ?? null) ? $apiSnapshot['marketplace_meta'] : [];
        $tags = is_array($apiSnapshot['tags'] ?? null) ? $apiSnapshot['tags'] : [];
        $resolved = is_array($apiSnapshot['tags_resolved'] ?? null) ? $apiSnapshot['tags_resolved'] : [];
        if ($marketplaceMeta === [] && $tags === [] && $resolved === []) {
            return [];
        }

        $sourceLocale = (string)($marketplaceMeta['source_locale'] ?? $marketplaceMeta['i18n']['source_locale'] ?? 'zh_Hans_CN');
        if ($tags === [] && isset($resolved[$sourceLocale]) && is_array($resolved[$sourceLocale])) {
            $tags = $resolved[$sourceLocale];
        }

        foreach ($tags as &$tag) {
            if (is_string($tag)) {
                $tag = ['code' => $tag];
            }
            if (!is_array($tag)) {
                $tag = [];
                continue;
            }
            foreach ($resolved as $locale => $items) {
                if (!is_array($items)) {
                    continue;
                }
                foreach ($items as $item) {
                    if (is_array($item) && ($item['code'] ?? '') === ($tag['code'] ?? '') && !empty($item['label'])) {
                        $tag['labels'][$locale] = (string)$item['label'];
                    }
                }
            }
            if (is_array($tag['label'] ?? null)) {
                $tag['labels'] = array_replace(is_array($tag['labels'] ?? null) ? $tag['labels'] : [], $tag['label']);
                unset($tag['label']);
            }
        }
        unset($tag);

        return [
            'schema_version' => (int)($marketplaceMeta['schema_version'] ?? 1),
            'module_name' => $moduleName,
            'i18n' => [
                'source_locale' => $sourceLocale,
                'locales' => [
                    $sourceLocale => [
                        'display_name' => (string)($apiSnapshot['display_name'] ?? $moduleName),
                        'description' => (string)($apiSnapshot['description'] ?? ''),
                    ],
                ],
            ],
            'tags' => array_values(array_filter($tags, 'is_array')),
            'surfaces' => is_array($marketplaceMeta['surfaces'] ?? null) ? $marketplaceMeta['surfaces'] : [],
            'seo' => is_array($marketplaceMeta['seo'] ?? null) ? $marketplaceMeta['seo'] : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function localizedInfoFromMeta(array $meta, string $locale): array
    {
        $i18n = is_array($meta['i18n'] ?? null) ? $meta['i18n'] : [];
        $locales = is_array($i18n['locales'] ?? null) ? $i18n['locales'] : [];
        $sourceLocale = (string)($i18n['source_locale'] ?? 'zh_Hans_CN');
        $source = is_array($locales[$sourceLocale] ?? null) ? $locales[$sourceLocale] : [];
        $localized = is_array($locales[$locale] ?? null) ? $locales[$locale] : [];
        $result = array_replace($source, $localized);
        if ($locale !== $sourceLocale) {
            foreach (['display_name', 'description'] as $field) {
                if (!isset($localized[$field]) && !empty($source[$field])) {
                    $result[$field] = (string)__((string)$source[$field]);
                }
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadInstalledMeta(string $moduleName): array
    {
        /** @var AppStoreInstalledModule $module */
        $module = ObjectManager::getInstance(AppStoreInstalledModule::class);
        $module->clear()->load(AppStoreInstalledModule::schema_fields_module_name, $moduleName);
        if (!$module->getInstallId()) {
            return [];
        }

        $meta = $module->getMarketplaceMetaJson();
        if ($meta !== []) {
            return $meta;
        }

        $result = $this->reader()->readFromModuleDir($this->moduleDir($moduleName), $moduleName);
        return is_array($result['meta'] ?? null) ? $result['meta'] : [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function platformTagRegistry(): array
    {
        $registry = $this->defaultTagRegistry();
        if (!is_file(self::TAG_CACHE_FILE)) {
            return $registry;
        }

        $data = json_decode((string)file_get_contents(self::TAG_CACHE_FILE), true);
        foreach ((array)($data['tags'] ?? []) as $tag) {
            if (!is_array($tag) || empty($tag['code'])) {
                continue;
            }
            $registry[(string)$tag['code']] = $tag;
        }

        return $registry;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function defaultTagRegistry(): array
    {
        return [
            'surface.backend' => ['code' => 'surface.backend', 'label' => '后端应用', 'seo_slug' => 'backend'],
            'surface.frontend' => ['code' => 'surface.frontend', 'label' => '前端应用', 'seo_slug' => 'frontend'],
            'surface.theme' => ['code' => 'surface.theme', 'label' => '主题/设计', 'seo_slug' => 'theme'],
            'surface.api' => ['code' => 'surface.api', 'label' => 'API / 集成', 'seo_slug' => 'api'],
            'surface.cli' => ['code' => 'surface.cli', 'label' => '命令行工具', 'seo_slug' => 'cli'],
            'surface.fullstack' => ['code' => 'surface.fullstack', 'label' => '全栈应用', 'seo_slug' => 'fullstack'],
            'capability.payment' => ['code' => 'capability.payment', 'label' => '支付能力', 'seo_slug' => 'payment'],
            'capability.seo' => ['code' => 'capability.seo', 'label' => 'SEO 能力', 'seo_slug' => 'seo'],
            'capability.ai' => ['code' => 'capability.ai', 'label' => 'AI 能力', 'seo_slug' => 'ai'],
            'audience.developer' => ['code' => 'audience.developer', 'label' => '开发者工具', 'seo_slug' => 'developer-tools'],
            'audience.merchant' => ['code' => 'audience.merchant', 'label' => '商家运营', 'seo_slug' => 'merchant'],
        ];
    }

    /**
     * @param array<string, mixed> $tag
     * @param array<string, array<string, mixed>> $registry
     */
    private function tagLabel(array $tag, string $code, string $locale, string $sourceLocale, array $registry): string
    {
        $labels = is_array($tag['labels'] ?? null) ? $tag['labels'] : [];
        foreach ([$locale, $sourceLocale] as $candidate) {
            if (!empty($labels[$candidate])) {
                if ($candidate === $sourceLocale && $locale !== $sourceLocale) {
                    return (string)__((string)$labels[$candidate]);
                }

                return (string)$labels[$candidate];
            }
        }
        if (!empty($registry[$code]['label'])) {
            return (string)__((string)$registry[$code]['label']);
        }

        $last = (string)preg_replace('/^.*[._]/', '', $code);
        return ucwords(str_replace(['-', '_'], ' ', $last));
    }

    /**
     * @param array<string, mixed> $tag
     * @param array<string, array<string, mixed>> $registry
     */
    private function tagSeoSlug(array $tag, string $code, array $registry): string
    {
        $seo = is_array($tag['seo'] ?? null) ? $tag['seo'] : [];
        $slug = trim((string)($seo['slug'] ?? $registry[$code]['seo_slug'] ?? ''));
        if ($slug !== '') {
            return $slug;
        }

        return str_replace(['.', '_'], '-', $code);
    }

    /**
     * @return string[]
     */
    private function surfaceCodes(array $meta): array
    {
        $surfaces = [];
        foreach ((array)($meta['surfaces'] ?? []) as $surface) {
            $surface = trim((string)$surface);
            if ($surface !== '') {
                $surfaces[$surface] = true;
            }
        }
        foreach ((array)($meta['tags'] ?? []) as $tag) {
            if (!is_array($tag)) {
                continue;
            }
            $code = (string)($tag['code'] ?? '');
            if (str_starts_with($code, 'surface.')) {
                $surfaces[substr($code, strlen('surface.'))] = true;
            }
        }

        return array_keys($surfaces);
    }

    private function primaryTagCode(array $meta): string
    {
        $fallback = '';
        foreach ((array)($meta['tags'] ?? []) as $tag) {
            if (!is_array($tag) || empty($tag['code'])) {
                continue;
            }
            $code = (string)$tag['code'];
            if ($fallback === '') {
                $fallback = $code;
            }
            if (!empty($tag['primary'])) {
                return $code;
            }
        }

        return $fallback;
    }

    private function moduleDir(string $moduleName): string
    {
        if (!str_contains($moduleName, '_')) {
            return '';
        }
        [$vendor, $module] = explode('_', $moduleName, 2);
        return rtrim(APP_CODE_PATH, DS) . DS . $vendor . DS . $module;
    }

    private function currentLocale(): string
    {
        return (string)w_env('user.lang', Env::get('user.lang', 'zh_Hans_CN'));
    }

    private function submitI18nWords(string $moduleName, array $meta): void
    {
        try {
            /** @var MarketplaceMetaI18nSubmitter $submitter */
            $submitter = ObjectManager::getInstance(MarketplaceMetaI18nSubmitter::class);
            $submitter->submit($moduleName, $meta);
        } catch (\Throwable $throwable) {
            w_log_warning('Marketplace meta i18n submit failed: ' . $throwable->getMessage(), [
                'module' => $moduleName,
            ], 'appstore');
        }
    }

    private function reader(): MarketplaceMetaReader
    {
        return $this->reader ?? ObjectManager::getInstance(MarketplaceMetaReader::class);
    }
}
