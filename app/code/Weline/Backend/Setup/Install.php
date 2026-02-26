<?php

declare(strict_types=1);

namespace Weline\Backend\Setup;

use Weline\Backend\Model\BackendUser;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\InstallInterface;

class Install implements InstallInterface
{
    /**
     * 安装时创建默认管理员用户 admin/admin
     */
    public function setup(Setup $setup, Context $context): void
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
