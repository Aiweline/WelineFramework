<?php

declare(strict_types=1);

namespace WeShop\Product\Controller;

use WeShop\Product\Model\Product;
use WeShop\Product\Model\ProductWebsite;
use Weline\Framework\App\Env;
use Weline\Framework\Cache\CacheFactory;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\RouterInterface;
use Weline\UrlManager\Model\UrlRewrite;

/**
 * Product 产品路由重写器
 * 
 * 功能：将友好的URL路径重写为产品查看路由
 * 例如：/product/product-handle -> /weshop/product/view?id={product_id}
 *       /CNY/en_US/product/product-handle -> /weshop/product/view?id={product_id}
 * 
 * 支持多站点：
 * - 优先从 product_website 表按 (website_id, handle) 查找
 * - 如果未找到，回退到 product 表的全局 handle 字段（向后兼容）
 * 
 * 支持 URL 前缀：
 * - 自动处理货币/语言前缀（如 /CNY/en_US/）
 * 
 * 注意：产品handle使用 handle 字段进行匹配
 */
class Router implements RouterInterface
{
    /**
     * 静态缓存，避免重复查询数据库（请求内缓存）
     * 格式：["{website_id}:{handle}" => product_id|false]
     */
    private static array $handleCache = [];
    
    /**
     * 跨请求缓存实例（文件缓存或Redis缓存）
     */
    private static ?CacheInterface $crossRequestCache = null;
    
    /**
     * 缓存配置常量
     */
    private const CACHE_TTL_FOUND = 3600; // 找到的 handle 缓存1小时
    private const CACHE_TTL_NOT_FOUND = 300; // 未找到的 handle 缓存5分钟
    private const CACHE_KEY_PREFIX = 'product_handle_site_';
    
    /**
     * @inheritDoc
     */
    public static function process(string &$path, array &$rule): void
    {
        // 1. 跳过已经匹配的路由
        if (!empty($rule['module'])) {
            return;
        }
        
        // 2. 标准化路径：去掉首尾斜杠（与 Category Router 保持一致）
        $path = trim($path, '/');
        
        // 3. 处理 /product/{product_handle} 格式的路径
        // 支持带有货币/语言前缀的 URL：CNY/en_US/product/{handle}
        $productPrefix = 'product/';
        
        // 如果路径不是以 product/ 开头，尝试跳过前缀
        if (!str_starts_with($path, $productPrefix)) {
            // 尝试查找 product/ 在路径中的位置
            $productPos = strpos($path, $productPrefix);
            if ($productPos === false) {
                return;
            }
            // 提取 product/ 及其后面的部分
            $path = substr($path, $productPos);
        }
        
        // 4. 取 product/ 后面的作为 handle
        $productHandle = substr($path, strlen($productPrefix));
        $productHandle = trim($productHandle, '/');
        
        // 处理可能的子路径（只取第一段作为 handle）
        if (str_contains($productHandle, '/')) {
            $productHandle = explode('/', $productHandle)[0];
        }
        
        // 验证 handle 不为空且不是其他控制器动作
        if (empty($productHandle) || in_array($productHandle, ['view', 'list', 'search', 'compare', 'frontend', 'backend'])) {
            return;
        }
        
        // 5. 获取当前站点ID
        $websiteId = self::getCurrentWebsiteId();
        
        // 6. 检查 product handle 是否存在
        $productId = self::getProductIdByHandle($productHandle, $websiteId);
        if (!$productId) {
            return;
        }
        
        // 7. 重写路由到产品查看控制器
        $path = 'product/frontend/product/view';
        $rule['module'] = 'WeShop_Product';
        $_GET['id'] = $productId;
        $_GET['product_id'] = $productId;
        $_GET['handle'] = $productHandle;
    }
    
    /**
     * 获取当前站点ID
     * 
     * @return int 站点ID，默认为0
     */
    private static function getCurrentWebsiteId(): int
    {
        // 优先使用 UrlRewrite 的方法获取（保持一致性）
        return UrlRewrite::getCurrentWebsiteId();
    }
    
