<?php

declare(strict_types=1);

namespace Weline\TwoFactorAuth\Setup;

use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Manager\ObjectManager;
use Weline\TwoFactorAuth\Model\UserTwoFactor;

/**
 * 模块安装脚本
 * 
 * @package Weline\TwoFactorAuth\Setup
 */
class Install implements \Weline\Framework\Setup\InstallInterface
{
    private UserTwoFactor $userTwoFactor;

    public function __construct(
        UserTwoFactor $userTwoFactor
    ) {
        $this->userTwoFactor = $userTwoFactor;
    }

    /**
     * @inheritDoc
     */
    public function setup(Setup $setup, Context $context): void
    {
        // 创建 ModelSetup 实例
        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($this->userTwoFactor);
        
        // 创建用户2FA表
        $this->userTwoFactor->install($modelSetup, $context);
    }
}

