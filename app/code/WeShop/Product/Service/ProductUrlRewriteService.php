<?php

declare(strict_types=1);

namespace WeShop\Product\Service;

use WeShop\Product\Controller\Router;
use WeShop\Product\Model\Product;
use WeShop\Product\Model\ProductWebsite;
use Weline\Framework\Manager\ObjectManager;
use Weline\UrlManager\Model\UrlRewrite;

/**
 * 产品 URL 重写服务
 * 
 * 负责管理产品的 SEO URL 重写规则：
 * - 产品保存时：按站点维度生成/更新 URL 重写规则
 * - 产品删除时：清理相关的 URL 重写规则
 * - 处理 Handle 冲突检测和自动后缀
 * 
 * 注意：UrlManager 的实际读取方向是 rewrite(外部 URL) -> path(内部路由)。
 * 产品规则需要把外部商品链接写入 rewrite，把内部控制器路径写入 path。
 */
class ProductUrlRewriteService
{
    private UrlRewrite $urlRewrite;
    private ProductWebsite $productWebsite;

    /**
     * URL 重写的目标路径模板
     */
    private const TARGET_PATH_TEMPLATE = '/product/view?id={product_id}';
    private const LEGACY_TARGET_PATH_TEMPLATE = '/weshop/product/view?id={product_id}';
    
    /**
     * 产品 URL 前缀
     */
    private const URL_PREFIX = 'product/';

    public function __construct(
        UrlRewrite $urlRewrite,
        ProductWebsite $productWebsite
    ) {
        $this->urlRewrite = $urlRewrite;
        $this->productWebsite = $productWebsite;
    }

    /**
     * 为产品生成/更新 URL 重写规则
     * 
     * @param Product $product 产品对象
     * @return array 生成的重写规则列表
     */
    public function syncProductUrlRewrites(Product $product): array
    {
        $productId = (int)$product->getId();
        if (!$productId) {
            return [];
        }

        $rewrites = [];

        // 1. 获取产品在各站点的配置
        $websiteConfigs = $this->productWebsite->getWebsitesByProduct($productId);

        // 2. 为每个站点配置生成 URL 重写
        foreach ($websiteConfigs as $config) {
            $websiteId = (int)($config[ProductWebsite::schema_fields_WEBSITE_ID] ?? 0);
            $handle = $config[ProductWebsite::schema_fields_HANDLE] ?? '';
            $isActive = (bool)($config[ProductWebsite::schema_fields_IS_ACTIVE] ?? true);

            if (empty($handle) || !$isActive) {
                continue;
            }

            $rewrite = $this->upsertUrlRewrite($productId, $websiteId, $handle);
            if ($rewrite) {
                $rewrites[] = $rewrite;
            }
        }

        // 3. 如果产品有全局 handle（向后兼容）
        $globalHandle = $product->getData(Product::schema_fields_HANDLE);
        if (!empty($globalHandle)) {
            // 检查是否已经有站点配置覆盖了这个 handle
            $hasGlobalConfig = false;
            foreach ($websiteConfigs as $config) {
                if (($config[ProductWebsite::schema_fields_WEBSITE_ID] ?? -1) == 0) {
                    $hasGlobalConfig = true;
                    break;
                }
            }

            // 如果没有全局站点配置，使用产品表的 handle
            if (!$hasGlobalConfig) {
                $rewrite = $this->upsertUrlRewrite($productId, 0, $globalHandle);
                if ($rewrite) {
                    $rewrites[] = $rewrite;
                }
            }
        }

        // 4. 清理缓存
        $this->clearProductCache($productId, $websiteConfigs, $globalHandle);

        return $rewrites;
    }

