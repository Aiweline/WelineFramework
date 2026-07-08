<?php

declare(strict_types=1);

namespace Weline\MediaManager\Setup;

use Weline\Backend\Model\BackendUserConfig;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data;
use Weline\Framework\Setup\UpgradeInterface;
use Weline\MediaManager\Service\AiDrawModelBinder;

class Upgrade implements UpgradeInterface
{
    private BackendUserConfig $backendUserConfig;

    public function __construct(BackendUserConfig $backendUserConfig)
    {
        $this->backendUserConfig = $backendUserConfig;
    }

    public function setup(Data\Setup $setup, Data\Context $context): void
    {
        $this->backendUserConfig->setDefaultConfig(
            'file_manager',
            'weline_media',
            'Weline_MediaManager',
            '文件管理器配置（默认使用 Weline 媒体管理器）',
            false
        );

        ObjectManager::getInstance(AiDrawModelBinder::class)->bindIfNeeded();
    }
}
