<?php

declare(strict_types=1);

namespace Weline\Database\Service;

use Weline\Database\Model\ModuleVersionOperation;
use Weline\Database\Model\ModuleVersionOperationItem;
use Weline\Database\Service\Artifact\ModuleArtifactProviderRegistry;
use Weline\Framework\App\Env;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Module\Manifest\ModuleManifestReader;

final class ModuleRollbackExecutor
{
    public const EVENT_COMPLETED = 'Weline_Database_ModuleRollback::completed';
    public const EVENT_STATE_COMMITTED = 'Weline_Database_ModuleRollback::state_committed';
    public const EVENT_RECOVERED = 'Weline_Database_ModuleRollback::failed_recovered';

    public function __construct(
        private readonly ModuleVersionOperation $operationModel,
        private readonly ModuleVersionOperationItem $itemModel,
        private readonly ModuleArtifactProviderRegistry $artifactRegistry,
        private readonly MigrationService $migrationService,
        private readonly SchemaRollbackService $schemaRollbackService,
        private readonly ModuleCodeSwitchService $codeSwitchService,
        private readonly VersionService $versionService,
        private readonly ModuleManifestReader $manifestReader,
        private readonly EventsManager $eventsManager,
    ) {
    }

    /** @return array<string, mixed> */
    public function execute(string $operationId): array
    {
        [$lockHandle, $operation, $plan] = $this->begin($operationId, ModuleVersionOperation::STATUS_QUEUED);
        $previousMaintenance = (bool)Env::get('system.maintenance', false);
        $journal = [
            'previous_maintenance' => $previousMaintenance,
            'maintenance_changed' => false,
            'snapshots' => [],
            'completed_scripts' => [],
            'completed_schema' => [],
            'switches' => [],
            'code_restored' => false,
            'finalized_versions' => [],
            'manual_command' => $this->recoveryCommand($operationId),
        ];

        try {
            $this->phase($operation, 'revalidate');
            $this->assertExecutionPreflight($plan);

            $this->phase($operation, 'maintenance');
            $journal['maintenance_changed'] = true;
            $this->persistJournal($operation, $journal);
            $this->codeSwitchService->setMaintenanceMode(true);

            $items = array_values((array)($plan['cascade_modules'] ?? []));
            $this->phase($operation, 'backup');
            foreach ($items as $item) {
                $module = (string)$item['module_name'];
                $snapshot = $this->artifactRegistry->snapshotCurrent(
                    $module,
                    (string)$item['from_version'],
                    $operationId
                );
                if (empty($snapshot['success'])) {
                    throw new \RuntimeException((string)($snapshot['error'] ?? __('代码快照失败: %{1}', $module)));
                }
                $journal['snapshots'][$module] = $snapshot;
                $this->updateItem($operationId, $module, [
                    ModuleVersionOperationItem::schema_fields_BACKUP_PATH => (string)($snapshot['path'] ?? ''),
                    ModuleVersionOperationItem::schema_fields_STATUS => 'backed_up',
                ]);
                $this->persistJournal($operation, $journal);
            }

            $this->phase($operation, 'database_rollback');
            foreach ($items as $item) {
                $module = (string)$item['module_name'];
                $journal['completed_scripts'][$module] = [];
                foreach ((array)($item['script_migrations'] ?? []) as $migration) {
                    $this->migrationService->executeRollbackPlan($module, [$migration], $operationId);
                    $journal['completed_scripts'][$module][] = $migration;
                    $this->persistJournal($operation, $journal);
                }
                $journal['completed_schema'][$module] = [];
                foreach ((array)($item['schema_operations'] ?? []) as $schemaOperation) {
                    $this->schemaRollbackService->executeRollbackPlan([$schemaOperation], $operationId);
                    $journal['completed_schema'][$module][] = $schemaOperation;
                    $this->persistJournal($operation, $journal);
                }
                $this->updateItem($operationId, $module, [
                    ModuleVersionOperationItem::schema_fields_STATUS => 'database_rolled_back',
                ]);
            }

            $this->phase($operation, 'code_switch');
            $journal['switches'] = $this->codeSwitchService->switchAll($items, $operationId);
            $this->persistJournal($operation, $journal);

            $moduleNames = array_column($items, 'module_name');
            $this->phase($operation, 'registry_refresh');
            $this->codeSwitchService->activate($moduleNames);

            $this->phase($operation, 'schema_verify');
            $schemaDiff = $this->codeSwitchService->schemaDiff($moduleNames);
            if ($schemaDiff !== []) {
                throw new \RuntimeException(__(
                    '目标代码的 Schema 只读检查仍产生 %{1} 项差异，代码与数据库不一致',
                    (string)count($schemaDiff)
                ));
            }
            $this->assertTargetCodeAndRegistry($items);

            $this->phase($operation, 'wls_reload');
            $this->codeSwitchService->reloadWls();

            $this->phase($operation, 'version_commit');
            foreach ($items as $item) {
                $module = (string)$item['module_name'];
                $this->versionService->finalizeCoordinatedRollback(
                    $module,
                    (string)$item['from_version'],
                    (string)$item['to_version'],
                    $operationId,
                );
                $journal['finalized_versions'][$module] = [
                    'from' => (string)$item['from_version'],
                    'to' => (string)$item['to_version'],
                ];
                $this->updateItem($operationId, $module, [
                    ModuleVersionOperationItem::schema_fields_STATUS => 'succeeded',
                ]);
                $this->persistJournal($operation, $journal);
            }
            $this->assertFinalVersions($items);

            $this->phase($operation, 'projection_sync');
            $this->eventsManager->dispatch(self::EVENT_STATE_COMMITTED, [
                'operation_id' => $operationId,
                'modules' => $items,
            ]);

            $this->codeSwitchService->setMaintenanceMode($previousMaintenance);
            $journal['maintenance_changed'] = false;
            $this->codeSwitchService->cleanup((array)$journal['switches']);
            $operation->setData([
                ModuleVersionOperation::schema_fields_STATUS => ModuleVersionOperation::STATUS_SUCCEEDED,
                ModuleVersionOperation::schema_fields_PHASE => 'completed',
                ModuleVersionOperation::schema_fields_ERROR => '',
                ModuleVersionOperation::schema_fields_RECOVERY_JSON => json_encode($journal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ModuleVersionOperation::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
            ])->save();

            $result = ['success' => true, 'operation_id' => $operationId, 'status' => ModuleVersionOperation::STATUS_SUCCEEDED];
            try {
                $this->eventsManager->dispatch(self::EVENT_COMPLETED, [
                    'operation_id' => $operationId,
                    'modules' => $items,
                ]);
            } catch (\Throwable $notificationFailure) {
                $journal['completion_notification_error'] = $notificationFailure->getMessage();
                $result['warning'] = $notificationFailure->getMessage();
                try {
                    $operation->setData([
                        ModuleVersionOperation::schema_fields_RECOVERY_JSON => json_encode(
                            $journal,
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                        ),
                        ModuleVersionOperation::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
                    ])->save();
                } catch (\Throwable) {
                    // The rollback is already stable and committed; notification diagnostics are best effort.
                }
            }

            return $result;
        } catch (\Throwable $failure) {
            $operation->setData([
                ModuleVersionOperation::schema_fields_STATUS => ModuleVersionOperation::STATUS_COMPENSATING,
                ModuleVersionOperation::schema_fields_PHASE => 'compensating',
                ModuleVersionOperation::schema_fields_ERROR => $failure->getMessage(),
            ])->save();
            try {
                $this->performCompensation($operation, $plan, $journal);
                if (!empty($journal['maintenance_changed'])) {
                    $this->codeSwitchService->setMaintenanceMode($previousMaintenance);
                    $journal['maintenance_changed'] = false;
                }
                $operation->setData([
                    ModuleVersionOperation::schema_fields_STATUS => ModuleVersionOperation::STATUS_FAILED_RECOVERED,
                    ModuleVersionOperation::schema_fields_PHASE => 'recovered',
                    ModuleVersionOperation::schema_fields_ERROR => $failure->getMessage(),
                    ModuleVersionOperation::schema_fields_RECOVERY_JSON => json_encode($journal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ModuleVersionOperation::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
                ])->save();
                return [
                    'success' => false,
                    'operation_id' => $operationId,
                    'status' => ModuleVersionOperation::STATUS_FAILED_RECOVERED,
                    'error' => $failure->getMessage(),
                ];
            } catch (\Throwable $compensationFailure) {
                $journal['compensation_error'] = $compensationFailure->getMessage();
                $journal['manual_command'] = $this->recoveryCommand($operationId);
                $operation->setData([
                    ModuleVersionOperation::schema_fields_STATUS => ModuleVersionOperation::STATUS_MANUAL_RECOVERY,
                    ModuleVersionOperation::schema_fields_PHASE => 'manual_recovery',
                    ModuleVersionOperation::schema_fields_ERROR => $failure->getMessage() . ' | ' . $compensationFailure->getMessage(),
                    ModuleVersionOperation::schema_fields_RECOVERY_JSON => json_encode($journal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ModuleVersionOperation::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
                ])->save();
                throw new \RuntimeException(__(
                    '回滚失败且自动补偿未完成，系统将保持维护状态。恢复命令: %{1}',
                    $journal['manual_command']
                ), 0, $compensationFailure);
            }
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    /** @return array<string, mixed> */
    public function recover(string $operationId): array
    {
        [$lockHandle, $operation, $plan] = $this->begin($operationId, ModuleVersionOperation::STATUS_MANUAL_RECOVERY);
        try {
            $journal = json_decode((string)$operation->getData(ModuleVersionOperation::schema_fields_RECOVERY_JSON), true);
            if (!is_array($journal)) {
                throw new \RuntimeException(__('人工恢复日志不完整'));
            }
            $operation->setData([
                ModuleVersionOperation::schema_fields_STATUS => ModuleVersionOperation::STATUS_COMPENSATING,
                ModuleVersionOperation::schema_fields_PHASE => 'manual_compensating',
            ])->save();
            $this->performCompensation($operation, $plan, $journal);
            if (!empty($journal['maintenance_changed'])) {
                $this->codeSwitchService->setMaintenanceMode((bool)($journal['previous_maintenance'] ?? false));
                $journal['maintenance_changed'] = false;
            }
            $operation->setData([
                ModuleVersionOperation::schema_fields_STATUS => ModuleVersionOperation::STATUS_FAILED_RECOVERED,
                ModuleVersionOperation::schema_fields_PHASE => 'recovered',
                ModuleVersionOperation::schema_fields_RECOVERY_JSON => json_encode($journal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ModuleVersionOperation::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
            ])->save();
            return ['success' => true, 'operation_id' => $operationId, 'status' => ModuleVersionOperation::STATUS_FAILED_RECOVERED];
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    private function performCompensation(ModuleVersionOperation $operation, array $plan, array &$journal): void
    {
        $items = array_values((array)($plan['cascade_modules'] ?? []));
        $moduleNames = array_column($items, 'module_name');
        if ((array)($journal['switches'] ?? []) !== [] && empty($journal['code_restored'])) {
            $this->phase($operation, 'compensating_code');
            $this->codeSwitchService->restore((array)$journal['switches']);
            $journal['code_restored'] = true;
            $this->codeSwitchService->activate($moduleNames);
            $this->persistJournal($operation, $journal);
        }

        $this->phase($operation, 'compensating_database');
        foreach (array_reverse($items) as $item) {
            $module = (string)$item['module_name'];
            $schema = (array)($journal['completed_schema'][$module] ?? []);
            if ($schema !== []) {
                $this->schemaRollbackService->compensate($schema, (string)$plan['operation_id']);
                $journal['completed_schema'][$module] = [];
                $this->persistJournal($operation, $journal);
            }
            $scripts = (array)($journal['completed_scripts'][$module] ?? []);
            if ($scripts !== []) {
                $this->migrationService->compensateRollbackPlan($module, $scripts);
                $journal['completed_scripts'][$module] = [];
                $this->persistJournal($operation, $journal);
            }
        }

        foreach (array_reverse((array)($journal['finalized_versions'] ?? []), true) as $module => $versions) {
            $this->versionService->compensateCoordinatedRollback(
                (string)$module,
                (string)$versions['to'],
                (string)$versions['from'],
                (string)$plan['operation_id'],
            );
            unset($journal['finalized_versions'][$module]);
            $this->persistJournal($operation, $journal);
        }

        $diff = $this->codeSwitchService->schemaDiff($moduleNames);
        if ($diff !== []) {
            throw new \RuntimeException(__('补偿后 Schema 仍存在 %{1} 项差异', (string)count($diff)));
        }
        if (!empty($journal['code_restored'])) {
            $this->codeSwitchService->reloadWls();
        }
        foreach ($items as $item) {
            $this->updateItem((string)$plan['operation_id'], (string)$item['module_name'], [
                ModuleVersionOperationItem::schema_fields_STATUS => 'failed_recovered',
            ]);
        }
        $this->eventsManager->dispatch(self::EVENT_RECOVERED, [
            'operation_id' => (string)$plan['operation_id'],
            'modules' => $items,
        ]);
    }

    /** @return array{0: resource, 1: ModuleVersionOperation, 2: array<string, mixed>} */
    private function begin(string $operationId, string $expectedStatus): array
    {
        $directory = BP . 'var' . DS . 'database';
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException(__('无法创建回滚锁目录'));
        }
        $lockHandle = fopen($directory . DS . 'module-rollback.lock', 'c+');
        if (!is_resource($lockHandle)) {
            throw new \RuntimeException(__('无法创建模块回滚执行锁'));
        }
        if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($lockHandle);
            throw new \RuntimeException(__('已有另一个模块回滚任务在执行'));
        }
        $operation = $this->loadOperation($operationId);
        if ($operation === null
            || (string)$operation->getData(ModuleVersionOperation::schema_fields_STATUS) !== $expectedStatus) {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
            throw new \RuntimeException(__('回滚任务状态不允许当前操作'));
        }
        $plan = json_decode((string)$operation->getData(ModuleVersionOperation::schema_fields_PLAN_JSON), true);
        if (!is_array($plan)) {
            $this->markBeginFailure($operation, $expectedStatus, __('回滚任务计划损坏'));
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
            throw new \RuntimeException(__('回滚任务计划损坏'));
        }
        $embeddedHash = (string)($plan['plan_hash'] ?? '');
        $hashPayload = $plan;
        unset($hashPayload['plan_hash']);
        $actualHash = hash('sha256', (string)json_encode(
            $this->canonicalize($hashPayload),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
        $storedHash = (string)$operation->getData(ModuleVersionOperation::schema_fields_PLAN_HASH);
        if ($embeddedHash === ''
            || !hash_equals($storedHash, $embeddedHash)
            || !hash_equals($storedHash, $actualHash)) {
            $this->markBeginFailure($operation, $expectedStatus, __('回滚任务计划哈希校验失败'));
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
            throw new \RuntimeException(__('回滚任务计划哈希校验失败'));
        }
        if ($expectedStatus === ModuleVersionOperation::STATUS_QUEUED) {
            $operation->setData([
                ModuleVersionOperation::schema_fields_STATUS => ModuleVersionOperation::STATUS_RUNNING,
                ModuleVersionOperation::schema_fields_PHASE => 'starting',
                ModuleVersionOperation::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
            ])->save();
        }
        return [$lockHandle, $operation, $plan];
    }

    private function markBeginFailure(
        ModuleVersionOperation $operation,
        string $expectedStatus,
        string $message,
    ): void {
        if ($expectedStatus !== ModuleVersionOperation::STATUS_QUEUED) {
            return;
        }
        $operation->setData([
            ModuleVersionOperation::schema_fields_STATUS => ModuleVersionOperation::STATUS_FAILED_RECOVERED,
            ModuleVersionOperation::schema_fields_PHASE => 'preflight_failed',
            ModuleVersionOperation::schema_fields_ERROR => $message,
            ModuleVersionOperation::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
        ])->save();
    }

    /** @param list<array<string, mixed>> $items */
    private function assertTargetCodeAndRegistry(array $items): void
    {
        $registry = is_file(Env::path_MODULES_FILE) ? (array)require Env::path_MODULES_FILE : [];
        foreach ($items as $item) {
            $module = (string)$item['module_name'];
            $target = (string)$item['to_version'];
            $info = Env::getInstance()->getModuleInfo($module);
            $path = is_array($info) ? (string)($info['base_path'] ?? '') : '';
            $manifest = $this->manifestReader->read($path);
            $registered = (string)($registry[$module]['version'] ?? '');
            if ($manifest->version !== $target || $registered !== $target) {
                throw new \RuntimeException(__(
                    '模块 %{1} 目标代码或注册版本验证失败：代码 %{2}，注册 %{3}，目标 %{4}',
                    [$module, $manifest->version, $registered, $target]
                ));
            }
        }
    }

    /** @param list<array<string, mixed>> $items */
    private function assertFinalVersions(array $items): void
    {
        foreach ($items as $item) {
            $module = (string)$item['module_name'];
            $target = (string)$item['to_version'];
            if ($this->versionService->getModuleVersionString($module) !== $target) {
                throw new \RuntimeException(__('模块 %{1} 最终数据库版本游标验证失败', $module));
            }
        }
    }

    private function updateItem(string $operationId, string $moduleName, array $data): void
    {
        $items = (clone $this->itemModel)->reset()
            ->where(ModuleVersionOperationItem::schema_fields_OPERATION_ID, $operationId)
            ->where(ModuleVersionOperationItem::schema_fields_MODULE_NAME, $moduleName)
            ->limit(1)
            ->select()
            ->fetch()
            ->getItems();
        $item = $items[0] ?? null;
        if ($item instanceof ModuleVersionOperationItem) {
            $data[ModuleVersionOperationItem::schema_fields_UPDATED_AT] = date('Y-m-d H:i:s');
            $item->setData($data)->save();
        }
    }

    private function phase(ModuleVersionOperation $operation, string $phase): void
    {
        $operation->setData([
            ModuleVersionOperation::schema_fields_PHASE => $phase,
            ModuleVersionOperation::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
        ])->save();
    }

    private function persistJournal(ModuleVersionOperation $operation, array $journal): void
    {
        $operation->setData([
            ModuleVersionOperation::schema_fields_RECOVERY_JSON => json_encode($journal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ModuleVersionOperation::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
        ])->save();
    }

    private function loadOperation(string $operationId): ?ModuleVersionOperation
    {
        $items = (clone $this->operationModel)->reset()
            ->where(ModuleVersionOperation::schema_fields_OPERATION_ID, $operationId)
            ->limit(1)
            ->select()
            ->fetch()
            ->getItems();
        $operation = $items[0] ?? null;
        return $operation instanceof ModuleVersionOperation ? $operation : null;
    }

    private function assertExecutionPreflight(array $plan): void
    {
        $this->codeSwitchService->assertRuntimeControlReady();
        $registry = is_file(Env::path_MODULES_FILE) ? (array)require Env::path_MODULES_FILE : [];
        foreach ((array)($plan['cascade_modules'] ?? []) as $item) {
            $module = (string)($item['module_name'] ?? '');
            $fromVersion = (string)($item['from_version'] ?? '');
            $toVersion = (string)($item['to_version'] ?? '');
            if ($module === '' || $fromVersion === '' || $toVersion === '') {
                throw new \RuntimeException(__('回滚任务模块明细不完整'));
            }

            $info = Env::getInstance()->getModuleInfo($module);
            $path = is_array($info) ? (string)($info['base_path'] ?? '') : '';
            $manifest = $this->manifestReader->read($path);
            $registeredVersion = (string)($registry[$module]['version'] ?? '');
            $databaseVersion = (string)($this->versionService->getModuleVersionString($module) ?? '');
            if ($manifest->version !== $fromVersion
                || $registeredVersion !== $fromVersion
                || $databaseVersion !== $fromVersion) {
                throw new \RuntimeException(__('模块 %{1} 在 worker 执行前发生版本漂移', $module));
            }

            $artifact = (array)($item['artifact'] ?? []);
            $actualArtifactHash = $this->directoryChecksum((string)($artifact['path'] ?? ''));
            if ($actualArtifactHash === ''
                || !hash_equals((string)($artifact['checksum'] ?? ''), $actualArtifactHash)) {
                throw new \RuntimeException(__('模块 %{1} 的目标制品在 worker 执行前已变化', $module));
            }

            $scriptPlan = $this->migrationService->planRollbackToVersion($module, $toVersion, $fromVersion);
            $schemaPlan = $this->schemaRollbackService->createPlan($module, $toVersion, $fromVersion);
            if ($scriptPlan['blockers'] !== [] || $schemaPlan['blockers'] !== []) {
                throw new \RuntimeException(__('模块 %{1} 的完整反向链在 worker 执行前已失效', $module));
            }
            $actualContentHash = hash('sha256', (string)json_encode($this->canonicalize([
                'script_migrations' => $scriptPlan['migrations'],
                'schema_operations' => $schemaPlan['operations'],
                'schema_checkpoints' => $schemaPlan['checkpoints'],
            ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $expectedContentHash = (string)($item['validation_hash'] ?? '');
            if ($expectedContentHash === '' || !hash_equals($expectedContentHash, $actualContentHash)) {
                throw new \RuntimeException(__('模块 %{1} 的反向链在 worker 执行前已变化', $module));
            }
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }

    private function directoryChecksum(string $directory): string
    {
        if (!is_dir($directory)) {
            return '';
        }
        $directory = rtrim($directory, '/\\');
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isLink()) {
                return '';
            }
            if ($file->isFile()) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));
                $files[$relative] = hash_file('sha256', $file->getPathname());
            }
        }
        ksort($files);
        return hash('sha256', (string)json_encode($files, JSON_UNESCAPED_SLASHES));
    }

    private function recoveryCommand(string $operationId): string
    {
        return PHP_BINARY . ' ' . BP . 'bin' . DS . 'w module:rollback:run --operation-id=' . $operationId . ' --recover';
    }
}
