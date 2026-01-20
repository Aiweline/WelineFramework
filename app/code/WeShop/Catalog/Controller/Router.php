<?php

declare(strict_types=1);

namespace WeShop\Catalog\Controller;

use WeShop\Catalog\Model\Category;
use WeShop\Catalog\Service\CategoryService;
use Weline\Framework\App\Env;
use Weline\Framework\Cache\CacheFactory;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\RouterInterface;

/**
 * Catalog 分类路由重写器
 * 
 * 功能：将友好的URL路径重写为分类查看路由
 * 例如：/catalog/category/foldable -> /catalog/category/view?handle=foldable
 * 
 * 注意：分类 handle 使用 Category::fields_HANDLE 字段进行匹配
 */
class Router implements RouterInterface
{
    /**
     * 静态缓存，避免重复查询数据库（请求内缓存）
     */
    private static array $urlKeyCache = [];
    
    /**
     * 跨请求缓存实例（文件缓存或Redis缓存）
     */
    private static ?CacheInterface $crossRequestCache = null;
    
    /**
     * 缓存配置常量
     */
    private const CACHE_TTL_FOUND = 3600; // 找到的 handle 缓存 1 小时
    private const CACHE_TTL_NOT_FOUND = 300; // 未找到的 handle 缓存 5 分钟
    private const CACHE_KEY_PREFIX = 'catalog_category_handle_';
    
    /**
     * @inheritDoc
     */
    public static function process(string &$path, array &$rule): void
    {
        // 1. 跳过已经匹配的路由
        if (!empty($rule['module'])) {
            return;
        }
        
        // 2. 只处理 /catalog/category/{category_handle} 格式的路径
        if (!preg_match('#^/catalog/category/([^/]+)/?$#', $path, $matches)) {
            return;
        }
        
        $categoryHandle = $matches[1];
        
        // 3. 检查category_handle是否存在（使用缓存避免重复查询）
        if (!self::categoryHandleExists($categoryHandle)) {
            return;
        }
        
        // 4. 重写路由到分类查看控制器
        $path = '/catalog/category/view';
        // 设置路由参数
        $rule['module'] = 'WeShop_Catalog';
        
        // 将handle参数写入$_GET，确保控制器能接收到
        $_GET['handle'] = $categoryHandle;
        
        // 保留原始URL参数（如 locale、currency 等）
        // $_GET中的参数会自动保留，无需特殊处理
    }
    
    /**
     * 检查category_handle是否存在于数据库
     * 
     * @param string $categoryHandle 分类句柄（handle）
     * @return bool
     */
    private static function categoryHandleExists(string $categoryHandle): bool
    {
        // 1. 首先检查请求内静态缓存（最快）
        if (isset(self::$urlKeyCache[$categoryHandle])) {
            return self::$urlKeyCache[$categoryHandle];
        }
        
        // 2. 检查跨请求缓存（文件缓存或Redis缓存）
        $crossRequestCacheKey = self::CACHE_KEY_PREFIX . md5($categoryHandle);
        $cachedResult = self::getCrossRequestCache()->get($crossRequestCacheKey);
        if ($cachedResult !== null) {
            // 缓存命中，更新静态缓存并返回
            $exists = (bool)$cachedResult;
            self::$urlKeyCache[$categoryHandle] = $exists;
            return $exists;
        }
        
        try {
            /** @var Category $categoryModel */
            $categoryModel = ObjectManager::getInstance(Category::class);
            $category = clone $categoryModel;
            
            // 查询启用的分类（只查询启用的分类），使用 handle 字段匹配
            $category->clear()
                ->where(Category::fields_HANDLE, $categoryHandle)
                ->where(Category::fields_IS_ACTIVE, 1)
                ->find()
                ->fetch();
            
            $exists = (bool)$category->getId();
            
            // 3. 缓存结果到静态缓存和跨请求缓存
            self::$urlKeyCache[$categoryHandle] = $exists;
            
            // 根据结果设置不同的TTL：找到的缓存1小时，未找到的缓存5分钟
            $ttl = $exists ? self::CACHE_TTL_FOUND : self::CACHE_TTL_NOT_FOUND;
            self::getCrossRequestCache()->set($crossRequestCacheKey, $exists ? '1' : '0', $ttl);
            
            return $exists;
        } catch (\Exception $e) {
            // 如果查询失败，记录日志并返回false
            if (DEV) {
                Env::log_error('catalog_router', 'Catalog Router Error: ' . $e->getMessage());
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
            $cacheFactory = new CacheFactory('catalog_category_handle_cache', 'Catalog分类Handle存在性缓存', true);
            self::$crossRequestCache = $cacheFactory->create();
        }
        return self::$crossRequestCache;
    }
    
    /**
     * 清理缓存（用于测试或手动清理）
     */
    public static function clearCache(): void
    {
        self::$urlKeyCache = [];
        if (self::$crossRequestCache !== null) {
            self::$crossRequestCache->clear();
        }
    }
}
