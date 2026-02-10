<?php

declare(strict_types=1);

namespace Weline\RdpWrapper\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * @DESC | RDP 用户管理模型，记录通过本模块创建的 Windows 用户账户
 */
class RdpUser extends Model
{
    // 字段常量
    public const fields_ID = 'id';
    public const fields_USERNAME = 'username';
    public const fields_DISPLAY_NAME = 'display_name';
    public const fields_PASSWORD_HINT = 'password_hint';
    public const fields_IS_ADMIN = 'is_admin';
    public const fields_STATUS = 'status';
    public const fields_REMARK = 'remark';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    // 状态常量
    public const STATUS_ENABLED = 1;
    public const STATUS_DISABLED = 0;

    // 主键
    public array $_unit_primary_keys = ['id'];

    // 索引字段
    public array $_index_sort_keys = ['id', 'username'];

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 后续升级逻辑
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable(__('RDP远程桌面用户管理'))
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'primary key auto_increment',
                    'ID'
                )
                ->addColumn(
                    self::fields_USERNAME,
                    TableInterface::column_type_VARCHAR,
                    100,
                    'not null',
                    __('Windows用户名')
                )
                ->addColumn(
                    self::fields_DISPLAY_NAME,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'default ""',
                    __('显示名称')
                )
                ->addColumn(
                    self::fields_PASSWORD_HINT,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'default ""',
                    __('密码提示')
                )
                ->addColumn(
                    self::fields_IS_ADMIN,
                    TableInterface::column_type_SMALLINT,
                    1,
                    'default 0',
                    __('是否管理员')
                )
                ->addColumn(
                    self::fields_STATUS,
                    TableInterface::column_type_SMALLINT,
                    1,
                    'default 1',
                    __('状态')
                )
                ->addColumn(
                    self::fields_REMARK,
                    TableInterface::column_type_TEXT,
                    0,
                    '',
                    __('备注')
                )
                ->addColumn(
                    self::fields_CREATED_AT,
                    TableInterface::column_type_TIMESTAMP,
                    0,
                    'default CURRENT_TIMESTAMP',
                    __('创建时间')
                )
                ->addColumn(
                    self::fields_UPDATED_AT,
                    TableInterface::column_type_TIMESTAMP,
                    0,
                    '',
                    __('更新时间')
                )
                ->addIndex(
                    TableInterface::index_type_UNIQUE,
                    'uk_username',
                    'username',
                    __('用户名唯一索引')
                )
                ->create();
        }
    }
}
