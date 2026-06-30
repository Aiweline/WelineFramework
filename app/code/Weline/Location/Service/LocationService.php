<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Location\Service;

use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;
use Weline\Location\Service\Provider\LocationProviderInterface;
use Weline\Location\Service\Provider\LocalProvider;
use Weline\Location\Service\Provider\IpApiComProvider;
use Weline\Location\Service\Provider\GeojsProvider;
use Weline\Location\Service\Provider\IpwhoisProvider;
use Weline\Location\Service\Provider\IpinfoProvider;
use Weline\Location\Service\Provider\IpapiCoProvider;

/**
 * Geo定位服务管理器
 * 
 * 管理所有定位服务提供者，实现fallback机制
 */
class LocationService
{
    /**
     * 提供者实例缓存
     * 
     * @var array
     */
    private array $providers = [];

    /**
     * 配置
     * 
     * @var array
     */
    private array $config = [];

    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->loadConfig();
        $this->initializeProviders();
    }

    /**
     * 加载配置
     */
    private function loadConfig(): void
    {
        $this->config = Env::module_env('Weline_Location', 'location') ?? [];
        
        // 默认配置
        $defaultConfig = [
            'providers' => [
                'local' => [
                    'enabled' => true,
                    'priority' => 0
                ],
                'ip-api.com' => [
                    'enabled' => true,
                    'priority' => 1,
                    'timeout' => 5
                ],
                'geojs.io' => [
                    'enabled' => true,
                    'priority' => 2,
                    'timeout' => 5
                ],
                'ipwhois.app' => [
                    'enabled' => true,
                    'priority' => 3,
                    'timeout' => 5
                ],
                'ipinfo.io' => [
                    'enabled' => false,
                    'priority' => 4,
                    'api_key' => '',
                    'timeout' => 5
                ],
                'ipapi.co' => [
                    'enabled' => false,
                    'priority' => 5,
                    'api_key' => '',
                    'timeout' => 5
                ]
            ],
            'timeout' => 5,
            'retry' => 1
        ];

        $this->config = array_merge($defaultConfig, $this->config);
    }

    /**
     * 初始化提供者
     */
    private function initializeProviders(): void
    {
        $providersConfig = $this->config['providers'] ?? [];

        // 创建提供者映射
        $providerMap = [
            'local' => LocalProvider::class,
            'ip-api.com' => IpApiComProvider::class,
            'geojs.io' => GeojsProvider::class,
            'ipwhois.app' => IpwhoisProvider::class,
            'ipinfo.io' => IpinfoProvider::class,
            'ipapi.co' => IpapiCoProvider::class,
        ];

        foreach ($providerMap as $name => $class) {
            $config = $providersConfig[$name] ?? [];
            
            // 检查是否启用
            if (!($config['enabled'] ?? false)) {
                continue;
            }

            try {
                $provider = $this->createProvider($class, $config);
                if ($provider && $provider->isAvailable()) {
                    $this->providers[] = $provider;
                }
            } catch (\Exception $e) {
                // 静默失败，继续初始化其他提供者
                continue;
            }
        }

        // 按优先级排序
        usort($this->providers, function ($a, $b) {
            return $a->getPriority() <=> $b->getPriority();
        });
    }

    /**
     * 创建提供者实例
     * 
     * @param string $class 提供者类名
     * @param array $config 配置
     * @return LocationProviderInterface|null
     */
    private function createProvider(string $class, array $config): ?LocationProviderInterface
    {
        $timeout = $config['timeout'] ?? $this->config['timeout'] ?? 5;
        $apiKey = $config['api_key'] ?? null;

        // 根据类名创建不同的实例
        if ($class === LocalProvider::class) {
            return ObjectManager::getInstance(LocalProvider::class);
        } elseif ($class === IpApiComProvider::class) {
            return ObjectManager::getInstance(IpApiComProvider::class, ['timeout' => $timeout]);
        } elseif ($class === GeojsProvider::class) {
            return ObjectManager::getInstance(GeojsProvider::class, ['timeout' => $timeout]);
        } elseif ($class === IpwhoisProvider::class) {
            return ObjectManager::getInstance(IpwhoisProvider::class, ['timeout' => $timeout]);
        } elseif ($class === IpinfoProvider::class) {
            return ObjectManager::getInstance(IpinfoProvider::class, ['apiKey' => $apiKey, 'timeout' => $timeout]);
        } elseif ($class === IpapiCoProvider::class) {
            return ObjectManager::getInstance(IpapiCoProvider::class, ['apiKey' => $apiKey, 'timeout' => $timeout]);
        }

        return null;
    }

    /**
     * 通过IP获取位置信息（带fallback）
     * 
     * @param string|null $ip IP地址
     * @return array
     * @throws Exception
     */
    public function getLocationByIp(?string $ip = null): array
    {
        if (empty($this->providers)) {
            throw new Exception(__('没有可用的定位服务提供者'));
        }

        $lastError = null;
        $retry = $this->config['retry'] ?? 1;

        // 按优先级尝试各个提供者
        foreach ($this->providers as $provider) {
            for ($attempt = 0; $attempt <= $retry; $attempt++) {
                try {
                    $result = $provider->getLocationByIp($ip);
                    
                    // 验证返回数据
                    if (!empty($result) && isset($result['success']) && $result['success']) {
                        return $result;
                    }
                } catch (\Exception $e) {
                    $lastError = $e;
                    // 如果是最后一次尝试，继续下一个提供者
                    if ($attempt < $retry) {
                        // 等待一小段时间后重试
                        usleep(100000); // 0.1秒
                        continue;
                    }
                }
            }
        }

        // 所有提供者都失败
        throw new Exception(
            __('所有定位服务提供者都失败，最后错误: %{1}', $lastError ? $lastError->getMessage() : __('未知错误'))
        );
    }

    /**
     * 获取所有可用的提供者
     * 
     * @return array
     */
    public function getAvailableProviders(): array
    {
        return array_map(function ($provider) {
            return [
                'name' => $provider->getName(),
                'priority' => $provider->getPriority(),
                'available' => $provider->isAvailable()
            ];
        }, $this->providers);
    }
}

