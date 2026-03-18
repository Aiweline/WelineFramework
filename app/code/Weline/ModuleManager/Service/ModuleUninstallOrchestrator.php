<?php

declare(strict_types=1);

namespace Weline\ModuleManager\Service;

use Weline\Framework\App\Env;
use Weline\ModuleManager\Model\ModuleUninstallAudit;

/**
 * 卸载前编排：先生成 MDP 文件包，再执行表重命名备份（ModuleBackupService）。
 */
class ModuleUninstallOrchestrator
{
    public function __construct(
        private readonly ModuleDataPackageService $dataPackageService,
        private readonly ModuleBackupService $moduleBackupService,
        private readonly ModuleUninstallAudit $moduleUninstallAudit
    ) {
    }

    /**
     * env: module_uninstall_mdp_strict — 非 0 时 MDP 失败则中止卸载（默认严格）。
     *
     * @return array{success: bool, message: string, backup_timestamp?: string, mdp_path?: string, mdp_row_count?: int}
     */
    public function runBeforeRemove(string $moduleName): array
    {
        $strict = (string) Env::get('module_uninstall_mdp_strict', '1', 'Weline_ModuleManager') !== '0';

        $mdp = $this->dataPackageService->createPackage($moduleName);
        if (!$mdp['success']) {
            if ($strict) {
                return [
                    'success' => false,
                    'message' => __('模块数据包（MDP）生成失败，已中止卸载：%{1}。修复后可重试；若确需跳过 MDP，可在 env 设置 module_uninstall_mdp_strict=0（不推荐生产环境）。', [$mdp['message']]),
                ];
            }
            w_log_error('[ModuleUninstallOrchestrator] MDP 失败（已按宽松模式继续）：' . $mdp['message']);
        }

        $rename = $this->moduleBackupService->backupModuleTables($moduleName);
        if (!$rename['success']) {
            return [
                'success' => false,
                'message' => ($rename['message'] ?? '') !== ''
                    ? (string) $rename['message']
                    : __('数据库表重命名备份失败'),
            ];
        }

        $msg = __('表重命名备份完成（时间戳：%{1}）', [$rename['backup_timestamp'] ?? '']);
        if (($mdp['success'] ?? false) && !empty($mdp['package_path'])) {
            $msg .= ' ' . __('MDP：%{1}（约 %{2} 行）', [
                $mdp['package_path'],
                (string) ($mdp['row_count'] ?? 0),
            ]);
        }

        try {
            $this->moduleUninstallAudit->reset()->clearData()
                ->setData(ModuleUninstallAudit::schema_fields_MODULE_NAME, $moduleName)
                ->setData(ModuleUninstallAudit::schema_fields_ACTION, ModuleUninstallAudit::ACTION_UNINSTALL_BEFORE)
                ->setData(ModuleUninstallAudit::schema_fields_PACKAGE_PATH, (string) ($mdp['package_path'] ?? ''))
                ->setData(ModuleUninstallAudit::schema_fields_TABLE_COUNT, (int) ($mdp['table_count'] ?? 0))
                ->setData(ModuleUninstallAudit::schema_fields_ROW_COUNT, (int) ($mdp['row_count'] ?? 0))
                ->setData(
                    ModuleUninstallAudit::schema_fields_META,
                    json_encode(['backup_timestamp' => $rename['backup_timestamp'] ?? ''], JSON_UNESCAPED_UNICODE)
                )
                ->setData(ModuleUninstallAudit::schema_fields_CREATED_AT, date('Y-m-d H:i:s'))
                ->save(true);
        } catch (\Throwable $e) {
            w_log_error('[ModuleUninstallOrchestrator] 审计写入失败：' . $e->getMessage());
        }

        return [
            'success' => true,
            'message' => $msg,
            'backup_timestamp' => $rename['backup_timestamp'] ?? '',
            'mdp_path' => $mdp['package_path'] ?? '',
            'mdp_row_count' => (int) ($mdp['row_count'] ?? 0),
        ];
    }
}
