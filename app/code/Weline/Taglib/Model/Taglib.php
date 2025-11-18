<?php

namespace Weline\Taglib\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\ModuleManager\Model\Module;

class Taglib extends Model
{
    public const fields_ID = 'taglib_id';
    public const fields_MODULE_ID = 'module_id';
    public const fields_NAME = 'name';
    public const fields_DESCRIPTION = 'description';
    public const fields_JSON = 'json';
    public const fields_is_system = 'is_system';
    private Module $module;

    public function __construct(Module $module, array $data = [])
    {
        parent::__construct($data);
        $this->module = $module;
    }

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
        $tagLibs = \Weline\Taglib\Helper\Taglib::getTagLibs();
        foreach ($tagLibs as $tagName => $tagLib) {
            unset($tagLib['callback']);
            $module_id = $tagLib['module_name'] ?? 0;
            if ($module_id) {
                $module = $this->module->clearData()
                    ->where('name', $module_id)
                    ->find()
                    ->fetch();
                $module_id = $module->getId();
            }
            $doc = $tagLib['doc'] ?? '系统标签，请查看：' . htmlentities('<a href="https://gitee.com/aiweline/WelineFramework/wikis/%E5%BC%80%E5%8F%91%E6%96%87%E6%A1%A3/%E5%89%8D%E7%AB%AF%E5%BC%80%E5%8F%91/%E6%A0%87%E7%AD%BE/var">标签文档</a>');
            $this->clearData();
            $this->setData(self::fields_NAME, $tagName, true)
                ->setData(self::fields_is_system, $tagLib['is_custom'] ?? 1)
                ->setData(self::fields_MODULE_ID, $module_id)
                ->setData(self::fields_DESCRIPTION, $doc)
                ->setData(self::fields_JSON, json_encode($tagLib, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))
                ->save(true);
        }
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {

    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        //                $setup->dropTable();
        if ($setup->tableExist()) {
            return;
        }
        $setup->createTable('标签表')
            ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 11, 'primary key auto_increment', '标签ID')
            ->addColumn(self::fields_NAME, TableInterface::column_type_VARCHAR, 60, 'unique', '标签')
            ->addColumn(self::fields_DESCRIPTION, TableInterface::column_type_TEXT, null, '', 'Taglib 详情')
            ->addColumn(self::fields_MODULE_ID, TableInterface::column_type_INTEGER, 11, 'not null', '标签模型ID')
            ->addColumn(self::fields_JSON, TableInterface::column_type_TEXT, null, '', 'Taglib数据')
            ->addColumn(self::fields_is_system, TableInterface::column_type_INTEGER, 1, 'default 0', '是否系统标签')
            ->create();
    }
}
