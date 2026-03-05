<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/** Sitemap URL 模型 - 存储各模块提供的 sitemap URL */
#[Table(comment: 'Sitemap URL 数据表')]
#[Index(name: 'idx_website_id', columns: ['website_id'])]
#[Index(name: 'idx_module', columns: ['module'])]
#[Index(name: 'idx_status', columns: ['status'])]
#[Index(name: 'idx_unique_url', columns: ['website_id', 'module', 'entity_type', 'entity_id'], type: 'UNIQUE')]
class SitemapUrl extends Model
{

    public const schema_table = 'weline_sitemap_url';
    public const schema_primary_key = 'url_id';
    public array $_unit_primary_keys = ['url_id'];
    #[Col('int', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: 'URL ID')]
    public const schema_fields_ID = 'url_id';
    #[Col('int', 0, nullable: false, default: 0, comment: '站点ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col('varchar', 100, nullable: false, comment: '模块标识')]
    public const schema_fields_MODULE = 'module';
    #[Col('varchar', 50, nullable: false, default: '', comment: '业务范围')]
    public const schema_fields_SCOPE = 'scope';
    #[Col('varchar', 50, nullable: false, default: '', comment: '实体类型')]
    public const schema_fields_ENTITY_TYPE = 'entity_type';
    #[Col('int', 0, nullable: false, default: 0, comment: '实体ID')]
    public const schema_fields_ENTITY_ID = 'entity_id';
    #[Col('text', null, false, comment: 'URL地址')]
    public const schema_fields_URL = 'url';
    #[Col('varchar', 20, nullable: false, default: 'weekly', comment: '更新频率')]
    public const schema_fields_CHANGEFREQ = 'changefreq';
    #[Col('varchar', 10, nullable: false, default: '0.5', comment: '优先级')]
    public const schema_fields_PRIORITY = 'priority';
    #[Col('datetime', comment: '最后修改时间')]
    public const schema_fields_LASTMOD = 'lastmod';
    #[Col('smallint', 1, nullable: false, default: 1, comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
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
            ->where(self::schema_fields_WEBSITE_ID, $websiteId)
            ->where(self::schema_fields_STATUS, 1)
            ->order(self::schema_fields_MODULE, 'ASC')
            ->order(self::schema_fields_ENTITY_TYPE, 'ASC')
            ->order(self::schema_fields_ENTITY_ID, 'ASC')
            ->select()
            ->fetchArray();

        $grouped = [];
        foreach ($urls as $url) {
            $module = $url[self::schema_fields_MODULE] ?? 'default';
            $scope = $url[self::schema_fields_SCOPE] ?? '';
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
            ->where(self::schema_fields_WEBSITE_ID, $websiteId)
            ->where(self::schema_fields_STATUS, 1)
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
            ->where(self::schema_fields_WEBSITE_ID, $websiteId)
            ->where(self::schema_fields_STATUS, 1)
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
                    ->where(self::schema_fields_WEBSITE_ID, $websiteId)
                    ->where(self::schema_fields_MODULE, $module)
                    ->where(self::schema_fields_ENTITY_TYPE, $entityType)
                    ->where(self::schema_fields_ENTITY_ID, $entityId)
                    ->find()
                    ->fetch();

                if ($existing) {
                    // 更新
                    $this->reset()->load($existing[self::schema_fields_ID]);
                    $this->setData(self::schema_fields_URL, $url['url'] ?? '');
                    $this->setData(self::schema_fields_CHANGEFREQ, $url['changefreq'] ?? 'weekly');
                    $this->setData(self::schema_fields_PRIORITY, $url['priority'] ?? '0.5');
                    $this->setData(self::schema_fields_LASTMOD, $url['lastmod'] ?? date('Y-m-d H:i:s'));
                    $this->setData(self::schema_fields_STATUS, $url['status'] ?? 1);
                    $this->save();
                } else {
                    // 插入
                    $this->reset()->setData([
                        self::schema_fields_WEBSITE_ID => $websiteId,
                        self::schema_fields_MODULE => $module,
                        self::schema_fields_SCOPE => $url['scope'] ?? '',
                        self::schema_fields_ENTITY_TYPE => $entityType,
                        self::schema_fields_ENTITY_ID => $entityId,
                        self::schema_fields_URL => $url['url'] ?? '',
                        self::schema_fields_CHANGEFREQ => $url['changefreq'] ?? 'weekly',
                        self::schema_fields_PRIORITY => $url['priority'] ?? '0.5',
                        self::schema_fields_LASTMOD => $url['lastmod'] ?? date('Y-m-d H:i:s'),
                        self::schema_fields_STATUS => $url['status'] ?? 1,
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
            ->where(self::schema_fields_WEBSITE_ID, $websiteId)
            ->where(self::schema_fields_STATUS, 1)
            ->select()
            ->fetchArray();

        $stats = [];
        foreach ($urls as $url) {
            $scope = $url[self::schema_fields_SCOPE] ?? '';
            $module = $url[self::schema_fields_MODULE] ?? '';
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
                ->where(self::schema_fields_WEBSITE_ID, $websiteId)
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
            $this->reset()->where(self::schema_fields_MODULE, $module);
            if ($websiteId > 0) {
                $this->where(self::schema_fields_WEBSITE_ID, $websiteId);
            }
            $this->delete();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}

