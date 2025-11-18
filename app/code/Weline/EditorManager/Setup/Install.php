<?php

namespace Weline\EditorManager\Setup;

use Weline\Backend\Model\BackendUserConfig;
use Weline\Framework\Setup\Data;
use Weline\Framework\Setup\InstallInterface;

class Install implements InstallInterface
{
    /**
     * @var \Weline\EditorManager\Model\BackendUserConfig
     */
    private BackendUserConfig $backendUserConfig;

    public function __construct(BackendUserConfig $backendUserConfig)
    {
        $this->backendUserConfig = $backendUserConfig;
    }

    /**
     * @inheritDoc
     */
    public function setup(Data\Setup $setup, Data\Context $context): void

    {
        if (!$this->backendUserConfig->getDefaultConfig('editor-manager')) {
            $this->backendUserConfig->setDefaultConfig('editor-manager', 'local', 'Weline_EditorManager', '编辑器管理器配置',false);
        }
    }
}
