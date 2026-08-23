<?php

namespace Weline\FileManager\Setup;

use Weline\Backend\Api\Config\BackendUserConfigStore;
use Weline\Eav\Api\Attribute\Type\AttributeTypeDefinition;
use Weline\Eav\Api\Attribute\Type\AttributeTypeRegistryInterface;
use Weline\FileManager\Ui\EavModel\Select\File;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Setup\Data;
use Weline\Framework\Setup\InstallInterface;

class Install implements InstallInterface
{
    private BackendUserConfigStore $backendUserConfig;
    private ?AttributeTypeRegistryInterface $attributeTypes = null;

    public function __construct(
        BackendUserConfigStore $backendUserConfig,
        private readonly RuntimeProviderResolver $runtimeProviders,
    ) {
        $this->backendUserConfig = $backendUserConfig;
    }

    /**
     * @inheritDoc
     */
    public function setup(Data\Setup $setup, Data\Context $context): void
    {
        // FileAsset、FileAssetLocale 与 FileAssetReference 由模型注解经 SchemaDiffStage 安装。
        if (!$this->backendUserConfig->getDefaultConfig('file_manager')) {
            $this->backendUserConfig->setDefaultConfig(
                'file_manager',
                'weline_media',
                'Weline_FileManager',
                '文件管理器配置',
            );
        }
        $this->attributeTypes()->register(new AttributeTypeDefinition(
            fieldType: TableInterface::column_type_VARCHAR,
            code: 'select_file',
            frontendAttributes: 'type="text" minlength="3" required',
            fieldLength: 255,
            swatch: false,
            element: 'input',
            modelClass: File::class,
            modelClassData: '',
            required: true,
            defaultValue: '',
            name: '选择文件',
        ));
    }

    private function attributeTypes(): AttributeTypeRegistryInterface
    {
        if ($this->attributeTypes instanceof AttributeTypeRegistryInterface) {
            return $this->attributeTypes;
        }

        $provider = $this->runtimeProviders->resolve(AttributeTypeRegistryInterface::class);
        if (!$provider instanceof AttributeTypeRegistryInterface) {
            throw new \RuntimeException('eav_attribute_type_registry_unavailable');
        }

        return $this->attributeTypes = $provider;
    }
}
