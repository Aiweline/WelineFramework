<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Helper;

use Weline\Framework\Cache\RuntimeCachePolicy;
use Weline\Framework\App\State;
use Weline\Framework\Cache\Contract\SharedCacheStateInterface;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\I18n\Api\Translation\DictionaryRepositoryInterface;
use Weline\Meta\Api\Data\MetaConfigIdentity;
use Weline\Meta\Api\Data\MetaConfigRecord;
use Weline\Meta\Api\Data\MetaConfigScopeSearch;
use Weline\Meta\Api\Data\MetaConfigSearch;
use Weline\Meta\Api\Data\MetaConfigWrite;
use Weline\Meta\Api\Data\MetadataRecord;
use Weline\Meta\Api\Data\MetadataSearch;
use Weline\Meta\Api\MetaConfigRepositoryInterface;
use Weline\Meta\Api\MetadataRepositoryInterface;
use Weline\Meta\Api\ParamDefinitionNormalizerInterface;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\PreviewThemeScopeService;
use Weline\Theme\Service\ThemeContextService;
use Weline\Widget\Api\Param\ParamDefinition;

final class ThemeDataRequestState
{
    public ?WelineTheme $currentTheme = null;
    public ?string $currentArea = null;
    public ?string $performanceKey = null;
    public bool $initialized = false;
    public bool $performanceLoading = false;
    public array $performanceCache = [];
    public ?string $performanceNamespace = null;
    public ?string $performanceScope = null;
    public ?string $performanceLocale = null;
    /** @var array<string, string> */
    public array $requestedScopes = [];
    /** @var array<string, string> */
    public array $effectiveScopes = [];
    public ?string $configLocale = null;
}

/**
 * ThemeData 静态类
 * 
 * 统一管理 Theme 模块对 Meta 公共 Repository 契约的调用。
 * 请求热路径只缓存标量或纯数组，不暴露 Meta Model/ORM 对象。
 * 
 * 使用示例：
 * // 获取配置值
 * $value = ThemeData::get('partials.header.value');
 * 
 * // 设置配置值
 * ThemeData::set('partials.header.value', 'minimal');
 * 
 * // 获取Meta数据（从缓存，返回数组）
 * $metaArr = ThemeData::getMeta('theme.frontend.layouts.default');
 * // 返回：['meta_id' => 1, 'meta_identify' => '...', 'meta_data' => [...], 'setting' => [...], ...]
 */
class ThemeData
{
    public const WIDGET_I18N_INSTANCE_CONFIG_KEY = '_i18n_instance';
    private const RUNTIME_CACHE_TTL = 300;
    private const SHARED_CACHE_NAMESPACE = 'weline_site_runtime';

    private static ?ThemeDataRequestState $mainState = null;

    /** @var \WeakMap<\Fiber, ThemeDataRequestState>|null */
    private static ?\WeakMap $fiberStates = null;
    /** @var array<string, ThemeDataRequestState> */
    private static array $scopedStates = [];
    /** @var array<string, array{expires_at: float, value: mixed}> */
    private static array $runtimeCache = [];
    private static ?SharedCacheStateInterface $sharedRuntimeCache = null;
    private static bool $sharedRuntimeCacheResolved = false;

    private static function currentFiber(): ?\Fiber
    {
        if (!class_exists(\Weline\Framework\Runtime\Runtime::class)) {
            return null;
        }

        if (!\Weline\Framework\Runtime\Runtime::isPersistent()) {
            return null;
        }

        return \Fiber::getCurrent();
    }

    private static function state(): ThemeDataRequestState
    {
        $scopeKey = self::currentScopeKey();
        if ($scopeKey !== null) {
            self::$scopedStates[$scopeKey] ??= new ThemeDataRequestState();
            return self::$scopedStates[$scopeKey];
        }

        $fiber = self::currentFiber();
        if ($fiber === null) {
            self::$mainState ??= new ThemeDataRequestState();
            return self::$mainState;
        }

        self::$fiberStates ??= new \WeakMap();
        if (!isset(self::$fiberStates[$fiber])) {
            self::$fiberStates[$fiber] = new ThemeDataRequestState();
        }

        return self::$fiberStates[$fiber];
    }

    private static function resetCurrentState(): void
    {
        $scopeKey = self::currentScopeKey();
        if ($scopeKey !== null) {
            self::$scopedStates[$scopeKey] = new ThemeDataRequestState();
            return;
        }

        $fiber = self::currentFiber();
        if ($fiber === null) {
            self::$mainState = new ThemeDataRequestState();
            return;
        }

        self::$fiberStates ??= new \WeakMap();
        self::$fiberStates[$fiber] = new ThemeDataRequestState();
    }

    private static function currentScopeKey(): ?string
    {
        try {
            $scopeId = RequestContext::getStorageScopeId();
        } catch (\Throwable) {
            return null;
        }

        return $scopeId === null || $scopeId === '' ? null : 'conn:' . $scopeId;
    }

    private static function resolveRequestedScopeForArea(string $area): string
    {
        $area = strtolower(trim($area)) === 'backend' ? 'backend' : 'frontend';
        $state = self::state();
        if (isset($state->requestedScopes[$area])) {
            return $state->requestedScopes[$area];
        }

        try {
            /** @var ThemeContextService $themeContext */
            $themeContext = ObjectManager::getInstance(ThemeContextService::class);
            return $state->requestedScopes[$area] = $themeContext->resolveCurrentScope($area);
        } catch (\Throwable) {
            return $state->requestedScopes[$area] = 'default';
        }
    }

    private static function resolveEffectiveScope(string $scope = 'default', ?string $area = null): string
    {
        self::ensureInitialized();

        $scope = trim($scope) !== '' ? trim($scope) : 'default';
        $state = self::state();
        $area = strtolower(trim((string)($area ?? $state->currentArea ?? '')));
        $area = $area === 'backend' ? 'backend' : 'frontend';
        $themeId = $state->currentTheme?->getId();

        if (!$themeId) {
            return $scope;
        }

        $cacheKey = (string)$themeId . "\0" . $area . "\0" . $scope;
        if (isset($state->effectiveScopes[$cacheKey])) {
            return $state->effectiveScopes[$cacheKey];
        }

        try {
            /** @var PreviewThemeScopeService $previewThemeScopeService */
            $previewThemeScopeService = ObjectManager::getInstance(PreviewThemeScopeService::class);
            return $state->effectiveScopes[$cacheKey] = $previewThemeScopeService->resolveEffectiveScope(
                (int)$themeId,
                $area,
                $scope,
            );
        } catch (\Throwable) {
            return $state->effectiveScopes[$cacheKey] = $scope;
        }
    }

    /**
     * @return array{0: bool, 1: mixed}
     */
    private static function getRuntimeCache(string $key): array
    {
        $entry = self::$runtimeCache[$key] ?? null;
        if (is_array($entry)) {
            if (($entry['expires_at'] ?? 0.0) >= microtime(true)) {
                return [true, $entry['value'] ?? null];
            }
            unset(self::$runtimeCache[$key]);
        }

        $cache = self::sharedRuntimeCache();
        if ($cache === null) {
            return [false, null];
        }

        try {
            $value = $cache->get(self::SHARED_CACHE_NAMESPACE, 'theme.' . $key);
            if ($value === null) {
                return [false, null];
            }
            self::$runtimeCache[$key] = [
                'expires_at' => microtime(true) + self::runtimeCacheTtl(),
                'value' => $value,
            ];
            return [true, $value];
        } catch (\Throwable) {
            self::$sharedRuntimeCache = null;
            self::$sharedRuntimeCacheResolved = true;
            return [false, null];
        }
    }

    private static function setRuntimeCache(string $key, mixed $value): void
    {
        self::$runtimeCache[$key] = [
            'expires_at' => microtime(true) + self::runtimeCacheTtl(),
            'value' => $value,
        ];

        $cache = self::sharedRuntimeCache();
        if ($cache === null) {
            return;
        }

        try {
            $cache->set(self::SHARED_CACHE_NAMESPACE, 'theme.' . $key, $value, self::runtimeCacheTtl());
        } catch (\Throwable) {
            self::$sharedRuntimeCache = null;
            self::$sharedRuntimeCacheResolved = true;
        }
    }

    private static function sharedRuntimeCache(): ?SharedCacheStateInterface
    {
        if (self::$sharedRuntimeCacheResolved) {
            return self::$sharedRuntimeCache;
        }
        self::$sharedRuntimeCacheResolved = true;

        if (!class_exists(Runtime::class, false) || !Runtime::isPersistent()) {
            return null;
        }

        try {
            $cache = ObjectManager::getInstance(RuntimeProviderResolver::class)
                ->resolve(SharedCacheStateInterface::class);
            self::$sharedRuntimeCache = $cache instanceof SharedCacheStateInterface ? $cache : null;
        } catch (\Throwable) {
            self::$sharedRuntimeCache = null;
        }

        return self::$sharedRuntimeCache;
    }

    private static function metadataRepository(): MetadataRepositoryInterface
    {
        $provider = ObjectManager::getInstance(RuntimeProviderResolver::class)
            ->resolve(MetadataRepositoryInterface::class);
        if (!$provider instanceof MetadataRepositoryInterface) {
            throw new \RuntimeException('Weline_Meta metadata repository provider is unavailable.');
        }

        return $provider;
    }

    private static function metaConfigRepository(): MetaConfigRepositoryInterface
    {
        $provider = ObjectManager::getInstance(RuntimeProviderResolver::class)
            ->resolve(MetaConfigRepositoryInterface::class);
        if (!$provider instanceof MetaConfigRepositoryInterface) {
            throw new \RuntimeException('Weline_Meta config repository provider is unavailable.');
        }

        return $provider;
    }

