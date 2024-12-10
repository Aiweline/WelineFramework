<?php

namespace Weline\ModuleManager\Model\Module;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Table extends Model
{
    public const fields_ID = 'module_table_id';
    public const fields_module_name = 'module_name';
    public const fields_name = 'name';
    public const fields_model = 'model';

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // TODO: Implement upgrade() method.
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('模块模型表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'auto_increment primary key', '模块表id')
                ->addColumn(self::fields_module_name, TableInterface::column_type_VARCHAR, 255, 'not null', '模块名称')
                ->addColumn(self::fields_name, TableInterface::column_type_VARCHAR, 255, 'unique', '表名')
                ->addColumn(self::fields_model, TableInterface::column_type_VARCHAR, 255, 'unique', '模块模型')
                ->addIndex(TableInterface::index_type_UNIQUE, 'UNIQUE_MODEL', self::fields_model, 'Model模型唯一')
                ->addIndex(TableInterface::index_type_UNIQUE, 'UNIQUE_NAME', self::fields_name, 'Model模型表唯一')
                ->create();
            $this->setModuleName($context->getModuleName())
                ->setName($this->getTable())
                ->setModel($this::class)
                ->save();
        }
    }

    public function setModuleName(string $module_name): static
    {
        return $this->setData(self::fields_module_name, $module_name);
    }

    public function getModuleName(): string
    {
        return $this->getData(self::fields_module_name) ?: '';
    }

    public function setName(string $name): static
    {
        return $this->setData(self::fields_name, $name);
    }

    public function getName(): string
    {
        return $this->getData(self::fields_name) ?: '';
    }

    public function setModel(string $model): static
    {
        return $this->setData(self::fields_model, $model, true);
    }

    public function getModel(): string
    {
        return $this->getData(self::fields_model) ?: '';
    }
}
