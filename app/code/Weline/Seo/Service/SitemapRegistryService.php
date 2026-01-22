<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Service;

use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Interface\SitemapProviderInterface;

/**
 * Sitemap Provider 注册服务
 *
 * 通过 extends/module/Weline_Seo/SitemapProvider 自动收集所有 SitemapProvider 实现。
 */
class SitemapRegistryService
{
    private ObjectManager $objectManager;
    private ?array $cachedProviders = null;
    private ?int $cachedExtendsMtime = null;

    public function __construct(ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * 获取所有 Sitemap Provider 实例
     *
     * @param bool $forceReload
     * @return SitemapProviderInterface[]
     */
    public function getProviders(bool $forceReload = false): array
    {
        if (!$forceReload && $this->cachedProviders !== null) {
            if (method_exists(ExtendsData::class, 'getRegistryFileMtime')) {
                $currentMtime = ExtendsData::getRegistryFileMtime();
                if ($currentMtime === $this->cachedExtendsMtime) {
                    return $this->cachedProviders;
                }
            }
        }

        $providers = [];

        try {
            $extendedBy = ExtendsData::getExtendedBy('Weline_Seo', $forceReload);
            if (empty($extendedBy)) {
                $this->cachedProviders = [];
                $this->cachedExtendsMtime = method_exists(ExtendsData::class, 'getRegistryFileMtime')
                    ? ExtendsData::getRegistryFileMtime()
                    : 0;
                return [];
            }

            $extends = ExtendsData::getModuleExtends('Weline_Seo', $forceReload);
            $sitemapExtend = $extends['SitemapProvider'] ?? null;
            if (!$sitemapExtend) {
                $this->cachedProviders = [];
                $this->cachedExtendsMtime = method_exists(ExtendsData::class, 'getRegistryFileMtime')
                    ? ExtendsData::getRegistryFileMtime()
                    : 0;
                return [];
            }

            foreach ($extendedBy as $sourceModule => $extensions) {
                foreach ($extensions as $extension) {
                    if (($extension['extend_name'] ?? '') === 'SitemapProvider') {
                        $class = $extension['class'] ?? null;
                        if ($class && class_exists($class)) {
                            try {
                                $instance = $this->objectManager->getInstance($class);
                                if ($instance instanceof SitemapProviderInterface) {
                                    $providers[] = $instance;
                                }
                            } catch (\Throwable $e) {
                                // 忽略单个 provider 错误
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // 忽略扩展扫描错误
        }

        $this->cachedProviders = $providers;
        $this->cachedExtendsMtime = method_exists(ExtendsData::class, 'getRegistryFileMtime')
            ? ExtendsData::getRegistryFileMtime()
            : 0;

        return $providers;
    }

    /**
     * 按 scope/module 过滤 Sitemap Provider
     *
     * @return SitemapProviderInterface[]
     */
    public function getProvidersByScopeModule(string $scope, string $module, bool $forceReload = false): array
    {
        $all = $this->getProviders($forceReload);
        $filtered = [];

        foreach ($all as $provider) {
            if ($provider->getScope() === $scope && $provider->getModule() === $module) {
                $filtered[] = $provider;
            }
        }

        return $filtered;
    }
}