    private static function dictionaryRepository(): DictionaryRepositoryInterface
    {
        $provider = ObjectManager::getInstance(RuntimeProviderResolver::class)
            ->resolve(DictionaryRepositoryInterface::class);
        if (!$provider instanceof DictionaryRepositoryInterface) {
            throw new \RuntimeException('Weline_I18n dictionary repository provider is unavailable.');
        }

        return $provider;
    }

    private static function paramDefinitionNormalizer(): ParamDefinitionNormalizerInterface
    {
        $provider = ObjectManager::getInstance(RuntimeProviderResolver::class)
            ->resolve(ParamDefinitionNormalizerInterface::class);
        if (!$provider instanceof ParamDefinitionNormalizerInterface) {
            throw new \RuntimeException('Weline_Meta param normalizer provider is unavailable.');
        }

        return $provider;
    }

    private static function currentConfigLocale(?string $locale = null): string
    {
        $locale = trim((string)($locale ?? ''));
        if ($locale !== '') {
            return $locale;
        }

        $state = self::state();
        return $state->configLocale ??= Cookie::getLang() ?? Cookie::getLangLocal() ?? 'zh_Hans_CN';
    }

    /** @return list<string> */
    private static function dualReadConfigKeys(string $configKey): array
    {
        $configKey = trim($configKey);
        if ($configKey === '') {
            return [];
        }

        if (str_ends_with($configKey, '.value')) {
            $canonical = substr($configKey, 0, -strlen('.value'));
            return $canonical !== '' ? [$canonical, $configKey] : [$configKey];
        }

        return [$configKey, $configKey . '.value'];
    }

    private static function resolveConfigValue(
        string $identifyId,
        string $namespace,
        string $configKey,
        string $scope,
        ?string $locale = null,
    ): ?string {
        $locale = self::currentConfigLocale($locale);
        $cacheKey = 'scalar_config:' . hash('sha256', implode("\0", [
            $identifyId,
            $namespace,
            $configKey,
            $scope,
            $locale,
        ]));
        $state = self::state();
        if (array_key_exists($cacheKey, $state->performanceCache)) {
            $cached = $state->performanceCache[$cacheKey];
            return is_string($cached) ? $cached : null;
        }

        [$runtimeHit, $runtimeValue] = self::getRuntimeCache($cacheKey);
        if ($runtimeHit && is_string($runtimeValue)) {
            $state->performanceCache[$cacheKey] = $runtimeValue;
            return $runtimeValue;
        }

        $identities = [];
        foreach (self::dualReadConfigKeys($configKey) as $candidateKey) {
            $identities[] = new MetaConfigIdentity(
                namespace: $namespace,
                configKey: $candidateKey,
                scope: $scope,
                locale: $locale,
                identifyId: $identifyId,
            );
        }

        $records = self::metaConfigRepository()->resolveBatch($identities);
        foreach ($records as $record) {
            if (!$record instanceof MetaConfigRecord) {
                continue;
            }
            $state->performanceCache[$cacheKey] = $record->value;
            self::setRuntimeCache($cacheKey, $record->value);
            return $record->value;
        }

        $state->performanceCache[$cacheKey] = null;
        return null;
    }

    /**
     * @param list<MetaConfigRecord> $records
     * @return array<string, string>
     */
    private static function resolveConfigMap(array $records, ?string $locale = null): array
    {
        $locale = self::currentConfigLocale($locale);
        $values = [];
        $ranks = [];

        foreach ($records as $record) {
            if (!$record instanceof MetaConfigRecord) {
                continue;
            }
            $rank = self::localeRank($record->locale, $locale);
            if ($rank === null || (isset($ranks[$record->configKey]) && $rank >= $ranks[$record->configKey])) {
                continue;
            }
            $ranks[$record->configKey] = $rank;
            $values[$record->configKey] = $record->value;
        }

        return $values;
    }

    private static function localeRank(?string $recordLocale, string $requestedLocale): ?int
    {
        $locales = array_values(array_unique([$requestedLocale, 'zh_Hans_CN'], SORT_STRING));
        $locales[] = null;
        foreach ($locales as $rank => $locale) {
            if ($recordLocale === $locale) {
                return $rank;
            }
        }

        return null;
    }

    private static function findMetadataRecord(string $identify): ?MetadataRecord
    {
        $records = self::metadataRepository()->search(new MetadataSearch(
            namespace: 'theme',
            identify: $identify,
        ));

        return $records[0] ?? null;
    }

    /** @return array<string, mixed> */
    private static function metadataRecordToArray(MetadataRecord $record): array
    {
        return [
            'meta_id' => $record->id,
            'meta_identify' => $record->identify,
            'file_path' => $record->filePath,
            'file_full_path' => $record->fileFullPath,
            'category' => $record->category,
            'meta_data' => $record->metaData,
            'setting' => $record->setting,
            '_model' => null,
        ];
    }