    /**
     * 创建或更新 URL 重写规则
     * 
     * @param int $productId 产品ID
     * @param int $websiteId 站点ID
     * @param string $handle Handle
     * @return array|null 重写规则数据，失败返回 null
     */
    public function upsertUrlRewrite(int $productId, int $websiteId, string $handle): ?array
    {
        $publicPath = self::URL_PREFIX . $handle;
        $targetPath = str_replace('{product_id}', (string)$productId, self::TARGET_PATH_TEMPLATE);
        $urlId = sprintf('product_%d_%d', $websiteId, $productId);

        try {
            // 查找是否已存在相同的重写规则（通过内部目标路径匹配）
            $existing = $this->findExistingRewrite($productId, $websiteId);

            if ($existing) {
                // 更新现有规则
                $this->urlRewrite->reset()->load($existing[UrlRewrite::schema_fields_ID] ?? 0);
                
                // 检查外部 URL 是否发生变化
                if (($existing[UrlRewrite::schema_fields_REWRITE] ?? '') !== $publicPath) {
                    // Handle 变化了，需要检查新 handle 是否可用
                    if (!$this->isHandleAvailable($websiteId, $handle, $productId)) {
                        // Handle 冲突，使用自动后缀
                        $handle = $this->generateUniqueHandle($websiteId, $handle, $productId);
                        $publicPath = self::URL_PREFIX . $handle;
                    }
                }

                $this->urlRewrite
                    ->setData(UrlRewrite::schema_fields_URL_ID, $urlId)
                    ->setData(UrlRewrite::schema_fields_URL_IDENTIFY, $urlId)
                    ->setData(UrlRewrite::schema_fields_PATH, ltrim($targetPath, '/'))
                    ->setData(UrlRewrite::schema_fields_REWRITE, $publicPath)
                    ->save();
            } else {
                // 新建规则
                // 检查 handle 是否可用
                if (!$this->isHandleAvailable($websiteId, $handle, $productId)) {
                    // Handle 冲突，使用自动后缀
                    $handle = $this->generateUniqueHandle($websiteId, $handle, $productId);
                    $publicPath = self::URL_PREFIX . $handle;
                }

                $this->urlRewrite->reset()
                    ->setData(UrlRewrite::schema_fields_URL_ID, $urlId)
                    ->setData(UrlRewrite::schema_fields_WEBSITE_ID, $websiteId)
                    ->setData(UrlRewrite::schema_fields_URL_IDENTIFY, $urlId)
                    ->setData(UrlRewrite::schema_fields_PATH, ltrim($targetPath, '/'))
                    ->setData(UrlRewrite::schema_fields_REWRITE, $publicPath)
                    ->save();
            }

            return [
                'website_id' => $websiteId,
                'url_path' => $publicPath,
                'target_path' => $targetPath,
                'handle' => $handle,
            ];
        } catch (\Exception $e) {
            // 记录错误但不中断流程
            return null;
        }
    }

