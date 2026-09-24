<?php

namespace Weline\Websites\Data;

use Weline\Currency\Api\CurrencyCatalogInterface;
use Weline\Framework\App\Localization\LocalizationProviderRegistry;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\Contract\NamespaceScopedCachePoolInterface;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\Website\LocalDescription as WebsiteLocalDescription;
use Weline\Websites\Model\WebsiteCurrency;
use Weline\Websites\Model\WebsiteLanguage;

/**
 * 网站数据静态类
 * 提供当前请求命中的网站数据，供其他模块使用
 */
class WebsiteData
{
    private const STATE_KEY = 'websites.website_data.state.v1';
    /** Request-scoped LocalDescription rows keyed by website_id (Fiber-safe). */
    private const LOCAL_ROWS_BAG_KEY = 'websites.website_local_rows.v1';
    private const SHARED_CACHE_TTL = 300;
    private const SHARED_SNAPSHOT_BY_ID_PREFIX = 'websites.snapshot.by_id.v1.';
    private const SHARED_SNAPSHOT_BY_CODE_PREFIX = 'websites.snapshot.by_code.v1.';
    private const MAX_PROCESS_SNAPSHOTS = 256;

    /** @var array<string, array{website: array<string, mixed>, currency_codes: list<string>, language_codes: list<string>, currencies: list<array<string, mixed>>}> */
    private static array $processSnapshotsById = [];
    /** @var array<string, array{website: array<string, mixed>, currency_codes: list<string>, language_codes: list<string>, currencies: list<array<string, mixed>>}> */
    private static array $processSnapshotsByCode = [];
    private static string $processSnapshotVersion = '';

    /**
     * @return array{
     *     website: Website|null,
     *     data: array<string, mixed>|null,
     *     currency_codes: array<int, string>|null,
     *     language_codes: array<int, string>|null,
     *     currencies: array<int, array<string, mixed>>|null
     * }
     */
    private static function emptyState(): array
    {
        return [
            'website' => null,
            'data' => null,
            'currency_codes' => null,
            'language_codes' => null,
            'currencies' => null,
        ];
    }

    /**
     * 设置当前网站数据。
     *
     * 命中后一次粘贴站字段与语/币关联进 RequestContext；同时写入共享缓存，
     * 供其它 WLS worker 先读缓存。普通读取走本快照；真要重载用 Website::load(..., forceReload: true)。
     */
    public static function setWebsite(Website $website): void
    {
        $state = self::emptyState();
        // ORM models are mutable and may be reset/reused by ObjectManager. Keep
        // an independent request snapshot so later mutations cannot rewrite the
        // Website already selected for this request.
        $state['website'] = clone $website;
        $websiteId = $website->hasData(Website::schema_fields_ID)
            ? (int)$website->getWebsiteId()
            : -1;
        $shared = $websiteId >= Website::ID_DEFAULT
            ? self::readSharedSnapshotById($websiteId)
            : null;
        if ($shared !== null && self::snapshotMatchesWebsite($shared, $website)) {
            // A complete snapshot is authoritative for this immutable request
            // scope, including an intentionally empty association list.
            $state['currency_codes'] = $shared['currency_codes'];
            $state['language_codes'] = $shared['language_codes'];
            $state['currencies'] = $shared['currencies'];
        } else {
            $state['currency_codes'] = $websiteId >= Website::ID_DEFAULT
                ? self::loadCurrencyCodesOnce($websiteId)
                : [];
            $state['language_codes'] = $websiteId >= Website::ID_DEFAULT
                ? self::loadLanguageCodesOnce($websiteId)
                : [];
            $state['currencies'] = self::buildCurrenciesFromCodes($state['currency_codes']);
        }
        $state['data'] = self::buildDataPayload($state);
        self::writeState($state);
        if ($shared === null || !self::snapshotMatchesWebsite($shared, $website)) {
            self::publishSharedSnapshot($state);
        }
    }

    public static function resetRequestState(): void
    {
        RequestContext::remove(self::STATE_KEY);
        RequestContext::remove(self::LOCAL_ROWS_BAG_KEY);
    }

