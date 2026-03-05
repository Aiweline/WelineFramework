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
/** SEO 关键词趋势模型 */
#[Table(comment: 'SEO关键词趋势表')]
#[Index(name: 'idx_keyword_id', columns: ['keyword_id'])]
#[Index(name: 'idx_platform', columns: ['platform'])]
#[Index(name: 'idx_trend_date', columns: ['trend_date'])]
#[Index(name: 'idx_keyword_platform_date', columns: ['keyword_id', 'platform', 'trend_date', 'region'], type: 'UNIQUE')]
class SeoKeywordTrend extends Model
{
    public const schema_table = 'weline_seo_keyword_trend';
    public const schema_primary_key = 'trend_id';
    #[Col('int', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: '趋势ID')]
    public const schema_fields_ID = 'trend_id';
    #[Col('int', 0, nullable: false, comment: '关键词ID')]
    public const schema_fields_KEYWORD_ID = 'keyword_id';
    #[Col('varchar', 50, nullable: false, comment: '平台')]
    public const schema_fields_PLATFORM = 'platform';
    #[Col('int', 0, nullable: false, default: 0, comment: '趋势值')]
    public const schema_fields_TREND_VALUE = 'trend_value';
    #[Col('date', nullable: false, comment: '趋势日期')]
    public const schema_fields_TREND_DATE = 'trend_date';
    #[Col('varchar', 10, nullable: false, default: 'global', comment: '地区代码')]
    public const schema_fields_REGION = 'region';
    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    // 平台常量
    public const PLATFORM_GOOGLE = 'google';
    public const PLATFORM_BAIDU = 'baidu';
    public const PLATFORM_BING = 'bing';
    public const PLATFORM_360 = '360';
    public const PLATFORM_SOGOU = 'sogou';
    public const PLATFORM_SHENMA = 'shenma';
/**
     * 保存前处理
     */
    public function save_before(): void
    {
        parent::save_before();
        
        if (!$this->getData(self::schema_fields_CREATED_AT)) {
            $this->setData(self::schema_fields_CREATED_AT, date('Y-m-d H:i:s'));
        }
    }
    // ===== Getters and Setters =====
    public function getKeywordId(): int
    {
        return (int)$this->getData(self::schema_fields_KEYWORD_ID);
    }
    public function setKeywordId(int $keywordId): self
    {
        return $this->setData(self::schema_fields_KEYWORD_ID, $keywordId);
    }
    public function getPlatform(): string
    {
        return (string)$this->getData(self::schema_fields_PLATFORM);
    }
    public function setPlatform(string $platform): self
    {
        return $this->setData(self::schema_fields_PLATFORM, $platform);
    }
    public function getTrendValue(): int
    {
        return (int)$this->getData(self::schema_fields_TREND_VALUE);
    }
    public function setTrendValue(int $trendValue): self
    {
        return $this->setData(self::schema_fields_TREND_VALUE, $trendValue);
    }
    public function getTrendDate(): string
    {
        return (string)$this->getData(self::schema_fields_TREND_DATE);
    }
    public function setTrendDate(string $trendDate): self
    {
        return $this->setData(self::schema_fields_TREND_DATE, $trendDate);
    }
    public function getRegion(): string
    {
        return (string)$this->getData(self::schema_fields_REGION);
    }
    public function setRegion(string $region): self
    {
        return $this->setData(self::schema_fields_REGION, $region);
    }
}
