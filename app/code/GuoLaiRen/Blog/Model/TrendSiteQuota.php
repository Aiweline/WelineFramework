<?php

declare(strict_types=1);

/*
 * GuoLaiRen Blog Module
 * 站点+画像每日发文配额模型
 */

namespace GuoLaiRen\Blog\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class TrendSiteQuota extends Model
{
    public const table = 'guolairen_blog_trend_site_quota';

    public const fields_ID                   = 'quota_id';
    public const fields_SITE_ID             = 'site_id';
    public const fields_PROFILE_ID          = 'profile_id';
    public const fields_ARTICLES_PER_DAY    = 'articles_per_day';
    public const fields_DEFAULT_CATEGORY_ID = 'default_category_id';
    public const fields_CREATED_AT         = 'created_at';
    public const fields_UPDATED_AT         = 'updated_at';

    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }

        $setup->createTable('站点趋势发文配额表')
            ->addColumn(
                self::fields_ID,
                TableInterface::column_type_INTEGER,
                0,
                'primary key auto_increment',
                '配额ID'
            )
            ->addColumn(
                self::fields_SITE_ID,
                TableInterface::column_type_INTEGER,
                0,
                'not null default 0',
                '站点ID'
            )
            ->addColumn(
                self::fields_PROFILE_ID,
                TableInterface::column_type_INTEGER,
                0,
                'not null default 0',
                '画像ID'
            )
            ->addColumn(
                self::fields_ARTICLES_PER_DAY,
                TableInterface::column_type_INTEGER,
                0,
                'not null default 0',
                '每日发文篇数'
            )
            ->addColumn(
                self::fields_DEFAULT_CATEGORY_ID,
                TableInterface::column_type_INTEGER,
                0,
                'not null default 0',
                '默认分类ID（该站点下博客分类）'
            )
            ->addColumn(
                self::fields_CREATED_AT,
                TableInterface::column_type_DATETIME,
                0,
                'not null default CURRENT_TIMESTAMP',
                '创建时间'
            )
            ->addColumn(
                self::fields_UPDATED_AT,
                TableInterface::column_type_DATETIME,
                0,
                'not null default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP',
                '更新时间'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_site_profile',
                [self::fields_SITE_ID, self::fields_PROFILE_ID],
                '站点+画像索引'
            )
            ->addIndex(
                TableInterface::index_type_UNIQUE,
                'uk_site_profile',
                [self::fields_SITE_ID, self::fields_PROFILE_ID],
                '站点+画像唯一'
            )
            ->create();
    }

    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 暂无升级
    }

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }
}
