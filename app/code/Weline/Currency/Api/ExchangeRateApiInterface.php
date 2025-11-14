<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Currency\Api;

/**
 * 汇率API服务接口
 * 
 * 定义统一的汇率API接口规范，所有汇率API实现类必须实现此接口
 */
interface ExchangeRateApiInterface
{
    /**
     * 获取实时汇率
     * 
     * @param string $baseCurrency 基准货币代码（如：CNY, USD）
     * @param array $targetCurrencies 目标货币代码数组（如：['USD', 'EUR']），为空则获取所有货币
     * @return array 返回汇率数组，格式：['USD' => 7.2, 'EUR' => 7.8]
     * @throws \Exception 当API调用失败时抛出异常
     */
    public function getExchangeRates(string $baseCurrency, array $targetCurrencies = []): array;

    /**
     * 获取单个货币的汇率
     * 
     * @param string $baseCurrency 基准货币代码
     * @param string $targetCurrency 目标货币代码
     * @return float 汇率值
     * @throws \Exception 当API调用失败时抛出异常
     */
    public function getExchangeRate(string $baseCurrency, string $targetCurrency): float;

    /**
     * 获取支持的货币列表
     * 
     * @return array 返回货币代码数组，格式：['USD', 'EUR', 'CNY', ...]
     * @throws \Exception 当API调用失败时抛出异常
     */
    public function getSupportedCurrencies(): array;

    /**
     * 获取货币详细信息（如果API支持）
     * 
     * @param string $currencyCode 货币代码
     * @return array|null 返回货币信息数组，包含：code, name, symbol, position等，如果API不支持则返回null
     */
    public function getCurrencyInfo(string $currencyCode): ?array;

    /**
     * 测试API连接
     * 
     * @return bool 连接成功返回true，失败返回false
     */
    public function testConnection(): bool;

    /**
     * 获取API提供者名称
     * 
     * @return string API提供者名称（如：exchangerate-api）
     */
    public function getProviderName(): string;
}

