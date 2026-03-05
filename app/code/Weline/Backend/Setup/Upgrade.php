<?php

declare(strict_types=1);

namespace Weline\Backend\Setup;

use Weline\Backend\Model\Backend\Acl\UserRole;
use Weline\Backend\Model\BackendUser;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\UpgradeInterface;

class Upgrade implements UpgradeInterface
{
    /**
     * 升级时确保存在默认管理员用户，并为 user_id=1 补全角色记录（修复历史上 Install 未正确写入 user_id 的问题）
     */
    public function setup(Setup $setup, Context $context): void
    {
        $version = $context->getVersion();

        $this->ensureDefaultAdminUser();
        if (version_compare($version, '1.2.1', '<')) {
            $this->ensureUser1HasRole1();
        }
    }

    /** 为管理员 ID=1 补全默认角色 role_id=1（升级修复） */
    private function ensureUser1HasRole1(): void
    {
        $backendUser = ObjectManager::getInstance(BackendUser::class);
        $backendUser->reset()->load(1);
        if (!$backendUser->getId()) {
            return;
        }
        $userRole = ObjectManager::getInstance(UserRole::class);
        $exist = $userRole->reset()
            ->where(UserRole::schema_fields_USER_ID, 1)
            ->find()
            ->fetch();
        if ($exist && $exist->getData(UserRole::schema_fields_ROLE_ID)) {
            return;
        }
        $userRole->clear()
            ->setUserId(1)
            ->setRoleId(1)
            ->save(true);
    }
    
    /**
     * 确保默认管理员用户存在
     */
    private function ensureDefaultAdminUser(): void
    {
        /** @var BackendUser $userModel */
        $userModel = ObjectManager::getInstance(BackendUser::class);
        
        $existingUser = $userModel->reset()
            ->where('username', 'admin')
            ->find()
            ->fetch();
        
        if ($existingUser && $existingUser->getId()) {
            return;
        }
        
        $userModel->reset()
            ->setUsername('admin')
            ->setEmail('admin@example.com')
            ->setPassword('admin')
            ->save();
    }
}