    /**
     * 删除产品的所有 URL 重写规则
     * 
     * @param int $productId 产品ID
     * @return int 删除的规则数量
     */
    public function deleteProductUrlRewrites(int $productId): int
    {
        try {
            $deletedCount = 0;
            $targetPath = str_replace('{product_id}', (string)$productId, self::TARGET_PATH_TEMPLATE);

            // 查找所有相关的重写规则（通过内部目标路径匹配）
            $rewrites = $this->urlRewrite->reset()
                ->where(UrlRewrite::schema_fields_PATH, ltrim($targetPath, '/'))
                ->select()
                ->fetch();

            if (is_array($rewrites)) {
                foreach ($rewrites as $rewrite) {
                    $this->urlRewrite->reset()->load($rewrite[UrlRewrite::schema_fields_ID] ?? 0);
                    if ($this->urlRewrite->getId()) {
                        $this->urlRewrite->delete();
                        $deletedCount++;
                    }
                }
            }

            // 清理缓存
            Router::clearCache();

            return $deletedCount;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * 删除产品在指定站点的 URL 重写规则
     * 
     * @param int $productId 产品ID
     * @param int $websiteId 站点ID
     * @return bool
     */
    public function deleteProductWebsiteUrlRewrite(int $productId, int $websiteId): bool
    {
        try {
            $existing = $this->findExistingRewrite($productId, $websiteId);

            if ($existing) {
                $this->urlRewrite->reset()->load($existing[UrlRewrite::schema_fields_ID] ?? 0);
                $this->urlRewrite->delete();
                return true;
            }

            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 查找产品在指定站点的现有重写规则
     * 
     * 通过目标路径（rewrite）匹配产品
     * 
     * @param int $productId 产品ID
     * @param int $websiteId 站点ID
     * @return array|null
     */
    private function findExistingRewrite(int $productId, int $websiteId): ?array
    {
        try {
            $targetPath = str_replace('{product_id}', (string)$productId, self::TARGET_PATH_TEMPLATE);
            
            foreach ([self::TARGET_PATH_TEMPLATE, self::LEGACY_TARGET_PATH_TEMPLATE] as $template) {
                $targetPath = str_replace('{product_id}', (string)$productId, $template);
                $this->urlRewrite->reset()
                    ->where(UrlRewrite::schema_fields_PATH, ltrim($targetPath, '/'))
                    ->where(UrlRewrite::schema_fields_WEBSITE_ID, $websiteId)
                    ->find()
                    ->fetch();

                if ($this->urlRewrite->getId()) {
                    return $this->urlRewrite->getData();
                }
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 检查 handle 在指定站点是否可用
     * 
     * @param int $websiteId 站点ID
     * @param string $handle Handle
     * @param int|null $excludeProductId 排除的产品ID
     * @return bool
     */
    public function isHandleAvailable(int $websiteId, string $handle, ?int $excludeProductId = null): bool
    {
        $publicPath = self::URL_PREFIX . $handle;

        try {
            $this->urlRewrite->reset()
                ->where(UrlRewrite::schema_fields_WEBSITE_ID, $websiteId)
                ->where(UrlRewrite::schema_fields_REWRITE, $publicPath);

            if ($excludeProductId) {
                $excludeTargetPath = str_replace('{product_id}', (string)$excludeProductId, self::TARGET_PATH_TEMPLATE);
                $this->urlRewrite->where(UrlRewrite::schema_fields_PATH, ['<>', ltrim($excludeTargetPath, '/')]);
            }

            $this->urlRewrite->find()->fetch();

            return !$this->urlRewrite->getId();
        } catch (\Exception $e) {
            // 如果查询出错，假设可用
            return true;
        }
    }

    /**
     * 生成唯一的 handle（通过添加后缀）
     * 
     * @param int $websiteId 站点ID
     * @param string $baseHandle 基础 handle
     * @param int|null $excludeProductId 排除的产品ID
     * @return string 唯一的 handle
     */
    public function generateUniqueHandle(int $websiteId, string $baseHandle, ?int $excludeProductId = null): string
    {
        $handle = $baseHandle;
        $suffix = 1;

        while (!$this->isHandleAvailable($websiteId, $handle, $excludeProductId)) {
            $handle = $baseHandle . '-' . $suffix;
            $suffix++;

            // 防止无限循环
            if ($suffix > 1000) {
                $handle = $baseHandle . '-' . uniqid();
                break;
            }
        }

        return $handle;
    }

    /**
     * 清理产品相关的缓存
     * 
     * @param int $productId 产品ID
     * @param array $websiteConfigs 站点配置
     * @param string|null $globalHandle 全局 handle
     */
    private function clearProductCache(int $productId, array $websiteConfigs, ?string $globalHandle = null): void
    {
        // 清理每个站点的缓存
        foreach ($websiteConfigs as $config) {
            $websiteId = (int)($config[ProductWebsite::schema_fields_WEBSITE_ID] ?? 0);
            $handle = $config[ProductWebsite::schema_fields_HANDLE] ?? '';
            
            if (!empty($handle)) {
                Router::clearHandleCache($handle, $websiteId);
            }
        }

        // 清理全局 handle 缓存
        if (!empty($globalHandle)) {
            Router::clearHandleCache($globalHandle, null);
        }
    }

    /**
     * 批量同步产品 URL 重写（用于升级或批量操作）
     * 
     * @param array $productIds 产品ID列表
     * @param callable|null $progressCallback 进度回调函数
     * @return array 统计结果
     */
    public function batchSyncUrlRewrites(array $productIds, ?callable $progressCallback = null): array
    {
        $stats = [
            'total' => count($productIds),
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        foreach ($productIds as $index => $productId) {
            try {
                /** @var Product $product */
                $product = ObjectManager::getInstance(Product::class);
                $product = clone $product;
                $product->load($productId);

                if (!$product->getId()) {
                    $stats['skipped']++;
                    continue;
                }

                $rewrites = $this->syncProductUrlRewrites($product);
                
                if (!empty($rewrites)) {
                    $stats['success']++;
                } else {
                    $stats['skipped']++;
                }
            } catch (\Exception $e) {
                $stats['failed']++;
            }

            // 调用进度回调
            if ($progressCallback) {
                $progressCallback($index + 1, $stats['total'], $productId);
            }
        }

        return $stats;
    }
}