    /**
     * 通过product_handle获取产品ID（支持多站点）
     * 
     * 查询优先级：
     * 1. product_website 表按 (website_id, handle) 查找
     * 2. 如果 website_id > 0 且未找到，尝试 website_id = 0（全局）
     * 3. 回退到 product 表的全局 handle 字段（向后兼容）
     * 
     * @param string $productHandle 产品句柄
     * @param int $websiteId 站点ID
     * @return int|null 产品ID，如果不存在返回null
     */
    private static function getProductIdByHandle(string $productHandle, int $websiteId = 0): ?int
    {
        // 构建缓存键（包含站点ID）
        $cacheKeyLocal = "{$websiteId}:{$productHandle}";
        
        // 1. 首先检查请求内静态缓存（最快）
        if (isset(self::$handleCache[$cacheKeyLocal])) {
            $cached = self::$handleCache[$cacheKeyLocal];
            return $cached === false ? null : (int)$cached;
        }
        
        // 2. 检查跨请求缓存（文件缓存或Redis缓存）
        $crossRequestCacheKey = self::CACHE_KEY_PREFIX . md5($cacheKeyLocal);
        $cachedResult = self::getCrossRequestCache()->get($crossRequestCacheKey);
        if ($cachedResult !== null && $cachedResult !== false) {
            // 缓存命中，更新静态缓存并返回
            $productId = $cachedResult === '0' ? null : (int)$cachedResult;
            self::$handleCache[$cacheKeyLocal] = $productId;
            return $productId;
        }
        
        try {
            $productId = null;
            
            // 步骤1: 从 product_website 表查找（按站点+handle）
            $productId = self::getProductIdFromWebsiteTable($productHandle, $websiteId);
            
            // 步骤2: 如果 website_id > 0 且未找到，尝试全局（website_id = 0）
            if ($productId === null && $websiteId > 0) {
                $productId = self::getProductIdFromWebsiteTable($productHandle, 0);
            }
            
            // 步骤3: 回退到 product 表的全局 handle 字段（向后兼容）
            if ($productId === null) {
                $productId = self::getProductIdFromProductTable($productHandle);
            }
            
            // 3. 缓存结果到静态缓存和跨请求缓存
            self::$handleCache[$cacheKeyLocal] = $productId;
            
            // 根据结果设置不同的TTL：找到的缓存1小时，未找到的缓存5分钟
            $ttl = $productId ? self::CACHE_TTL_FOUND : self::CACHE_TTL_NOT_FOUND;
            self::getCrossRequestCache()->set($crossRequestCacheKey, $productId ? (string)$productId : '0', $ttl);
            
            return $productId;
        } catch (\Exception $e) {
            // 如果查询失败，记录日志并返回null
            if (defined('DEV') && DEV) {
                Env::log_error('product_router', 'Product Router Error: ' . $e->getMessage());
            }
            return null;
        }
    }
    
    /**
     * 从 product_website 表获取产品ID
     * 
     * @param string $handle Handle
     * @param int $websiteId 站点ID
     * @return int|null
     */
    private static function getProductIdFromWebsiteTable(string $handle, int $websiteId): ?int
    {
        try {
            /** @var ProductWebsite $productWebsite */
            $productWebsite = ObjectManager::getInstance(ProductWebsite::class);
            $result = clone $productWebsite;
            
            $result->clear()
                ->where(ProductWebsite::fields_WEBSITE_ID, $websiteId)
                ->where(ProductWebsite::fields_HANDLE, $handle)
                ->where(ProductWebsite::fields_IS_ACTIVE, 1)
                ->find()
                ->fetch();
            
            $productId = $result->getProductId();
            
            if ($productId) {
                // 验证产品是否启用
                if (self::isProductEnabled($productId)) {
                    return $productId;
                }
            }
            
            return null;
        } catch (\Exception $e) {
            // 表可能不存在（模块未安装），静默失败
            return null;
        }
    }
    
    /**
     * 从 product 表获取产品ID（向后兼容）
     * 
     * @param string $handle Handle
     * @return int|null
     */
    private static function getProductIdFromProductTable(string $handle): ?int
    {
        try {
            /** @var Product $productModel */
            $productModel = ObjectManager::getInstance(Product::class);
            $product = clone $productModel;
            
            // 通过 handle 查询产品（status=1 表示启用）
            $product->clear()
                ->where(Product::fields_HANDLE, $handle)
                ->where(Product::fields_status, 1)
                ->find()
                ->fetch();
            
            return $product->getId() ? (int)$product->getId() : null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * 检查产品是否启用
     * 
     * @param int $productId 产品ID
     * @return bool
     */
    private static function isProductEnabled(int $productId): bool
    {
        try {
            /** @var Product $productModel */
            $productModel = ObjectManager::getInstance(Product::class);
            $product = clone $productModel;
            
            $product->load($productId);
            
            $status = $product->getData(Product::fields_status);
            return $status === 'enabled' || $status === 1 || $status === '1';
        } catch (\Exception $e) {
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
            $cacheFactory = new CacheFactory('product_handle_cache', 'Product产品Handle存在性缓存', true);
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
    
    /**
     * 清理特定 handle 的缓存（产品保存时调用）
     * 
     * @param string $handle Handle
     * @param int|null $websiteId 站点ID，null 则清理所有站点
     */
    public static function clearHandleCache(string $handle, ?int $websiteId = null): void
    {
        if ($websiteId !== null) {
            $cacheKeyLocal = "{$websiteId}:{$handle}";
            unset(self::$handleCache[$cacheKeyLocal]);
            
            $crossRequestCacheKey = self::CACHE_KEY_PREFIX . md5($cacheKeyLocal);
            self::getCrossRequestCache()->delete($crossRequestCacheKey);
        } else {
            // 清理所有站点的缓存（通过遍历静态缓存）
            foreach (array_keys(self::$handleCache) as $key) {
                if (str_ends_with($key, ":{$handle}")) {
                    unset(self::$handleCache[$key]);
                    $crossRequestCacheKey = self::CACHE_KEY_PREFIX . md5($key);
                    self::getCrossRequestCache()->delete($crossRequestCacheKey);
                }
            }
        }
    }
}
