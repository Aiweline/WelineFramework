<?php

declare(strict_types=1);

/*
 * GuoLaiRen Blog Module
 * 有增长的趋势词记录（供 AI 取词）
 */

namespace GuoLaiRen\Blog\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class TrendingKeywordLog extends Model
{
    public const table = 'guolairen_blog_trending_keyword_log';

    public const fields_ID             = 'log_id';
    public const fields_PROFILE_ID    = 'profile_id';
    public const fields_KEYWORD       = 'keyword';
    public const fields_TREND_VALUE   = 'trend_value';
    public const fields_PREVIOUS_VALUE = 'previous_value';
    public const fields_CHANGE_RATE   = 'change_rate';
    public const fields_COMPARISON_TYPE = 'comparison_type';
    public const fields_TREND_DATE    = 'trend_date';
    public const fields_SOURCE        = 'source';
    public const fields_USED_AT       = 'used_at';
    public const fields_CREATED_AT    = 'created_at';

    public const COMPARISON_DAY = 'day';
    public const COMPARISON_WEEK = 'week';

    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }

        $setup->createTable('趋势增长词记录表')
            ->addColumn(
                self::fields_ID,
                TableInterface::column_type_INTEGER,
                0,
                'primary key auto_increment',
                '记录ID'
            )
            ->addColumn(
                self::fields_PROFILE_ID,
                TableInterface::column_type_INTEGER,
                0,
                'not null default 0',
                '画像ID'
            )
            ->addColumn(
                self::fields_KEYWORD,
                TableInterface::column_type_VARCHAR,
                255,
                'not null',
                '关键词'
            )
            ->addColumn(
                self::fields_TREND_VALUE,
                TableInterface::column_type_INTEGER,
                0,
                'not null default 0',
                '当前趋势值'
            )
            ->addColumn(
                self::fields_PREVIOUS_VALUE,
                TableInterface::column_type_INTEGER,
                0,
                'not null default 0',
                '对比期趋势值'
            )
            ->addColumn(
                self::fields_CHANGE_RATE,
                TableInterface::column_type_DECIMAL,
                '10,2',
                'not null default 0',
                '增长率(%)'
            )
            ->addColumn(
                self::fields_COMPARISON_TYPE,
                TableInterface::column_type_VARCHAR,
                16,
                'not null default \'day\'',
                '比较类型:day日环比,week周环比'
            )
            ->addColumn(
                self::fields_TREND_DATE,
                TableInterface::column_type_DATE,
                0,
                'not null',
                '趋势日期'
            )
            ->addColumn(
                self::fields_SOURCE,
                TableInterface::column_type_VARCHAR,
                64,
                'not null default \'google_trends\'',
                '数据来源'
            )
            ->addColumn(
                self::fields_USED_AT,
                TableInterface::column_type_DATETIME,
                0,
                '',
                '已用于生成时间'
            )
            ->addColumn(
                self::fields_CREATED_AT,
                TableInterface::column_type_DATETIME,
                0,
                'not null default CURRENT_TIMESTAMP',
                '创建时间'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_profile_date',
                [self::fields_PROFILE_ID, self::fields_TREND_DATE],
                '画像+日期'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_used_at',
                [self::fields_USED_AT],
                '已使用'
            )
            ->create();
    }

    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 暂无
    }

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }
}
