<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\DataTable\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 用户地址表（用于测试链表功能）
 */
class TestUserAddress extends Model
{
    public const table = 'datatable_test_user_addresses';

    public const fields_ID = 'id';
    public const fields_user_id = 'user_id';
    public const fields_address_type = 'address_type';
    public const fields_province = 'province';
    public const fields_city = 'city';
    public const fields_district = 'district';
    public const fields_street = 'street';
    public const fields_postal_code = 'postal_code';
    public const fields_phone = 'phone';
    public const fields_is_default = 'is_default';
    public const fields_created_at = 'created_at';
    public const fields_updated_at = 'updated_at';

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
        // 升级逻辑
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('测试用户地址表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'auto_increment primary key', '地址ID')
                ->addColumn(self::fields_user_id, TableInterface::column_type_INTEGER, 0, 'not null', '用户ID（外键关联datatable_test_users.id）')
                ->addColumn(self::fields_address_type, TableInterface::column_type_VARCHAR, 20, "default 'home'", '地址类型：home-家庭，work-工作，other-其他')
                ->addColumn(self::fields_province, TableInterface::column_type_VARCHAR, 50, '', '省份')
                ->addColumn(self::fields_city, TableInterface::column_type_VARCHAR, 50, '', '城市')
                ->addColumn(self::fields_district, TableInterface::column_type_VARCHAR, 50, '', '区县')
                ->addColumn(self::fields_street, TableInterface::column_type_VARCHAR, 200, '', '街道')
                ->addColumn(self::fields_postal_code, TableInterface::column_type_VARCHAR, 10, '', '邮政编码')
                ->addColumn(self::fields_phone, TableInterface::column_type_VARCHAR, 20, '', '联系电话')
                ->addColumn(self::fields_is_default, TableInterface::column_type_INTEGER, 1, 'default 0', '是否默认地址：1-是，0-否')
                ->addColumn(self::fields_created_at, TableInterface::column_type_DATETIME, 0, 'default CURRENT_TIMESTAMP', '创建时间')
                ->addColumn(self::fields_updated_at, TableInterface::column_type_DATETIME, 0, 'default CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', '更新时间')
                ->create();
        }
    }

    /**
     * 获取地址类型选项
     */
    public function getAddressTypeOptions(): array
    {
        return [
            ['value' => 'home', 'label' => '家庭地址'],
            ['value' => 'work', 'label' => '工作地址'],
            ['value' => 'other', 'label' => '其他地址']
        ];
    }
}

