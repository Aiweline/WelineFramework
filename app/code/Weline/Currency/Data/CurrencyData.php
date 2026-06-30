<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Currency\Data;

use Weline\Currency\Helper\CurrencyFormatter;
use Weline\Currency\Model\Currency;
use Weline\Currency\Model\Currency\LocalDescription;
use Weline\Framework\App\State;
use Weline\Framework\Manager\ObjectManager;

/**
 * 货币数据类
 * 
 * 提供静态方法获取货币信息，支持缓存
 */
class CurrencyData
{
    /**
     * 缓存时间（1小时）
     */
    private const CACHE_TTL = 3600;

    /**
     * 获取所有启用的货币
     * 
     * @return array 货币数组
     */
    public static function getCurrencies(): array
    {
        $localeCode = self::getLocaleCode();
        $cacheKey = 'all_currencies_' . $localeCode;
        $cache = w_cache('currency');
        
        $cached = $cache->get($cacheKey);
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }
        
        /** @var Currency $currencyModel */
        $currencyModel = ObjectManager::getInstance(Currency::class);
        
        $currencies = $currencyModel->clear()
            ->loadLocalDescription($localeCode, LocalDescription::class)
            ->where('main_table.' . Currency::schema_fields_STATUS, true)
            ->select()
            ->fetchArray();

        foreach ($currencies as &$currency) {
            $currency = self::applyLocalDescription($currency);
        }
        unset($currency);
        
        $cache->set($cacheKey, $currencies, self::CACHE_TTL);
        
        return $currencies;
    }

    /**
     * 获取指定货币信息
     * 
     * @param string $currencyCode 货币代码
     * @return array|null 货币信息数组
     */
    public static function getCurrency(string $currencyCode): ?array
    {
        $localeCode = self::getLocaleCode();
        $cacheKey = 'currency_' . strtoupper($currencyCode) . '_' . $localeCode;
        $cache = w_cache('currency');
        
        $cached = $cache->get($cacheKey);
        if ($cached !== false && is_array($cached) && !empty($cached)) {
            return $cached;
        }
        
        /** @var Currency $currencyModel */
        $currencyModel = ObjectManager::getInstance(Currency::class);
        
        $currency = $currencyModel->clear()
            ->loadLocalDescription($localeCode, LocalDescription::class)
            ->where('main_table.' . Currency::schema_fields_CODE, strtoupper($currencyCode))
            ->find()
            ->fetch();
        
        if (!$currency->getId()) {
            return null;
        }
        
        $data = self::applyLocalDescription($currency->getData());
        $cache->set($cacheKey, $data, self::CACHE_TTL);
        
        return $data;
    }

    /**
     * 格式化货币金额
     * 
     * @param float $amount 金额
     * @param string|null $currencyCode 货币代码
     * @return string 格式化后的金额
     */
    public static function formatAmount(float $amount, ?string $currencyCode = null): string
    {
        return CurrencyFormatter::format($amount, $currencyCode);
    }

    /**
     * 清除货币缓存
     * 
     * @return void
     */
    public static function clearCache(): void
    {
        w_cache('currency')->clear();
    }

    private static function getLocaleCode(): string
    {
        $localeCode = trim(State::getLang());

        return $localeCode !== '' ? $localeCode : 'zh_Hans_CN';
    }

    private static function applyLocalDescription(array $currency): array
    {
        $localName = trim((string)($currency['local_name'] ?? ''));
        if ($localName !== '') {
            $currency[Currency::schema_fields_NAME] = $localName;
        }

        return $currency;
    }
}

