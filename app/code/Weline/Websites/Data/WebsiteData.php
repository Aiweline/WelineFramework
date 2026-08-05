<?php

namespace Weline\Websites\Data;

use Weline\Currency\Api\CurrencyCatalogInterface;
use Weline\Framework\App\Localization\LocalizationProviderRegistry;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteCurrency;
use Weline\Websites\Model\WebsiteLanguage;

/**
 * 网站数据静态类
 * 提供当前请求命中的网站数据，供其他模块使用
 */
class WebsiteData
{
    private const STATE_KEY = 'websites.website_data.state.v1';

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
     * 设置当前网站数据
     * 
     * @param Website $website
     * @return void
     */
    public static function setWebsite(Website $website): void
    {
        $state = self::emptyState();
        // ORM models are mutable and may be reset/reused by ObjectManager. Keep
        // an independent request snapshot so later mutations cannot rewrite the
        // Website already selected for this request.
        $state['website'] = clone $website;
        self::writeState($state);
    }

    public static function resetRequestState(): void
    {
        RequestContext::remove(self::STATE_KEY);
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
     * 获取当前网站名称
     * 
     * @return string|null
     */
    public static function getName(): ?string
    {
        $website = self::getWebsite();
        return $website ? $website->getName() : null;
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
        $websiteCurrency = ObjectManager::getInstance(WebsiteCurrency::class);
        $currencyCodes = $websiteCurrency->getWebsiteCurrencyCodes($website->getWebsiteId());
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
        $websiteLanguage = ObjectManager::getInstance(WebsiteLanguage::class);
        $languageCodes = $websiteLanguage->getWebsiteLanguageCodes($website->getWebsiteId());
        self::writeCache('language_codes', $languageCodes);

        return $languageCodes;
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

        $currencyCodes = self::getCurrencyCodes();
        $activeCurrencies = self::currencyCatalog()->active();
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

        $data = [
            'website_id' => $website->getWebsiteId(),
            'code' => $website->getCode(),
            'name' => $website->getName(),
            'url' => $website->getUrl(),
            'default_currency' => $website->getDefaultCurrency(),
            'default_language' => $website->getDefaultLanguage(),
            'default_timezone' => $website->getDefaultTimezone(),
            'currency_codes' => self::getCurrencyCodes(),
            'language_codes' => self::getLanguageCodes(),
            'currencies' => self::getCurrencies(),
        ];

        self::writeCache('data', $data);
        return $data;
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
}