    private static function nestedArrayValue(array $data, string $path): mixed
    {
        $value = $data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    private static function metadataFieldValue(MetadataRecord $record, string $field): mixed
    {
        foreach ([$record->metaData, $record->metaData['meta'] ?? [], $record->metaData['attributes'] ?? []] as $data) {
            if (!is_array($data) || !array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field];
            if (!is_array($value)) {
                return $value;
            }
            if ($field === 'default' && array_key_exists('default', $value)) {
                return $value['default'];
            }
            if (array_key_exists('name', $value)) {
                return $value['name'];
            }
            if (array_key_exists('default', $value)) {
                return $value['default'];
            }
        }

        return null;
    }

    private static function translatedValue(string $translationKey, mixed $fallback = null): mixed
    {
        $requestedLocale = Cookie::getLangLocal() ?? 'zh_Hans_CN';
        $locales = array_values(array_unique([$requestedLocale, 'zh_Hans_CN'], SORT_STRING));

        try {
            $dictionary = self::dictionaryRepository();
            foreach ($locales as $locale) {
                $entry = $dictionary->getEntry($translationKey, $locale);
                if ($entry === null) {
                    continue;
                }
                $translation = $entry->translation;
                if ($translation !== null && $translation !== '') {
                    return $translation;
                }
            }
        } catch (\Throwable) {
        }

        return $fallback;
    }

    private static function metadataFallbackValue(string $identify): mixed
    {
        if (preg_match('/^(.+)\.(name|description|default)(\.(lang|value|info|config))?$/', $identify, $matches)) {
            $record = self::findMetadataRecord($matches[1]);
            if (!$record instanceof MetadataRecord) {
                return null;
            }
            $field = $matches[2];
            $suffix = $matches[4] ?? null;
            $fieldValue = self::metadataFieldValue($record, $field);
            $defaultValue = self::metadataFieldValue($record, 'default');
            if ($suffix === 'lang') {
                return self::translatedValue(
                    "@meta::{$record->namespace}.{$record->type}.{$record->identify}.{$field}",
                    $fieldValue,
                );
            }
            if ($suffix === 'value' && $defaultValue !== null) {
                return $defaultValue;
            }
            return $fieldValue;
        }

        if (preg_match('/^(.+)\.info\.(.+)$/', $identify, $matches)) {
            $record = self::findMetadataRecord($matches[1]);
            if (!$record instanceof MetadataRecord) {
                return null;
            }
            $value = self::nestedArrayValue($record->metaData, $matches[2]);
            return self::translatedValue(
                "@meta::{$record->namespace}.{$record->type}.{$record->identify}.{$matches[2]}",
                $value,
            );
        }

        if (preg_match('/^(.+)\.lang$/', $identify, $matches)) {
            $translationKey = str_starts_with($matches[1], '@meta::')
                ? $matches[1]
                : '@meta::' . $matches[1];
            return self::translatedValue($translationKey);
        }

        return self::findMetadataRecord($identify);
    }

    private static function runtimeCacheTtl(): int
    {
        try {
            /** @var RuntimeCachePolicy $policy */
            $policy = ObjectManager::getInstance(RuntimeCachePolicy::class);
            return $policy->ttl('theme.runtime_data_ttl', self::RUNTIME_CACHE_TTL);
        } catch (\Throwable) {
            return self::RUNTIME_CACHE_TTL;
        }
    }

    /**
     * @param array<string, mixed> $themeConfigs
     * @return array<string, mixed>
     */
    private static function filterThemeConfigsByType(array $themeConfigs, string $type): array
    {
        $prefix = $type . '.';
        $result = [];

        foreach ($themeConfigs as $configKey => $configValue) {
            $configKey = (string)$configKey;
            if (!str_starts_with($configKey, $prefix)) {
                continue;
            }

            if (is_string($configValue) && $configValue !== ''
                && ($configValue[0] === '{' || $configValue[0] === '[')) {
                $decoded = json_decode($configValue, true);
                if ($decoded !== null && json_last_error() === JSON_ERROR_NONE) {
                    $configValue = $decoded;
                }
            }

            $result[substr($configKey, strlen($prefix))] = $configValue;
        }

        return $result;
    }
    
    /**
     * 自动初始化（延迟加载），使用performanceLoad预加载配置
     */
    private static function ensureInitialized(): void
    {
        $state = self::state();
        if ($state->initialized) {
            return;
        }
        
        try {
            // 自动识别区域
            if ($state->currentArea === null) {
                $state->currentArea = State::isBackend() ? 'backend' : 'frontend';
            }

            // 获取当前主题（按当前区域解析激活主题，缺失时回退全局）
            if ($state->currentTheme === null) {
                try {
                    /** @var ThemeContextService $themeContext */
                    $themeContext = ObjectManager::getInstance(ThemeContextService::class);
                    $state->currentTheme = $themeContext->resolveTheme($state->currentArea);
                } catch (\Throwable) {
                    /** @var WelineTheme $theme */
                    $theme = ObjectManager::getInstance(WelineTheme::class);
                    $state->currentTheme = $theme->getActiveTheme($state->currentArea);
                }
            }
            
            // 如果主题存在且不在加载中，预加载配置（防止循环调用）
            if ($state->currentTheme && $state->currentTheme->getId() && !$state->performanceLoading) {
                self::performanceLoad();
            }
            
            $state->initialized = true;
        } catch (\Exception $e) {
            // 初始化失败，继续执行但不预加载
            $state->initialized = true;
        }
    }
    
    /**
     * 主要接口：获取配置值
     * 
     * 优先从本地缓存读取，避免触发额外的数据库查询
     * 
     * @param string $identify meta_identify（如 partials.header.value 或 theme.frontend.layouts.default.value）
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function get(string $identify, $default = null)
    {
        self::ensureInitialized();
        
        // 规范化identify（自动补全前缀）
        $identify = self::normalizeIdentify($identify);

        if (preg_match('/^theme\.(frontend|backend)\.(.+)\.value$/', $identify, $matches)) {
            $area = $matches[1];
            $configKeyWithoutValue = $matches[2];
            $requestedScope = self::resolveRequestedScopeForArea($area);
            $effectiveScope = self::resolveEffectiveScope($requestedScope, $area);
            $themeId = self::state()->currentTheme?->getId();
            $locale = self::currentConfigLocale();

            if ($themeId !== null && (string)$themeId !== '') {
                $state = self::state();
                if ($state->performanceKey !== null
                    && $state->performanceNamespace === 'theme.' . $area
                    && $state->performanceScope === $effectiveScope
                    && $state->performanceLocale === $locale
                    && isset($state->performanceCache[$state->performanceKey])
                    && is_array($state->performanceCache[$state->performanceKey])
                ) {
                    $themeConfigs = $state->performanceCache[$state->performanceKey];
                    foreach (self::dualReadConfigKeys($configKeyWithoutValue) as $candidateKey) {
                        if (array_key_exists($candidateKey, $themeConfigs)) {
                            return $themeConfigs[$candidateKey];
                        }
                    }
                }

                $configValue = self::resolveConfigValue(
                    (string)$themeId,
                    'theme.' . $area,
                    $configKeyWithoutValue,
                    $effectiveScope,
                    $locale,
                );
                if ($configValue !== null) {
                    return $configValue;
                }
            }

            if ($effectiveScope !== $requestedScope) {
                return $default;
            }
        }

        $result = self::metadataFallbackValue($identify);
        
        return $result !== null ? $result : $default;
    }
    
    /**
     * 主要接口：通过 Meta 公共 Repository 设置配置值
     * 
     * @param string $identify meta_identify（如 partials.header.value）
     * @param string $value 配置值
     * @param string $scope 作用域，默认 'default'
     * @param string|null $locale 语言代码，如果为 null 表示默认语言（通用配置）
     * @return bool 是否设置成功
     */
    public static function set(string $identify, string $value, string $scope = 'default', ?string $locale = null): bool
    {
        self::ensureInitialized();
        $identify = self::normalizeIdentify($identify);

        if (preg_match('/^theme\.(frontend|backend)\.(.+)\.value$/', $identify, $matches)) {
            $themeId = self::state()->currentTheme?->getId();
            if ($themeId === null || (string)$themeId === '') {
                return false;
            }

            $area = $matches[1];
            $namespace = 'theme.' . $area;
            $configKey = $matches[2];
            $effectiveScope = self::resolveEffectiveScope($scope, $area);

            try {
                $identity = new MetaConfigIdentity(
                    namespace: $namespace,
                    configKey: $configKey,
                    scope: $effectiveScope,
                    locale: $locale,
                    identifyId: (string)$themeId,
                );

                $existing = self::metaConfigRepository()->search(new MetaConfigSearch(
                    namespace: $namespace,
                    scope: $effectiveScope,
                    configKey: $configKey,
                    locale: $locale,
                    identifyId: (string)$themeId,
                ));
                if ($existing === []) {
                    $baseIdentify = substr($identify, 0, -strlen('.value'));
                    $metadata = self::findMetadataRecord($baseIdentify);
                    if (!$metadata instanceof MetadataRecord) {
                        $parentIdentify = str_contains($baseIdentify, '.')
                            ? substr($baseIdentify, 0, (int)strrpos($baseIdentify, '.'))
                            : '';
                        $metadata = $parentIdentify !== '' ? self::findMetadataRecord($parentIdentify) : null;
                    }
                    if ($metadata instanceof MetadataRecord) {
                        $identity = new MetaConfigIdentity(
                            namespace: $namespace,
                            configKey: $configKey,
                            scope: $effectiveScope,
                            locale: $locale,
                            identifyId: (string)$themeId,
                            metaId: $metadata->id,
                            metaIdentify: $metadata->identify,
                        );
                    }
                }

                self::metaConfigRepository()->upsert(new MetaConfigWrite($identity, $value));
                self::clearCache();
                return true;
            } catch (\Throwable) {
                return false;
            }
        }

        if (preg_match('/^(.+)\.lang$/', $identify, $matches)) {
            $translationKey = str_starts_with($matches[1], '@meta::')
                ? $matches[1]
                : '@meta::' . $matches[1];
            if (count(explode('.', substr($translationKey, strlen('@meta::')))) < 5) {
                return false;
            }
            $locale = $locale ?? Cookie::getLangLocal() ?? 'zh_Hans_CN';

            try {
                return self::dictionaryRepository()->upsert($translationKey, $locale, (string)$value);
            } catch (\Throwable) {
                return false;
            }
        }

        return false;
    }
    
    /**
     * 获取Meta数据对象（用于获取完整meta信息）
     * 
     * 优先从缓存中查找，如果缓存中没有则返回null
     * 不再触发额外的数据库查询
     * 
     * @param string $identify meta_identify（如 theme.frontend.layouts.default）
     * @return array|null 返回缓存的meta数据数组，包含 meta_id, meta_identify, meta_data, setting 等
     */
    public static function getMeta(string $identify): ?array
    {
        self::ensureInitialized();
        
        // 规范化identify（自动补全前缀）
        $identify = self::normalizeIdentify($identify);
        
        // 先从单条缓存中查找
        $cacheKey = "meta_single_{$identify}";
        $state = self::state();
        if (isset($state->performanceCache[$cacheKey])) {
            return $state->performanceCache[$cacheKey];
        }
        
        // 解析 identify 提取 area 和 type
        // 格式：theme.{area}.{type}.{rest}
        $parts = explode('.', $identify);
        if (count($parts) < 4 || $parts[0] !== 'theme') {
            return null;
        }
        
        $area = $parts[1]; // frontend 或 backend
        $type = $parts[2]; // layouts, colors, variables, components, partials
        
        // 从已缓存的 MetaList 中查找
        $metaList = self::getMetaList($area, $type);
        
        foreach ($metaList as $meta) {
            if (($meta['meta_identify'] ?? '') === $identify) {
                // 缓存单条记录
                $state->performanceCache[$cacheKey] = $meta;
                return $meta;
            }
        }
        
        return null;
    }
    
    /**
     * 快速获取文件的参数定义和配置值
     * 从 Meta 表的 setting 字段中读取参数定义，并获取每个参数的配置值
     * 
     * @param string $identify meta_identify（如 layouts.account.dashboard 或 theme.frontend.layouts.account.dashboard）
     * @return array 参数数组，键为参数名，值为配置值（或默认值）
     */
    public static function getFileParams(string $identify, string $scope = 'default', ?string $locale = null): array
    {
        $traceEnabled = \Weline\Framework\Runtime\RequestLifecycleTrace::isEnabled();
        $traceName = 'theme_data::getFileParams::' . $identify;
        $traceStart = $traceEnabled ? microtime(true) : 0.0;
        if ($traceEnabled) {
            \Weline\Framework\Runtime\RequestLifecycleTrace::pushCurrentParent($traceName);
        }

        try {
            return self::getParamValues($identify, $scope, $locale);
        } finally {
            if ($traceEnabled) {
                \Weline\Framework\Runtime\RequestLifecycleTrace::popCurrentParent();
                \Weline\Framework\Runtime\RequestLifecycleTrace::recordSpan(
                    $traceName,
                    (microtime(true) - $traceStart) * 1000,
                    'theme'
                );
            }
        }
    }

    /**
     * 获取参数定义（结构信息，不含值）
     *
     * @param string $identify meta_identify（如 layouts.account）
     * @return array
     */
    public static function getParamDefinitions(string $identify): array
    {
        self::ensureInitialized();
        $identify = self::normalizeIdentify($identify);

        $metaData = self::getMeta($identify);
        if (!$metaData) {
            return [];
        }

        $params = $metaData['setting']['param'] ?? [];
        if (empty($params) || !is_array($params)) {
            return [];
        }

        $params = self::paramDefinitionNormalizer()->normalizeDefinitions($params);

        $definitions = [];
        foreach ($params as $name => $definition) {
            if (!is_array($definition)) {
                $definition = ['default' => $definition];
            }
            $uiType = $definition['ui_type'] ?? $definition['input'] ?? $definition['type'] ?? 'text';
            $isTranslatable = !empty($definition['i18n']) || !empty($definition['translate']) || !empty($definition['translatable']);
            $definitions[$name] = [
                'name' => $definition['name'] ?? $definition['label'] ?? $name,
                'label' => $definition['label'] ?? $definition['name'] ?? $name,
                'description' => $definition['description'] ?? '',
                'default' => $definition['default'] ?? null,
                'type' => $definition['type'] ?? 'string',
                'ui_type' => $uiType,
                'input' => $definition['input'] ?? $uiType,
                'translate' => $isTranslatable,
                'i18n' => $isTranslatable,
                'translatable' => $isTranslatable,
                'required' => !empty($definition['required']),
                'options' => $definition['options'] ?? $definition['option'] ?? [],
                'meta' => $definition,
            ];
        }

        return $definitions;
    }

    /**
     * 获取指定 scope/locale 下的参数值集合
     *
     * @param string $identify meta_identify（如 layouts.account）
     * @param string $scope    作用域，默认 default
     * @param string|null $locale 语言代码，null 时使用当前语言
     * @return array
     */
    public static function getParamValues(string $identify, string $scope = 'default', ?string $locale = null): array
    {
        self::ensureInitialized();
        $identify = self::normalizeIdentify($identify);
        $identifyParts = explode('.', $identify);
        $identifyArea = $identifyParts[1] ?? 'frontend';
        $effectiveScope = self::resolveEffectiveScope($scope, $identifyArea);

        $definitions = self::getParamDefinitions($identify);
        if (empty($definitions)) {
            return [];
        }

        $resolvedLocale = self::currentConfigLocale($locale);
        $themeId = self::state()->currentTheme?->getId();
        $identities = [];
        $candidateIndexes = [];
        if ($themeId !== null && (string)$themeId !== '') {
            foreach ($definitions as $paramName => $definition) {
                if (!empty($definition['translate'])) {
                    continue;
                }
                [$namespace, $configKey] = self::resolveNamespaceAndConfigKey($identify, "param.{$paramName}");
                foreach (self::dualReadConfigKeys($configKey) as $candidateKey) {
                    $candidateIndexes[$paramName][] = count($identities);
                    $identities[] = new MetaConfigIdentity(
                        namespace: $namespace,
                        configKey: $candidateKey,
                        scope: $effectiveScope,
                        locale: $resolvedLocale,
                        identifyId: (string)$themeId,
                    );
                }
            }
        }

        $records = $identities !== []
            ? self::metaConfigRepository()->resolveBatch($identities)
            : [];

        $values = [];
        foreach ($definitions as $paramName => $definition) {
            $defaultValue = $definition['default'] ?? null;
            if (!empty($definition['translate'])) {
                $values[$paramName] = self::getParamTranslation(
                    $identify,
                    $paramName,
                    $effectiveScope,
                    $locale,
                    is_scalar($defaultValue) ? (string)$defaultValue : null,
                );
                continue;
            }

            $value = null;
            foreach ($candidateIndexes[$paramName] ?? [] as $recordIndex) {
                $record = $records[$recordIndex] ?? null;
                if ($record instanceof MetaConfigRecord) {
                    $value = $record->value;
                    break;
                }
            }
            if ($value === null) {
                $value = is_scalar($defaultValue) ? (string)$defaultValue : $defaultValue;
            }

            $values[$paramName] = self::normalizeParamValueForDefinition($value, $definition);
        }

        return $values;
    }

    public static function normalizeParamValueForDefinition(mixed $value, array $definition): mixed
    {
        $defaultValue = $definition['default'] ?? null;
        $type = strtolower(trim((string)($definition['type'] ?? '')));
        $expectsArray = $type === 'array' || is_array($defaultValue);

        if (!$expectsArray || is_array($value)) {
            return $value;
        }

        if ($value === null) {
            return is_array($defaultValue) ? $defaultValue : [];
        }

        if (!is_string($value)) {
            return $value;
        }

        $trimmedValue = trim($value);
        if ($trimmedValue === '') {
            return is_array($defaultValue) ? $defaultValue : [];
        }

        $decodedValue = json_decode($trimmedValue, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decodedValue)) {
            return $decodedValue;
        }

        return $value;
    }

