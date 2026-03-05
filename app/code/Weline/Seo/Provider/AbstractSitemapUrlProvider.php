<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Provider;

use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Interface\SitemapUrlProviderInterface;
use Weline\Seo\Model\SitemapUrl;

/**
 * Sitemap URL 提供者抽象基类
 *
 * 提供通用功能：
 * - 自动同步 URL 到数据库
 * - 增量更新（对比现有数据，只更新变化的 URL）
 * - 统一的数据格式验证
 *
 * 子类只需实现：
 * - getScope()
 * - getModule()
 * - getWebsiteIds()
 * - getUrlsForWebsite()
 * - getDescription()
 *
 * @package Weline_Seo
 */
abstract class AbstractSitemapUrlProvider implements SitemapUrlProviderInterface
{
    protected SitemapUrl $sitemapUrlModel;

    public function __construct()
    {
        $this->sitemapUrlModel = ObjectManager::getInstance(SitemapUrl::class);
    }

    /**
     * 判断 Provider 是否启用（默认启用）
     */
    public function isEnabled(): bool
    {
        return true;
    }

    /**
     * 保存所有站点的 URL 数据到数据库
     *
     * 此方法会遍历所有站点并调用 syncUrls() 进行同步
     *
     * @return int 总共保存的 URL 数量
     */
    public function saveUrls(): int
    {
        $totalUrls = 0;
        $websiteIds = $this->getWebsiteIds();
        
        foreach ($websiteIds as $websiteId) {
            try {
                $result = $this->syncUrls($websiteId);
                // 统计插入和更新的 URL 数量（不包括删除的）
                $totalUrls += ($result['inserted'] ?? 0) + ($result['updated'] ?? 0);
            } catch (\Throwable $e) {
                // 记录错误但继续处理其他站点
                if (defined('DEV') && DEV) {
                    w_log_error(sprintf(
                        '[%s] 同步站点 %d 失败: %s',
                        $this->getModule(),
                        $websiteId,
                        $e->getMessage()
                    ));
                }
            }
        }
        
        return $totalUrls;
    }

    /**
     * 同步 URL 数据到数据库
     *
     * 此方法会：
     * 1. 获取当前 Provider 为指定站点提供的 URL
     * 2. 对比数据库中已存在的 URL
     * 3. 增量更新：插入新 URL，更新已存在的 URL，删除不再存在的 URL
     *
     * @param int $websiteId 站点ID
     * @return array 同步结果 ['inserted' => int, 'updated' => int, 'deleted' => int]
     */
    public function syncUrls(int $websiteId): array
    {
        $scope = $this->getScope();
        $module = $this->getModule();
        
        // 获取新的 URL 数据
        $newUrls = $this->getUrlsForWebsite($websiteId);
        
        // 验证数据格式
        $validatedUrls = $this->validateUrls($newUrls);
        
        // 获取数据库中现有的 URL
        $existingUrls = $this->getExistingUrls($websiteId, $scope, $module);
        
        // 计算差异并执行增量更新
        return $this->performIncrementalUpdate($websiteId, $scope, $module, $validatedUrls, $existingUrls);
    }

    /**
     * 验证 URL 数据格式
     *
     * @param array $urls
     * @return array 验证后的 URL 数组
     */
    protected function validateUrls(array $urls): array
    {
        $validated = [];
        
        foreach ($urls as $url) {
            // 必需字段检查：url_key 作为唯一标识，loc 作为实际URL
            if (empty($url['url_key']) || empty($url['loc'])) {
                continue; // 跳过无效数据
            }
            
            // 从 url_key 中提取 entity_id（如 "page-123" -> 123）
            $entityId = 0;
            $entityType = '';
            if (preg_match('/^([a-z_]+)-(\d+)$/', $url['url_key'], $matches)) {
                $entityType = $matches[1];
                $entityId = (int)$matches[2];
            }
            
            // 标准化数据格式（适配 SitemapUrl 模型字段）
            $validated[] = [
                'url_key' => (string)$url['url_key'],
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'url' => (string)$url['loc'], // loc -> url 字段映射
                'lastmod' => $url['lastmod'] ?? date('Y-m-d'),
                'changefreq' => $url['changefreq'] ?? 'weekly',
                'priority' => isset($url['priority']) ? (string)$url['priority'] : '0.5',
            ];
        }
        
        return $validated;
    }

