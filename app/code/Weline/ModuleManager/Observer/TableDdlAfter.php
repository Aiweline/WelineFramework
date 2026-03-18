<?php

declare(strict_types=1);

namespace Weline\ModuleManager\Observer;

use Weline\Framework\App\Exception;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Setup\Model\ModuleTable;

/**
 * 监听 Weline_Framework_Schema::table_ddl_after，写 Module\Table 记录（与原 model_update_after 逻辑对齐）。
 */
class TableDdlAfter implements ObserverInterface
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

        $tableName = str_replace('`', '', $tableName);

        /** @var ModuleTable $existingByModel */
        $existingByModel = $this->tableModel->reset()->where(ModuleTable::schema_fields_model, $modelClass ?? '')->find()->fetch();
        if ($existingByModel->getId()) {
            return;
        }

        /** @var ModuleTable $existingByName */
        $existingByName = $this->tableModel->reset()->where(ModuleTable::schema_fields_name, $tableName)->find()->fetch();
        if ($existingByName->getId() && $existingByName->getModuleName() !== $moduleName) {
            $policy = (string) ($existingByName->getData(ModuleTable::schema_fields_TABLE_POLICY) ?: ModuleTable::POLICY_OWNED);
            $succ = trim((string) ($existingByName->getData(ModuleTable::schema_fields_SUCCESSOR_MODULE_NAME) ?: ''));
            if ($policy === ModuleTable::POLICY_SUCCESSOR && $succ !== '' && $succ === $moduleName) {
                $modelForRecord = $modelClass !== null && $modelClass !== ''
                    ? $modelClass
                    : 'Eav::' . $tableName;
                $existingByName
                    ->setModuleName($moduleName)
                    ->setModel($modelForRecord)
                    ->setData(ModuleTable::schema_fields_TABLE_POLICY, ModuleTable::POLICY_OWNED)
                    ->setData(ModuleTable::schema_fields_OWNER_MODULE_NAME, null)
                    ->setData(ModuleTable::schema_fields_SUCCESSOR_MODULE_NAME, null)
                    ->setData(ModuleTable::schema_fields_DEPRECATED_AT, null)
                    ->save(true);

                return;
            }
        }
        if ($existingByName->getId() && $existingByName->getModuleName() !== $moduleName && $existingByName->getModel() !== (string) $modelClass) {
            throw new Exception($tableName . __(' 表已存在！该表已被：%{1} 模组下的 %{2} 模型创建。请为当前模型更换表名。', [$existingByName->getModuleName(), $existingByName->getModel()]));
        }
        if ($existingByName->getId() && $existingByName->getModel() === (string) $modelClass) {
            return;
        }

        $modelForRecord = $modelClass !== null && $modelClass !== ''
            ? $modelClass
            : 'Eav::' . $tableName;

        $this->tableModel->reset()->clearData()
            ->setData(ModuleTable::schema_fields_module_name, $moduleName)
            ->setData(ModuleTable::schema_fields_name, $tableName, true)
            ->setData(ModuleTable::schema_fields_model, $modelForRecord, true)
            ->save(true);
    }
}
