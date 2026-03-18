<?php

declare(strict_types=1);

namespace Weline\ModuleManager\Service;

use Weline\Framework\Setup\Model\ModuleTable;

/**
 * 将 weline_module_table 中表归属从源模块划转到目标模块（合并模块 / 消除冲突）。
 */
class ModuleTableHandoverService
{
    public function __construct(
        private readonly ModuleTable $moduleTableModel
    ) {
    }

    /**
     * @param array<string, string> $logicalTableToModel 逻辑表名 => 新模型 FQCN
     * @return array{success: bool, message: string, updated: int}
     */
    public function handover(string $fromModule, string $toModule, array $logicalTableToModel): array
    {
        $fromModule = trim($fromModule);
        $toModule = trim($toModule);
        if ($fromModule === '' || $toModule === '') {
            return ['success' => false, 'message' => __('源模块与目标模块不能为空'), 'updated' => 0];
        }
        if ($logicalTableToModel === []) {
            return ['success' => false, 'message' => __('请指定至少一张表：逻辑表名=>模型类'), 'updated' => 0];
        }

        $updated = 0;
        foreach ($logicalTableToModel as $logical => $modelClass) {
            $logical = trim($logical);
            $modelClass = trim($modelClass);
            if ($logical === '' || $modelClass === '' || !class_exists($modelClass)) {
                return ['success' => false, 'message' => __('无效表名或模型类：%{1}', [$logical]), 'updated' => $updated];
            }

            $row = clone $this->moduleTableModel;
            $row->reset()
                ->where(ModuleTable::schema_fields_module_name, $fromModule)
                ->where(ModuleTable::schema_fields_name, $logical)
                ->find()
                ->fetch();
            if (!$row->getId()) {
                return ['success' => false, 'message' => __('未找到登记：模块 %{1} 表 %{2}', [$fromModule, $logical]), 'updated' => $updated];
            }

            $row->setModuleName($toModule);
            $row->setModel($modelClass);
            $row->setData(ModuleTable::schema_fields_TABLE_POLICY, ModuleTable::POLICY_OWNED);
            $row->setData(ModuleTable::schema_fields_OWNER_MODULE_NAME, null);
            $row->setData(ModuleTable::schema_fields_SUCCESSOR_MODULE_NAME, null);
            $row->setData(ModuleTable::schema_fields_DEPRECATED_AT, null);
            $row->save(true);
            $updated++;
        }

        return [
            'success' => true,
            'message' => __('已划转 %{1} 张表：%{2} → %{3}', [(string) $updated, $fromModule, $toModule]),
            'updated' => $updated,
        ];
    }

    /**
     * 将源模块在登记中的全部表划转到目标（需逐个提供模型类时使用 handover；本方法仅同模型类前缀场景）。
     *
     * @param array<string, string> $logicalTableToModel 若某表未在 map 中则跳过
     */
    public function handoverAllMapped(string $fromModule, string $toModule, array $logicalTableToModel): array
    {
        $items = $this->moduleTableModel->reset()
            ->where(ModuleTable::schema_fields_module_name, trim($fromModule))
            ->select()
            ->fetch()
            ->getItems();

        $map = [];
        foreach ($items as $it) {
            if (!$it instanceof ModuleTable) {
                continue;
            }
            $n = $it->getName();
            if ($n !== '' && isset($logicalTableToModel[$n])) {
                $map[$n] = $logicalTableToModel[$n];
            }
        }
        if ($map === []) {
            return ['success' => false, 'message' => __('源模块无已登记表，或 map 与登记不匹配'), 'updated' => 0];
        }

        return $this->handover($fromModule, $toModule, $map);
    }

    /**
     * 标记 successor：不改 module_name，允许目标模块后续 DDL 接管（配合 TableDdlAfter）。
     */
    public function markSuccessor(string $fromModule, string $logicalTable, string $successorModule): array
    {
        $row = clone $this->moduleTableModel;
        $row->reset()
            ->where(ModuleTable::schema_fields_module_name, trim($fromModule))
            ->where(ModuleTable::schema_fields_name, trim($logicalTable))
            ->find()
            ->fetch();
        if (!$row->getId()) {
            return ['success' => false, 'message' => __('未找到登记'), 'updated' => 0];
        }
        $row->setData(ModuleTable::schema_fields_TABLE_POLICY, ModuleTable::POLICY_SUCCESSOR);
        $row->setData(ModuleTable::schema_fields_SUCCESSOR_MODULE_NAME, trim($successorModule));
        $row->setData(ModuleTable::schema_fields_DEPRECATED_AT, date('Y-m-d H:i:s'));
        $row->save(true);

        return ['success' => true, 'message' => __('已标记 successor：%{1} → %{2}', [$logicalTable, $successorModule]), 'updated' => 1];
    }
}
