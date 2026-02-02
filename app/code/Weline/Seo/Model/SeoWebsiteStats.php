<?php

declare(strict_types=1);

namespace Weline\Seo\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 站点 SEO 统计数据模型
 *
 * 存储各平台返回的站点统计数据，如：
 * - 索引量（已收录页面数）
 * - 点击量
 * - 展示量
 * - 平均排名
 * - 提交的 URL 数量
 * - 最后同步时间
 *
 * @package Weline_Seo
 */
class SeoWebsiteStats extends Model
{
    public const table = 'weline_seo_website_stats';

    public string $_primary_key = 'id';
    public array $_unit_primary_keys = ['id'];

    // 字段常量
    public const fields_ID = 'id';
    public const fields_WEBSITE_ID = 'website_id';
    public const fields_ACCOUNT_ID = 'account_id';
    public const fields_PLATFORM = 'platform';
    
    // 统计数据字段
    public const fields_INDEXED_PAGES = 'indexed_pages';           // 已索引/收录页面数
    public const fields_SUBMITTED_URLS = 'submitted_urls';         // 已提交 URL 数
    public const fields_CRAWLED_PAGES = 'crawled_pages';           // 已抓取页面数
    public const fields_CLICKS = 'clicks';                         // 点击量（搜索结果点击）
    public const fields_IMPRESSIONS = 'impressions';               // 展示量
    public const fields_CTR = 'ctr';                               // 点击率
    public const fields_AVERAGE_POSITION = 'average_position';     // 平均排名
    
    // 错误统计
    public const fields_ERROR_COUNT = 'error_count';               // 错误页面数
    public const fields_WARNING_COUNT = 'warning_count';           // 警告页面数
    
    // 配额信息
    public const fields_DAILY_QUOTA = 'daily_quota';               // 每日配额
    public const fields_QUOTA_USED = 'quota_used';                 // 已使用配额
    
    // 额外数据
    public const fields_EXTRA_DATA = 'extra_data';                 // JSON 格式的额外数据
    
