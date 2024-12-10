<?php

namespace Weline\ModuleManager\Observer;

use Weline\Framework\App\Exception;
use Weline\Framework\Database\Model;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Module\Model\Module;
use Weline\ModuleManager\Model\Module\Table;

class ModelUpdateBefore implements ObserverInterface
{
    private Table $table;

    public function __construct(
        Table $table
    ) {
        $this->table = $table;
    }

    /**
     * @inheritDoc
     */
    public function execute(Event $event): void
    {
        $type = $event->getData('type');
        $data = $event->getData('data');
        /**@var Model $model */
        $model = $data->getModel();
        if ($model::class !== Table::class and $type === 'install') {
            /**@var Module $module */
            $module = $event->getData('module');
            # 检查是否存在表
            /**@var Table $modelTable */
            $modelTable = $this->table->where($this->table::fields_model, $model::class)->find()->fetch();
            if ($modelTable->getName()) {
                throw new Exception(__('【冲突模组：%1和%2】：你当前安装的模型 %3 和模型 %4 的表名（%5）重复。请修改当前重复模型【%6】的表名(table)属性或者重命名模型名。', [$module->getName(), $modelTable->getModuleName(), $model->getTable(), $modelTable->getModel(), $modelTable->getName(), $model::class]));
            }
        }
    }
}
