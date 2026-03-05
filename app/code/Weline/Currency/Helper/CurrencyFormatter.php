<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Currency\Helper;

use Weline\Currency\Model\Currency;
use Weline\Framework\Manager\ObjectManager;

/**
 * 货币格式化Helper
 * 
 * 提供货币金额格式化的便捷方法
 */
class CurrencyFormatter
{
    /**
     * 格式化货币金额
     * 
     * @param float $amount 金额
     * @param string|null $currencyCode 货币代码，如果为null则使用默认货币
     * @return string 格式化后的金额字符串
     */
    public static function format(float $amount, ?string $currencyCode = null): string
    {
        $currency = self::getCurrency($currencyCode);
        
        if (!$currency) {
            return (string)$amount;
        }
        
        return $currency->formatAmount($amount);
    }

    /**
     * 获取货币对象
     * 
     * @param string|null $currencyCode 货币代码
     * @return Currency|null
     */
    private static function getCurrency(?string $currencyCode): ?Currency
    {
        /** @var Currency $currencyModel */
        $currencyModel = ObjectManager::getInstance(Currency::class);
        
        if ($currencyCode) {
            $currency = $currencyModel->clear()
                ->where(Currency::schema_fields_CODE, strtoupper($currencyCode))
                ->find()
                ->fetch();
            
            if ($currency->getId()) {
                return $currency;
            }
        }
        
        // 如果没有指定货币代码或找不到，返回默认货币（CNY）
        $currency = $currencyModel->clear()
            ->where(Currency::schema_fields_CODE, 'CNY')
            ->find()
            ->fetch();
        
        return $currency->getId() ? $currency : null;
    }
}

