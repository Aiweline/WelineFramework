<?php

declare(strict_types=1);

namespace Weline\Cms\Controller;

use Weline\Cms\Model\Page;
use Weline\Framework\App\Env;
use Weline\Framework\Cache\CacheFactory;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\RouterInterface;

/**
 * Cms 路由重写器
 * 
 * 功能：将友好的URL路径重写为页面查看路由
 * 例如：/about-us -> /cms/frontend/page/view?handle=about-us
 */
class Router implements RouterInterface
{
    /**
     * 静态缓存，避免重复查询数据库（请求内缓存）
     */
    private static array $handleCache = [];
    
    /**
     * 跨请求缓存实例（文件缓存或Redis缓存）
     */
    private static ?CacheInterface $crossRequestCache = null;
    
    /**
     * 缓存配置常量
     */
    private const CACHE_TTL_FOUND = 3600; // 找到的handle缓存1小时
    private const CACHE_TTL_NOT_FOUND = 300; // 未找到的handle缓存5分钟
    private const CACHE_KEY_PREFIX = 'cms_handle_exists_';
    
    /**
     * @inheritDoc
     */
    public static function process(string &$path, array &$rule): void
    {
        // 1. 跳过已经匹配的路由
        if (!empty($rule['module'])) {
            return;
        }
        
        // 2. 跳过系统路由和媒体文件
        if (self::isSystemPath($path)) {
            return;
        }
        
        // 3. 清理路径，提取可能的handle
        $handle = self::extractHandle($path);
        
        if (empty($handle)) {
            return;
        }
        
        // 4. 检查handle是否存在（使用缓存避免重复查询）
        // 检查是否为预览模式
        $isPreview = isset($_GET['preview']) && $_GET['preview'] == '1';
        if (!self::handleExists($handle, $isPreview)) {
            return;
        }
        
        // 5. 重写路由到页面查看控制器
        $path = '/cms/frontend/page/view';
        // 设置路由参数
        $rule['module'] = 'Weline_Cms';
        $rule['handle'] = $handle;
        
        // 将handle参数写入$_GET，确保控制器能接收到
        $_GET['handle'] = $handle;
        
        // 保留原始URL参数（如 preview、locale 等）
        // $_GET中的参数会自动保留，无需特殊处理
    }
    
    /**
     * 判断是否为系统路径
     */
    private static function isSystemPath(string $path): bool
    {
        $systemPaths = [
            '/admin',
            '/api',
            '/cms',
            '/media',
            '/static',
            '/pub',
            '/errors',
            '/setup',
        ];
        
        $lowerPath = strtolower($path);
        
        foreach ($systemPaths as $systemPath) {
            if (str_starts_with($lowerPath, $systemPath)) {
                return true;
            }
        }
        
        // 跳过带文件扩展名的请求
        if (preg_match('/\.(js|css|jpg|jpeg|png|gif|svg|ico|woff|woff2|ttf|eot|pdf|zip|xml|txt|html)$/i', $path)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * 从路径中提取handle
     */
    private static function extractHandle(string $path): string
    {
        // 移除开头和结尾的斜杠
        $handle = trim($path, '/');
        
        // 空路径不处理
        if (empty($handle)) {
            return '';
        }
        
        // 移除查询字符串（如果有）
        if (str_contains($handle, '?')) {
            $handle = substr($handle, 0, strpos($handle, '?'));
        }
        
        // 替换斜杠为连字符（支持多级路径）
        // 例如：/products/new -> products-new
        $handle = str_replace('/', '-', $handle);
        
        // 验证handle格式（只允许字母、数字、连字符、下划线）
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $handle)) {
            return '';
        }
        
        return $handle;
    }
    
    /**
     * 检查handle是否存在于数据库
     * 
     * @param string $handle 页面句柄
     * @param bool $isPreview 是否为预览模式
     * @return bool
     */
    private static function handleExists(string $handle, bool $isPreview = false): bool
    {
        // 预览模式下，使用不同的缓存键，避免与正常模式冲突
        $cacheKey = $handle . ($isPreview ? '_preview' : '');
        
        // 1. 首先检查请求内静态缓存（最快）
        if (isset(self::$handleCache[$cacheKey])) {
            return self::$handleCache[$cacheKey];
        }
        
        // 2. 检查跨请求缓存（文件缓存或Redis缓存）
        $crossRequestCacheKey = self::CACHE_KEY_PREFIX . md5($cacheKey);
        $cachedResult = self::getCrossRequestCache()->get($crossRequestCacheKey);
        if ($cachedResult !== null) {
            // 缓存命中，更新静态缓存并返回
            $exists = (bool)$cachedResult;
            self::$handleCache[$cacheKey] = $exists;
            return $exists;
        }
        
        try {
            /** @var Page $pageModel */
            $pageModel = ObjectManager::getInstance(Page::class);
            $page = clone $pageModel;
            
            // 预览模式下，允许访问所有状态的页面
            if ($isPreview) {
                $page->clear()
                    ->where(Page::fields_HANDLE, $handle)
                    ->find()
                    ->fetch();
            } else {
                // 非预览模式：允许访问已发布的页面，或者草稿状态的测试页面
                // 先查询已发布的页面
                $page->clear()
                    ->where(Page::fields_HANDLE, $handle)
                    ->where(Page::fields_STATUS, Page::STATUS_PUBLISHED)
                    ->find()
                    ->fetch();
                
                // 如果没找到已发布的页面，再查询测试页面（允许草稿状态）
                if (!$page->getId()) {
                    $page->clear()
                        ->where(Page::fields_HANDLE, $handle)
                        ->where(Page::fields_TYPE, 'test_page')
                        ->find()
                        ->fetch();
                }
            }
            
            $exists = (bool)$page->getId();
            
            // 3. 缓存结果到静态缓存和跨请求缓存
            self::$handleCache[$cacheKey] = $exists;
            
            // 根据结果设置不同的TTL：找到的缓存1小时，未找到的缓存5分钟
            $ttl = $exists ? self::CACHE_TTL_FOUND : self::CACHE_TTL_NOT_FOUND;
            self::getCrossRequestCache()->set($crossRequestCacheKey, $exists ? '1' : '0', $ttl);
            
            return $exists;
        } catch (\Exception $e) {
            // 如果查询失败，记录日志并返回false
            if (DEV) {
                \Weline\Framework\App\Env::log_error('cms_router', 'Cms Router Error: ' . $e->getMessage());
            }
            return false;
        }
    }
    
    /**
     * 获取跨请求缓存实例
     * 
     * @return CacheInterface
     */
    private static function getCrossRequestCache(): CacheInterface
    {
        if (self::$crossRequestCache === null) {
            $cacheFactory = new CacheFactory('cms_handle_cache', 'CMS Handle存在性缓存', true);
            self::$crossRequestCache = $cacheFactory->create();
        }
        return self::$crossRequestCache;
    }
    
    /**
     * 清理缓存（用于测试或手动清理）
     */
    public static function clearCache(): void
    {
        self::$handleCache = [];
        if (self::$crossRequestCache !== null) {
            self::$crossRequestCache->clear();
        }
    }
}

