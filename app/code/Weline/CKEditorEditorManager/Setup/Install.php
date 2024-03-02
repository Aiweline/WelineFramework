<?php

namespace Weline\CKEditorEditorManager\Setup;

use Weline\EditorManager\Model\BackendUserConfig;
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
    public function setup(Data\Setup $setup, Data\Context $context): void
    {
        $this->backendUserConfig->setConfig('editor-manager', 'ckeditor', 'Weline_EditorManager', '编辑器管理器配置');
    }
}