    /**
     * 获取数据库中已存在的 URL
     *
     * @param int $websiteId
     * @param string $scope
     * @param string $module
     * @return array [url_key => [id, url, ...], ...]
     */
    protected function getExistingUrls(int $websiteId, string $scope, string $module): array
    {
        $rows = $this->sitemapUrlModel->reset()
            ->where(SitemapUrl::schema_fields_WEBSITE_ID, $websiteId)
            ->where(SitemapUrl::schema_fields_SCOPE, $scope)
            ->where(SitemapUrl::schema_fields_MODULE, $module)
            ->select()
            ->fetchArray();
        
        $existing = [];
        foreach ($rows as $row) {
            // 使用 entity_type + entity_id 构建 url_key
            $entityType = $row[SitemapUrl::schema_fields_ENTITY_TYPE] ?? '';
            $entityId = $row[SitemapUrl::schema_fields_ENTITY_ID] ?? 0;
            if ($entityType && $entityId) {
                $urlKey = $entityType . '-' . $entityId;
                $existing[$urlKey] = $row;
            }
        }
        
        return $existing;
    }

    /**
     * 执行增量更新
     *
     * @param int $websiteId
     * @param string $scope
     * @param string $module
     * @param array $newUrls 新的 URL 数据
     * @param array $existingUrls 现有的 URL 数据
     * @return array 统计结果
     */
    protected function performIncrementalUpdate(
        int $websiteId,
        string $scope,
        string $module,
        array $newUrls,
        array $existingUrls
    ): array {
        $inserted = 0;
        $updated = 0;
        $deleted = 0;
        
        $newUrlKeys = [];
        
        // 插入或更新新 URL
        foreach ($newUrls as $urlData) {
            $urlKey = $urlData['url_key'];
            $newUrlKeys[] = $urlKey;
            
            if (isset($existingUrls[$urlKey])) {
                // 已存在，检查是否需要更新
                $existingRow = $existingUrls[$urlKey];
                if ($this->needsUpdate($urlData, $existingRow)) {
                    $this->updateUrl($existingRow[SitemapUrl::schema_fields_ID], $urlData);
                    $updated++;
                }
            } else {
                // 不存在，插入新记录
                $this->insertUrl($websiteId, $scope, $module, $urlData);
                $inserted++;
            }
        }
        
        // 删除不再存在的 URL
        foreach ($existingUrls as $urlKey => $existingRow) {
            if (!in_array($urlKey, $newUrlKeys, true)) {
                $this->deleteUrl($existingRow[SitemapUrl::schema_fields_ID]);
                $deleted++;
            }
        }
        
        return [
            'inserted' => $inserted,
            'updated' => $updated,
            'deleted' => $deleted,
            'total' => count($newUrls),
        ];
    }

    /**
     * 判断 URL 是否需要更新
     *
     * @param array $newData
     * @param array $existingRow
     * @return bool
     */
    protected function needsUpdate(array $newData, array $existingRow): bool
    {
        // 对比关键字段（注意字段映射）
        $comparisons = [
            'url' => SitemapUrl::schema_fields_URL,  // newData['url'] vs existingRow['url']
            'lastmod' => SitemapUrl::schema_fields_LASTMOD,
            'changefreq' => SitemapUrl::schema_fields_CHANGEFREQ,
            'priority' => SitemapUrl::schema_fields_PRIORITY,
        ];
        
        foreach ($comparisons as $newField => $dbField) {
            if (($newData[$newField] ?? '') !== ($existingRow[$dbField] ?? '')) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * 插入新 URL
     */
    protected function insertUrl(int $websiteId, string $scope, string $module, array $urlData): void
    {
        $model = clone $this->sitemapUrlModel;
        $model->setData([
            SitemapUrl::schema_fields_WEBSITE_ID => $websiteId,
            SitemapUrl::schema_fields_SCOPE => $scope,
            SitemapUrl::schema_fields_MODULE => $module,
            SitemapUrl::schema_fields_ENTITY_TYPE => $urlData['entity_type'] ?? '',
            SitemapUrl::schema_fields_ENTITY_ID => $urlData['entity_id'] ?? 0,
            SitemapUrl::schema_fields_URL => $urlData['url'], // 使用 url 字段
            SitemapUrl::schema_fields_LASTMOD => $urlData['lastmod'],
            SitemapUrl::schema_fields_CHANGEFREQ => $urlData['changefreq'],
            SitemapUrl::schema_fields_PRIORITY => $urlData['priority'],
            SitemapUrl::schema_fields_STATUS => 1, // active
        ]);
        $model->save();
    }

    /**
     * 更新现有 URL
     */
    protected function updateUrl(int $id, array $urlData): void
    {
        $model = clone $this->sitemapUrlModel;
        $model->load($id);
        $model->setData([
            SitemapUrl::schema_fields_URL => $urlData['url'], // 使用 url 字段
            SitemapUrl::schema_fields_LASTMOD => $urlData['lastmod'],
            SitemapUrl::schema_fields_CHANGEFREQ => $urlData['changefreq'],
            SitemapUrl::schema_fields_PRIORITY => $urlData['priority'],
        ]);
        $model->save();
    }

    /**
     * 删除 URL
     */
    protected function deleteUrl(int $id): void
    {
        $model = clone $this->sitemapUrlModel;
        $model->load($id);
        $model->delete();
    }
}
