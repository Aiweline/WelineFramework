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
 * 用户资料表（用于测试链表功能）
 */
class TestUserProfile extends Model
{
    public const table = 'datatable_test_user_profiles';

    public const fields_ID = 'id';
    public const fields_user_id = 'user_id';
    public const fields_real_name = 'real_name';
    public const fields_id_card = 'id_card';
    public const fields_education = 'education';
    public const fields_occupation = 'occupation';
    public const fields_company = 'company';
    public const fields_salary = 'salary';
    public const fields_hobby = 'hobby';
    public const fields_introduction = 'introduction';
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
            $setup->createTable('测试用户资料表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'auto_increment primary key', '资料ID')
                ->addColumn(self::fields_user_id, TableInterface::column_type_INTEGER, 0, 'not null', '用户ID（外键关联datatable_test_users.id）')
                ->addColumn(self::fields_real_name, TableInterface::column_type_VARCHAR, 100, '', '真实姓名')
                ->addColumn(self::fields_id_card, TableInterface::column_type_VARCHAR, 18, '', '身份证号')
                ->addColumn(self::fields_education, TableInterface::column_type_VARCHAR, 30, "default 'college'", '学历：primary-小学，middle-初中，high-高中，college-大专，university-本科，graduate-研究生')
                ->addColumn(self::fields_occupation, TableInterface::column_type_VARCHAR, 100, '', '职业')
                ->addColumn(self::fields_company, TableInterface::column_type_VARCHAR, 200, '', '公司名称')
                ->addColumn(self::fields_salary, TableInterface::column_type_DECIMAL, '10,2', '', '薪资')
                ->addColumn(self::fields_hobby, TableInterface::column_type_TEXT, 0, '', '兴趣爱好')
                ->addColumn(self::fields_introduction, TableInterface::column_type_TEXT, 0, '', '个人介绍')
                ->addColumn(self::fields_created_at, TableInterface::column_type_DATETIME, 0, 'default CURRENT_TIMESTAMP', '创建时间')
                ->addColumn(self::fields_updated_at, TableInterface::column_type_DATETIME, 0, 'default CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', '更新时间')
                ->create();
        }
    }

    /**
     * 获取学历选项
     */
    public function getEducationOptions(): array
    {
        return [
            ['value' => 'primary', 'label' => '小学'],
            ['value' => 'middle', 'label' => '初中'],
            ['value' => 'high', 'label' => '高中'],
            ['value' => 'college', 'label' => '大专'],
            ['value' => 'university', 'label' => '本科'],
            ['value' => 'graduate', 'label' => '研究生']
        ];
    }
}