    /**
     * 获取当前网站实例
     * 
     * @return Website|null
     */
    public static function getWebsite(): ?Website
    {
        return self::readState()['website'];
    }

    /**
     * 获取当前网站ID
     * 
     * @return int|null
     */
    public static function getWebsiteId(): ?int
    {
        $website = self::getWebsite();
        return $website ? $website->getWebsiteId() : null;
    }

    /**
     * 获取当前网站代码
     * 
     * @return string|null
     */
    public static function getCode(): ?string
    {
        $website = self::getWebsite();
        return $website ? $website->getCode() : null;
    }

    /**
     * 获取当前网站名称（优先当前语言 LocalDescription，回退主表）
     * 
     * @return string|null
     */
    public static function getName(): ?string
    {
        $website = self::getWebsite();
        if (!$website instanceof Website) {
            return null;
        }

        $localized = self::resolveLocalizedField((int)$website->getId(), WebsiteLocalDescription::schema_fields_NAME);
        if ($localized !== null && $localized !== '') {
            return $localized;
        }

        $name = trim((string)$website->getName());

        return $name !== '' ? $name : null;
    }

    /**
     * 指定语种站名（LocalDescription 优先，回退主表）。供 Organization.alternateName 等配置驱动用途。
     */
    public static function getNameForLocale(string $locale): ?string
    {
        $website = self::getWebsite();
        if (!$website instanceof Website) {
            return null;
        }
        $locale = trim($locale);
        if ($locale === '') {
            return self::getName();
        }

        $localized = self::resolveLocalizedFieldForLocale(
            (int)$website->getId(),
            WebsiteLocalDescription::schema_fields_NAME,
            $locale,
        );
        if ($localized !== null && $localized !== '') {
            return $localized;
        }

        $name = trim((string)$website->getName());

        return $name !== '' ? $name : null;
    }

    /**
     * 指定语种网站简介（LocalDescription 优先，回退主表）。供邮件壳等按邮件 locale 取品牌叙述。
     */
    public static function getDescriptionForLocale(string $locale): ?string
    {
        $website = self::getWebsite();
        if (!$website instanceof Website) {
            return null;
        }
        $locale = trim($locale);
        if ($locale === '') {
            return self::getDescription();
        }

        $localized = self::resolveLocalizedFieldForLocale(
            (int)$website->getId(),
            WebsiteLocalDescription::schema_fields_DESCRIPTION,
            $locale,
        );
        if ($localized !== null && $localized !== '') {
            return $localized;
        }

        $description = trim((string)$website->getDescription());

        return $description !== '' ? $description : null;
    }

    /**
     * Organization.alternateName：取「另一语种」已配置站名（非当前展示名）。
     * 优先 en_US ↔ 主表/中文源；无其它语种配置则返回 null（禁止硬编码品牌字）。
     */
    public static function getOrganizationAlternateName(string $currentDisplayName = ''): ?string
    {
        $website = self::getWebsite();
        if (!$website instanceof Website) {
            return null;
        }

        $current = trim($currentDisplayName);
        if ($current === '') {
            $current = trim((string)(self::getName() ?? ''));
        }

        $candidates = [];
        $currentLocale = '';
        try {
            $currentLocale = trim((string)\Weline\Framework\Http\Cookie::getLang());
        } catch (\Throwable) {
            $currentLocale = '';
        }

        $preferLocales = [];
        $normalized = strtolower(str_replace('_', '-', $currentLocale));
        if ($normalized === '' || str_starts_with($normalized, 'zh')) {
            $preferLocales[] = 'en_US';
            $preferLocales[] = 'en';
        } else {
            $preferLocales[] = 'zh_Hans_CN';
            $preferLocales[] = 'zh_CN';
            $preferLocales[] = 'zh_Hant_TW';
        }

        foreach ($preferLocales as $locale) {
            $name = self::resolveLocalizedFieldForLocale(
                (int)$website->getId(),
                WebsiteLocalDescription::schema_fields_NAME,
                $locale,
            );
            if ($name !== null && $name !== '') {
                $candidates[] = $name;
            }
        }

        $mainName = trim((string)$website->getName());
        if ($mainName !== '') {
            $candidates[] = $mainName;
        }

        foreach (self::listConfiguredLocalNames((int)$website->getId()) as $name) {
            $candidates[] = $name;
        }

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '' || $candidate === $current) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * 获取当前网站简介（网站范围品牌/SEO 叙述；优先当前语言 LocalDescription）
     */
    public static function getDescription(): ?string
    {
        $website = self::getWebsite();
        if (!$website instanceof Website) {
            return null;
        }

        $localized = self::resolveLocalizedField(
            (int)$website->getId(),
            WebsiteLocalDescription::schema_fields_DESCRIPTION,
        );
        if ($localized !== null && $localized !== '') {
            return $localized;
        }

        $description = trim((string)$website->getDescription());

        return $description !== '' ? $description : null;
    }

