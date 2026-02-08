<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * Sitemap URL 模型
 *
 * 存储所有模块提供的 sitemap URL 数据
 *
 * @package Weline_Seo
 */
class SitemapUrl extends Model
{
    public const table = 'weline_sitemap_url';

    public string $_primary_key = 'url_id';
    public array $_unit_primary_keys = ['url_id'];

    public const fields_ID = 'url_id';
    public const fields_WEBSITE_ID = 'website_id';
    public const fields_MODULE = 'module';
    public const fields_SCOPE = 'scope';
    public const fields_ENTITY_TYPE = 'entity_type';
    public const fields_ENTITY_ID = 'entity_id';
    public const fields_URL = 'url';
    public const fields_CHANGEFREQ = 'changefreq';
    public const fields_PRIORITY = 'priority';
    public const fields_LASTMOD = 'lastmod';
    public const fields_STATUS = 'status';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::fields_ID;
    }

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist() === false) {
            $setup->createTable('Sitemap URL 数据表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'primary key auto_increment',
                    'URL ID'
                )
                ->addColumn(
                    self::fields_WEBSITE_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'not null default 0',
                    '站点 ID'
                )
                ->addColumn(
                    self::fields_MODULE,
                    TableInterface::column_type_VARCHAR,
                    100,
                    'not null',
                    '模块标识，如 GuoLaiRen_PageBuilder'
                )
                ->addColumn(
                    self::fields_SCOPE,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'default \'\'',
                    '业务范围标识，如 page_builder'
                )
                ->addColumn(
                    self::fields_ENTITY_TYPE,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'default \'\'',
                    '实体类型，如 page、product'
                )
                ->addColumn(
                    self::fields_ENTITY_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'default 0',
                    '实体 ID'
                )
                ->addColumn(
                    self::fields_URL,
                    TableInterface::column_type_TEXT,
                    0,
                    'not null',
                    'URL 地址'
                )
                ->addColumn(
                    self::fields_CHANGEFREQ,
                    TableInterface::column_type_VARCHAR,
                    20,
                    'default \'weekly\'',
                    '更新频率：always, hourly, daily, weekly, monthly, yearly, never'
                )
                ->addColumn(
                    self::fields_PRIORITY,
                    TableInterface::column_type_VARCHAR,
                    10,
                    'default \'0.5\'',
                    '优先级：0.0 - 1.0'
                )
                ->addColumn(
                    self::fields_LASTMOD,
                    TableInterface::column_type_DATETIME,
                    0,
                    'null',
                    '最后修改时间'
                )
                ->addColumn(
                    self::fields_STATUS,
                    TableInterface::column_type_SMALLINT,
                    1,
                    'default 1',
                    '状态：1=启用，0=禁用'
                )
                ->addColumn(
                    self::fields_CREATED_AT,
                    TableInterface::column_type_DATETIME,
                    0,
                    'default current_timestamp',
                    '创建时间'
                )
                ->addColumn(
                    self::fields_UPDATED_AT,
                    TableInterface::column_type_DATETIME,
                    0,
                    'default current_timestamp on update current_timestamp',
                    '更新时间'
                )
                ->addIndex(TableInterface::index_type_KEY, 'idx_website_id', self::fields_WEBSITE_ID, '站点索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_module', self::fields_MODULE, '模块索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_status', self::fields_STATUS, '状态索引')
                ->addIndex(
                    TableInterface::index_type_UNIQUE,
                    'idx_unique_url',
                    self::fields_WEBSITE_ID . ',' . self::fields_MODULE . ',' . self::fields_ENTITY_TYPE . ',' . self::fields_ENTITY_ID,
                    '唯一索引'
                )
                ->create();
        }
    }

    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 暂无升级逻辑
    }

    /**
     * 获取站点的所有活跃 URL（按模块分组）
     *
     * @param int $websiteId
     * @return array ['module_scope' => [url1, url2, ...], ...]
     */
    public function getActiveUrlsByWebsiteGrouped(int $websiteId): array
    {
        $urls = $this->reset()
            ->where(self::fields_WEBSITE_ID, $websiteId)
            ->where(self::fields_STATUS, 1)
            ->order(self::fields_MODULE, 'ASC')
            ->order(self::fields_ENTITY_TYPE, 'ASC')
            ->order(self::fields_ENTITY_ID, 'ASC')
            ->select()
            ->fetchArray();

        $grouped = [];
        foreach ($urls as $url) {
            $module = $url[self::fields_MODULE] ?? 'default';
            $scope = $url[self::fields_SCOPE] ?? '';
            $key = $scope ? $module . '_' . $scope : $module;
            
            if (!isset($grouped[$key])) {
                $grouped[$key] = [];
            }
            
            $grouped[$key][] = $url;
        }

        return $grouped;
    }

    /**
     * 获取站点的所有活跃 URL
     *
     * @param int $websiteId
     * @return array
     */
    public function getActiveUrls(int $websiteId): array
    {
        return $this->reset()
            ->where(self::fields_WEBSITE_ID, $websiteId)
            ->where(self::fields_STATUS, 1)
            ->select()
            ->fetchArray();
    }

    /**
     * 获取站点的活跃 URL 数量
     *
     * @param int $websiteId
     * @return int
     */
    public function getActiveUrlCount(int $websiteId): int
    {
        return (int)$this->reset()
            ->where(self::fields_WEBSITE_ID, $websiteId)
            ->where(self::fields_STATUS, 1)
            ->select()
            ->count();
    }

    /**
     * 批量保存或更新 URL
     *
     * @param array $urls
     * @return bool
     */
    public function batchSaveUrls(array $urls): bool
    {
        if (empty($urls)) {
            return true;
        }

        try {
            foreach ($urls as $url) {
                $websiteId = $url['website_id'] ?? 0;
                $module = $url['module'] ?? '';
                $entityType = $url['entity_type'] ?? '';
                $entityId = $url['entity_id'] ?? 0;

                if (!$websiteId || !$module) {
                    continue;
                }

                // 查找现有记录
                $existing = $this->reset()
                    ->where(self::fields_WEBSITE_ID, $websiteId)
                    ->where(self::fields_MODULE, $module)
                    ->where(self::fields_ENTITY_TYPE, $entityType)
                    ->where(self::fields_ENTITY_ID, $entityId)
                    ->find()
                    ->fetch();

                if ($existing) {
                    // 更新
                    $this->reset()->load($existing[self::fields_ID]);
                    $this->setData(self::fields_URL, $url['url'] ?? '');
                    $this->setData(self::fields_CHANGEFREQ, $url['changefreq'] ?? 'weekly');
                    $this->setData(self::fields_PRIORITY, $url['priority'] ?? '0.5');
                    $this->setData(self::fields_LASTMOD, $url['lastmod'] ?? date('Y-m-d H:i:s'));
                    $this->setData(self::fields_STATUS, $url['status'] ?? 1);
                    $this->save();
                } else {
                    // 插入
                    $this->reset()->setData([
                        self::fields_WEBSITE_ID => $websiteId,
                        self::fields_MODULE => $module,
                        self::fields_SCOPE => $url['scope'] ?? '',
                        self::fields_ENTITY_TYPE => $entityType,
                        self::fields_ENTITY_ID => $entityId,
                        self::fields_URL => $url['url'] ?? '',
                        self::fields_CHANGEFREQ => $url['changefreq'] ?? 'weekly',
                        self::fields_PRIORITY => $url['priority'] ?? '0.5',
                        self::fields_LASTMOD => $url['lastmod'] ?? date('Y-m-d H:i:s'),
                        self::fields_STATUS => $url['status'] ?? 1,
                    ])->save();
                }
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 获取站点的 URL 按 scope 统计
     *
     * @param int $websiteId
     * @return array [['scope' => string, 'module' => string, 'count' => int], ...]
     */
    public function getScopeStats(int $websiteId): array
    {
        $urls = $this->reset()
            ->where(self::fields_WEBSITE_ID, $websiteId)
            ->where(self::fields_STATUS, 1)
            ->select()
            ->fetchArray();

        $stats = [];
        foreach ($urls as $url) {
            $scope = $url[self::fields_SCOPE] ?? '';
            $module = $url[self::fields_MODULE] ?? '';
            $key = $scope . '|' . $module;

            if (!isset($stats[$key])) {
                $stats[$key] = [
                    'scope' => $scope,
                    'module' => $module,
                    'count' => 0,
                ];
            }
            $stats[$key]['count']++;
        }

        return array_values($stats);
    }

    /**
     * 删除站点的所有 URL
     *
     * @param int $websiteId
     * @return bool
     */
    public function deleteByWebsite(int $websiteId): bool
    {
        try {
            $this->reset()
                ->where(self::fields_WEBSITE_ID, $websiteId)
                ->delete();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 删除模块的所有 URL
     *
     * @param string $module
     * @param int $websiteId
     * @return bool
     */
    public function deleteByModule(string $module, int $websiteId = 0): bool
    {
        try {
            $this->reset()->where(self::fields_MODULE, $module);
            if ($websiteId > 0) {
                $this->where(self::fields_WEBSITE_ID, $websiteId);
            }
            $this->delete();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
