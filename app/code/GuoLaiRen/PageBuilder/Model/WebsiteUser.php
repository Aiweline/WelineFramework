<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 页面构建器 - 站点与后台用户一对一映射
 *
 * 约束：
 * - 一个站点同一时间只能分配给一个后台用户
 * - 后续分配给新的用户时，会自动替换之前的分配关系
 */
class WebsiteUser extends Model
{
    public const table = 'guolairen_page_builder_website_user';

    public const fields_ID = 'id';
    public const fields_WEBSITE_ID = 'website_id';
    public const fields_BACKEND_USER_ID = 'backend_user_id';
    public const fields_IS_OWNER = 'is_owner';
    public const fields_CREATE_TIME = 'create_time';

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 当前版本无增量升级逻辑，占位以满足接口约定
    }

    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }

        $setup->createTable('页面构建器-站点用户一对一映射表')
            ->addColumn(
                self::fields_ID,
                TableInterface::column_type_INTEGER,
                11,
                'primary key auto_increment',
                '主键ID'
            )
            ->addColumn(
                self::fields_WEBSITE_ID,
                TableInterface::column_type_INTEGER,
                11,
                'not null',
                '网站ID'
            )
            ->addColumn(
                self::fields_BACKEND_USER_ID,
                TableInterface::column_type_INTEGER,
                11,
                'not null',
                '后台用户ID'
            )
            ->addColumn(
                self::fields_IS_OWNER,
                TableInterface::column_type_SMALLINT,
                1,
                'not null default 0',
                '是否站点创建者'
            )
            ->addColumn(
                self::fields_CREATE_TIME,
                TableInterface::column_type_DATETIME,
                0,
                'not null default CURRENT_TIMESTAMP',
                '创建时间'
            )
            // 一个站点同一时间只能分配给一个后台用户
            ->addIndex(
                TableInterface::index_type_UNIQUE,
                'uniq_website',
                [self::fields_WEBSITE_ID],
                '站点唯一分配约束'
            )
            ->create();
    }
}

