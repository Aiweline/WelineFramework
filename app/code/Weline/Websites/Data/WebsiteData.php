<?php

namespace Weline\Websites\Data;

use Weline\Currency\Api\CurrencyCatalogInterface;
use Weline\Framework\App\Localization\LocalizationProviderRegistry;
use Weline\Framework\Manager\ObjectManager;
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
    /**
     * @var Website|null 当前网站实例
     */
    private static ?Website $website = null;

    /**
     * @var array|null 当前网站的完整数据
     */
    private static ?array $data = null;

    /**
     * @var array|null 关联货币代码列表
     */
    private static ?array $currencyCodes = null;

    /**
     * @var array|null 关联语言代码列表
     */
    private static ?array $languageCodes = null;

    /**
     * @var array|null 关联货币详细信息（包含format等）
     */
    private static ?array $currencies = null;

    /**
     * 设置当前网站数据
     * 
     * @param Website $website
     * @return void
     */
    public static function setWebsite(Website $website): void
    {
        self::$website = $website;
        self::$data = null;
        self::$currencyCodes = null;
        self::$languageCodes = null;
        self::$currencies = null;
    }

    public static function resetRequestState(): void
    {
        self::$website = null;
        self::$data = null;
        self::$currencyCodes = null;
        self::$languageCodes = null;
        self::$currencies = null;
    }

    /**
     * 获取当前网站实例
     * 
     * @return Website|null
     */
    public static function getWebsite(): ?Website
    {
        return self::$website;
    }

    /**
     * 获取当前网站ID
     * 
     * @return int|null
     */
    public static function getWebsiteId(): ?int
    {
        return self::$website ? self::$website->getWebsiteId() : null;
    }

    /**
     * 获取当前网站代码
     * 
     * @return string|null
     */
    public static function getCode(): ?string
    {
        return self::$website ? self::$website->getCode() : null;
    }

    /**
     * 获取当前网站名称
     * 
     * @return string|null
     */
    public static function getName(): ?string
    {
        return self::$website ? self::$website->getName() : null;
    }

    /**
     * 获取当前网站URL
     * 
     * @return string|null
     */
    public static function getUrl(): ?string
    {
        return self::$website ? self::$website->getUrl() : null;
    }

    /**
     * 获取默认货币代码
     * 
     * @return string|null
     */
    public static function getDefaultCurrency(): ?string
    {
        return self::$website ? self::$website->getDefaultCurrency() : null;
    }

    /**
     * 获取默认语言代码
     * 
     * @return string|null
     */
    public static function getDefaultLanguage(): ?string
    {
        return self::$website ? self::$website->getDefaultLanguage() : null;
    }

    /**
     * 获取默认时区
     * 
     * @return string|null
     */
    public static function getDefaultTimezone(): ?string
    {
        return self::$website ? self::$website->getDefaultTimezone() : null;
    }

    /**
     * 获取网站的关联货币代码列表
     * 
     * @return array
     */
    public static function getCurrencyCodes(): array
    {
        if (self::$currencyCodes !== null) {
            return self::$currencyCodes;
        }

        if (!self::$website || !self::$website->hasData(Website::schema_fields_ID)) {
            self::$currencyCodes = [];
            return self::$currencyCodes;
        }
        $websiteCurrency = ObjectManager::getInstance(WebsiteCurrency::class);
        self::$currencyCodes = $websiteCurrency->getWebsiteCurrencyCodes(self::$website->getWebsiteId());
        
        return self::$currencyCodes;
    }

    /**
     * 获取网站的关联语言代码列表
     * 
     * @return array
     */
    public static function getLanguageCodes(): array
    {
        if (self::$languageCodes !== null) {
            return self::$languageCodes;
        }

        if (!self::$website || !self::$website->hasData(Website::schema_fields_ID)) {
            self::$languageCodes = [];
            return self::$languageCodes;
        }
        $websiteLanguage = ObjectManager::getInstance(WebsiteLanguage::class);
        self::$languageCodes = $websiteLanguage->getWebsiteLanguageCodes(self::$website->getWebsiteId());
        
        return self::$languageCodes;
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
        if (self::$currencies !== null) {
            return self::$currencies;
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

        self::$currencies = [];
        foreach ($activeCurrencies as $currency) {
            self::$currencies[] = [
                'code' => $currency->code,
                'name' => $currency->name,
                'format' => $currency->format,
                'symbol' => $currency->symbol,
                'position' => $currency->position,
                'rate' => $currency->rate,
                'status' => $currency->active,
            ];
        }

        return self::$currencies;
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
        if (self::$data !== null) {
            return self::$data;
        }

        if (!self::$website) {
            return null;
        }

        self::$data = [
            'website_id' => self::$website->getWebsiteId(),
            'code' => self::$website->getCode(),
            'name' => self::$website->getName(),
            'url' => self::$website->getUrl(),
            'default_currency' => self::$website->getDefaultCurrency(),
            'default_language' => self::$website->getDefaultLanguage(),
            'default_timezone' => self::$website->getDefaultTimezone(),
            'currency_codes' => self::getCurrencyCodes(),
            'language_codes' => self::getLanguageCodes(),
            'currencies' => self::getCurrencies(),
        ];

        return self::$data;
    }

    /**
     * 重置所有数据（用于测试或清理）
     * 
     * @return void
     */
    public static function reset(): void
    {
        self::$website = null;
        self::$data = null;
        self::$currencyCodes = null;
        self::$languageCodes = null;
        self::$currencies = null;
    }
}
