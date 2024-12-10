<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/1/19 22:26:47
 */

namespace Weline\Backend\Model\Backend\Acl;

use Weline\Backend\Model\BackendUser;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class UserRole extends \Weline\Framework\Database\Model
{
    public const fields_ID      = 'user_id';
    public const fields_USER_ID = 'user_id';
    public const fields_ROLE_ID = 'role_id';

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
        // TODO: Implement upgrade() method.
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
//        $setup->dropTable();
        if (!$setup->tableExist()) {
            $setup->createTable('管理员角色表')
                  ->addColumn(
                      self::fields_ID,
                      TableInterface::column_type_INTEGER,
                      null,
                      'primary key',
                      '管理员ID'
                  )
                  ->addColumn(
                      self::fields_ROLE_ID,
                      TableInterface::column_type_INTEGER,
                      null,
                      'not null',
                      '角色ID'
                  )
                  ->addForeignKey(
                      'USER_ID',
                      self::fields_ID,
                      $setup->getTable('backend_user'),
                      BackendUser::fields_ID,
                      true,

                  )
                  ->addAdditional('ENGINE=MyIsam;')
                  ->create();
            // 分配超管
            $this->setData(self::fields_ID, 1)
                 ->setData(self::fields_ROLE_ID, 1)
                 ->save(true);
            // 分配管理
            $this->setData(self::fields_ID, 2)
                 ->setData(self::fields_ROLE_ID, 2)
                 ->save(true);
        }
    }

    public function getRoleId()
    {
        return $this->getData(self::fields_ROLE_ID);
    }

    public function setRoleId(int $role_id): static
    {
        $this->setData(self::fields_ROLE_ID, $role_id);
        return $this;
    }

    public function getUserId()
    {
        return $this->getData(self::fields_USER_ID);
    }

    public function setUserId(int $user_id): static
    {
        $this->getData(self::fields_USER_ID, $user_id);
        return $this;
    }
}