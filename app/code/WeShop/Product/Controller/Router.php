<?php

declare(strict_types=1);

namespace WeShop\Product\Controller;

use WeShop\Product\Model\Product;
use WeShop\Product\Service\ProductService;
use Weline\Framework\App\Env;
use Weline\Framework\Cache\CacheFactory;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\RouterInterface;

/**
 * Product 产品路由重写器
 * 
 * 功能：将友好的URL路径重写为产品查看路由
 * 例如：/catalog/product/product-handle -> /weshop/product/view?id={product_id}
 * 
 * 注意：产品handle使用 handle 字段进行匹配
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
    private const CACHE_TTL_FOUND = 3600; // 找到的 handle 缓存1小时
    private const CACHE_TTL_NOT_FOUND = 300; // 未找到的 handle 缓存5分钟
    private const CACHE_KEY_PREFIX = 'product_handle_';
    
    /**
     * @inheritDoc
     */
    public static function process(string &$path, array &$rule): void
    {
        // 1. 跳过已经匹配的路由
        if (!empty($rule['module'])) {
            return;
        }
        
        // 2. 只处理 /catalog/product/{product_handle} 格式的路径
        if (!preg_match('#^/catalog/product/([^/]+)/?$#', $path, $matches)) {
            return;
        }
        
        $productHandle = $matches[1];
        
        // 3. 检查product_handle是否存在（使用缓存避免重复查询）
        $productId = self::getProductIdByHandle($productHandle);
        if (!$productId) {
            return;
        }
        
        // 4. 重写路由到产品查看控制器
        $path = '/weshop/product/view';
        // 设置路由参数
        $rule['module'] = 'WeShop_Product';
        
        // 将product_id参数写入$_GET，确保控制器能接收到
        $_GET['id'] = $productId;
        $_GET['product_id'] = $productId; // 兼容两种参数名
        
        // 保留原始URL参数（如 locale、currency 等）
        // $_GET中的参数会自动保留，无需特殊处理
    }
    
    /**
     * 通过product_handle获取产品ID
     * 
     * @param string $productHandle 产品句柄（使用 handle 字段）
     * @return int|null 产品ID，如果不存在返回null
     */
    private static function getProductIdByHandle(string $productHandle): ?int
    {
        // 1. 首先检查请求内静态缓存（最快）
        if (isset(self::$handleCache[$productHandle])) {
            $cached = self::$handleCache[$productHandle];
            return $cached === false ? null : (int)$cached;
        }
        
        // 2. 检查跨请求缓存（文件缓存或Redis缓存）
        $crossRequestCacheKey = self::CACHE_KEY_PREFIX . md5($productHandle);
        $cachedResult = self::getCrossRequestCache()->get($crossRequestCacheKey);
        if ($cachedResult !== null) {
            // 缓存命中，更新静态缓存并返回
            $productId = $cachedResult === false ? null : (int)$cachedResult;
            self::$handleCache[$productHandle] = $productId;
            return $productId;
        }
        
        try {
            /** @var Product $productModel */
            $productModel = ObjectManager::getInstance(Product::class);
            $product = clone $productModel;
            
            // 通过 handle 查询产品
            $product->clear()
                ->where(Product::fields_HANDLE, $productHandle)
                ->where(Product::fields_status, 'enabled')
                ->find()
                ->fetch();
            
            $productId = $product->getId() ? (int)$product->getId() : null;
            
            // 3. 缓存结果到静态缓存和跨请求缓存
            self::$handleCache[$productHandle] = $productId;
            
            // 根据结果设置不同的TTL：找到的缓存1小时，未找到的缓存5分钟
            $ttl = $productId ? self::CACHE_TTL_FOUND : self::CACHE_TTL_NOT_FOUND;
            self::getCrossRequestCache()->set($crossRequestCacheKey, $productId ? (string)$productId : '0', $ttl);
            
            return $productId;
        } catch (\Exception $e) {
            // 如果查询失败，记录日志并返回null
            if (DEV) {
                Env::log_error('product_router', 'Product Router Error: ' . $e->getMessage());
            }
            return null;
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
}
