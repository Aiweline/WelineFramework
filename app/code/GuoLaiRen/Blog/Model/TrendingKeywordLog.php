<?php
declare(strict_types=1);
/*
 * GuoLaiRen Blog Module
 * 有增长的趋势词记录（供 AI 取词）
 */
namespace GuoLaiRen\Blog\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;
#[Table(comment: '趋势增长词记录表')]
#[Index(name: 'idx_profile_date', columns: ['profile_id', 'trend_date'], comment: '画像+日期')]
#[Index(name: 'idx_used_at', columns: ['used_at'], comment: '已使用')]
class TrendingKeywordLog extends Model
{
    public const schema_table = 'guolairen_blog_trending_keyword_log';
    public const schema_primary_key = 'log_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '日志ID')]
    public const schema_fields_ID = 'log_id';
    #[Col(type: 'int', nullable: false, default: 0, comment: '画像ID')]
    public const schema_fields_PROFILE_ID = 'profile_id';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '关键词')]
    public const schema_fields_KEYWORD = 'keyword';
    #[Col(type: 'int', nullable: false, default: 0, comment: '当前趋势值')]
    public const schema_fields_TREND_VALUE = 'trend_value';
    #[Col(type: 'int', nullable: false, default: 0, comment: '对比期趋势值')]
    public const schema_fields_PREVIOUS_VALUE = 'previous_value';
    #[Col(type: 'decimal', length: '10,4', nullable: false, default: 0, comment: '变化率')]
    public const schema_fields_CHANGE_RATE = 'change_rate';
    #[Col(type: 'varchar', length: 16, nullable: false, default: 'day', comment: '比较类型:day日环比,week周环比')]
    public const schema_fields_COMPARISON_TYPE = 'comparison_type';
    #[Col(type: 'date', nullable: false, comment: '趋势日期')]
    public const schema_fields_TREND_DATE = 'trend_date';
    #[Col(type: 'varchar', length: 64, nullable: true, comment: '数据来源')]
    public const schema_fields_SOURCE = 'source';
    #[Col(type: 'datetime', nullable: true, comment: '已使用时间')]
    public const schema_fields_USED_AT = 'used_at';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    public const COMPARISON_DAY = 'day';
    public const COMPARISON_WEEK = 'week';
}
