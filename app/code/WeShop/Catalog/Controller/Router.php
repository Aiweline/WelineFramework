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
        // #region agent log
        $logFile = 'e:\WelineFramework\DEV-workspace\.cursor\debug.log';
        $logData = json_encode(['sessionId'=>'debug-session','runId'=>'run2','hypothesisId'=>'J','location'=>'Router.php:45','message'=>'process called','data'=>['path'=>$path,'rule_module'=>($rule['module']??'none'),'REQUEST_URI'=>($_SERVER['REQUEST_URI']??'none')],'timestamp'=>time()*1000])."\n";
        @file_put_contents($logFile, $logData, FILE_APPEND);
        // #endregion
        
        // 1. 跳过已经匹配的路由
        if (!empty($rule['module'])) {
            // #region agent log
            file_put_contents('e:\WelineFramework\DEV-workspace\.cursor\debug.log', json_encode(['sessionId'=>'debug-session','runId'=>'run2','hypothesisId'=>'J','location'=>'Router.php:50','message'=>'route already matched, skipping','data'=>['module'=>$rule['module']],'timestamp'=>time()*1000])."\n", FILE_APPEND);
            // #endregion
            return;
        }
        
        // 2. 如果路径包含完整URL（协议和主机名），提取纯路径部分
        // 框架传入的路径可能包含完整URL，需要提取路径部分
        $originalPath = $path;
        if (strpos($path, '://') !== false) {
            // 路径包含协议，需要解析URL
            $parsed = parse_url($path);
            $path = $parsed['path'] ?? $path;
        }
        // 去除前导斜杠，统一路径格式
        $path = trim($path, '/');
        
        // 3. 如果路径包含主机名（如 127.0.0.1:9981），去除主机名部分
        // 路径格式可能是：127.0.0.1:9981/USD/zh_Hant_MO/catalog/category/smartphones
        if (preg_match('#^[^/]+/(.+)$#', $path, $hostMatches)) {
            $path = $hostMatches[1];
        }
        
        // 4. 去除货币和语言前缀（如果存在）
        // 路径格式可能是：USD/zh_Hant_MO/catalog/category/smartphones
        // 或者：zh_Hant_MO/catalog/category/smartphones
        // 或者：catalog/category/smartphones
        $cleanedPath = $path;
        if (preg_match('#^(?:[^/]+/)?(?:[^/]+/)?(catalog/category/.+)$#', $path, $prefixMatches)) {
            $cleanedPath = $prefixMatches[1];
        }
        
        // #region agent log
        file_put_contents('e:\WelineFramework\DEV-workspace\.cursor\debug.log', json_encode(['sessionId'=>'debug-session','runId'=>'run8','hypothesisId'=>'K','location'=>'Router.php:75','message'=>'path cleaned','data'=>['original_path'=>$originalPath,'path'=>$path,'cleaned_path'=>$cleanedPath],'timestamp'=>time()*1000])."\n", FILE_APPEND);
        // #endregion
        
        // 5. 只处理 /catalog/category/{category_handle} 格式的路径
        // 使用清理后的路径进行匹配
        // #region agent log
        file_put_contents('e:\WelineFramework\DEV-workspace\.cursor\debug.log', json_encode(['sessionId'=>'debug-session','runId'=>'run8','hypothesisId'=>'B','location'=>'Router.php:90','message'=>'checking path pattern','data'=>['cleaned_path'=>$cleanedPath,'pattern'=>'/?catalog/category/([^/]+)/?'],'timestamp'=>time()*1000])."\n", FILE_APPEND);
        // #endregion
        
        if (!preg_match('#^/?catalog/category/([^/]+)/?$#', $cleanedPath, $matches)) {
            // #region agent log
            file_put_contents('e:\WelineFramework\DEV-workspace\.cursor\debug.log', json_encode(['sessionId'=>'debug-session','runId'=>'run8','hypothesisId'=>'B','location'=>'Router.php:93','message'=>'path pattern not matched','data'=>['cleaned_path'=>$cleanedPath],'timestamp'=>time()*1000])."\n", FILE_APPEND);
            // #endregion
            return;
        }
        
        $categoryHandle = $matches[1];
        
        // #region agent log
        file_put_contents('e:\WelineFramework\DEV-workspace\.cursor\debug.log', json_encode(['sessionId'=>'debug-session','runId'=>'run8','hypothesisId'=>'B','location'=>'Router.php:100','message'=>'path matched, extracted handle','data'=>['categoryHandle'=>$categoryHandle,'cleaned_path'=>$cleanedPath],'timestamp'=>time()*1000])."\n", FILE_APPEND);
        // #endregion
        
        // 6. 检查category_handle是否存在（使用缓存避免重复查询）
        // categoryHandleExists 可能会更新 $categoryHandle 为完整的handle（如果找到）
        $exists = self::categoryHandleExists($categoryHandle);
        
        // #region agent log
        file_put_contents('e:\WelineFramework\DEV-workspace\.cursor\debug.log', json_encode(['sessionId'=>'debug-session','runId'=>'run10','hypothesisId'=>'C','location'=>'Router.php:106','message'=>'categoryHandleExists result','data'=>['categoryHandle'=>$categoryHandle,'exists'=>$exists],'timestamp'=>time()*1000])."\n", FILE_APPEND);
        // #endregion
        
        if (!$exists) {
            // #region agent log
            file_put_contents('e:\WelineFramework\DEV-workspace\.cursor\debug.log', json_encode(['sessionId'=>'debug-session','runId'=>'run12','hypothesisId'=>'C','location'=>'Router.php:118','message'=>'category not found, restoring cleaned path','data'=>['categoryHandle'=>$categoryHandle,'cleaned_path'=>$cleanedPath],'timestamp'=>time()*1000])."\n", FILE_APPEND);
            // #endregion
            // 分类不存在，恢复清理后的路径（不包含主机名和前缀），让框架继续处理
            // 注意：使用清理后的路径，而不是原始路径，因为原始路径可能包含主机名和前缀
            $path = $cleanedPath;
            return;
        }
        
        // 7. 重写路由到分类查看控制器
        // 注意：路径不应该包含前导斜杠，因为路由文件中的键不包含前导斜杠
        $path = 'catalog/frontend/category/view';
        // 设置路由参数
        $rule['module'] = 'WeShop_Catalog';
        
        // 将handle参数写入$_GET，确保控制器能接收到
        // 注意：使用完整的handle（如果找到了），否则使用原始的handle
        $_GET['handle'] = $categoryHandle;
        
        // #region agent log
        file_put_contents('e:\WelineFramework\DEV-workspace\.cursor\debug.log', json_encode(['sessionId'=>'debug-session','runId'=>'run5','hypothesisId'=>'F','location'=>'Router.php:113','message'=>'route rule set','data'=>['original_path'=>$oldPath??$path,'newPath'=>$path,'module'=>$rule['module'],'handle'=>$categoryHandle],'timestamp'=>time()*1000])."\n", FILE_APPEND);
        // #endregion
        
        // 保留原始URL参数（如 locale、currency 等）
        // $_GET中的参数会自动保留，无需特殊处理
    }
    
    /**
     * 检查category_handle是否存在于数据库
     * 
     * @param string $categoryHandle 分类句柄（handle），可能会被更新为完整的handle
     * @return bool
     */
    private static function categoryHandleExists(string &$categoryHandle): bool
    {
        // #region agent log
        file_put_contents('e:\WelineFramework\DEV-workspace\.cursor\debug.log', json_encode(['sessionId'=>'debug-session','runId'=>'run1','hypothesisId'=>'C','location'=>'Router.php:85','message'=>'categoryHandleExists called','data'=>['categoryHandle'=>$categoryHandle],'timestamp'=>time()*1000])."\n", FILE_APPEND);
        // #endregion
        
        // 1. 首先检查请求内静态缓存（最快）
        if (isset(self::$urlKeyCache[$categoryHandle])) {
            // #region agent log
            file_put_contents('e:\WelineFramework\DEV-workspace\.cursor\debug.log', json_encode(['sessionId'=>'debug-session','runId'=>'run1','hypothesisId'=>'D','location'=>'Router.php:90','message'=>'static cache hit','data'=>['categoryHandle'=>$categoryHandle,'exists'=>self::$urlKeyCache[$categoryHandle]],'timestamp'=>time()*1000])."\n", FILE_APPEND);
            // #endregion
            return self::$urlKeyCache[$categoryHandle];
        }
        
        // 2. 检查跨请求缓存（文件缓存或Redis缓存）
        // 注意：由于支持suffix匹配，缓存键需要基于原始handle，但查询逻辑会尝试匹配完整路径
        // 暂时跳过缓存，直接查询数据库，确保能够匹配完整路径的handle
        // TODO: 优化缓存策略，支持suffix匹配
        $crossRequestCacheKey = self::CACHE_KEY_PREFIX . md5($categoryHandle);
        $cachedResult = self::getCrossRequestCache()->get($crossRequestCacheKey);
        // #region agent log
        file_put_contents('e:\WelineFramework\DEV-workspace\.cursor\debug.log', json_encode(['sessionId'=>'debug-session','runId'=>'run11','hypothesisId'=>'D','location'=>'Router.php:165','message'=>'checking cache','data'=>['categoryHandle'=>$categoryHandle,'cachedResult'=>$cachedResult],'timestamp'=>time()*1000])."\n", FILE_APPEND);
        // #endregion
        // 暂时跳过缓存，直接查询数据库（因为需要支持suffix匹配）
        // if ($cachedResult !== null) {
        //     // 缓存命中，更新静态缓存并返回
        //     $exists = (bool)$cachedResult;
        //     self::$urlKeyCache[$categoryHandle] = $exists;
        //     // #region agent log
        //     file_put_contents('e:\WelineFramework\DEV-workspace\.cursor\debug.log', json_encode(['sessionId'=>'debug-session','runId'=>'run1','hypothesisId'=>'D','location'=>'Router.php:100','message'=>'cross-request cache hit','data'=>['categoryHandle'=>$categoryHandle,'exists'=>$exists],'timestamp'=>time()*1000])."\n", FILE_APPEND);
        //     // #endregion
        //     return $exists;
        // }
        
        try {
            /** @var Category $categoryModel */
            $categoryModel = ObjectManager::getInstance(Category::class);
            $category = clone $categoryModel;
            
            // 查询启用的分类（只查询启用的分类），使用 handle 字段匹配
            // URL 解码 handle（因为 URL 中可能包含编码字符）
            $decodedHandle = urldecode($categoryHandle);
            
            // #region agent log
            file_put_contents('e:\WelineFramework\DEV-workspace\.cursor\debug.log', json_encode(['sessionId'=>'debug-session','runId'=>'run1','hypothesisId'=>'E','location'=>'Router.php:112','message'=>'querying database','data'=>['categoryHandle'=>$categoryHandle,'decodedHandle'=>$decodedHandle,'handleField'=>Category::fields_HANDLE],'timestamp'=>time()*1000])."\n", FILE_APPEND);
            // #endregion
            
            // 先尝试使用解码后的 handle（精确匹配）
            $category->clear()
                ->where(Category::fields_HANDLE, $decodedHandle)
                ->where(Category::fields_IS_ACTIVE, 1)
                ->find()
                ->fetch();
            
            $exists = (bool)$category->getId();
            $categoryId = $category->getId();
            
            // #region agent log
            file_put_contents('e:\WelineFramework\DEV-workspace\.cursor\debug.log', json_encode(['sessionId'=>'debug-session','runId'=>'run9','hypothesisId'=>'E','location'=>'Router.php:193','message'=>'first query result (exact match)','data'=>['categoryHandle'=>$categoryHandle,'decodedHandle'=>$decodedHandle,'exists'=>$exists,'categoryId'=>$categoryId],'timestamp'=>time()*1000])."\n", FILE_APPEND);
            // #endregion
            
            // 如果精确匹配没找到，尝试匹配以 handle 结尾的完整路径
            // 例如：URL中的 handle 是 "smartphones"，数据库中可能是 "electronics/phones/smartphones"
            if (!$exists) {
                // #region agent log
                file_put_contents('e:\WelineFramework\DEV-workspace\.cursor\debug.log', json_encode(['sessionId'=>'debug-session','runId'=>'run11','hypothesisId'=>'E','location'=>'Router.php:200','message'=>'trying suffix match','data'=>['categoryHandle'=>$categoryHandle,'decodedHandle'=>$decodedHandle],'timestamp'=>time()*1000])."\n", FILE_APPEND);
                // #endregion
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
                
                // #region agent log
                file_put_contents('e:\WelineFramework\DEV-workspace\.cursor\debug.log', json_encode(['sessionId'=>'debug-session','runId'=>'run10','hypothesisId'=>'E','location'=>'Router.php:220','message'=>'suffix match result','data'=>['categoryHandle'=>$categoryHandle,'exists'=>$exists,'categoryId'=>$categoryId,'foundHandle'=>$foundHandle],'timestamp'=>time()*1000])."\n", FILE_APPEND);
                // #endregion
                
                // 如果找到了，更新categoryHandle为完整的handle，以便后续使用
                if ($exists && $foundHandle) {
                    $categoryHandle = $foundHandle;
                }
            }
            
            // 如果使用解码后的 handle 没找到，尝试使用原始 handle（可能数据库存储的就是编码后的）
            if (!$exists && $decodedHandle !== $categoryHandle) {
                // #region agent log
                file_put_contents('e:\WelineFramework\DEV-workspace\.cursor\debug.log', json_encode(['sessionId'=>'debug-session','runId'=>'run9','hypothesisId'=>'E','location'=>'Router.php:225','message'=>'trying original handle','data'=>['categoryHandle'=>$categoryHandle],'timestamp'=>time()*1000])."\n", FILE_APPEND);
                // #endregion
                $category->clear()
                    ->where(Category::fields_HANDLE, $categoryHandle)
                    ->where(Category::fields_IS_ACTIVE, 1)
                    ->find()
                    ->fetch();
                $exists = (bool)$category->getId();
                $categoryId = $category->getId();
                
                // #region agent log
                file_put_contents('e:\WelineFramework\DEV-workspace\.cursor\debug.log', json_encode(['sessionId'=>'debug-session','runId'=>'run9','hypothesisId'=>'E','location'=>'Router.php:234','message'=>'original handle result','data'=>['categoryHandle'=>$categoryHandle,'exists'=>$exists,'categoryId'=>$categoryId],'timestamp'=>time()*1000])."\n", FILE_APPEND);
                // #endregion
            }
            
            // 3. 缓存结果到静态缓存和跨请求缓存
            self::$urlKeyCache[$categoryHandle] = $exists;
            
            // 根据结果设置不同的TTL：找到的缓存1小时，未找到的缓存5分钟
            $ttl = $exists ? self::CACHE_TTL_FOUND : self::CACHE_TTL_NOT_FOUND;
            self::getCrossRequestCache()->set($crossRequestCacheKey, $exists ? '1' : '0', $ttl);
            
            // #region agent log
            file_put_contents('e:\WelineFramework\DEV-workspace\.cursor\debug.log', json_encode(['sessionId'=>'debug-session','runId'=>'run1','hypothesisId'=>'C','location'=>'Router.php:145','message'=>'categoryHandleExists final result','data'=>['categoryHandle'=>$categoryHandle,'exists'=>$exists,'cached'=>true],'timestamp'=>time()*1000])."\n", FILE_APPEND);
            // #endregion
            
            return $exists;
        } catch (\Exception $e) {
            // 如果查询失败，记录日志并返回false
            // #region agent log
            file_put_contents('e:\WelineFramework\DEV-workspace\.cursor\debug.log', json_encode(['sessionId'=>'debug-session','runId'=>'run1','hypothesisId'=>'E','location'=>'Router.php:151','message'=>'database query exception','data'=>['categoryHandle'=>$categoryHandle,'error'=>$e->getMessage()],'timestamp'=>time()*1000])."\n", FILE_APPEND);
            // #endregion
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
