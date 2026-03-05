<?php
declare(strict_types=1);
namespace Weline\Seo\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/** 站点 SEO 统计数据模型 - 存储各平台索引量、点击、展示、排名等 */
#[Table(comment: '站点SEO统计数据表')]
#[Index(name: 'unique_website_account_platform_date', columns: ['website_id', 'account_id', 'platform', 'stats_date'], type: 'UNIQUE')]
#[Index(name: 'idx_website', columns: ['website_id'])]
#[Index(name: 'idx_account', columns: ['account_id'])]
#[Index(name: 'idx_platform', columns: ['platform'])]
#[Index(name: 'idx_stats_date', columns: ['stats_date'])]
class SeoWebsiteStats extends Model
{
    public const schema_table = 'weline_seo_website_stats';
    public const schema_primary_key = 'id';
    public array $_unit_primary_keys = ['id'];
    #[Col('int', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: '记录ID')]
    public const schema_fields_ID = 'id';
    #[Col('int', 0, nullable: false, comment: '站点ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col('int', 0, nullable: false, comment: 'SEO账户ID')]
    public const schema_fields_ACCOUNT_ID = 'account_id';
    #[Col('varchar', 50, nullable: false, comment: '平台代码')]
    public const schema_fields_PLATFORM = 'platform';
    
    // 统计数据字段
    #[Col('int', 0, nullable: false, default: 0, comment: '已索引页面数')]
    public const schema_fields_INDEXED_PAGES = 'indexed_pages';
    #[Col('int', 0, nullable: false, default: 0, comment: '已提交URL数')]
    public const schema_fields_SUBMITTED_URLS = 'submitted_urls';
    #[Col('int', 0, nullable: false, default: 0, comment: '已抓取页面数')]
    public const schema_fields_CRAWLED_PAGES = 'crawled_pages';
    #[Col('int', 0, nullable: false, default: 0, comment: '点击量')]
    public const schema_fields_CLICKS = 'clicks';
    #[Col('int', 0, nullable: false, default: 0, comment: '展示量')]
    public const schema_fields_IMPRESSIONS = 'impressions';
    #[Col('decimal', '5,2', nullable: false, default: 0.00, comment: '点击率')]
    public const schema_fields_CTR = 'ctr';
    #[Col('decimal', '5,2', nullable: false, default: 0.00, comment: '平均排名')]
    public const schema_fields_AVERAGE_POSITION = 'average_position';
    
    #[Col('int', 0, nullable: false, default: 0, comment: '错误页面数')]
    public const schema_fields_ERROR_COUNT = 'error_count';
    #[Col('int', 0, nullable: false, default: 0, comment: '警告页面数')]
    public const schema_fields_WARNING_COUNT = 'warning_count';
    
    #[Col('int', 0, nullable: false, default: 0, comment: '每日配额')]
    public const schema_fields_DAILY_QUOTA = 'daily_quota';
    #[Col('int', 0, nullable: false, default: 0, comment: '已使用配额')]
    public const schema_fields_QUOTA_USED = 'quota_used';
    
    #[Col('text', comment: '额外数据JSON')]
    public const schema_fields_EXTRA_DATA = 'extra_data';
    
    #[Col('date', comment: '统计数据日期')]
    public const schema_fields_STATS_DATE = 'stats_date';
    #[Col('datetime', comment: '最后同步时间')]
    public const schema_fields_LAST_SYNC_AT = 'last_sync_at';
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
     * 获取或创建当日统计记录
     */
    public function getOrCreateTodayStats(int $websiteId, int $accountId, string $platform): self
    {
        $today = date('Y-m-d');
        
        $this->reset()
            ->where(self::schema_fields_WEBSITE_ID, $websiteId)
            ->where(self::schema_fields_ACCOUNT_ID, $accountId)
            ->where(self::schema_fields_PLATFORM, $platform)
            ->where(self::schema_fields_STATS_DATE, $today)
            ->find()
            ->fetch();
        
        if (!$this->getId()) {
            $this->setData([
                self::schema_fields_WEBSITE_ID => $websiteId,
                self::schema_fields_ACCOUNT_ID => $accountId,
                self::schema_fields_PLATFORM => $platform,
                self::schema_fields_STATS_DATE => $today,
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
            ->where(self::schema_fields_WEBSITE_ID, $websiteId)
            ->where(self::schema_fields_ACCOUNT_ID, $accountId)
            ->where(self::schema_fields_PLATFORM, $platform)
            ->order(self::schema_fields_STATS_DATE, 'DESC')
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
            ->where(self::schema_fields_WEBSITE_ID, $websiteId)
            ->order(self::schema_fields_STATS_DATE, 'DESC')
            ->select()
            ->fetchArray();
        
        // 为每个平台取最新一条
        foreach ($allStats as $stats) {
            $platform = $stats[self::schema_fields_PLATFORM] ?? '';
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
            self::schema_fields_INDEXED_PAGES,
            self::schema_fields_SUBMITTED_URLS,
            self::schema_fields_CRAWLED_PAGES,
            self::schema_fields_CLICKS,
            self::schema_fields_IMPRESSIONS,
            self::schema_fields_CTR,
            self::schema_fields_AVERAGE_POSITION,
            self::schema_fields_ERROR_COUNT,
            self::schema_fields_WARNING_COUNT,
            self::schema_fields_DAILY_QUOTA,
            self::schema_fields_QUOTA_USED,
            self::schema_fields_EXTRA_DATA,
        ];
        
        foreach ($data as $key => $value) {
            if (in_array($key, $allowedFields)) {
                $this->setData($key, $value);
            }
        }
        
        $this->setData(self::schema_fields_LAST_SYNC_AT, date('Y-m-d H:i:s'));
        $this->save();
        
        return $this;
    }
    /**
     * 获取额外数据
     */
    public function getExtraData(): array
    {
        $data = $this->getData(self::schema_fields_EXTRA_DATA);
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
        $this->setData(self::schema_fields_EXTRA_DATA, json_encode($data, JSON_UNESCAPED_UNICODE));
        return $this;
    }
    public function save_before(): void
    {
        $now = date('Y-m-d H:i:s');
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }
        $this->setData(self::schema_fields_UPDATED_AT, $now);
    }
}
