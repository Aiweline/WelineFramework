<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Service;

use Weline\Cdn\Api\AdapterInterface;
use Weline\Cdn\Api\EdgeCacheAdapterBridge;
use Weline\Framework\Cache\Contract\EdgeCacheAdapterInterface;
use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\File\Scan;

/**
 * 适配器解析器服务
 *
 * 仅从 framework:compile 生成的静态 Provider Registry 加载适配器。
 * 请求和常驻 Worker 路径不读目录、源文件、Extends Registry 或 mtime。
 *
 * @package Weline_Cdn
 */
class AdapterResolver
{
    public const CAPABILITY_PREFIX = 'cache.edge_adapter.';

    /**
     * 已注册的适配器缓存
     *
     * @var array<string, AdapterInterface>
     */
    private array $adapters = [];

    private bool $loaded = false;
    private readonly ServiceProviderRegistry $providerRegistry;
    private readonly ObjectManager $objectManager;

    /**
     * @param Scan $fileScanner Deprecated compatibility argument; no filesystem scan is performed.
     */
    public function __construct(
        Scan $fileScanner,
        ObjectManager $objectManager,
        ?ServiceProviderRegistry $providerRegistry = null,
    ) {
        $this->objectManager = $objectManager;
        $this->providerRegistry = $providerRegistry ?? new ServiceProviderRegistry();
    }

    /**
     * 获取所有适配器
     * 
     * @param bool $forceReload 是否强制重新加载
     * @return array<string, AdapterInterface> 适配器代码 => 适配器实例
     */
    public function getAllAdapters(bool $forceReload = false): array
    {
        if ($this->loaded && !$forceReload) {
            return $this->adapters;
        }

        // Provider 清单在当前进程中不可变。forceReload 仅重建适配器实例；
        // 清单变更需先执行 framework:compile，再重启/重载进程。
        $this->adapters = [];
        $this->scanAdapters();
        $this->loaded = true;

        return $this->adapters;
    }

    /**
     * 获取适配器实例
     * 
     * @param string $adapterCode 适配器代码
     * @return AdapterInterface|null
     */
    public function getAdapter(string $adapterCode): ?AdapterInterface
    {
        $adapters = $this->getAllAdapters();
        return $adapters[$adapterCode] ?? null;
    }

    /**
     * 从编译 Provider Registry 加载所有适配器。
     */
    private function scanAdapters(): void
    {
        foreach ($this->providerRegistry->implementationsWithPrefix(self::CAPABILITY_PREFIX) as $capability => $implementation) {
            try {
                $instance = $this->objectManager->getInstance($implementation);

                if ($instance instanceof EdgeCacheAdapterInterface && !$instance instanceof AdapterInterface) {
                    $instance = new EdgeCacheAdapterBridge($instance);
                }
                if (!$instance instanceof AdapterInterface) {
                    w_log_error("忽略无效 CDN 适配器 Provider: {$capability} => {$implementation}");
                    continue;
                }

                $adapterCode = $instance->getAdapterCode();
                if ($adapterCode === '') {
                    w_log_error("忽略缺少 code 的 CDN 适配器 Provider: {$capability} => {$implementation}");
                    continue;
                }
                $this->adapters[$adapterCode] = $instance;
            } catch (\Throwable $e) {
                w_log_error("加载 CDN 适配器 Provider 失败: {$capability} => {$implementation}, 错误: " . $e->getMessage());
            }
        }
    }
}