    /**
     * 批量设置参数值
     *
     * @param string $identify meta_identify（如 layouts.account）
     * @param array $values    形如 ['title' => 'xxx']
     * @param string $scope    作用域，默认 default
     * @param string|null $locale 语言代码，可选
     */
    public static function setParamValues(string $identify, array $values, string $scope = 'default', ?string $locale = null): void
    {
        self::ensureInitialized();
        if (empty($values)) {
            return;
        }

        $identify = self::normalizeIdentify($identify);
        $definitions = self::getParamDefinitions($identify);

        foreach ($values as $paramName => $value) {
            $definition = $definitions[$paramName] ?? null;
            $isTranslatable = $definition && !empty($definition['translate']);

            if ($isTranslatable) {
                self::setParamTranslation($identify, (string)$paramName, (string)$value, $scope, $locale);
                continue;
            }

            $configIdentify = "{$identify}.param.{$paramName}.value";
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            self::set($configIdentify, (string)$value, $scope, $locale);
        }
    }

    /**
     * 删除参数值（恢复默认）
     */
    public static function deleteParamValue(string $identify, string $paramName, string $scope = 'default', ?string $locale = null): void
    {
        self::ensureInitialized();
        $identify = self::normalizeIdentify($identify);
        $identifyParts = explode('.', $identify);
        $identifyArea = $identifyParts[1] ?? 'frontend';
        $effectiveScope = self::resolveEffectiveScope($scope, $identifyArea);
        $definitions = self::getParamDefinitions($identify);
        $definition = $definitions[$paramName] ?? null;
        $isTranslatable = $definition && !empty($definition['translate']);

        if ($isTranslatable) {
            self::deleteParamTranslation($identify, $paramName, $effectiveScope, $locale);
            return;
        }

        [$namespace, $configKey] = self::resolveNamespaceAndConfigKey($identify, "param.{$paramName}");
        $themeId = self::state()->currentTheme?->getId();

        if ($themeId !== null && (string)$themeId !== '') {
            $deleted = false;
            foreach (self::dualReadConfigKeys($configKey) as $candidateKey) {
                $deleted = self::metaConfigRepository()->delete(new MetaConfigIdentity(
                    namespace: $namespace,
                    configKey: $candidateKey,
                    scope: $effectiveScope,
                    locale: $locale,
                    identifyId: (string)$themeId,
                )) || $deleted;
            }
            if (!$deleted) {
                return;
            }
            self::clearCache();
        }
    }

    /**
     * 获取某个参数在指定语言下的翻译值
     *
     * @param string      $identify  meta_identify（如 layouts.account）
     * @param string      $paramName 参数名
     * @param string      $scope     作用域，默认 default
     * @param string|null $locale    语言代码，null 时使用当前语言
     * @param string|null $default   默认值
     * @return string
     */
    public static function getParamTranslation(string $identify, string $paramName, string $scope = 'default', ?string $locale = null, ?string $default = null): string
    {
        self::ensureInitialized();
        $identify = self::normalizeIdentify($identify);
        $identifyParts = explode('.', $identify);
        $identifyArea = $identifyParts[1] ?? 'frontend';
        $effectiveScope = self::resolveEffectiveScope($scope, $identifyArea);
        $configIdentify = "{$identify}.param.{$paramName}.value";

        return MetaTranslation::getTranslatedValueWithScope(
            $configIdentify,
            $effectiveScope,
            $locale,
            $default
        );
    }

    /**
     * 设置某个参数在指定语言下的翻译值
     *
     * 注意：翻译值写入 I18n Dictionary 表，而不是 MetaConfig 表，
     * 读取时通过 MetaTranslation 统一取值。
     *
     * @param string      $identify  meta_identify（如 layouts.account）
     * @param string      $paramName 参数名
     * @param string      $value     翻译值
     * @param string      $scope     作用域，默认 default
     * @param string|null $locale    语言代码，null 时使用当前语言
     * @return bool
     */
    public static function setParamTranslation(string $identify, string $paramName, string $value, string $scope = 'default', ?string $locale = null): bool
    {
        self::ensureInitialized();
        $identify = self::normalizeIdentify($identify);
        $identifyParts = explode('.', $identify);
        $identifyArea = $identifyParts[1] ?? 'frontend';
        $effectiveScope = self::resolveEffectiveScope($scope, $identifyArea);

        if ($locale === null) {
            $locale = Cookie::getLangLocal() ?? 'zh_Hans_CN';
        }

        // 与 MetaTranslation::getTranslatedValueWithScope 保持相同的 key 约定
        $metaKey = "{$identify}.param.{$paramName}.value";
        $translationKey = '@meta::' . $metaKey;
        if ($effectiveScope !== 'default') {
            $translationKey .= '|scope:' . $effectiveScope;
        }

        return self::dictionaryRepository()->upsert($translationKey, $locale, $value);
    }

    /**
     * 删除参数翻译（恢复默认）
     */
    public static function deleteParamTranslation(string $identify, string $paramName, string $scope = 'default', ?string $locale = null): bool
    {
        self::ensureInitialized();
        $identify = self::normalizeIdentify($identify);
        $identifyParts = explode('.', $identify);
        $identifyArea = $identifyParts[1] ?? 'frontend';
        $effectiveScope = self::resolveEffectiveScope($scope, $identifyArea);

        if ($locale === null) {
            $locale = Cookie::getLangLocal() ?? 'zh_Hans_CN';
        }

        $metaKey = "{$identify}.param.{$paramName}.value";
        $translationKey = '@meta::' . $metaKey;
        if ($effectiveScope !== 'default') {
            $translationKey .= '|scope:' . $effectiveScope;
        }

        return self::dictionaryRepository()->deleteEntry($translationKey, $locale);
    }

    /**
     * 解析 namespace 与 config key
     *
     * @param string $identify 形如 theme.frontend.layouts.default
     * @param string $extraPath 追加到 config key 的路径，例如 param.title
     * @return array{0:string,1:string}
     */
    private static function resolveNamespaceAndConfigKey(string $identify, string $extraPath = ''): array
    {
        $identify = self::normalizeIdentify($identify);
        $segments = explode('.', $identify);
        if (count($segments) < 3) {
            return ['', ''];
        }

        $namespace = $segments[0] . '.' . ($segments[1] ?? 'frontend');
        $configKey = implode('.', array_slice($segments, 2));

        if ($extraPath !== '') {
            $configKey .= '.' . ltrim($extraPath, '.');
        }

        return [$namespace, $configKey];
    }
    
