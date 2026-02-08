<?php

declare(strict_types=1);

/*
 * GuoLaiRen Blog Module
 * 趋势关键词画像模型
 */

namespace GuoLaiRen\Blog\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class TrendProfile extends Model
{
    public const table = 'guolairen_blog_trend_profile';

    public const fields_ID        = 'profile_id';
    public const fields_NAME      = 'name';
    public const fields_KEYWORDS  = 'keywords';
    public const fields_SORT      = 'sort';
    public const fields_IS_ACTIVE = 'is_active';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    /**
     * 关键词字符串转数组（逗号分隔）
     */
    public function getKeywordsArray(): array
    {
        $kw = $this->getData(self::fields_KEYWORDS);
        if ($kw === null || $kw === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', (string)$kw))));
    }

    /**
     * 设置关键词（数组转逗号分隔）
     */
    public function setKeywordsFromArray(array $keywords): self
    {
        $this->setData(self::fields_KEYWORDS, implode(',', array_map('trim', $keywords)));
        return $this;
    }

    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }

        $setup->createTable('趋势关键词画像表')
            ->addColumn(
                self::fields_ID,
                TableInterface::column_type_INTEGER,
                0,
                'primary key auto_increment',
                '画像ID'
            )
            ->addColumn(
                self::fields_NAME,
                TableInterface::column_type_VARCHAR,
                128,
                'not null',
                '画像名称'
            )
            ->addColumn(
                self::fields_KEYWORDS,
                TableInterface::column_type_TEXT,
                0,
                '',
                '关键词（逗号分隔）'
            )
            ->addColumn(
                self::fields_SORT,
                TableInterface::column_type_INTEGER,
                0,
                'not null default 0',
                '排序'
            )
            ->addColumn(
                self::fields_IS_ACTIVE,
                TableInterface::column_type_SMALLINT,
                1,
                'not null default 1',
                '是否启用:0否,1是'
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
                'idx_is_active',
                [self::fields_IS_ACTIVE],
                '启用状态索引'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_sort',
                [self::fields_SORT],
                '排序索引'
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
