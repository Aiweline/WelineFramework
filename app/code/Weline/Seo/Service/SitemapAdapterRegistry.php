<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Interface\SitemapPlatformAdapterInterface;
use Weline\Seo\Adapter\GoogleSitemapAdapter;
use Weline\Seo\Adapter\BingSitemapAdapter;
use Weline\Seo\Adapter\BaiduSitemapAdapter;

/**
 * Sitemap 平台适配器注册中心
 *
 * 管理所有平台适配器，支持：
 * - 内置适配器（Google/Bing/百度）
 * - 通过 extends 机制扩展新适配器
 *
 * 遵循开闭原则：添加新平台只需添加适配器类，无需修改此类
 *
 * @package Weline_Seo
 */
class SitemapAdapterRegistry
{
    /**
     * 内置适配器类列表
     */
    private const BUILTIN_ADAPTERS = [
        'google' => GoogleSitemapAdapter::class,
        'bing' => BingSitemapAdapter::class,
        'baidu' => BaiduSitemapAdapter::class,
    ];

    /**
     * 适配器缓存
     */
    private array $adapters = [];

    /**
     * 是否已加载
     */
    private bool $loaded = false;

    /**
     * 获取所有已注册的适配器
     *
     * @param bool $forceReload 是否强制重新加载
     * @return SitemapPlatformAdapterInterface[]
     */
    public function getAdapters(bool $forceReload = false): array
    {
        if (!$this->loaded || $forceReload) {
            $this->loadAdapters();
        }
        return $this->adapters;
    }

    /**
     * 根据平台代码获取适配器
     *
     * @param string $platformCode
     * @return SitemapPlatformAdapterInterface|null
     */
    public function getAdapter(string $platformCode): ?SitemapPlatformAdapterInterface
    {
        $adapters = $this->getAdapters();
        return $adapters[$platformCode] ?? null;
    }

    /**
     * 检查平台是否已注册
     *
     * @param string $platformCode
     * @return bool
     */
    public function hasAdapter(string $platformCode): bool
    {
        return $this->getAdapter($platformCode) !== null;
    }

    /**
     * 获取所有平台代码列表
     *
     * @return string[]
     */
    public function getPlatformCodes(): array
    {
        return array_keys($this->getAdapters());
    }

    /**
     * 获取平台信息列表（用于 UI 展示）
     *
     * @return array [platform_code => ['name' => ..., 'color' => ...], ...]
     */
    public function getPlatformInfo(): array
    {
        $info = [];
        foreach ($this->getAdapters() as $code => $adapter) {
            $info[$code] = [
                'code' => $code,
                'name' => $adapter->getPlatformName(),
                'color' => $adapter->getPlatformColor(),
                'supports_submit' => $adapter->supportsAutoSubmit(),
            ];
        }
        return $info;
    }

    /**
     * 加载所有适配器
     */
    private function loadAdapters(): void
    {
        $this->adapters = [];
        
        // 加载内置适配器
        foreach (self::BUILTIN_ADAPTERS as $code => $class) {
            $this->registerAdapter($class);
        }
        
        // 加载扩展适配器（通过 extends 机制）
        $this->loadExtendedAdapters();
        
        $this->loaded = true;
    }

    /**
     * 注册适配器
     *
     * @param string $class 适配器类名
     */
    private function registerAdapter(string $class): void
    {
        if (!class_exists($class)) {
            return;
        }
        
        try {
            /** @var SitemapPlatformAdapterInterface $adapter */
            $adapter = ObjectManager::getInstance($class);
            
            if ($adapter instanceof SitemapPlatformAdapterInterface) {
                $code = $adapter->getPlatformCode();
                $this->adapters[$code] = $adapter;
            }
        } catch (\Throwable $e) {
            // 适配器加载失败，记录日志但不中断
            // TODO: 添加日志记录
        }
    }

    /**
     * 加载扩展适配器
     *
     * 扫描 extends/module/Weline_Seo/SitemapAdapter/ 目录
     */
    private function loadExtendedAdapters(): void
    {
        $pattern = BP . '/app/code/*/extends/module/Weline_Seo/SitemapAdapter/*Adapter.php';
        $files = glob($pattern);
        
        if (empty($files)) {
            return;
        }
        
        foreach ($files as $file) {
            // 从文件路径推断类名
            // e.g., /app/code/Vendor/Module/extends/module/Weline_Seo/SitemapAdapter/YandexSitemapAdapter.php
            // => Vendor\Module\Extends\Module\Weline_Seo\SitemapAdapter\YandexSitemapAdapter
            
            if (preg_match('#app/code/([^/]+)/([^/]+)/extends/module/Weline_Seo/SitemapAdapter/([^/]+)Adapter\.php$#', $file, $matches)) {
                $vendor = $matches[1];
                $module = $matches[2];
                $adapterName = $matches[3];
                
                $class = sprintf(
                    '%s\\%s\\Extends\\Module\\Weline_Seo\\SitemapAdapter\\%sAdapter',
                    $vendor,
                    $module,
                    $adapterName
                );
                
                $this->registerAdapter($class);
            }
        }
    }

    /**
     * 从 provider 代码提取平台代码
     *
     * @param string $provider 如 'google_indexing_api', 'bing_webmaster'
     * @return string|null
     */
    public function extractPlatformFromProvider(string $provider): ?string
    {
        $provider = strtolower($provider);
        
        foreach ($this->getPlatformCodes() as $code) {
            if (strpos($provider, $code) !== false) {
                return $code;
            }
        }
        
        return null;
    }
}
