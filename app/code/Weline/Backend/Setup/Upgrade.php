<?php

declare(strict_types=1);

namespace Weline\Backend\Setup;

use Weline\Backend\Model\BackendUser;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\UpgradeInterface;

class Upgrade implements UpgradeInterface
{
    /**
     * 升级时确保存在默认管理员用户
     */
    public function setup(Setup $setup, Context $context): void
    {
        $version = $context->getVersion();
        
        if (version_compare($version, '1.0.3', '<')) {
            $this->ensureDefaultAdminUser();
        }
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