    /**
     * 性能预加载：一次性加载当前主题的所有Meta配置
     * 
     * 默认加载：
     * - 主题命名空间下的（theme.frontend 或 theme.backend）
     * - 主题ID的（当前主题的ID）
     * - 关于当前区域的（frontend 或 backend）
     * - 当前scope的所有数据（默认 'default'）
     * 
     * @param string|null $namespace 命名空间（如 theme.frontend），如果为null则自动生成
     * @param string|null $metaIdentify Meta标识（如 theme.frontend.layouts.*），如果为null则使用通配符加载所有
     * @param string|null $scope 作用域，如果为null则使用 'default'
     * @param string|null $locale 语言代码，如果为null则使用当前语言
     * @return void
     */
    public static function performanceLoad(?string $namespace = null, ?string $metaIdentify = null, ?string $scope = null, ?string $locale = null): void
    {
        // 如果正在加载，直接返回，防止循环调用
        $state = self::state();
        if ($state->performanceLoading) {
            return;
        }
        
        try {
            // 标记为正在加载
            $state->performanceLoading = true;
            
            // 确保已初始化（获取当前主题和区域）
            // 注意：ensureInitialized 中会检查 performanceLoading 标志，不会再次调用 performanceLoad
            self::ensureInitialized();
            
            // 如果没有提供namespace，自动生成（基于当前区域）
            if ($namespace === null) {
                if ($state->currentArea === null) {
                    $state->currentArea = State::isBackend() ? 'backend' : 'frontend';
                }
                $namespace = "theme." . $state->currentArea;
            }
            
            // 如果没有提供metaIdentify，使用通配符加载所有
            if ($metaIdentify === null) {
                $metaIdentify = $namespace . ".*";
            }
            
            // 如果没有提供scope，使用 'default'
            if ($scope === null) {
                $scope = self::resolveRequestedScopeForArea($state->currentArea ?? 'frontend');
            }
            $scope = self::resolveEffectiveScope($scope, $state->currentArea);
            
            $locale = self::currentConfigLocale($locale);
            
            // 获取当前主题ID
            $themeId = null;
            if ($state->currentTheme && $state->currentTheme->getId()) {
                $themeId = $state->currentTheme->getId();
            }
            
            $key = hash('sha256', implode("\0", [
                $namespace,
                $metaIdentify,
                $scope,
                $locale,
                $themeId === null ? 'null' : (string)$themeId,
            ]));
            
            // 如果已经加载过相同的配置，直接返回
            if ($state->performanceKey === $key) {
                return;
            }

            [$runtimeHit, $runtimeThemeConfigs] = self::getRuntimeCache('performance:' . $key);
            if ($runtimeHit && is_array($runtimeThemeConfigs)) {
                $state->performanceCache[$key] = $runtimeThemeConfigs;
                $state->performanceKey = $key;
                $state->performanceNamespace = $namespace;
                $state->performanceScope = $scope;
                $state->performanceLocale = $locale;
                return;
            }

            if ($themeId !== null && (string)$themeId !== '') {
                try {
                    $records = self::metaConfigRepository()->search(new MetaConfigSearch(
                        namespace: $namespace,
                        scope: $scope,
                        allLocales: true,
                        identifyId: (string)$themeId,
                    ));
                    $themeConfigs = self::resolveConfigMap($records, $locale);
                    $state->performanceCache[$key] = $themeConfigs;
                    self::setRuntimeCache('performance:' . $key, $themeConfigs);
                } catch (\Throwable) {
                    $state->performanceCache[$key] = [];
                    self::setRuntimeCache('performance:' . $key, []);
                }
            } else {
                $state->performanceCache[$key] = [];
            }
            
            $state->performanceKey = $key;
            $state->performanceNamespace = $namespace;
            $state->performanceScope = $scope;
            $state->performanceLocale = $locale;
        } catch (\Throwable) {
            // 预加载失败，继续执行
        } finally {
            // 重置加载标志，允许后续调用
            $state->performanceLoading = false;
        }
    }
    
    /**
     * 清除缓存
     */
    public static function clearCache(): void
    {
        self::$runtimeCache = [];
        self::clearSharedRuntimeCache();
        self::resetCurrentState();
    }

    private static function clearSharedRuntimeCache(): void
    {
        $cache = self::sharedRuntimeCache();
        if ($cache === null) {
            return;
        }

        try {
            $cache->clearNamespace(self::SHARED_CACHE_NAMESPACE);
        } catch (\Throwable) {
            self::$sharedRuntimeCache = null;
            self::$sharedRuntimeCacheResolved = true;
        }
    }

    /**
     * WLS 请求结束后的请求级状态重置。
     */
    public static function resetRequestState(): void
    {
        self::resetCurrentState();
        self::state()->performanceLoading = false;
    }
    
    /**
     * 规范化 identify，自动补全前缀
     * 如果键名不包含 frontend/backend，自动根据当前请求判断并添加
     * 
     * @param string $identify 原始 identify
     * @return string 规范化后的 identify
     */
    private static function normalizeIdentify(string $identify): string
    {
        // 如果已经包含 theme. 前缀，检查是否包含 frontend/backend
        if (str_starts_with($identify, 'theme.')) {
            // 检查是否包含 frontend 或 backend
            if (preg_match('/^theme\.(frontend|backend)\./', $identify)) {
                // 已经包含 frontend 或 backend，直接返回
                return $identify;
            }
            
            // 包含 theme. 但不包含 frontend/backend，自动添加
            $state = self::state();
            if ($state->currentArea === null) {
                $state->currentArea = State::isBackend() ? 'backend' : 'frontend';
            }
            // 去掉 theme. 前缀，添加 area
            $rest = substr($identify, 6); // 去掉 'theme.'
            return "theme." . $state->currentArea . "." . $rest;
        }
        
        // 如果不包含 theme. 前缀，检查是否包含 frontend/backend
        if (preg_match('/^(frontend|backend)\./', $identify)) {
            // 包含 frontend 或 backend，添加 theme. 前缀
            return "theme." . $identify;
        }
        
        // 既不包含 theme. 也不包含 frontend/backend，自动添加
        $state = self::state();
        if ($state->currentArea === null) {
            $state->currentArea = State::isBackend() ? 'backend' : 'frontend';
        }
        return "theme." . $state->currentArea . "." . $identify;
    }
    
    /**
     * 设置当前主题（用于特殊场景）
     * 
     * @param WelineTheme|null $theme 主题对象
     * @return void
     */
    public static function setCurrentTheme(?WelineTheme $theme): void
    {
        $state = self::state();
        $state->currentTheme = $theme;
        $state->performanceKey = null;
        $state->performanceNamespace = null;
        $state->performanceScope = null;
        $state->performanceLocale = null;
        $state->requestedScopes = [];
        $state->effectiveScopes = [];
        $state->initialized = false; // 重置初始化状态，下次调用时会重新初始化
    }
    
    /**
     * 设置当前区域（用于特殊场景）
     * 
     * @param string|null $area 区域（frontend/backend）
     * @return void
     */
    public static function setCurrentArea(?string $area): void
    {
        $state = self::state();
        $state->currentArea = $area;
        $state->performanceKey = null;
        $state->performanceNamespace = null;
        $state->performanceScope = null;
        $state->performanceLocale = null;
        $state->requestedScopes = [];
        $state->effectiveScopes = [];
        $state->initialized = false; // 重置初始化状态，下次调用时会重新初始化
    }
    
    /**
     * 获取当前主题
     * 
     * @return WelineTheme|null
     */
    public static function getCurrentTheme(): ?WelineTheme
    {
        self::ensureInitialized();
        return self::state()->currentTheme;
    }
    
    /**
     * 获取当前区域
     * 
     * @return string|null
     */
    public static function getCurrentArea(): ?string
    {
        self::ensureInitialized();
        return self::state()->currentArea;
    }
    
    /**
     * 获取指定区域和类型的所有Meta记录
     * 
     * 根据 meta_identify 前缀查询 w_meta 表，获取所有符合条件的记录
     * 例如：getMetaList('frontend', 'layouts') 会查询 meta_identify LIKE 'theme.frontend.layouts.%'
     * 
     * @param string $area 区域（frontend/backend）
     * @param string $type 类型（layouts/colors/variables/components/partials）
     * @return array Meta记录数组，每个元素包含 meta_id, meta_identify, meta_data, setting 等
     */
    public static function getMetaList(string $area, string $type): array
    {
        self::ensureInitialized();
        
        $cacheKey = "meta_list_{$area}_{$type}";
        $state = self::state();
        if (isset($state->performanceCache[$cacheKey])) {
            return $state->performanceCache[$cacheKey];
        }

        [$runtimeHit, $runtimeValue] = self::getRuntimeCache($cacheKey);
        if ($runtimeHit && is_array($runtimeValue)) {
            $state->performanceCache[$cacheKey] = $runtimeValue;
            return $runtimeValue;
        }
        
        try {
            $records = self::metadataRepository()->search(new MetadataSearch(
                namespace: 'theme',
                identifyPrefix: "theme.{$area}.{$type}.",
            ));
            $result = [];
            foreach ($records as $record) {
                if (!$record instanceof MetadataRecord) {
                    continue;
                }
                $result[] = self::metadataRecordToArray($record);
            }
            
            $state->performanceCache[$cacheKey] = $result;
            self::setRuntimeCache($cacheKey, $result);
            return $result;
        } catch (\Throwable) {
            return [];
        }
    }
    
