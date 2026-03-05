<?php

namespace Weline\ModuleManager\Observer;

use Weline\Framework\App\Exception;
use Weline\Framework\Database\Model;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Module\Model\Module;
use Weline\Framework\Setup\Model\ModuleTable;

class ModelUpdateBefore implements ObserverInterface
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
        if ($model::class !== ModuleTable::class and $type === 'install') {
            /**@var Module $module */
            $module = $event->getData('module');
            # 检查是否存在表
            /**@var ModuleTable $modelTable */
            $modelTable = $this->table->where($this->table::schema_fields_model, $model::class)->find()->fetch();
            if ($modelTable->getName()) {
                // 如果是同一个模块，不认为是冲突（可能是模块重新安装）
                if ($module->getName() !== $modelTable->getModuleName()) {
                    throw new Exception(__('【冲突模组：%{1}和%{2}】：你当前安装的模型 %{3} 和模型 %{4} 的表名（%{5}）重复。请修改当前重复模型【%{6}】的表名(table)属性或者重命名模型名。', [$module->getName(), $modelTable->getModuleName(), $model->getTable(), $modelTable->getModel(), $modelTable->getName(), $model::class]));
                }
            }
        }
    }
}
