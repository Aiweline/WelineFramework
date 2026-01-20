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
 * 例如：/catalog/category/foldable -> /catalog/frontend/category/view?handle=foldable
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
        // 1. 已经有模块匹配的路由直接跳过
        if (!empty($rule['module'])) {
            return;
        }
        // 2. 标准化路径：去掉首尾斜杠
        $path = trim($path, '/');

        // 3. 只处理以 catalog/category/ 开头的路径
        $prefix = 'catalog/category/';
        if (!str_starts_with($path, $prefix)) {
            return;
        }

        // 4. 取 catalog/category/ 后面的全部作为 handle
        $categoryHandle = substr($path, strlen($prefix));
        $categoryHandle = trim($categoryHandle, '/');
        if ($categoryHandle === '') {
            return;
        }

        // 5. 检查 handle 是否存在（使用缓存避免重复查询）
        if (!self::categoryHandleExists($categoryHandle)) {
            // 分类不存在，保持原路径不变，让框架继续处理
            return;
        }

        // 6. 重写路由到分类查看控制器
        $path = 'catalog/frontend/category/view';
        $rule['module'] = 'WeShop_Catalog';
        $_GET['handle'] = $categoryHandle;
    }
    
    /**
     * 检查category_handle是否存在于数据库
     * 
     * @param string $categoryHandle 分类句柄（handle），可能会被更新为完整的handle
     * @return bool
     */
    private static function categoryHandleExists(string &$categoryHandle): bool
    {   
        // 1. 首先检查请求内静态缓存（最快）
        if (isset(self::$urlKeyCache[$categoryHandle])) {
            return self::$urlKeyCache[$categoryHandle];
        }
        
        // 2. 检查跨请求缓存（文件缓存或Redis缓存）
        // 注意：由于支持suffix匹配，缓存键需要基于原始handle，但查询逻辑会尝试匹配完整路径
        // 暂时跳过缓存，直接查询数据库，确保能够匹配完整路径的handle
        // TODO: 优化缓存策略，支持suffix匹配
        $crossRequestCacheKey = self::CACHE_KEY_PREFIX . md5($categoryHandle);
        $cachedResult = self::getCrossRequestCache()->get($crossRequestCacheKey);
        // 暂时跳过缓存，直接查询数据库（因为需要支持suffix匹配）
        // if ($cachedResult !== null) {
        //     // 缓存命中，更新静态缓存并返回
        //     $exists = (bool)$cachedResult;
        //     self::$urlKeyCache[$categoryHandle] = $exists;
        //     return $exists;
        // }
        
        try {
            /** @var Category $categoryModel */
            $categoryModel = ObjectManager::getInstance(Category::class);
            $category = clone $categoryModel;
            
            // 查询启用的分类（只查询启用的分类），使用 handle 字段匹配
            // URL 解码 handle（因为 URL 中可能包含编码字符）
            $decodedHandle = urldecode($categoryHandle);
            
            // 先尝试使用解码后的 handle（精确匹配）
            $category->clear()
                ->where(Category::fields_HANDLE, $decodedHandle)
                ->where(Category::fields_IS_ACTIVE, 1)
                ->find()
                ->fetch();
            
            $exists = (bool)$category->getId();
            $categoryId = $category->getId();
            
            // 如果精确匹配没找到，尝试匹配以 handle 结尾的完整路径
            // 例如：URL中的 handle 是 "smartphones"，数据库中可能是 "electronics/phones/smartphones"
            if (!$exists) {
                // 使用 LIKE 查询匹配以 handle 结尾的完整路径
                // 正确格式：where('field', '%value%', 'like')
                $category->clear()
                    ->where(Category::fields_HANDLE, '%/' . $decodedHandle, 'like')
                    ->where(Category::fields_IS_ACTIVE, 1)
                    ->find()
                    ->fetch();
                
                // 如果还是没找到，尝试匹配以 handle 结尾的路径（不包含前导斜杠）
                if (!$category->getId()) {
                    $category->clear()
                        ->where(Category::fields_HANDLE, '%' . $decodedHandle, 'like')
                        ->where(Category::fields_IS_ACTIVE, 1)
                        ->find()
                        ->fetch();
                }
                
                $exists = (bool)$category->getId();
                $categoryId = $category->getId();
                $foundHandle = $category->getId() ? $category->getData(Category::fields_HANDLE) : '';
                
                // 如果找到了，更新categoryHandle为完整的handle，以便后续使用
                if ($exists && $foundHandle) {
                    $categoryHandle = $foundHandle;
                }
            }
            
            // 如果使用解码后的 handle 没找到，尝试使用原始 handle（可能数据库存储的就是编码后的）
            if (!$exists && $decodedHandle !== $categoryHandle) {   
                $category->clear()
                    ->where(Category::fields_HANDLE, $categoryHandle)
                    ->where(Category::fields_IS_ACTIVE, 1)
                    ->find()
                    ->fetch();
                $exists = (bool)$category->getId();
                $categoryId = $category->getId();
                
            }
            
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
