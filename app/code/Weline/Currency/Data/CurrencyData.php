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
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Cache\CacheFactory;
use Weline\Framework\Manager\ObjectManager;

/**
 * 货币数据类
 * 
 * 提供静态方法获取货币信息，支持缓存
 */
class CurrencyData
{
    /**
     * 缓存标识
     */
    private const CACHE_TAG = 'currency_data';
    
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
        $cacheKey = self::CACHE_TAG . '_all_currencies';
        $cache = self::getCache();
        
        $cached = $cache->get($cacheKey);
        if ($cached !== false) {
            return $cached;
        }
        
        /** @var Currency $currencyModel */
        $currencyModel = ObjectManager::getInstance(Currency::class);
        
        $currencies = $currencyModel->clear()
            ->where(Currency::fields_STATUS, true)
            ->select()
            ->fetchArray();
        
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
        $cacheKey = self::CACHE_TAG . '_currency_' . strtoupper($currencyCode);
        $cache = self::getCache();
        
        $cached = $cache->get($cacheKey);
        if ($cached !== false) {
            return $cached;
        }
        
        /** @var Currency $currencyModel */
        $currencyModel = ObjectManager::getInstance(Currency::class);
        
        $currency = $currencyModel->clear()
            ->where(Currency::fields_CODE, strtoupper($currencyCode))
            ->find()
            ->fetch();
        
        if (!$currency->getId()) {
            return null;
        }
        
        $data = $currency->getData();
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
        $cache = self::getCache();
        $cache->clean([self::CACHE_TAG]);
    }

    /**
     * 获取缓存实例
     * 
     * @return CacheInterface
     */
    private static function getCache(): CacheInterface
    {
        $cacheFactory = new CacheFactory(self::CACHE_TAG, __('货币数据缓存'), false);
        return $cacheFactory->create();
    }
}

