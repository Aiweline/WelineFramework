<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/1/12 21:14:16
 */

namespace Weline\Acl\Model;

use Weline\Framework\App\Exception;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Role extends \Weline\Framework\Database\Model
{
    public const fields_ID = 'role_id';
    public const fields_ROLE_ID = 'role_id';
    public const fields_ROLE_NAME = 'role_name';
    public const fields_ROLE_DESCRIPTION = 'role_description';

    function getId(mixed $default = 0)
    {
        return (int)parent::getId($default);
    }

    function setRoleName(string $name): Role
    {
        return $this->setData(self::fields_ROLE_NAME, $name);
    }

    function setRoleDescription(string $description): Role
    {
        return $this->setData(self::fields_ROLE_DESCRIPTION, $description);
    }

    function getRoleName(): string
    {
        return $this->getData(self::fields_ROLE_NAME);
    }

    function getRoleDescription(): string
    {
        return $this->getData(self::fields_ROLE_DESCRIPTION);
    }

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
//        $setup->query('SET foreign_key_checks = 0;');
//        $setup->dropTable();
//        $setup->query('SET foreign_key_checks = 1;');
        if (!$setup->tableExist()) {
            $setup->createTable()
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'primary key auto_increment',
                    '角色ID'
                )
                ->addColumn(
                    self::fields_ROLE_NAME,
                    TableInterface::column_type_VARCHAR,
                    128,
                    'not null unique', '角色名'
                )
                ->addColumn(
                    self::fields_ROLE_DESCRIPTION,
                    TableInterface::column_type_TEXT,
                    null,
                    '',
                    '角色描述'
                )
                ->create();
            // 创建超管
            $this->setId(1)
                ->setRoleName('超级管理员')
                ->setRoleDescription('拥有所有权限的超管角色')
                ->save(true);
            $this->setId(2)
                ->setRoleName('管理员')
                ->setRoleDescription('拥有部分特殊权限的管理角色')
                ->save(true);
        }
    }

    function delete_before()
    {
        if ($this->getId() === 1) {
            throw new Exception(__('不能删除超级管理员！'));
        }
        parent::delete_before();
    }

    /**
     * @DESC          # 获取角色权限
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/1/27 23:16
     * 参数区：
     * @return array
     * @throws null
     */
    function getAccess(): array
    {
        /**@var \Weline\Acl\Model\RoleAccess $roleAccess */
        $roleAccess = ObjectManager::getInstance(RoleAccess::class);
        return $roleAccess->getRoleAccessList($this);
    }
}