    // 时间字段
    public const fields_STATS_DATE = 'stats_date';                 // 统计数据日期
    public const fields_LAST_SYNC_AT = 'last_sync_at';             // 最后同步时间
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
            $setup->createTable('站点SEO统计数据表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'primary key auto_increment',
                    '记录ID'
                )
                ->addColumn(
                    self::fields_WEBSITE_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'not null',
                    '站点ID'
                )
                ->addColumn(
                    self::fields_ACCOUNT_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'not null',
                    'SEO账户ID'
                )
                ->addColumn(
                    self::fields_PLATFORM,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'not null',
                    '平台代码：google/bing/baidu'
                )
                // 统计数据
                ->addColumn(
                    self::fields_INDEXED_PAGES,
                    TableInterface::column_type_INTEGER,
                    0,
                    'default 0',
                    '已索引/收录页面数'
                )
                ->addColumn(
                    self::fields_SUBMITTED_URLS,
                    TableInterface::column_type_INTEGER,
                    0,
                    'default 0',
                    '已提交URL数'
                )
                ->addColumn(
                    self::fields_CRAWLED_PAGES,
                    TableInterface::column_type_INTEGER,
                    0,
                    'default 0',
                    '已抓取页面数'
                )
                ->addColumn(
                    self::fields_CLICKS,
                    TableInterface::column_type_INTEGER,
                    0,
                    'default 0',
                    '点击量'
                )
                ->addColumn(
                    self::fields_IMPRESSIONS,
                    TableInterface::column_type_INTEGER,
                    0,
                    'default 0',
                    '展示量'
                )
                ->addColumn(
                    self::fields_CTR,
                    TableInterface::column_type_DECIMAL,
                    '5,2',
                    'default 0.00',
                    '点击率（百分比）'
                )
                ->addColumn(
                    self::fields_AVERAGE_POSITION,
                    TableInterface::column_type_DECIMAL,
                    '5,2',
                    'default 0.00',
                    '平均排名'
                )
                // 错误统计
                ->addColumn(
                    self::fields_ERROR_COUNT,
                    TableInterface::column_type_INTEGER,
                    0,
                    'default 0',
                    '错误页面数'
                )
                ->addColumn(
                    self::fields_WARNING_COUNT,
                    TableInterface::column_type_INTEGER,
                    0,
                    'default 0',
                    '警告页面数'
                )
                // 配额
                ->addColumn(
                    self::fields_DAILY_QUOTA,
                    TableInterface::column_type_INTEGER,
                    0,
                    'default 0',
                    '每日配额'
                )
                ->addColumn(
                    self::fields_QUOTA_USED,
                    TableInterface::column_type_INTEGER,
                    0,
                    'default 0',
                    '已使用配额'
                )
                // 额外数据
                ->addColumn(
                    self::fields_EXTRA_DATA,
                    TableInterface::column_type_TEXT,
                    0,
                    '',
                    '额外数据（JSON）'
                )
                // 时间
                ->addColumn(
                    self::fields_STATS_DATE,
                    TableInterface::column_type_DATE,
                    0,
                    '',
                    '统计数据日期'
                )
                ->addColumn(
                    self::fields_LAST_SYNC_AT,
                    TableInterface::column_type_DATETIME,
                    0,
                    '',
                    '最后同步时间'
                )
                ->addColumn(
                    self::fields_CREATED_AT,
                    TableInterface::column_type_DATETIME,
                    0,
                    '',
                    '创建时间'
                )
                ->addColumn(
                    self::fields_UPDATED_AT,
                    TableInterface::column_type_DATETIME,
                    0,
                    '',
                    '更新时间'
                )
                // 索引
                ->addIndex(
                    TableInterface::index_type_UNIQUE,
                    'unique_website_account_platform_date',
                    [self::fields_WEBSITE_ID, self::fields_ACCOUNT_ID, self::fields_PLATFORM, self::fields_STATS_DATE],
                    '站点-账户-平台-日期唯一索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_website',
                    [self::fields_WEBSITE_ID],
                    '站点索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_account',
                    [self::fields_ACCOUNT_ID],
                    '账户索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_platform',
                    [self::fields_PLATFORM],
                    '平台索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_stats_date',
                    [self::fields_STATS_DATE],
                    '统计日期索引'
                )
                ->create();
        }
    }

    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 暂无升级逻辑
    }

    /**
     * 获取或创建当日统计记录
     */
    public function getOrCreateTodayStats(int $websiteId, int $accountId, string $platform): self
    {
        $today = date('Y-m-d');
        
        $this->reset()
            ->where(self::fields_WEBSITE_ID, $websiteId)
            ->where(self::fields_ACCOUNT_ID, $accountId)
            ->where(self::fields_PLATFORM, $platform)
            ->where(self::fields_STATS_DATE, $today)
            ->find()
            ->fetch();
        
        if (!$this->getId()) {
            $this->setData([
                self::fields_WEBSITE_ID => $websiteId,
                self::fields_ACCOUNT_ID => $accountId,
                self::fields_PLATFORM => $platform,
                self::fields_STATS_DATE => $today,
            ])->save();
        }
        
        return $this;
    }

    /**
     * 获取站点最新统计数据
     */
    public function getLatestStats(int $websiteId, int $accountId, string $platform): ?self
    {
        $this->reset()
            ->where(self::fields_WEBSITE_ID, $websiteId)
            ->where(self::fields_ACCOUNT_ID, $accountId)
            ->where(self::fields_PLATFORM, $platform)
            ->order(self::fields_STATS_DATE, 'DESC')
            ->find()
            ->fetch();
        
        return $this->getId() ? $this : null;
    }

    /**
     * 获取站点所有平台的最新统计数据
     * 
     * @return array ['platform' => stats_data, ...]
     */
    public function getAllPlatformLatestStats(int $websiteId): array
    {
        $result = [];
        
        // 获取该站点所有的统计记录（按日期降序）
        $allStats = $this->reset()
            ->where(self::fields_WEBSITE_ID, $websiteId)
            ->order(self::fields_STATS_DATE, 'DESC')
            ->select()
            ->fetchArray();
        
        // 为每个平台取最新一条
        foreach ($allStats as $stats) {
            $platform = $stats[self::fields_PLATFORM] ?? '';
            if ($platform && !isset($result[$platform])) {
                $result[$platform] = $stats;
            }
        }
        
        return $result;
    }

    /**
     * 更新统计数据
     */
    public function updateStats(array $data): self
    {
        $allowedFields = [
            self::fields_INDEXED_PAGES,
            self::fields_SUBMITTED_URLS,
            self::fields_CRAWLED_PAGES,
            self::fields_CLICKS,
            self::fields_IMPRESSIONS,
            self::fields_CTR,
            self::fields_AVERAGE_POSITION,
            self::fields_ERROR_COUNT,
            self::fields_WARNING_COUNT,
            self::fields_DAILY_QUOTA,
            self::fields_QUOTA_USED,
            self::fields_EXTRA_DATA,
        ];
        
        foreach ($data as $key => $value) {
            if (in_array($key, $allowedFields)) {
                $this->setData($key, $value);
            }
        }
        
        $this->setData(self::fields_LAST_SYNC_AT, date('Y-m-d H:i:s'));
        $this->save();
        
        return $this;
    }

    /**
     * 获取额外数据
     */
    public function getExtraData(): array
    {
        $data = $this->getData(self::fields_EXTRA_DATA);
        if (is_string($data) && !empty($data)) {
            $decoded = json_decode($data, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    /**
     * 设置额外数据
     */
    public function setExtraData(array $data): self
    {
        $this->setData(self::fields_EXTRA_DATA, json_encode($data, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    public function save_before(): void
    {
        $now = date('Y-m-d H:i:s');
        if (!$this->getId()) {
            $this->setData(self::fields_CREATED_AT, $now);
        }
        $this->setData(self::fields_UPDATED_AT, $now);
    }
}
