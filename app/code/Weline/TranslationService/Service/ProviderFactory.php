<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\TranslationService\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\App\Exception;
use Weline\TranslationService\Api\ProviderInterface;

/**
 * 翻译渠道工厂类
 * 
 * 负责创建和管理翻译渠道适配器实例
 */
class ProviderFactory
{
    /**
     * 渠道适配器映射
     * 
     * @var array
     */
    private array $adapterMap = [
        'google' => \Weline\TranslationService\Provider\GoogleProvider::class,
        'baidu' => \Weline\TranslationService\Provider\BaiduProvider::class,
        'deepl' => \Weline\TranslationService\Provider\DeepLProvider::class,
        'microsoft' => \Weline\TranslationService\Provider\MicrosoftProvider::class,
        'youdao' => \Weline\TranslationService\Provider\YoudaoProvider::class,
        'tencent' => \Weline\TranslationService\Provider\TencentProvider::class,
    ];

    /**
     * 已创建的适配器实例缓存
     * 
     * @var array
     */
    private array $instances = [];

    /**
     * 创建渠道适配器
     * 
     * @param string $providerCode 渠道代码
     * @return ProviderInterface|null
     */
    public function create(string $providerCode): ?ProviderInterface
    {
        $providerCode = strtolower($providerCode);
        
        // 检查缓存
        if (isset($this->instances[$providerCode])) {
            return $this->instances[$providerCode];
        }

        // 检查是否支持该渠道
        if (!isset($this->adapterMap[$providerCode])) {
            return null;
        }

        $adapterClass = $this->adapterMap[$providerCode];
        
        // 检查类是否存在
        if (!class_exists($adapterClass)) {
            return null;
        }

        try {
            // 创建适配器实例
            $adapter = ObjectManager::getInstance($adapterClass);
            
            // 验证是否实现了接口
            if (!$adapter instanceof ProviderInterface) {
                return null;
            }

            // 缓存实例
            $this->instances[$providerCode] = $adapter;
            
            return $adapter;
        } catch (\Exception $e) {
            error_log('Failed to create translation provider: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 获取所有支持的渠道代码
     * 
     * @return array
     */
    public function getSupportedProviders(): array
    {
        return array_keys($this->adapterMap);
    }

    /**
     * 注册自定义渠道适配器
     * 
     * @param string $providerCode 渠道代码
     * @param string $adapterClass 适配器类名
     * @return void
     * @throws Exception
     */
    public function register(string $providerCode, string $adapterClass): void
    {
        if (!class_exists($adapterClass)) {
            throw new Exception(__('适配器类不存在：%{1}', [$adapterClass]));
        }

        if (!is_subclass_of($adapterClass, ProviderInterface::class)) {
            throw new Exception(__('适配器类必须实现ProviderInterface接口：%{1}', [$adapterClass]));
        }

        $this->adapterMap[strtolower($providerCode)] = $adapterClass;
    }
}

