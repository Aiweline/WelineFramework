<?php

declare(strict_types=1);

namespace Weline\TwoFactorAuth\Setup;

use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;
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
    public function setup(ModelSetup $setup, Context $context): void
    {
        // 创建用户2FA表
        $this->userTwoFactor->install($setup, $context);
        
        $context->getIo()->info('TwoFactorAuth module installed successfully!');
        $context->getIo()->info('Table created: ' . $this->userTwoFactor->getTableName());
    }
}

