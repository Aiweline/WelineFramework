<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Api\Helper;

use Weline\Framework\App\Exception;

/**
 * API模块配置助手类
 */
class Config extends \Weline\Backend\Model\Config
{
    // 配置键名
    const API_TOKEN_REFRESH_PERIOD = 'api_token_refresh_period';
    const API_TOKEN_REFRESH_BEFORE_EXPIRE = 'api_token_refresh_before_expire';
    const API_TOKEN_DEFAULT_EXPIRES_IN = 'api_token_default_expires_in';
    const API_REFRESH_TOKEN_DEFAULT_EXPIRES_IN = 'api_refresh_token_default_expires_in';

    const keys = [
        self::API_TOKEN_REFRESH_PERIOD,
        self::API_TOKEN_REFRESH_BEFORE_EXPIRE,
        self::API_TOKEN_DEFAULT_EXPIRES_IN,
        self::API_REFRESH_TOKEN_DEFAULT_EXPIRES_IN,
    ];

    private array $config = [];

    /**
     * 获取配置
     * @param string $key 配置键
     * @param string $module 模块名
     * @return string|array
     */
    function get(string $key = '', string $module = 'Weline_Api'): string|array
    {
        if (isset($this->config[$module])) {
            if ($key) {
                return $this->config[$module][$key] ?? $this->getDefault($key);
            } else {
                return $this->config[$module];
            }
        }

        // 从数据库加载配置
        $items = $this->systemConfig
            ->where('module', $module, '=', 'and')
            ->where('key', self::keys, '=', 'or')
            ->select()
            ->fetch()
            ->getItems();

        foreach ($items as $item) {
            $this->config[$module][$item->getKey()] = $item->getData('v');
        }

        // 确保所有配置项都有默认值
        foreach (self::keys as $configKey) {
            if (!isset($this->config[$module][$configKey])) {
                $this->config[$module][$configKey] = $this->getDefault($configKey);
            }
        }

        if ($key) {
            return $this->config[$module][$key] ?? $this->getDefault($key);
        }

        return $this->config[$module];
    }

    /**
     * 获取默认值
     * @param string $key
     * @return string
     */
    private function getDefault(string $key): string
    {
        $defaults = [
            self::API_TOKEN_REFRESH_PERIOD => '300', // 默认5分钟（300秒）刷新周期
            self::API_TOKEN_REFRESH_BEFORE_EXPIRE => '60', // 默认过期前60秒刷新（确保在过期前刷新）
            self::API_TOKEN_DEFAULT_EXPIRES_IN => '3600', // 默认1小时
            self::API_REFRESH_TOKEN_DEFAULT_EXPIRES_IN => '2592000', // 默认30天
        ];
        return $defaults[$key] ?? '';
    }

    /**
     * 设置配置
     * @param string|array $key 配置键或配置数组
     * @param string $data 配置值（当$key为数组时忽略）
     * @param string $module 模块名
     * @return static
     * @throws Exception
     */
    function set(string|array $key, string $data = '', string $module = 'Weline_Api'): static
    {
        if (is_array($key)) {
            // 批量设置
            foreach ($key as $k => $v) {
                if (in_array($k, self::keys)) {
                    try {
                        $this->set($k, (string)$v, $module);
                    } catch (Exception $e) {
                        throw $e;
                    }
                }
            }
            return $this;
        }

        // 验证配置值
        if (in_array($key, [self::API_TOKEN_REFRESH_PERIOD, self::API_TOKEN_REFRESH_BEFORE_EXPIRE, 
                            self::API_TOKEN_DEFAULT_EXPIRES_IN, self::API_REFRESH_TOKEN_DEFAULT_EXPIRES_IN])) {
            $intValue = (int)$data;
            if ($intValue < 0) {
                throw new Exception(__('配置值不能为负数：%{1}', [$key]));
            }
        }

        $this->setConfig($key, $data, $module);
        
        // 更新缓存
        if (isset($this->config[$module])) {
            $this->config[$module][$key] = $data;
        }
        
        return $this;
    }

    /**
     * 获取所有配置（带默认值）
     * @param string $module
     * @return array
     */
    function getAll(string $module = 'Weline_Api'): array
    {
        $config = $this->get('', $module);
        $result = [];
        foreach (self::keys as $key) {
            $result[$key] = $config[$key] ?? $this->getDefault($key);
        }
        return $result;
    }
}

