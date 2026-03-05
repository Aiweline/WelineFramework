<?php

declare(strict_types=1);

namespace Weline\Acl\Setup;

use Weline\Acl\Model\Role;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\InstallInterface;

class Install implements InstallInterface
{
    /**
     * 安装时初始化默认角色（业务初始化，计划 3.10）
     */
    public function setup(Setup $setup, Context $context): void
    {
        /** @var Role $role */
        $role = ObjectManager::getInstance(Role::class);
        $role->load(1);
        if (!$role->getId()) {
            $role->clearData()->setId(1)
                ->setRoleName('超级管理员')
                ->setRoleDescription('拥有所有权限的超管角色')
                ->save(true);
        }
        $role->load(2);
        if (!$role->getId()) {
            $role->clearData()->setId(2)
                ->setRoleName('管理员')
                ->setRoleDescription('拥有部分特殊权限的管理角色')
                ->save(true);
        }
    }
}
