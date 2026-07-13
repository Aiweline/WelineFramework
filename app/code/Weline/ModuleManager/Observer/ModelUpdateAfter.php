<?php

namespace Weline\ModuleManager\Observer;

use Weline\Framework\App\Exception;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\SchemaDiffExcludedModelInterface;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\ModuleManager\Model\Module;
use Weline\Framework\Setup\Model\ModuleTable;

class ModelUpdateAfter implements ObserverInterface
{
    private ModuleTable $table;

    public function __construct(
        ModuleTable $table
    )
    {
        $this->table = $table;
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $type = $event->getData('type');
        $data = $event->getData('data');
        /**@var Model $model */
        $model = $data->getModel();
        if ($model::class !== ModuleTable::class
            && !$model instanceof SchemaDiffExcludedModelInterface
            && $model instanceof Model) {
            $this->table->reset()->clearData();
            /**@var Module $module */
            $module = $event->getData('module');
            # 检查是否存在表
            $table = $model->getTable();
            $table = str_replace('`', '', $table);
            
            # 检查模型类是否已存在
            /**@var ModuleTable $existingModel */
            $existingModel = $this->table->where($this->table::schema_fields_model, $model::class)->find()->fetch();
            if ($existingModel->getId()) {
                # 如果模型已存在，跳过插入
                return;
            }
            
            # 检查表名是否已被其他模型使用
            /**@var ModuleTable $has */
            $has = $this->table->where($this->table::schema_fields_name, $table)->find()->fetch();
            if ($has->getId() and $has->getModuleName() != $module->getName() and $has->getModel() != $model::class) {
                throw new Exception($table . __('表已存在！该表已被：%{1} 模组下的 %{2} 模型创建，请为当前模型 %{3} 更换表名。如果你确认需要移除表，请访问module_table表，手动删除表。', [$has->getModuleName(), $has->getModel(), $model::class]));
            }
            
            # 如果表名已存在且是同一个模型，跳过插入
            if ($has->getId() and $has->getModel() == $model::class) {
                return;
            }
            
            $this->table->reset()->clearData()
                ->setData($this->table::schema_fields_module_name, $module->getName())
                ->setData($this->table::schema_fields_name, $table, true)
                ->setData($this->table::schema_fields_model, $model::class, true)
                ->save(true);
        }
    }
}
