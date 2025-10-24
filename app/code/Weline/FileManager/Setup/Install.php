<?php

namespace Weline\FileManager\Setup;

use Weline\Backend\Model\BackendUserConfig;
use Weline\Eav\Model\EavAttribute\Type;
use Weline\FileManager\Ui\EavModel\Select\File;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data;
use Weline\Framework\Setup\InstallInterface;

class Install implements InstallInterface
{
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
        if (!$this->backendUserConfig->getDefaultConfig('file-manager')) {
            $this->backendUserConfig->setDefaultConfig('file-manager', 'local', 'Weline_FileManager', '文件管理器配置');
        }
        # 安装选择文件属性
        /** @var Type $type */
        $type = ObjectManager::getInstance(Type::class);
        $type->setFieldType(TableInterface::column_type_VARCHAR)
            ->setCode('select_file')
            ->setFrontendAttrs('type="text" data-parsley-minlength="3" required')
            ->setFieldLength(255)
            ->setIsSwatch(false)
            ->setElement('input')
            ->setModelClass(File::class)
            ->setModelClassData('')
            ->setRequired(true)
            ->setDefaultValue('')
            ->setName('选择文件')
            ->save();
    }
}
