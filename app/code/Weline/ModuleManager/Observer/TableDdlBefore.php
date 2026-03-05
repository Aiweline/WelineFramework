<?php

declare(strict_types=1);

namespace Weline\ModuleManager\Observer;

use Weline\Framework\App\Exception;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Setup\Model\ModuleTable;

/**
 * 监听 Weline_Framework_Schema::table_ddl_before，做表名冲突检查（与原 model_update_before 逻辑对齐）。
 */
class TableDdlBefore implements ObserverInterface
{
    public function __construct(
        private readonly ModuleTable $tableModel,
    ) {
    }

    public function execute(Event &$event): void
    {
        $moduleName = (string) $event->getData('module_name');
        $tableName = (string) $event->getData('table_name');
        $modelClass = $event->getData('model_class');

        if ($tableName === '' || $moduleName === '') {
            return;
        }
        if ($modelClass === ModuleTable::class) {
            return;
        }

        /** @var ModuleTable $existing */
        $existing = $this->tableModel->reset()->where($this->tableModel::schema_fields_name, $tableName)->find()->fetch();
        if (!$existing->getId()) {
            return;
        }
        if ($existing->getModuleName() === $moduleName) {
            return;
        }

        throw new Exception(__('【冲突模组：%{1} 和 %{2}】：表 %{3} 已被模块 %{4} 的模型 %{5} 使用。请为当前模型更换表名或联系管理员。', [
            $moduleName,
            $existing->getModuleName(),
            $tableName,
            $existing->getModuleName(),
            $existing->getModel(),
        ]));
    }
}