    /**
     * 根据类型获取可用选项列表
     * 
     * 从 Meta 表读取数据，并转换为前端可用的选项格式
     * 支持分组（如 layouts、partials）和非分组（如 colors、variables、components）
     * 
     * @param string $area 区域（frontend/backend）
     * @param string $type 类型（layouts/colors/variables/components/partials）
     * @return array 选项数组
     */
    public static function getAvailableOptions(string $area, string $type): array
    {
        self::ensureInitialized();
        
        $metaList = self::getMetaList($area, $type);
        
        if (empty($metaList)) {
            return [];
        }
        
        // 判断是否需要分组
        $grouped = in_array($type, ['layouts', 'partials'], true);
        $result = $grouped ? [] : [];
        
        foreach ($metaList as $meta) {
            $identify = $meta['meta_identify'] ?? '';
            $metaData = $meta['meta_data'] ?? [];
            $setting = $meta['setting'] ?? [];
            $filePath = $meta['file_path'] ?? '';
            $fileFullPath = $meta['file_full_path'] ?? '';
            $category = $meta['category'] ?? '';
            
            // 从 identify 提取选项值
            // 例如：theme.frontend.layouts.account -> account
            // 或：theme.frontend.layouts.account.dashboard -> dashboard（group=account）
            $parts = explode('.', $identify);
            // 格式：theme.{area}.{type}.{...rest}
            // 移除前3个部分
            $restParts = array_slice($parts, 3);
            
            if (empty($restParts)) {
                continue;
            }
            
            // 提取分组和值
            $group = 'default';
            $value = '';
            
            if ($grouped) {
                // 统一规则：
                // group = 第一个段（布局类型：default/account/cart/...）
                // value = 最后一个段（具体布局名：default/category/...）
                $last = $restParts[count($restParts) - 1];

                // 跳过仅用于 name/description 元信息的记录：
                // theme.frontend.layouts.default.name / .description 等不应作为可选布局出现
                if (in_array($last, ['name', 'description'], true)) {
                    continue;
                }

                $group = $restParts[0];
                $value = $last;
            } else {
                // 非分组类型，直接取最后一个部分作为值
                $value = $restParts[count($restParts) - 1];
            }
            
            if ($value === '') {
                continue;
            }
            
            // 构建选项
            $option = [
                'value' => $value,
                'meta' => self::buildOptionMeta($metaData, $setting, $filePath, $fileFullPath),
                'file' => $fileFullPath ?: $filePath,
                'meta_id' => $meta['meta_id'],
                'meta_identify' => $identify,
            ];
            
            if ($grouped) {
                if (!isset($result[$group])) {
                    $result[$group] = [];
                }
                // 检查是否已存在相同值
                $exists = false;
                foreach ($result[$group] as $existing) {
                    if ($existing['value'] === $value) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $result[$group][] = $option;
                }
            } else {
                // 检查是否已存在相同值
                $exists = false;
                foreach ($result as $existing) {
                    if ($existing['value'] === $value) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $result[] = $option;
                }
            }
        }
        
        // 排序
        if ($grouped) {
            foreach ($result as $group => $options) {
                usort($result[$group], function($a, $b) {
                    $nameA = $a['meta']['name'] ?? $a['value'];
                    $nameB = $b['meta']['name'] ?? $b['value'];

                    // 兼容数组结构的 name，优先取其中的 name/default 字段
                    if (is_array($nameA)) {
                        $nameA = $nameA['name'] ?? $nameA['default'] ?? reset($nameA) ?? '';
                    }
                    if (is_array($nameB)) {
                        $nameB = $nameB['name'] ?? $nameB['default'] ?? reset($nameB) ?? '';
                    }
                    $nameA = (string)$nameA;
                    $nameB = (string)$nameB;
                    return strcmp($nameA, $nameB);
                });
            }
        } else {
            usort($result, function($a, $b) {
                $nameA = $a['meta']['name'] ?? $a['value'];
                $nameB = $b['meta']['name'] ?? $b['value'];

                if (is_array($nameA)) {
                    $nameA = $nameA['name'] ?? $nameA['default'] ?? reset($nameA) ?? '';
                }
                if (is_array($nameB)) {
                    $nameB = $nameB['name'] ?? $nameB['default'] ?? reset($nameB) ?? '';
                }
                $nameA = (string)$nameA;
                $nameB = (string)$nameB;
                return strcmp($nameA, $nameB);
            });
        }
        
        return $result;
    }
    
    /**
     * 构建选项的 meta 信息
     * 
     * @param array $metaData meta_data 字段解析后的数组
     * @param array $setting setting 字段解析后的数组
     * @param string $filePath 文件相对路径
     * @param string $fileFullPath 文件完整路径
     * @return array meta 信息数组
     */
    private static function buildOptionMeta(array $metaData, array $setting, string $filePath, string $fileFullPath): array
    {
        $meta = [
            'name' => '',
            'description' => '',
            'icon' => '',
            'version' => '',
            'author' => '',
            'params' => [],
        ];
        
        // 从 meta_data 中提取信息
        // 支持多种数据结构：attributes、meta、直接字段
        $attributes = $metaData['attributes'] ?? $metaData['meta'] ?? $metaData;
        
        if (isset($attributes['name'])) {
            $meta['name'] = is_array($attributes['name']) ? ($attributes['name']['name'] ?? $attributes['name']['default'] ?? '') : $attributes['name'];
        }
        if (isset($attributes['description'])) {
            $meta['description'] = is_array($attributes['description']) ? ($attributes['description']['name'] ?? $attributes['description']['default'] ?? '') : $attributes['description'];
        }
        if (isset($attributes['icon'])) {
            $meta['icon'] = $attributes['icon'];
        }
        if (isset($attributes['version'])) {
            $meta['version'] = $attributes['version'];
        }
        if (isset($attributes['author'])) {
            $meta['author'] = $attributes['author'];
        }
        
        // 从 setting 中提取参数
        if (isset($setting['param']) && is_array($setting['param'])) {
            $meta['params'] = self::paramDefinitionNormalizer()->normalizeDefinitions($setting['param']);
        }
        
        // 如果 meta_data 和 setting 都没有参数，尝试从文件解析
        if (empty($meta['params']) && !empty($fileFullPath)) {
            $absolutePath = $fileFullPath;
            if (!str_starts_with($absolutePath, '/') && !preg_match('/^[A-Za-z]:/', $absolutePath)) {
                $absolutePath = BP . DS . ltrim(str_replace(['/', '\\'], DS, $fileFullPath), DS);
            }
            
            if (is_file($absolutePath)) {
                try {
                    $parsed = ComponentMetaParser::parse($absolutePath);
                    if (!empty($parsed['params'])) {
                        $meta['params'] = self::formatParsedParams($parsed['params']);
                    }
                    // 如果 name/description 为空，也从文件解析
                    if (empty($meta['name']) && !empty($parsed['meta']['name'])) {
                        $meta['name'] = $parsed['meta']['name'];
                    }
                    if (empty($meta['description']) && !empty($parsed['meta']['description'])) {
                        $meta['description'] = $parsed['meta']['description'];
                    }
                } catch (\Throwable $e) {
                    // 解析失败，忽略
                }
            }
        }
        
        // 如果 name 仍然为空，使用文件名
        if (empty($meta['name'])) {
            $fileName = pathinfo($fileFullPath ?: $filePath, PATHINFO_FILENAME);
            $meta['name'] = ucfirst($fileName);
        }
        
        return $meta;
    }
    
    /**
     * 格式化解析的参数
     * 
     * @param array $parsedParams ComponentMetaParser 解析的参数
     * @return array 格式化后的参数
     */
    private static function formatParsedParams(array $parsedParams): array
    {
        return self::paramDefinitionNormalizer()->normalizeParsedParamList($parsedParams);
    }
    
