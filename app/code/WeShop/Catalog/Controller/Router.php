<?php

declare(strict_types=1);

namespace WeShop\Catalog\Controller;

use Weline\Framework\App\Debug;
use WeShop\Catalog\Model\Category;
use WeShop\Catalog\Service\CategoryService;
use Weline\Framework\Http\Request;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\RouterInterface;

/**
 * Catalog 分类路由重写器
 * 
 * 功能：将友好的URL路径重写为分类查看路由
 * 例如：/catalog/category/foldable -> /catalog/frontend/category/view?handle=foldable
 * 
 * 注意：分类 handle 使用 Category::schema_fields_HANDLE 字段进行匹配
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
    private static ?CachePoolInterface $crossRequestCache = null;
    
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

        // 3. 跳过货币/语言前缀（如 CNY/en_US/）
        // 常见格式：{currency}/{locale}/catalog/category/... 或 {locale}/catalog/category/...
        $prefix = 'catalog/category/';
        $workingPath = $path;
        
        // 如果路径不是以 catalog/category/ 开头，尝试跳过前缀
        if (!str_starts_with($workingPath, $prefix)) {
            // 尝试查找 catalog/category/ 在路径中的位置
            $catalogPos = strpos($workingPath, $prefix);
            
            if ($catalogPos !== false) {
                // 提取 catalog/category/ 及其后面的部分
                $workingPath = substr($workingPath, $catalogPos);
            } else {
                // 路径中不包含 catalog/category/，跳过
                return;
            }
        }

        // 4. 取 catalog/category/ 后面的全部作为 handle（支持层级路径，如 men/shirts）
        $categoryHandle = substr($workingPath, strlen($prefix));
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
        self::setQueryParam('handle', $categoryHandle);
    }

    private static function setQueryParam(string $key, mixed $value): void
    {
        \Weline\Framework\Context::current()->set('input.query.' . $key, $value);
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
        $cachedResult = boolval(self::getCrossRequestCache()->get($crossRequestCacheKey));
        
        // 暂时跳过缓存，直接查询数据库（因为需要支持suffix匹配）
        if ($cachedResult) {
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
            // URL 解码 handle（因为 URL 中可能包含编码字符）
            $decodedHandle = urldecode($categoryHandle);
            
            // 先尝试使用解码后的 handle（精确匹配，支持直接存储层级路径的场景）
            // SELECT "main_table".* FROM "public"."m_weshop_category" AS "main_table" WHERE (is_active = '1') AND (handle = 'smartphones') LIMIT 1 OFFSET 0
            $category->clear()
                ->where(Category::schema_fields_HANDLE, $decodedHandle)
                ->where(Category::schema_fields_IS_ACTIVE, 1)
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
                    ->where(Category::schema_fields_HANDLE, '%/' . $decodedHandle, 'like')
                    ->where(Category::schema_fields_IS_ACTIVE, 1)
                    ->find()
                    ->fetch();
                
                // 如果还是没找到，尝试匹配以 handle 结尾的路径（不包含前导斜杠）
                if (!$category->getId()) {
                    $category->clear()
                        ->where(Category::schema_fields_HANDLE, '%' . $decodedHandle, 'like')
                        ->where(Category::schema_fields_IS_ACTIVE, 1)
                        ->find()
                        ->fetch();
                }
                
                $exists = (bool)$category->getId();
                $categoryId = $category->getId();
                $foundHandle = $category->getId() ? $category->getData(Category::schema_fields_HANDLE) : '';
                
                // 如果找到了，更新categoryHandle为完整的handle，以便后续使用
                if ($exists && $foundHandle) {
                    $categoryHandle = $foundHandle;
                }
            }
            
            // 如果使用解码后的 handle 没找到，尝试使用原始 handle（可能数据库存储的就是编码后的）
            if (!$exists && $decodedHandle !== $categoryHandle) {   
                $category->clear()
                    ->where(Category::schema_fields_HANDLE, $categoryHandle)
                    ->where(Category::schema_fields_IS_ACTIVE, 1)
                    ->find()
                    ->fetch();
                $exists = (bool)$category->getId();
                $categoryId = $category->getId();
                
            }

            // 额外兜底：如果仍未找到，且 handle 为层级路径（如 men/shirts），
            // 则使用最后一段（shirts）作为 handle 进行匹配，兼容「平铺 handle + 层级 URL」的用法
            if (!$exists && str_contains($decodedHandle, '/')) {
                $leafHandle = basename($decodedHandle);
                if ($leafHandle !== '') {
                    $category->clear()
                        ->where(Category::schema_fields_HANDLE, $leafHandle)
                        ->where(Category::schema_fields_IS_ACTIVE, 1)
                        ->find()
                        ->fetch();

                    $exists = (bool)$category->getId();
                    $categoryId = $category->getId();
                }
            }

            // 计算分类层级结构，并挂载到 Request 对象，方便后续（如面包屑）统一使用
            if ($exists && $category->getId()) {
                /** @var Request $request */
                $request = ObjectManager::getInstance(Request::class);

                // 从当前分类向上递归，构建「从根到当前」的分类链
                $nodes = [];
                $currentNode = clone $category;
                while ($currentNode && $currentNode->getId()) {
                    array_unshift($nodes, clone $currentNode);
                    $parentId = (int)($currentNode->getData(Category::schema_fields_PARENT_ID) ?? 0);
                    if ($parentId <= 0) {
                        break;
                    }
                    $currentNode = clone $categoryModel;
                    $currentNode->clear()->load($parentId);
                }

                // 构建两种结构：
                // 1）breadcrumbs：有父子关系的数组（不含当前节点，用于面包屑）
                // 2）handle 水平结构：从根到当前的 handle/路径，便于生成 URL
                $breadcrumbs = [];
                $pathSegments = [];
                $nodesCount = count($nodes);

                foreach ($nodes as $index => $node) {
                    $handle = trim((string)($node->getData(Category::schema_fields_HANDLE) ?? ''), '/');
                    if ($handle === '') {
                        continue;
                    }
                    $pathSegments[] = $handle;
                    $path = implode('/', $pathSegments);

                    $item = [
                        'category_id' => (int)$node->getId(),
                        'name'        => (string)($node->getData(Category::schema_fields_NAME) ?? ''),
                        'handle'      => $handle,
                        'path'        => $path,
                    ];

                    // 父级链：不包含最后一个（当前分类）
                    if ($index < $nodesCount - 1) {
                        $breadcrumbs[] = $item;
                    } else {
                        // 最后一个视为当前分类
                        $currentCategory = $item;
                        $currentCategory['description'] = (string)($node->getData(Category::schema_fields_DESCRIPTION) ?? '');
                        $currentCategory['image'] = (string)($node->getData(Category::schema_fields_IMAGE) ?? '');
                        $currentCategory['parent_id'] = (int)($node->getData(Category::schema_fields_PARENT_ID) ?? 0);
                        $currentCategory['sort_order'] = (int)($node->getData(Category::schema_fields_SORT_ORDER) ?? 0);
                    }
                }

                // 统一结构：categories
                // - current：当前分类（包含 path、基本字段）
                // - breadcrumbs：父级分类数组（有父子结构）
                // - path：从根到当前的 handle 水平结构（如 electronics/phones/smartphones）
                $categoriesData = [
                    'current'     => $currentCategory ?? null,
                    'breadcrumbs' => $breadcrumbs,
                    'path'        => implode('/', $pathSegments),
                ];

                $request->setData('categories', $categoriesData);
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
                w_log_error('Catalog Router Error: ' . $e->getMessage(), [], 'catalog_router');
            }
            return false;
        }
    }
    
    /**
     * 获取跨请求缓存实例
     * 
     * @return CachePoolInterface
     */
    private static function getCrossRequestCache(): CachePoolInterface
    {
        if (self::$crossRequestCache === null) {
            self::$crossRequestCache = w_cache('default');
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
