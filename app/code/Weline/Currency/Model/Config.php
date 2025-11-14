<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Currency\Model;

use Weline\Backend\Model\Config as BackendConfig;
use Weline\Framework\Manager\ObjectManager;

/**
 * 货币模块配置模型
 * 
 * 管理货币模块的配置项
 */
class Config
{
    /**
     * 配置键名
     */
    public const KEY_IMPORT_ENABLED = 'import_enabled';
    public const KEY_IMPORT_PROVIDER = 'import_provider';
    public const KEY_IMPORT_API_KEY = 'import_api_key';
    public const KEY_IMPORT_CRON_TIME = 'import_cron_time';
    public const KEY_BASE_CURRENCY = 'base_currency';
    public const KEY_LAST_IMPORT_TIME = 'last_import_time';

    /**
     * 模块名称
     */
    private const MODULE = 'Weline_Currency';

    /**
     * @var BackendConfig
     */
    private BackendConfig $backendConfig;

    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->backendConfig = ObjectManager::getInstance(BackendConfig::class);
    }

    /**
     * 获取配置值
     * 
     * @param string $key 配置键
     * @param mixed $default 默认值
     * @return mixed
     */
    private function get(string $key, $default = null)
    {
        return $this->backendConfig->getConfig($key, self::MODULE) ?? $default;
    }

    /**
     * 设置配置值
     * 
     * @param string $key 配置键
     * @param mixed $value 配置值
     * @return bool
     * @throws \Weline\Framework\App\Exception
     */
    private function set(string $key, $value): bool
    {
        return $this->backendConfig->setConfig($key, (string)$value, self::MODULE);
    }

    /**
     * 是否启用自动导入
     * 
     * @return bool
     */
    public function isImportEnabled(): bool
    {
        return (bool)$this->get(self::KEY_IMPORT_ENABLED, false);
    }

    /**
     * 设置是否启用自动导入
     * 
     * @param bool $enabled
     * @return bool
     * @throws \Weline\Framework\App\Exception
     */
    public function setImportEnabled(bool $enabled): bool
    {
        return $this->set(self::KEY_IMPORT_ENABLED, $enabled ? '1' : '0');
    }

    /**
     * 获取导入渠道
     * 
     * @return string
     */
    public function getImportProvider(): string
    {
        return $this->get(self::KEY_IMPORT_PROVIDER, 'exchangerate-api');
    }

    /**
     * 设置导入渠道
     * 
     * @param string $provider
     * @return bool
     * @throws \Weline\Framework\App\Exception
     */
    public function setImportProvider(string $provider): bool
    {
        return $this->set(self::KEY_IMPORT_PROVIDER, $provider);
    }

    /**
     * 获取API密钥
     * 
     * @return string|null
     */
    public function getImportApiKey(): ?string
    {
        return $this->get(self::KEY_IMPORT_API_KEY);
    }

    /**
     * 设置API密钥
     * 
     * @param string|null $apiKey
     * @return bool
     * @throws \Weline\Framework\App\Exception
     */
    public function setImportApiKey(?string $apiKey): bool
    {
        return $this->set(self::KEY_IMPORT_API_KEY, $apiKey ?? '');
    }

    /**
     * 获取Cron执行时间表达式
     * 
     * @return string
     */
    public function getImportCronTime(): string
    {
        return $this->get(self::KEY_IMPORT_CRON_TIME, '0 2 * * *'); // 默认每天凌晨2点
    }

    /**
     * 设置Cron执行时间表达式
     * 
     * @param string $cronTime
     * @return bool
     * @throws \Weline\Framework\App\Exception
     */
    public function setImportCronTime(string $cronTime): bool
    {
        return $this->set(self::KEY_IMPORT_CRON_TIME, $cronTime);
    }

    /**
     * 获取基准货币代码
     * 
     * @return string
     */
    public function getBaseCurrency(): string
    {
        return $this->get(self::KEY_BASE_CURRENCY, 'CNY');
    }

    /**
     * 设置基准货币代码
     * 
     * @param string $baseCurrency
     * @return bool
     * @throws \Weline\Framework\App\Exception
     */
    public function setBaseCurrency(string $baseCurrency): bool
    {
        return $this->set(self::KEY_BASE_CURRENCY, $baseCurrency);
    }

    /**
     * 获取最后导入时间
     * 
     * @return int|null 时间戳
     */
    public function getLastImportTime(): ?int
    {
        $time = $this->get(self::KEY_LAST_IMPORT_TIME);
        return $time ? (int)$time : null;
    }

    /**
     * 设置最后导入时间
     * 
     * @param int|null $timestamp 时间戳
     * @return bool
     * @throws \Weline\Framework\App\Exception
     */
    public function setLastImportTime(?int $timestamp): bool
    {
        return $this->set(self::KEY_LAST_IMPORT_TIME, $timestamp ?? time());
    }
}