    private static function resolveLocalizedField(int $websiteId, string $field): ?string
    {
        $locale = '';
        try {
            $locale = trim((string)\Weline\Framework\Http\Cookie::getLang());
        } catch (\Throwable) {
            $locale = '';
        }
        if ($locale === '') {
            return null;
        }

        return self::resolveLocalizedFieldForLocale($websiteId, $field, $locale);
    }

    private static function resolveLocalizedFieldForLocale(int $websiteId, string $field, string $locale): ?string
    {
        if ($websiteId < 0 || $field === '' || trim($locale) === '') {
            return null;
        }
        $locale = trim($locale);
        foreach (self::loadLocalRowsOnce($websiteId) as $row) {
            if (($row['local_code'] ?? '') !== $locale) {
                continue;
            }
            $value = trim((string)($row[$field] ?? ''));

            return $value !== '' ? $value : null;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function listConfiguredLocalNames(int $websiteId): array
    {
        if ($websiteId < 0) {
            return [];
        }
        $names = [];
        foreach (self::loadLocalRowsOnce($websiteId) as $row) {
            $value = trim((string)($row[WebsiteLocalDescription::schema_fields_NAME] ?? ''));
            if ($value !== '') {
                $names[] = $value;
            }
        }

        return $names;
    }

    /**
     * Load all LocalDescription rows for a website once per request (RequestContext bag).
     * SeoHead / seo::body / seo::footer previously each hit `WHERE website_id=?` separately.
     *
     * @return list<array{local_code: string, name: string, description: string}>
     */
    private static function loadLocalRowsOnce(int $websiteId): array
    {
        if ($websiteId < 0) {
            return [];
        }

        $bag = RequestContext::get(self::LOCAL_ROWS_BAG_KEY);
        if (!\is_array($bag)) {
            $bag = [];
        }
        $idKey = (string)$websiteId;
        if (\array_key_exists($idKey, $bag) && \is_array($bag[$idKey])) {
            /** @var list<array{local_code: string, name: string, description: string}> $hit */
            $hit = $bag[$idKey];

            return $hit;
        }

        $rows = self::fetchLocalRowsFromDb($websiteId);
        $bag[$idKey] = $rows;
        RequestContext::set(self::LOCAL_ROWS_BAG_KEY, $bag);

        return $rows;
    }

    /**
     * @return list<array{local_code: string, name: string, description: string}>
     */
    private static function fetchLocalRowsFromDb(int $websiteId): array
    {
        try {
            /** @var WebsiteLocalDescription $local */
            $local = ObjectManager::getInstance(WebsiteLocalDescription::class);
            $items = $local->reset()
                ->where(WebsiteLocalDescription::schema_fields_ID, $websiteId)
                ->select()
                ->fetch()
                ->getItems();
            $rows = [];
            foreach ($items as $item) {
                if (!$item instanceof WebsiteLocalDescription) {
                    continue;
                }
                $rows[] = [
                    'local_code' => trim((string)$item->getData(WebsiteLocalDescription::schema_fields_local_code)),
                    WebsiteLocalDescription::schema_fields_NAME => trim(
                        (string)$item->getData(WebsiteLocalDescription::schema_fields_NAME)
                    ),
                    WebsiteLocalDescription::schema_fields_DESCRIPTION => trim(
                        (string)$item->getData(WebsiteLocalDescription::schema_fields_DESCRIPTION)
                    ),
                ];
            }

            return $rows;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 获取当前网站URL
     * 
     * @return string|null
     */
    public static function getUrl(): ?string
    {
        $website = self::getWebsite();
        return $website ? $website->getUrl() : null;
    }

    /**
     * 获取默认货币代码
     * 
     * @return string|null
     */
    public static function getDefaultCurrency(): ?string
    {
        $website = self::getWebsite();
        return $website ? $website->getDefaultCurrency() : null;
    }

    /**
     * 获取默认语言代码
     * 
     * @return string|null
     */
    public static function getDefaultLanguage(): ?string
    {
        $website = self::getWebsite();
        return $website ? $website->getDefaultLanguage() : null;
    }

    /**
     * 获取默认时区
     * 
     * @return string|null
     */
    public static function getDefaultTimezone(): ?string
    {
        $website = self::getWebsite();
        return $website ? $website->getDefaultTimezone() : null;
    }

    /**
     * 获取网站的关联货币代码列表
     * 
     * @return array
     */
    public static function getCurrencyCodes(): array
    {
        $state = self::readState();
        if ($state['currency_codes'] !== null) {
            return $state['currency_codes'];
        }

        $website = $state['website'];
        if (!$website || !$website->hasData(Website::schema_fields_ID)) {
            self::writeCache('currency_codes', []);
            return [];
        }
        $currencyCodes = self::loadCurrencyCodesOnce($website->getWebsiteId());
        self::writeCache('currency_codes', $currencyCodes);

        return $currencyCodes;
    }

    /**
     * 获取网站的关联语言代码列表
     * 
     * @return array
     */
    public static function getLanguageCodes(): array
    {
        $state = self::readState();
        if ($state['language_codes'] !== null) {
            return $state['language_codes'];
        }

        $website = $state['website'];
        if (!$website || !$website->hasData(Website::schema_fields_ID)) {
            self::writeCache('language_codes', []);
            return [];
        }
        $languageCodes = self::loadLanguageCodesOnce($website->getWebsiteId());
        self::writeCache('language_codes', $languageCodes);

        return $languageCodes;
    }

    /** True when the current request already resolved the website language association, including empty. */
    public static function hasLanguageSnapshot(): bool
    {
        $state = self::readState();
        return $state['website'] instanceof Website && $state['language_codes'] !== null;
    }

    /** True when the current request already resolved the website currency association, including empty. */
    public static function hasCurrencySnapshot(): bool
    {
        $state = self::readState();
        return $state['website'] instanceof Website && $state['currency_codes'] !== null;
    }

    /**
     * 获取指定货币的format格式
     * 
     * @param string|null $currencyCode 货币代码，如果为null则使用默认货币
     * @return string|null 货币format格式，如 "1,0"，如果货币不存在则返回null
     */
    public static function getCurrencyFormat(?string $currencyCode = null): ?string
    {
        if ($currencyCode === null) {
            $currencyCode = self::getDefaultCurrency();
        }
        
        if (empty($currencyCode)) {
            return null;
        }
        
        $currencies = self::getCurrencies();
        foreach ($currencies as $currency) {
            if (strtoupper($currency['code']) === strtoupper($currencyCode)) {
                return $currency['format'] ?? null;
            }
        }
        
        return null;
    }

    /**
     * 获取指定货币的详细信息
     * 
     * @param string|null $currencyCode 货币代码，如果为null则使用默认货币
     * @return array|null 货币详细信息，包含code、name、format、symbol等，如果货币不存在则返回null
     */
    public static function getCurrency(?string $currencyCode = null): ?array
    {
        if ($currencyCode === null) {
            $currencyCode = self::getDefaultCurrency();
        }
        
        if (empty($currencyCode)) {
            return null;
        }
        
        $currencies = self::getCurrencies();
        foreach ($currencies as $currency) {
            if (strtoupper($currency['code']) === strtoupper($currencyCode)) {
                return $currency;
            }
        }
        
        return null;
    }

    /**
     * 获取指定货币的符号
     * 
     * @param string|null $currencyCode 货币代码，如果为null则使用默认货币
     * @return string|null 货币符号，如 "￥"、"$"，如果货币不存在则返回null
     */
    public static function getCurrencySymbol(?string $currencyCode = null): ?string
    {
        $currency = self::getCurrency($currencyCode);
        return $currency['symbol'] ?? null;
    }

    /**
     * 获取指定货币的符号位置
     * 
     * @param string|null $currencyCode 货币代码，如果为null则使用默认货币
     * @return string|null 货币符号位置，如 "left"、"right"，如果货币不存在则返回null
     */
    public static function getCurrencyPosition(?string $currencyCode = null): ?string
    {
        $currency = self::getCurrency($currencyCode);
        return $currency['position'] ?? null;
    }

    /**
     * 获取指定货币的汇率
     * 
     * @param string|null $currencyCode 货币代码，如果为null则使用默认货币
     * @return float|null 货币汇率，如果货币不存在则返回null
     */
    public static function getCurrencyRate(?string $currencyCode = null): ?float
    {
        $currency = self::getCurrency($currencyCode);
        return $currency['rate'] ?? null;
    }

    /**
     * 获取关联货币的详细信息（包含format、symbol等）
     * 
     * @return array 格式：[['code' => 'CNY', 'name' => '人民币', 'format' => '1,0', 'symbol' => '￥', ...], ...]
     */
    public static function getCurrencies(): array
    {
        $state = self::readState();
        if ($state['currencies'] !== null) {
            return $state['currencies'];
        }

        $currencies = self::buildCurrenciesFromCodes(self::getCurrencyCodes());
        self::writeCache('currencies', $currencies);
        return $currencies;
    }

    /**
     * 验证货币代码是否允许
     * 
     * @param string $currencyCode 货币代码
     * @return bool
     */
    public static function isCurrencyAllowed(string $currencyCode): bool
    {
        $currencyCodes = self::getCurrencyCodes();
        
        // 如果没有限定关联货币，检查货币表中是否存在且启用
        if (empty($currencyCodes)) {
            $currencyCode = strtoupper($currencyCode);
            foreach (self::getCurrencies() as $currency) {
                if (strtoupper((string)($currency['code'] ?? '')) === $currencyCode) {
                    return true;
                }
            }
            return false;
        }
        
        // 如果限定了关联货币，只允许这些货币
        return in_array(strtoupper($currencyCode), array_map('strtoupper', $currencyCodes));
    }

    private static function currencyCatalog(): CurrencyCatalogInterface
    {
        $catalog = ObjectManager::getInstance(RuntimeProviderResolver::class)
            ->resolve(CurrencyCatalogInterface::class);
        if (!$catalog instanceof CurrencyCatalogInterface) {
            throw new \RuntimeException('Weline_Currency catalog provider is unavailable.');
        }

        return $catalog;
    }

    /**
     * 验证语言代码是否允许
     * 
     * @param string $languageCode 语言代码
     * @return bool
     */
    public static function isLanguageAllowed(string $languageCode): bool
    {
        $languageCodes = self::getLanguageCodes();
        
        // 没有网站级限制时，交给已注册的本地化 Provider 验证安装/启用状态。
        if (empty($languageCodes)) {
            return ObjectManager::getInstance(LocalizationProviderRegistry::class)
                ->supportsLanguage($languageCode);
        }
        
        // 如果限定了关联语言，只允许这些语言
        return in_array($languageCode, $languageCodes);
    }

    /**
     * 获取当前网站的完整数据数组
     * 
     * @return array|null
     */
    public static function getData(): ?array
    {
        $state = self::readState();
        if ($state['data'] !== null) {
            return $state['data'];
        }

        $website = $state['website'];
        if (!$website) {
            return null;
        }

        $data = self::buildDataPayload([
            'website' => $website,
            'currency_codes' => self::getCurrencyCodes(),
            'language_codes' => self::getLanguageCodes(),
            'currencies' => self::getCurrencies(),
        ]);

        self::writeCache('data', $data);
        return $data;
    }

    /**
     * 当前请求站的行快照（供 load / w_query 普通路径复用）。
     *
     * @return array<string, mixed>|null
     */
    public static function getRowSnapshot(): ?array
    {
        $website = self::getWebsite();
        if (!$website instanceof Website || !$website->hasData(Website::schema_fields_ID)) {
            return null;
        }

        $row = $website->getData();
        return \is_array($row) ? $row : null;
    }

    public static function matchesWebsiteId(int $websiteId): bool
    {
        if ($websiteId < Website::ID_DEFAULT) {
            return false;
        }
        $current = self::getWebsiteId();
        return $current !== null && $current === $websiteId;
    }

    public static function matchesWebsiteCode(string $code): bool
    {
        $code = \trim($code);
        if ($code === '') {
            return false;
        }
        $current = (string)(self::getCode() ?? '');
        return $current !== '' && \hash_equals($current, $code);
    }

    /**
     * @return array{website: array<string, mixed>, currency_codes: list<string>, language_codes: list<string>, currencies: list<array<string, mixed>>}|null
     */
    public static function readSharedSnapshotById(int $websiteId): ?array
    {
        if ($websiteId < Website::ID_DEFAULT) {
            return null;
        }
        self::syncProcessSnapshotVersion();
        $key = (string)$websiteId;
        if (isset(self::$processSnapshotsById[$key])) {
            return self::$processSnapshotsById[$key];
        }
        try {
            $snapshot = self::normalizeSharedSnapshot(
                self::sharedCache()->get(self::SHARED_SNAPSHOT_BY_ID_PREFIX . $websiteId),
            );
        } catch (\Throwable) {
            return null;
        }
        if ($snapshot !== null) {
            self::rememberProcessSnapshot(self::$processSnapshotsById, $key, $snapshot);
        }
        return $snapshot;
    }

    /**
     * @return array{website: array<string, mixed>, currency_codes: list<string>, language_codes: list<string>, currencies: list<array<string, mixed>>}|null
     */
    public static function readSharedSnapshotByCode(string $code): ?array
    {
        $code = \trim($code);
        if ($code === '') {
            return null;
        }
        self::syncProcessSnapshotVersion();
        $key = \sha1($code);
        if (isset(self::$processSnapshotsByCode[$key])) {
            return self::$processSnapshotsByCode[$key];
        }
        try {
            $snapshot = self::normalizeSharedSnapshot(
                self::sharedCache()->get(self::SHARED_SNAPSHOT_BY_CODE_PREFIX . $key),
            );
        } catch (\Throwable) {
            return null;
        }
        if ($snapshot !== null) {
            self::rememberProcessSnapshot(self::$processSnapshotsByCode, $key, $snapshot);
        }
        return $snapshot;
    }

    /** Clear immutable website snapshots after a changed event or worker reset. */
    public static function clearProcessCache(): void
    {
        self::$processSnapshotsById = [];
        self::$processSnapshotsByCode = [];
        self::$processSnapshotVersion = '';
    }

    /**
     * 重置所有数据（用于测试或清理）
     * 
     * @return void
     */
    public static function reset(): void
    {
        self::resetRequestState();
    }

    /**
     * @return array{
     *     website: Website|null,
     *     data: array<string, mixed>|null,
     *     currency_codes: array<int, string>|null,
     *     language_codes: array<int, string>|null,
     *     currencies: array<int, array<string, mixed>>|null
     * }
     */
    private static function readState(): array
    {
        $state = RequestContext::get(self::STATE_KEY);
        if (!\is_array($state)) {
            return self::emptyState();
        }

        return [
            'website' => ($state['website'] ?? null) instanceof Website ? $state['website'] : null,
            'data' => \is_array($state['data'] ?? null) ? $state['data'] : null,
            'currency_codes' => \is_array($state['currency_codes'] ?? null) ? $state['currency_codes'] : null,
            'language_codes' => \is_array($state['language_codes'] ?? null) ? $state['language_codes'] : null,
            'currencies' => \is_array($state['currencies'] ?? null) ? $state['currencies'] : null,
        ];
    }

    /**
     * @param array{
     *     website: Website|null,
     *     data: array<string, mixed>|null,
     *     currency_codes: array<int, string>|null,
     *     language_codes: array<int, string>|null,
     *     currencies: array<int, array<string, mixed>>|null
     * } $state
     */
    private static function writeState(array $state): void
    {
        RequestContext::set(self::STATE_KEY, $state);
    }

    private static function writeCache(string $key, array $value): void
    {
        $state = self::readState();
        if (!\array_key_exists($key, $state) || $key === 'website') {
            throw new \LogicException('Unknown WebsiteData request cache: ' . $key);
        }
        $state[$key] = $value;
        self::writeState($state);
    }

    /** @return list<string> */
    private static function loadCurrencyCodesOnce(int $websiteId): array
    {
        try {
            $websiteCurrency = ObjectManager::getInstance(WebsiteCurrency::class);
            $codes = $websiteCurrency->getWebsiteCurrencyCodes($websiteId);
            return \is_array($codes) ? \array_values($codes) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return list<string> */
    private static function loadLanguageCodesOnce(int $websiteId): array
    {
        try {
            $websiteLanguage = ObjectManager::getInstance(WebsiteLanguage::class);
            $codes = $websiteLanguage->getWebsiteLanguageCodes($websiteId);
            return \is_array($codes) ? \array_values($codes) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param list<string>|array<int, string> $currencyCodes
     * @return list<array<string, mixed>>
     */
    private static function buildCurrenciesFromCodes(array $currencyCodes): array
    {
        try {
            $activeCurrencies = self::currencyCatalog()->active();
        } catch (\Throwable) {
            return [];
        }

        if ($currencyCodes !== []) {
            $activeByCode = [];
            foreach ($activeCurrencies as $currency) {
                $activeByCode[strtoupper($currency->code)] = $currency;
            }

            $activeCurrencies = [];
            foreach ($currencyCodes as $code) {
                $currency = $activeByCode[strtoupper((string)$code)] ?? null;
                if ($currency !== null) {
                    $activeCurrencies[] = $currency;
                }
            }
        }

        $currencies = [];
        foreach ($activeCurrencies as $currency) {
            $currencies[] = [
                'code' => $currency->code,
                'name' => $currency->name,
                'format' => $currency->format,
                'symbol' => $currency->symbol,
                'position' => $currency->position,
                'rate' => $currency->rate,
                'status' => $currency->active,
            ];
        }

        return $currencies;
    }

    /**
     * @param array{
     *     website?: Website|null,
     *     currency_codes?: array<int, string>|null,
     *     language_codes?: array<int, string>|null,
     *     currencies?: array<int, array<string, mixed>>|null
     * } $state
     * @return array<string, mixed>
     */
    private static function buildDataPayload(array $state): array
    {
        $website = $state['website'] ?? null;
        if (!$website instanceof Website) {
            return [];
        }

        return [
            'website_id' => $website->getWebsiteId(),
            'code' => $website->getCode(),
            'name' => $website->getName(),
            'description' => $website->getDescription(),
            'url' => $website->getUrl(),
            'default_currency' => $website->getDefaultCurrency(),
            'default_language' => $website->getDefaultLanguage(),
            'default_timezone' => $website->getDefaultTimezone(),
            'currency_codes' => \is_array($state['currency_codes'] ?? null) ? $state['currency_codes'] : [],
            'language_codes' => \is_array($state['language_codes'] ?? null) ? $state['language_codes'] : [],
            'currencies' => \is_array($state['currencies'] ?? null) ? $state['currencies'] : [],
        ];
    }

    /**
     * @param array{
     *     website: Website|null,
     *     data: array<string, mixed>|null,
     *     currency_codes: array<int, string>|null,
     *     language_codes: array<int, string>|null,
     *     currencies: array<int, array<string, mixed>>|null
     * } $state
     */
    private static function publishSharedSnapshot(array $state): void
    {
        $website = $state['website'] ?? null;
        if (!$website instanceof Website || !$website->hasData(Website::schema_fields_ID)) {
            return;
        }
        $row = $website->getData();
        if (!\is_array($row)) {
            return;
        }
        $websiteId = (int)$website->getWebsiteId();
        $code = \trim((string)$website->getCode());
        $payload = [
            'website' => $row,
            'currency_codes' => \is_array($state['currency_codes'] ?? null) ? \array_values($state['currency_codes']) : [],
            'language_codes' => \is_array($state['language_codes'] ?? null) ? \array_values($state['language_codes']) : [],
            'currencies' => \is_array($state['currencies'] ?? null) ? \array_values($state['currencies']) : [],
        ];
        self::syncProcessSnapshotVersion();
        self::rememberProcessSnapshot(self::$processSnapshotsById, (string)$websiteId, $payload);
        if ($code !== '') {
            self::rememberProcessSnapshot(self::$processSnapshotsByCode, \sha1($code), $payload);
        }
        try {
            $cache = self::sharedCache();
            $cache->set(self::SHARED_SNAPSHOT_BY_ID_PREFIX . $websiteId, $payload, self::SHARED_CACHE_TTL);
            if ($code !== '') {
                $cache->set(self::SHARED_SNAPSHOT_BY_CODE_PREFIX . \sha1($code), $payload, self::SHARED_CACHE_TTL);
            }
        } catch (\Throwable) {
            // Shared cache is an accelerator; request snapshot remains authoritative.
        }
    }

    private static function sharedCache(): CachePoolInterface
    {
        $cache = w_cache('website_detect');
        return $cache instanceof NamespaceScopedCachePoolInterface
            ? $cache->withNamespace('global/websites-registry')
            : $cache;
    }

    /**
     * @return array{website: array<string, mixed>, currency_codes: list<string>, language_codes: list<string>, currencies: list<array<string, mixed>>}|null
     */
    private static function normalizeSharedSnapshot(mixed $cached): ?array
    {
        if (!\is_array($cached) || !\is_array($cached['website'] ?? null)) {
            return null;
        }
        return [
            'website' => $cached['website'],
            'currency_codes' => \is_array($cached['currency_codes'] ?? null)
                ? \array_values($cached['currency_codes'])
                : [],
            'language_codes' => \is_array($cached['language_codes'] ?? null)
                ? \array_values($cached['language_codes'])
                : [],
            'currencies' => \is_array($cached['currencies'] ?? null)
                ? \array_values($cached['currencies'])
                : [],
        ];
    }

    /** @param array<string, array{website: array<string, mixed>, currency_codes: list<string>, language_codes: list<string>, currencies: list<array<string, mixed>>}> $cache */
    private static function rememberProcessSnapshot(array &$cache, string $key, array $snapshot): void
    {
        if (!isset($cache[$key]) && count($cache) >= self::MAX_PROCESS_SNAPSHOTS) {
            $first = array_key_first($cache);
            if ($first !== null) {
                unset($cache[$first]);
            }
        }
        $cache[$key] = $snapshot;
    }

    private static function syncProcessSnapshotVersion(): void
    {
        try {
            $version = Url::websiteParserSitesVersion();
        } catch (\Throwable) {
            $version = '';
        }
        $version = $version !== '' ? $version : '0';
        if (self::$processSnapshotVersion !== ''
            && !hash_equals(self::$processSnapshotVersion, $version)
        ) {
            self::$processSnapshotsById = [];
            self::$processSnapshotsByCode = [];
        }
        self::$processSnapshotVersion = $version;
    }

    /** @param array{website: array<string, mixed>, currency_codes: list<string>, language_codes: list<string>, currencies: list<array<string, mixed>>} $snapshot */
    private static function snapshotMatchesWebsite(array $snapshot, Website $website): bool
    {
        $row = $snapshot['website'];
        return (int)($row[Website::schema_fields_ID] ?? -1) === $website->getWebsiteId()
            && (string)($row[Website::schema_fields_CODE] ?? '') === $website->getCode()
            && (string)($row[Website::schema_fields_DEFAULT_CURRENCY] ?? '') === $website->getDefaultCurrency()
            && (string)($row[Website::schema_fields_DEFAULT_LANGUAGE] ?? '') === $website->getDefaultLanguage()
            && (string)($row[Website::schema_fields_DEFAULT_TIMEZONE] ?? '') === $website->getDefaultTimezone();
    }
}