    /**
     * 获取指定类型的配置值
     * 
     * 从 w_meta_config 表读取配置值
     * 
     * @param string $area 区域（frontend/backend）
     * @param string $type 类型（layouts/colors/variables/components/partials）
     * @param string $scope 作用域，默认 'default'
     * @return array 配置数组，键为配置键，值为配置值
     */
    public static function getConfigList(string $area, string $type, string $scope = 'default'): array
    {
        self::ensureInitialized();
        $effectiveScope = self::resolveEffectiveScope($scope, $area);
        
        // 获取主题ID（用于查询配置）
        $state = self::state();
        $themeId = null;
        if ($state->currentTheme && $state->currentTheme->getId()) {
            $themeId = $state->currentTheme->getId();
        }
        
        $locale = self::currentConfigLocale();
        $cacheKey = "config_list_{$area}_{$type}_{$effectiveScope}_{$themeId}_{$locale}";
        if (isset($state->performanceCache[$cacheKey])) {
            return $state->performanceCache[$cacheKey];
        }

        [$runtimeHit, $runtimeValue] = self::getRuntimeCache($cacheKey);
        if ($runtimeHit && is_array($runtimeValue)) {
            $state->performanceCache[$cacheKey] = $runtimeValue;
            return $runtimeValue;
        }

        if ($state->performanceKey
            && $state->performanceNamespace === "theme.{$area}"
            && $state->performanceScope === $effectiveScope
            && $state->performanceLocale === $locale
            && isset($state->performanceCache[$state->performanceKey])
            && is_array($state->performanceCache[$state->performanceKey])) {
            $result = self::filterThemeConfigsByType($state->performanceCache[$state->performanceKey], $type);
            $state->performanceCache[$cacheKey] = $result;
            self::setRuntimeCache($cacheKey, $result);
            return $result;
        }

        if ($themeId === null || (string)$themeId === '') {
            $state->performanceCache[$cacheKey] = [];
            return [];
        }
        
        try {
            $namespace = "theme.{$area}";
            $records = self::metaConfigRepository()->search(new MetaConfigSearch(
                namespace: $namespace,
                scope: $effectiveScope,
                configKeyPrefix: $type . '.',
                allLocales: true,
                identifyId: (string)$themeId,
            ));
            $result = self::filterThemeConfigsByType(self::resolveConfigMap($records, $locale), $type);
            
            $state->performanceCache[$cacheKey] = $result;
            self::setRuntimeCache($cacheKey, $result);
            return $result;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 获取当前主题在指定 area 下已存在的 scope 列表
     *
     * @param string $area 区域（frontend/backend）
     * @return array
     */
    public static function getScopes(string $area): array
    {
        self::ensureInitialized();

        $area = strtolower(trim($area)) === 'backend' ? 'backend' : 'frontend';
        $state = self::state();
        $themeId = $state->currentTheme?->getId();
        $cacheKey = "scope_list_{$area}_{$themeId}";
        if (isset($state->performanceCache[$cacheKey])) {
            return $state->performanceCache[$cacheKey];
        }

        $scopes = ['default'];
        if (!$themeId) {
            $state->performanceCache[$cacheKey] = $scopes;
            return $scopes;
        }

        try {
            $scopes = array_merge($scopes, self::metaConfigRepository()->listScopes(new MetaConfigScopeSearch(
                namespace: "theme.{$area}",
                identifyId: (string)$themeId,
            )));
        } catch (\Throwable) {
            // ignore and keep default scope only
        }

        $scopes = array_values(array_unique($scopes));
        try {
            /** @var PreviewThemeScopeService $previewThemeScopeService */
            $previewThemeScopeService = ObjectManager::getInstance(PreviewThemeScopeService::class);
            $scopes = $previewThemeScopeService->filterPreviewScopes($scopes);
        } catch (\Throwable) {
        }
        if (empty($scopes)) {
            $scopes = ['default'];
        }
        usort($scopes, static function (string $left, string $right): int {
            if ($left === 'default') {
                return -1;
            }
            if ($right === 'default') {
                return 1;
            }

            return strcmp($left, $right);
        });

        $state->performanceCache[$cacheKey] = $scopes;
        return $scopes;
    }
    
    /**
     * 获取布局配置
     * 
     * @param string $area 区域（frontend/backend）
     * @param string $scope 作用域，默认 'default'
     * @return array 布局配置数组，格式：['account' => 'dashboard', 'default' => 'default']
     */
    public static function getLayoutConfig(string $area, string $scope = 'default'): array
    {
        $configList = self::getConfigList($area, 'layouts', $scope);
        
        $result = [];
        foreach ($configList as $key => $value) {
            // key 格式：{layoutType}.value 或 {layoutType}
            $parts = explode('.', $key);
            $layoutType = $parts[0];
            
            // 处理值：如果是数组（可能是从 JSON 解析出来的对象），需要提取对应的值
            $layoutValue = $value;
            if (is_array($value)) {
                // 如果值是数组，可能是 {"homepage":"minimal"} 这样的结构
                // 尝试从数组中提取对应的 layoutType 的值
                if (isset($value[$layoutType])) {
                    $layoutValue = $value[$layoutType];
                } elseif (count($value) === 1) {
                    // 如果数组只有一个元素，直接取第一个值
                    $layoutValue = reset($value);
                } else {
                    // 如果数组有多个元素，保持原样（这种情况不应该发生）
                    $layoutValue = $value;
                }
            } elseif (is_string($value)) {
                // 如果是字符串，检查是否是 JSON
                if (($value[0] === '{' || $value[0] === '[') && 
                    ($decoded = json_decode($value, true)) !== null && 
                    json_last_error() === JSON_ERROR_NONE) {
                    // 解析 JSON
                    if (is_array($decoded)) {
                        if (isset($decoded[$layoutType])) {
                            $layoutValue = $decoded[$layoutType];
                        } elseif (count($decoded) === 1) {
                            $layoutValue = reset($decoded);
                        } else {
                            $layoutValue = $decoded;
                        }
                    } else {
                        $layoutValue = $decoded;
                    }
                }
            }
            
            // 只取 .value 结尾的配置
            if (count($parts) >= 2 && $parts[count($parts) - 1] === 'value') {
                $result[$layoutType] = $layoutValue;
            } elseif (count($parts) === 1) {
                // 兼容没有 .value 后缀的配置
                $result[$layoutType] = $layoutValue;
            }
        }
        
        return $result;
    }
    
    /**
     * 获取色系配置
     * 
     * @param string $area 区域（frontend/backend）
     * @param string $scope 作用域，默认 'default'
     * @return string|null 色系配置值
     */
    public static function getColorConfig(string $area, string $scope = 'default'): ?string
    {
        $configList = self::getConfigList($area, 'colors', $scope);
        
        // 查找 primary.value 或直接的值
        if (isset($configList['primary.value'])) {
            return $configList['primary.value'];
        }
        if (isset($configList['value'])) {
            return $configList['value'];
        }
        
        // 返回第一个值
        foreach ($configList as $value) {
            return $value;
        }
        
        return null;
    }
    
    /**
     * 获取变量配置
     * 
     * @param string $area 区域（frontend/backend）
     * @param string $scope 作用域，默认 'default'
     * @return array 变量配置数组
     */
    public static function getVariablesConfig(string $area, string $scope = 'default'): array
    {
        $configList = self::getConfigList($area, 'variables', $scope);
        
        // 查找 value 键
        if (isset($configList['value'])) {
            $value = $configList['value'];
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                return is_array($decoded) ? $decoded : [$value];
            }
            return is_array($value) ? $value : [$value];
        }
        
        return [];
    }
    
    /**
     * 获取部件配置
     * 
     * @param string $area 区域（frontend/backend）
     * @param string $scope 作用域，默认 'default'
     * @return array 部件配置数组，格式：['header' => 'minimal', 'footer' => 'default']
     */
    public static function getPartialsConfig(string $area, string $scope = 'default'): array
    {
        $configList = self::getConfigList($area, 'partials', $scope);
        
        $result = [];
        foreach ($configList as $key => $value) {
            // key 格式：{partialType}.value 或 {partialType}
            $parts = explode('.', $key);
            $partialType = $parts[0];
            
            // 只取 .value 结尾的配置
            if (count($parts) >= 2 && $parts[count($parts) - 1] === 'value') {
                $result[$partialType] = $value;
            } elseif (count($parts) === 1) {
                // 兼容没有 .value 后缀的配置
                $result[$partialType] = $value;
            }
        }
        
        return $result;
    }
    
    // ========================================
    // 部件专用函数（Widget-specific functions）
    // ========================================
    
    /**
     * 获取部件的meta_identify
     * 
     * @param string $widgetModule 部件模块名（如 Weline_Theme）
     * @param string $widgetCode 部件代码（如 footer-social）
     * @param string $area 区域（frontend 或 backend）
     * @return string 例如：theme.frontend.widgets.Weline_Theme.footer-social
     */
    public static function getWidgetIdentify(string $widgetModule, string $widgetCode, string $area = 'frontend'): string
    {
        return "theme.{$area}.widgets.{$widgetModule}.{$widgetCode}";
    }

    public static function getWidgetInstanceIdentify(string $instanceId, string $area = 'frontend'): string
    {
        $area = trim($area) !== '' ? trim($area) : 'frontend';
        $instanceId = preg_replace('/[^a-zA-Z0-9_-]+/', '_', trim($instanceId)) ?: '';
        if ($instanceId === '') {
            $instanceId = 'unknown';
        }

        return "theme.{$area}.widget_instances.{$instanceId}";
    }
    
    /**
     * 获取部件参数定义（不含值，仅结构）
     * 
     * 优先从 WidgetRegistry（widget.php）获取，回退到 Meta 扫描（@param 注释）
     * 
     * @param string $widgetModule 部件模块名
     * @param string $widgetCode 部件代码
     * @param string $area 区域
     * @param \Weline\Widget\Service\WidgetRegistry|null $widgetRegistry WidgetRegistry 实例（可选）
     * @return array 参数定义数组，格式：['param_name' => ['type' => 'string', 'label' => '标题', ...]]
     */
    public static function getWidgetParamDefinitions(
        string $widgetModule,
        string $widgetCode,
        string $area = 'frontend',
        $widgetRegistry = null
    ): array {
        $eventData = [
            'data' => [
                'operation' => 'getParamDefinitions',
                'params' => [
                    'widget_module' => $widgetModule,
                    'widget_code' => $widgetCode,
                    'area' => $area,
                ],
            ],
        ];
        /** @var EventsManager $eventsManager */
        $eventsManager = ObjectManager::getInstance(EventsManager::class);
        $eventsManager->dispatch('Weline_Widget::query', $eventData);
        $params = $eventData['data']['result'] ?? null;
        return is_array($params) ? $params : [];
    }
    
    /**
     * 获取部件参数定义（使用 WidgetRegistry，推荐使用此方法）
     * 
     * 此方法接受 WidgetRegistry 实例，确保能获取到 widget.php 中定义的参数
     * 
     * @param string $widgetModule 部件模块名
     * @param string $widgetCode 部件代码
     * @param \Weline\Widget\Service\WidgetRegistry $widgetRegistry WidgetRegistry 实例
     * @param string $area 区域
     * @return array 参数定义数组
     */
    public static function getWidgetParamDefinitionsWithRegistry(
        string $widgetModule,
        string $widgetCode,
        $widgetRegistry,
        string $area = 'frontend'
    ): array {
        return self::getWidgetParamDefinitions($widgetModule, $widgetCode, $area, $widgetRegistry);
    }
    
    /**
     * 获取部件单个参数值（支持多语言）
     * 
     * @param string $widgetModule 部件模块名
     * @param string $widgetCode 部件代码
     * @param string $paramName 参数名
     * @param string|null $locale 语言代码，null时使用当前语言
     * @param mixed $default 默认值
     * @param string $area 区域
     * @return mixed
     */
    public static function getWidgetParam(
        string $widgetModule,
        string $widgetCode,
        string $paramName,
        ?string $locale = null,
        $default = null,
        string $area = 'frontend'
    ) {
        $identify = self::getWidgetIdentify($widgetModule, $widgetCode, $area);
        $definitions = self::getParamDefinitions($identify);
        
        if (!isset($definitions[$paramName])) {
            return $default;
        }
        
        $definition = $definitions[$paramName];
        $isTranslatable = !empty($definition['translate']);
        
        if ($isTranslatable) {
            $value = self::getParamTranslation($identify, $paramName, 'default', $locale, $default);
            return $value ?? $default;
        }
        
        $configIdentify = "{$identify}.param.{$paramName}.value";
        $value = self::get($configIdentify);
        
        return $value ?? $definition['default'] ?? $default;
    }
    
    /**
     * 设置部件单个参数值（支持多语言）
     * 
     * @param string $widgetModule 部件模块名
     * @param string $widgetCode 部件代码
     * @param string $paramName 参数名
     * @param mixed $value 参数值
     * @param string|null $locale 语言代码，null时保存为默认值
     * @param string $area 区域
     * @return bool
     */
    public static function setWidgetParam(
        string $widgetModule,
        string $widgetCode,
        string $paramName,
        $value,
        ?string $locale = null,
        string $area = 'frontend'
    ): bool {
        $identify = self::getWidgetIdentify($widgetModule, $widgetCode, $area);
        $definitions = self::getParamDefinitions($identify);
        
        if (!isset($definitions[$paramName])) {
            return false;
        }
        
        $definition = $definitions[$paramName];
        $isTranslatable = !empty($definition['translate']);
        
        if ($isTranslatable) {
            return self::setParamTranslation($identify, $paramName, (string)$value, 'default', $locale);
        }
        
        $configIdentify = "{$identify}.param.{$paramName}.value";
        return self::set($configIdentify, (string)$value, 'default', $locale);
    }
    
    /**
     * 批量获取部件所有参数（支持多语言）
     * 
     * @param string $widgetModule 部件模块名
     * @param string $widgetCode 部件代码
     * @param string|null $locale 语言代码，null时使用当前语言
     * @param string $area 区域
     * @return array 参数数组
     */
    public static function getWidgetParams(
        string $widgetModule,
        string $widgetCode,
        ?string $locale = null,
        string $area = 'frontend'
    ): array {
        $identify = self::getWidgetIdentify($widgetModule, $widgetCode, $area);
        return self::getParamValues($identify, 'default', $locale);
    }
    
    /**
     * 批量设置部件参数（支持多语言）
     * 
     * @param string $widgetModule 部件模块名
     * @param string $widgetCode 部件代码
     * @param array $params 参数数组
     * @param string|null $locale 语言代码，null时保存为默认值
     * @param string $area 区域
     */
    public static function setWidgetParams(
        string $widgetModule,
        string $widgetCode,
        array $params,
        ?string $locale = null,
        string $area = 'frontend'
    ): void {
        $identify = self::getWidgetIdentify($widgetModule, $widgetCode, $area);
        self::setParamValues($identify, $params, 'default', $locale);
    }
    
    /**
     * 删除部件单个参数值
     * 
     * @param string $widgetModule 部件模块名
     * @param string $widgetCode 部件代码
     * @param string $paramName 参数名
     * @param string|null $locale 语言代码
     * @param string $area 区域
     */
    public static function deleteWidgetParam(
        string $widgetModule,
        string $widgetCode,
        string $paramName,
        ?string $locale = null,
        string $area = 'frontend'
    ): void {
        $identify = self::getWidgetIdentify($widgetModule, $widgetCode, $area);
        self::deleteParamValue($identify, $paramName, 'default', $locale);
    }

    // ─── 按路径的多语言读写（支持数组子字段） ─────────────────────

    /**
     * 获取部件所有可翻译路径模式
     *
     * 返回格式:
     *   'top'   => ['title', 'subtitle'],                    // 顶级可翻译参数名
     *   'array' => [ 'slides' => ['title', 'subtitle'] ]     // 数组参数 => 可翻译子字段列表
     *
     * @param array $paramDefs 参数定义（来自 Weline_Widget::query 或 widget.php）
     */
    public static function getTranslatablePaths(array $paramDefs): array
    {
        $top = [];
        $arrayFields = [];

        foreach ($paramDefs as $paramName => $def) {
            if (!is_array($def)) {
                continue;
            }

            $type = $def['type'] ?? 'string';

            if ($type !== 'array' && ParamDefinition::isTranslatable($def)) {
                $top[] = $paramName;
            }

            if ($type === 'array' && !empty($def['item_schema']) && is_array($def['item_schema'])) {
                $subTranslatable = [];
                foreach ($def['item_schema'] as $fieldKey => $fieldDef) {
                    if (is_array($fieldDef) && ParamDefinition::isTranslatable($fieldDef)) {
                        $subTranslatable[] = $fieldKey;
                    }
                }
                if (!empty($subTranslatable)) {
                    $arrayFields[$paramName] = $subTranslatable;
                }
            }
        }

        return ['top' => $top, 'array' => $arrayFields];
    }

    /**
     * 按路径读取翻译值（支持 slides.0.title 格式）
     */
    public static function getPathTranslation(
        string $identify,
        string $path,
        string $scope = 'default',
        ?string $locale = null,
        ?string $default = null
    ): ?string {
        self::ensureInitialized();
        $identify = self::normalizeIdentify($identify);
        $identifyParts = explode('.', $identify);
        $identifyArea = $identifyParts[1] ?? 'frontend';
        $effectiveScope = self::resolveEffectiveScope($scope, $identifyArea);
        $configIdentify = "{$identify}.path.{$path}.value";

        $result = MetaTranslation::getTranslatedValueWithScope(
            $configIdentify,
            $effectiveScope,
            $locale,
            $default
        );
        return $result !== '' ? $result : $default;
    }

    /**
     * 按路径写入翻译值
     */
    public static function setPathTranslation(
        string $identify,
        string $path,
        string $value,
        string $scope = 'default',
        ?string $locale = null
    ): bool {
        self::ensureInitialized();
        $identify = self::normalizeIdentify($identify);
        $identifyParts = explode('.', $identify);
        $identifyArea = $identifyParts[1] ?? 'frontend';
        $effectiveScope = self::resolveEffectiveScope($scope, $identifyArea);

        if ($locale === null) {
            $locale = Cookie::getLangLocal() ?? 'zh_Hans_CN';
        }

        $metaKey = "{$identify}.path.{$path}.value";
        $translationKey = '@meta::' . $metaKey;
        if ($effectiveScope !== 'default') {
            $translationKey .= '|scope:' . $effectiveScope;
        }

        return self::dictionaryRepository()->upsert($translationKey, $locale, $value);
    }

    /**
     * 将 locale 的可翻译路径值合并进 base config
     *
     * @param array $baseConfig   基础配置（来自 m_theme_layout.config）
     * @param array $paramDefs    参数定义
     * @param string $identify    meta identify
     * @param string|null $locale 语言代码
     * @return array 合并后的 config
     */
    public static function mergeTranslatedPaths(
        array $baseConfig,
        array $paramDefs,
        string $identify,
        ?string $locale = null
    ): array {
        $effectiveLocale = $locale ?? (Cookie::getLangLocal() ?? \Weline\Framework\App\Env::default_LANGUAGE_CODE);
        if ($effectiveLocale === \Weline\Framework\App\Env::default_LANGUAGE_CODE) {
            return $baseConfig;
        }

        $paths = self::getTranslatablePaths($paramDefs);

        foreach ($paths['top'] as $paramName) {
            $val = self::getParamTranslation($identify, $paramName, 'default', $locale);
            if ($val !== '' && $val !== null) {
                $baseConfig[$paramName] = $val;
            }
        }

        foreach ($paths['array'] as $arrayKey => $subFields) {
            if (!isset($baseConfig[$arrayKey]) || !is_array($baseConfig[$arrayKey])) {
                continue;
            }
            foreach ($baseConfig[$arrayKey] as $index => $item) {
                if (!is_array($item)) {
                    continue;
                }
                foreach ($subFields as $fieldKey) {
                    $path = "{$arrayKey}.{$index}.{$fieldKey}";
                    $val = self::getPathTranslation($identify, $path, 'default', $locale);
                    if ($val !== null) {
                        $baseConfig[$arrayKey][$index][$fieldKey] = $val;
                    }
                }
            }
        }

        return $baseConfig;
    }

    /**
     * 将 config 中的可翻译路径值保存到翻译存储
     *
     * @param array $configData  用户提交的 config（可能包含 "slides.0.title" 形式的路径 key）
     * @param array $paramDefs   参数定义
     * @param string $identify   meta identify
     * @param string|null $locale 语言代码
     * @return array 过滤掉路径 key 后的普通 config（用于写入 m_theme_layout.config）
     */
    public static function saveTranslatablePaths(
        array $configData,
        array $paramDefs,
        string $identify,
        ?string $locale = null
    ): array {
        $paths = self::getTranslatablePaths($paramDefs);
        $normalConfig = [];

        foreach ($configData as $key => $value) {
            if (str_contains($key, '.')) {
                self::setPathTranslation($identify, $key, (string)$value, 'default', $locale);
            } else {
                $normalConfig[$key] = $value;
            }
        }

        $resolvedLocale = $locale ?? \Weline\Framework\App\Env::default_LANGUAGE_CODE;
        foreach ($paths['top'] as $paramName) {
            if (array_key_exists($paramName, $normalConfig) && is_scalar($normalConfig[$paramName])) {
                self::setParamTranslation($identify, $paramName, (string)$normalConfig[$paramName], 'default', $resolvedLocale);
            }
        }

        foreach ($paths['array'] as $arrayKey => $subFields) {
            if (!isset($normalConfig[$arrayKey]) || !is_array($normalConfig[$arrayKey])) {
                continue;
            }
            foreach ($normalConfig[$arrayKey] as $index => $item) {
                if (!is_array($item)) {
                    continue;
                }
                foreach ($subFields as $fieldKey) {
                    if (array_key_exists($fieldKey, $item) && is_scalar($item[$fieldKey])) {
                        $path = "{$arrayKey}.{$index}.{$fieldKey}";
                        self::setPathTranslation($identify, $path, (string)$item[$fieldKey], 'default', $resolvedLocale);
                    }
                }
            }
        }

        if ($locale !== null) {
            foreach ($paths['top'] as $paramName) {
                unset($normalConfig[$paramName]);
            }
            foreach (array_keys($paths['array']) as $arrayKey) {
                unset($normalConfig[$arrayKey]);
            }
        }

        return $normalConfig;
    }
}
