<?php

declare(strict_types=1);

namespace Weline\Database\Service;

use Weline\Database\Api\ModuleRollbackManagerInterface;
use Weline\Database\Model\ModuleVersionOperation;
use Weline\Database\Model\ModuleVersionOperationItem;
use Weline\Database\Service\Artifact\ModuleArtifactProviderRegistry;
use Weline\Framework\App\Env;
use Weline\Framework\Architecture\Module\ModuleGraphValidator;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Module\Manifest\ModuleManifest;
use Weline\Framework\Module\Manifest\ModuleManifestReader;
use Weline\Framework\System\Process\Processer;

final class ModuleRollbackManager implements ModuleRollbackManagerInterface
{
    public const EVENT_AUDIT_STATE = 'Weline_Database_ModuleRollback::audit_state';
    public const PLAN_TTL_SECONDS = 900;

    private const PROTECTED_MODULES = [
        'Weline_Framework',
        'Weline_Database',
        'Weline_ModuleManager',
    ];

    public function __construct(
        private readonly VersionService $versionService,
        private readonly MigrationService $migrationService,
        private readonly SchemaRollbackService $schemaRollbackService,
        private readonly ModuleArtifactProviderRegistry $artifactRegistry,
        private readonly ModuleManifestReader $manifestReader,
        private readonly ModuleGraphValidator $graphValidator,
        private readonly ModuleVersionOperation $operationModel,
        private readonly ModuleVersionOperationItem $itemModel,
        private readonly EventsManager $eventsManager,
    ) {
    }

    public function listTargets(string $moduleName): array
    {
        $audit = $this->auditModuleState($moduleName);
        $currentVersion = (string)($audit['code_version'] ?? '');
        $versions = [];
        foreach ($this->artifactRegistry->listVersions($moduleName) as $artifact) {
            $version = trim((string)($artifact['version'] ?? ''));
            if ($version !== '') {
                $versions[$version] = $artifact + ['artifact_available' => true];
            }
        }
        foreach ($this->versionService->getModuleVersionHistory($moduleName, 200) as $history) {
            foreach (['from_version', 'to_version'] as $field) {
                $version = trim((string)($history[$field] ?? ''));
                if ($version !== '' && $version !== '0.0.0') {
                    $versions[$version] ??= ['version' => $version, 'source' => 'history', 'artifact_available' => false];
                }
            }
        }
        uksort($versions, static fn(string $left, string $right): int => version_compare($right, $left));

        $targets = [];
        foreach ($versions as $version => $info) {
            if ($currentVersion === '' || !version_compare($version, $currentVersion, '<')) {
                continue;
            }
            $blockers = (array)($audit['blockers'] ?? []);
            if (empty($info['artifact_available'])) {
                $blockers[] = __('缺少版本 %{1} 的精确代码制品', $version);
            }
            $targets[] = [
                'module_name' => $moduleName,
                'current_version' => $currentVersion,
                'target_version' => $version,
                'source' => (string)($info['source'] ?? ''),
                'checksum' => (string)($info['checksum'] ?? ''),
                'rollbackable' => $blockers === [],
                'blockers' => array_values(array_unique($blockers)),
                'warnings' => (array)($audit['warnings'] ?? []),
            ];
        }
        return $targets;
    }

    public function getModuleState(string $moduleName): array
    {
        return $this->auditModuleState(trim($moduleName));
    }

    public function createPlan(string $moduleName, string $targetVersion): array
    {
        $moduleName = trim($moduleName);
        $targetVersion = trim($targetVersion);
        if ($moduleName === '' || !$this->versionService->validateVersion($targetVersion)) {
            throw new \InvalidArgumentException(__('模块名或目标版本无效'));
        }

        $operationId = 'rollback-' . date('YmdHis') . '-' . bin2hex(random_bytes(6));
        $blockers = [];
        $warnings = [];
        if (in_array($moduleName, self::PROTECTED_MODULES, true)) {
            $blockers[] = __(
                '运行基础模块 %{1} 不允许在 ModuleManager 内回滚，请交由 Weline_Deploy 执行整站版本回滚',
                $moduleName
            );
        }

        $currentManifests = $this->readCurrentManifests();
        $rootManifest = $currentManifests[$moduleName] ?? null;
        if (!$rootManifest instanceof ModuleManifest) {
            $blockers[] = __('模块未激活或缺少权威 etc/module.php: %{1}', $moduleName);
        }
        $currentVersion = $rootManifest?->version ?? '';
        if ($currentVersion !== '' && !version_compare($targetVersion, $currentVersion, '<')) {
            $blockers[] = __('目标版本 %{1} 必须低于当前版本 %{2}', [$targetVersion, $currentVersion]);
        }

        $affectedModules = $rootManifest instanceof ModuleManifest
            ? $this->reverseDependencyClosure($moduleName, $currentManifests)
            : [$moduleName];
        foreach ($affectedModules as $affectedModule) {
            if (in_array($affectedModule, self::PROTECTED_MODULES, true)) {
                $blockers[] = __(
                    '级联计划涉及运行基础模块 %{1}，请交由 Weline_Deploy 执行整站回滚',
                    $affectedModule
                );
            }
            $audit = $this->auditModuleState($affectedModule);
            $blockers = array_merge($blockers, (array)($audit['blockers'] ?? []));
            $warnings = array_merge($warnings, (array)($audit['warnings'] ?? []));
        }

        $candidates = [];
        foreach ($affectedModules as $affectedModule) {
            $manifest = $currentManifests[$affectedModule] ?? null;
            if (!$manifest instanceof ModuleManifest) {
                $blockers[] = __('级联模块缺少当前 manifest: %{1}', $affectedModule);
                continue;
            }
            if ($affectedModule !== $moduleName) {
                $candidates[$affectedModule][$manifest->version] = [
                    'manifest' => $manifest,
                    'artifact' => null,
                ];
            }

            $availableVersions = $this->artifactRegistry->listVersions($affectedModule);
            if ($affectedModule === $moduleName
                && !in_array($targetVersion, array_column($availableVersions, 'version'), true)) {
                $availableVersions[] = ['version' => $targetVersion, 'source' => 'requested'];
            }
            foreach ($availableVersions as $available) {
                $version = trim((string)($available['version'] ?? ''));
                if ($version === '' || version_compare($version, $manifest->version, '>')) {
                    continue;
                }
                if ($affectedModule === $moduleName && $version !== $targetVersion) {
                    continue;
                }
                if ($affectedModule !== $moduleName && isset($candidates[$affectedModule][$version])) {
                    continue;
                }
                $artifact = $this->artifactRegistry->stage($affectedModule, $version, $operationId);
                if (empty($artifact['success'])) {
                    if ($affectedModule === $moduleName) {
                        $blockers[] = (string)($artifact['error'] ?? __('目标制品不可用'));
                    } else {
                        $warnings[] = (string)($artifact['error'] ?? __('级联候选制品不可用'));
                    }
                    continue;
                }
                try {
                    $candidateManifest = $this->manifestReader->read((string)$artifact['path']);
                } catch (\Throwable $e) {
                    $blockers[] = __('制品 manifest 无法读取: %{1}', $e->getMessage());
                    continue;
                }
                if ($candidateManifest->name !== $affectedModule || $candidateManifest->version !== $version) {
                    $blockers[] = __('制品身份校验失败: %{1} %{2}', [$affectedModule, $version]);
                    continue;
                }
                $candidates[$affectedModule][$version] = [
                    'manifest' => $candidateManifest,
                    'artifact' => $artifact,
                ];
            }
            if (isset($candidates[$affectedModule])) {
                uksort(
                    $candidates[$affectedModule],
                    static fn(string $left, string $right): int => version_compare($right, $left)
                );
            }
        }

        $selection = [];
        if ($blockers === []) {
            $selection = $this->solveHighestCompatible(
                $affectedModules,
                $candidates,
                $currentManifests,
                0,
                []
            ) ?? [];
            if ($selection === []) {
                $blockers[] = __('无法找到不高于当前版本的最高兼容级联组合');
            }
        }

        $items = [];
        foreach ($selection as $selectedModule => $candidate) {
            $fromVersion = $currentManifests[$selectedModule]->version;
            $toVersion = $candidate['manifest']->version;
            if ($fromVersion === $toVersion) {
                continue;
            }
            $artifact = is_array($candidate['artifact'] ?? null) ? $candidate['artifact'] : [];
            if ($artifact === []) {
                $blockers[] = __('模块 %{1} 缺少目标代码制品', $selectedModule);
                continue;
            }
            $scriptPlan = $this->migrationService->planRollbackToVersion($selectedModule, $toVersion, $fromVersion);
            $schemaPlan = $this->schemaRollbackService->createPlan($selectedModule, $toVersion, $fromVersion);
            $blockers = array_merge($blockers, $scriptPlan['blockers'], $schemaPlan['blockers']);
            $warnings = array_merge($warnings, $schemaPlan['warnings']);
            $items[$selectedModule] = [
                'module_name' => $selectedModule,
                'from_version' => $fromVersion,
                'to_version' => $toVersion,
                'artifact' => $artifact,
                'script_migrations' => $scriptPlan['migrations'],
                'schema_operations' => $schemaPlan['operations'],
                'schema_checkpoints' => $schemaPlan['checkpoints'],
                'validation_hash' => $this->rollbackContentHash(
                    $scriptPlan['migrations'],
                    $schemaPlan['operations'],
                    $schemaPlan['checkpoints'],
                ),
            ];
        }
        $orderedModules = $this->sortReverseDependencies(array_keys($items), $selection);
        $orderedItems = [];
        foreach ($orderedModules as $sortOrder => $orderedModule) {
            $items[$orderedModule]['sort_order'] = $sortOrder;
            $orderedItems[] = $items[$orderedModule];
        }

        $expiresAt = date('Y-m-d H:i:s', time() + self::PLAN_TTL_SECONDS);
        $plan = [
            'plan_id' => $operationId,
            'operation_id' => $operationId,
            'root_module' => $moduleName,
            'current_version' => $currentVersion,
            'target_version' => $targetVersion,
            'cascade_modules' => $orderedItems,
            'reverse_dependency_order' => $orderedModules,
            'backup_summary' => $this->backupSummary($orderedItems),
            'blockers' => array_values(array_unique(array_filter(array_map('strval', $blockers)))),
            'warnings' => array_values(array_unique(array_filter(array_map('strval', $warnings)))),
            'estimated_downtime_seconds' => max(30, count($orderedItems) * 20),
            'expires_at' => $expiresAt,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $planHash = hash('sha256', (string)json_encode($this->canonicalize($plan), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $plan['plan_hash'] = $planHash;
        $this->persistPlan($plan);
        return $plan;
    }

    public function start(string $planId, string $planHash, string $operator): array
    {
        $operator = trim($operator);
        if ($operator === '') {
            throw new \InvalidArgumentException(__('回滚操作人不能为空'));
        }
        $operation = $this->loadOperation($planId);
        if ($operation === null) {
            throw new \RuntimeException(__('回滚计划不存在: %{1}', $planId));
        }
        if ((string)$operation->getData(ModuleVersionOperation::schema_fields_STATUS) !== ModuleVersionOperation::STATUS_PLANNED) {
            throw new \RuntimeException(__('回滚计划已启动或不可再次执行'));
        }
        $plan = json_decode((string)$operation->getData(ModuleVersionOperation::schema_fields_PLAN_JSON), true);
        $hashPayload = is_array($plan) ? $plan : [];
        unset($hashPayload['plan_hash']);
        $actualPlanHash = hash('sha256', (string)json_encode(
            $this->canonicalize($hashPayload),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
        if (!is_array($plan)
            || !hash_equals((string)$operation->getData(ModuleVersionOperation::schema_fields_PLAN_HASH), $planHash)
            || !hash_equals((string)($plan['plan_hash'] ?? ''), $planHash)
            || !hash_equals($actualPlanHash, $planHash)) {
            throw new \RuntimeException(__('回滚计划校验失败，请重新预检'));
        }
        if (strtotime((string)$operation->getData(ModuleVersionOperation::schema_fields_EXPIRES_AT)) <= time()) {
            throw new \RuntimeException(__('回滚计划已过期，请重新预检'));
        }
        if ((array)($plan['blockers'] ?? []) !== []) {
            throw new \RuntimeException(__('回滚计划存在阻断项，禁止启动'));
        }

        $startLock = $this->acquireStartLock();
        try {
            $this->assertNoActiveOperation($planId);
            foreach ((array)($plan['cascade_modules'] ?? []) as $item) {
                $module = (string)($item['module_name'] ?? '');
                $fromVersion = (string)($item['from_version'] ?? '');
                $toVersion = (string)($item['to_version'] ?? '');
                $audit = $this->auditModuleState($module);
                if ((array)($audit['blockers'] ?? []) !== []
                    || (string)($audit['code_version'] ?? '') !== $fromVersion) {
                    throw new \RuntimeException(__('模块 %{1} 状态在预检后已漂移', $module));
                }
                $artifact = (array)($item['artifact'] ?? []);
                $actualChecksum = $this->directoryChecksum((string)($artifact['path'] ?? ''));
                if ($actualChecksum === '' || !hash_equals((string)($artifact['checksum'] ?? ''), $actualChecksum)) {
                    throw new \RuntimeException(__('模块 %{1} 目标制品在预检后已变化', $module));
                }

                $scriptPlan = $this->migrationService->planRollbackToVersion($module, $toVersion, $fromVersion);
                $schemaPlan = $this->schemaRollbackService->createPlan($module, $toVersion, $fromVersion);
                if ($scriptPlan['blockers'] !== [] || $schemaPlan['blockers'] !== []) {
                    throw new \RuntimeException(__('模块 %{1} 的迁移或 Schema 反向链在预检后已失效', $module));
                }
                $freshHash = $this->rollbackContentHash(
                    $scriptPlan['migrations'],
                    $schemaPlan['operations'],
                    $schemaPlan['checkpoints'],
                );
                $expectedHash = (string)($item['validation_hash'] ?? '');
                if ($expectedHash === '' || !hash_equals($expectedHash, $freshHash)) {
                    throw new \RuntimeException(__('模块 %{1} 的迁移或 Schema 计划在预检后已变化', $module));
                }
            }

            $operation->setData([
                ModuleVersionOperation::schema_fields_STATUS => ModuleVersionOperation::STATUS_QUEUED,
                ModuleVersionOperation::schema_fields_PHASE => 'queued',
                ModuleVersionOperation::schema_fields_OPERATOR => $operator,
                ModuleVersionOperation::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
            ])->save();

            $command = escapeshellarg(PHP_BINARY)
                . ' ' . escapeshellarg(BP . 'bin' . DS . 'w')
                . ' module:rollback:run --operation-id=' . escapeshellarg($planId);
            $pid = Processer::create($command, false, false, true);
            if ($pid <= 0) {
                $operation->setData([
                    ModuleVersionOperation::schema_fields_STATUS => ModuleVersionOperation::STATUS_PLANNED,
                    ModuleVersionOperation::schema_fields_PHASE => 'preflight',
                    ModuleVersionOperation::schema_fields_ERROR => __('无法启动独立回滚 worker'),
                ])->save();
                throw new \RuntimeException(__('无法启动独立回滚 worker'));
            }
            $operation->setData(ModuleVersionOperation::schema_fields_RECOVERY_JSON, json_encode([
                'worker_pid' => $pid,
                'manual_command' => $command,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))->save();

            return ['success' => true, 'operation_id' => $planId, 'status' => ModuleVersionOperation::STATUS_QUEUED, 'worker_pid' => $pid];
        } finally {
            flock($startLock, LOCK_UN);
            fclose($startLock);
        }
    }

    public function getOperation(string $operationId): array
    {
        $operation = $this->loadOperation($operationId);
        if ($operation === null) {
            throw new \RuntimeException(__('回滚任务不存在: %{1}', $operationId));
        }
        $items = (clone $this->itemModel)->reset()
            ->where(ModuleVersionOperationItem::schema_fields_OPERATION_ID, $operationId)
            ->order(ModuleVersionOperationItem::schema_fields_SORT_ORDER, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
        return [
            'operation_id' => $operationId,
            'status' => (string)$operation->getData(ModuleVersionOperation::schema_fields_STATUS),
            'phase' => (string)$operation->getData(ModuleVersionOperation::schema_fields_PHASE),
            'operator' => (string)$operation->getData(ModuleVersionOperation::schema_fields_OPERATOR),
            'error' => (string)$operation->getData(ModuleVersionOperation::schema_fields_ERROR),
            'recovery' => json_decode((string)$operation->getData(ModuleVersionOperation::schema_fields_RECOVERY_JSON), true) ?: [],
            'plan' => json_decode((string)$operation->getData(ModuleVersionOperation::schema_fields_PLAN_JSON), true) ?: [],
            'items' => array_map(static fn($item): array => $item->getData(), $items),
            'updated_at' => (string)$operation->getData(ModuleVersionOperation::schema_fields_UPDATED_AT),
        ];
    }

    /** @return array<string, ModuleManifest> */
    private function readCurrentManifests(): array
    {
        $manifests = [];
        foreach (Env::getInstance()->getActiveModules(true) as $moduleName => $info) {
            $path = (string)($info['base_path'] ?? '');
            if ($path === '' || !is_dir($path)) {
                continue;
            }
            try {
                $manifests[$moduleName] = $this->manifestReader->read($path, true);
            } catch (\Throwable) {
            }
        }
        return $manifests;
    }

    /** @param array<string, ModuleManifest> $manifests @return list<string> */
    private function reverseDependencyClosure(string $root, array $manifests): array
    {
        $affected = [$root => true];
        do {
            $changed = false;
            foreach ($manifests as $manifest) {
                foreach (array_keys($manifest->requires) as $dependency) {
                    if (isset($affected[$dependency]) && !isset($affected[$manifest->name])) {
                        $affected[$manifest->name] = true;
                        $changed = true;
                    }
                }
            }
        } while ($changed);
        return array_keys($affected);
    }

    /**
     * @param list<string> $modules
     * @param array<string, array<string, array{manifest: ModuleManifest, artifact: ?array}>> $candidates
     * @param array<string, ModuleManifest> $currentManifests
     * @param array<string, array{manifest: ModuleManifest, artifact: ?array}> $selected
     * @return array<string, array{manifest: ModuleManifest, artifact: ?array}>|null
     */
    private function solveHighestCompatible(array $modules, array $candidates, array $currentManifests, int $offset, array $selected): ?array
    {
        if ($offset >= count($modules)) {
            $manifests = $currentManifests;
            foreach ($selected as $module => $candidate) {
                $manifests[$module] = $candidate['manifest'];
            }
            return $this->graphValidator->validate($manifests) === [] ? $selected : null;
        }
        $module = $modules[$offset];
        foreach ($candidates[$module] ?? [] as $candidate) {
            $selected[$module] = $candidate;
            $solution = $this->solveHighestCompatible($modules, $candidates, $currentManifests, $offset + 1, $selected);
            if ($solution !== null) {
                return $solution;
            }
        }
        return null;
    }

    /** @param list<string> $modules @param array<string, array{manifest: ModuleManifest, artifact: ?array}> $selection */
    private function sortReverseDependencies(array $modules, array $selection): array
    {
        $moduleSet = array_fill_keys($modules, true);
        $adjacency = array_fill_keys($modules, []);
        $inDegree = array_fill_keys($modules, 0);
        foreach ($modules as $module) {
            foreach (array_keys($selection[$module]['manifest']->requires ?? []) as $dependency) {
                if (!isset($moduleSet[$dependency])) {
                    continue;
                }
                $adjacency[$module][] = $dependency;
                $inDegree[$dependency]++;
            }
        }
        $queue = array_keys(array_filter($inDegree, static fn(int $value): bool => $value === 0));
        sort($queue);
        $ordered = [];
        while ($queue !== []) {
            $module = array_shift($queue);
            $ordered[] = $module;
            foreach ($adjacency[$module] as $dependency) {
                $inDegree[$dependency]--;
                if ($inDegree[$dependency] === 0) {
                    $queue[] = $dependency;
                    sort($queue);
                }
            }
        }
        return count($ordered) === count($modules) ? $ordered : $modules;
    }

    /** @return array{module_name: string, code_version: string, registry_version: string, database_version: string, blockers: list<string>, warnings: list<string>} */
    private function auditModuleState(string $moduleName): array
    {
        $blockers = [];
        $warnings = [];
        $info = Env::getInstance()->getModuleInfo($moduleName);
        $registryVersion = is_array($info) ? trim((string)($info['version'] ?? '')) : '';
        $path = is_array($info) ? trim((string)($info['base_path'] ?? '')) : '';
        $codeVersion = '';
        if ($path === '' || !is_dir($path)) {
            $blockers[] = __('模块代码路径不存在: %{1}', $moduleName);
        } else {
            try {
                $manifest = $this->manifestReader->read($path);
                $codeVersion = $manifest->version;
                if ($manifest->name !== $moduleName) {
                    $blockers[] = __('模块代码身份与注册名不一致: %{1}', $moduleName);
                }
            } catch (\Throwable $e) {
                $blockers[] = $e->getMessage();
            }
            $realPath = realpath($path);
            $realRoot = realpath(APP_CODE_PATH);
            if ($realPath === false || $realRoot === false || !$this->pathWithin($realPath, $realRoot)) {
                $blockers[] = __('模块 %{1} 不在可原子切换的 app/code 路径下', $moduleName);
            }
        }
        $databaseVersion = (string)($this->versionService->getModuleVersionString($moduleName) ?? '');
        if ($codeVersion === '' || $registryVersion === '' || $databaseVersion === '') {
            $blockers[] = __('模块 %{1} 的代码、注册或数据库版本基线不完整', $moduleName);
        } elseif ($codeVersion !== $registryVersion || $codeVersion !== $databaseVersion) {
            $blockers[] = __(
                '模块 %{1} 状态漂移：代码 %{2}，注册 %{3}，数据库 %{4}',
                [$moduleName, $codeVersion, $registryVersion, $databaseVersion]
            );
        }
        $payload = [
            'module_name' => $moduleName,
            'code_version' => $codeVersion,
            'registry_version' => $registryVersion,
            'database_version' => $databaseVersion,
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
        $this->eventsManager->dispatch(self::EVENT_AUDIT_STATE, $payload);
        return [
            'module_name' => $moduleName,
            'code_version' => $codeVersion,
            'registry_version' => $registryVersion,
            'database_version' => $databaseVersion,
            'blockers' => array_values(array_unique((array)($payload['blockers'] ?? $blockers))),
            'warnings' => array_values(array_unique((array)($payload['warnings'] ?? $warnings))),
        ];
    }

    private function persistPlan(array $plan): void
    {
        $operation = clone $this->operationModel;
        $operation->clearData()->setData([
            ModuleVersionOperation::schema_fields_OPERATION_ID => $plan['operation_id'],
            ModuleVersionOperation::schema_fields_ROOT_MODULE => $plan['root_module'],
            ModuleVersionOperation::schema_fields_TARGET_VERSION => $plan['target_version'],
            ModuleVersionOperation::schema_fields_PLAN_HASH => $plan['plan_hash'],
            ModuleVersionOperation::schema_fields_PLAN_JSON => json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ModuleVersionOperation::schema_fields_STATUS => ModuleVersionOperation::STATUS_PLANNED,
            ModuleVersionOperation::schema_fields_PHASE => 'preflight',
            ModuleVersionOperation::schema_fields_EXPIRES_AT => $plan['expires_at'],
            ModuleVersionOperation::schema_fields_CREATED_AT => date('Y-m-d H:i:s'),
            ModuleVersionOperation::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
        ])->save();
        foreach ($plan['cascade_modules'] as $item) {
            $artifact = (array)($item['artifact'] ?? []);
            $model = clone $this->itemModel;
            $model->clearData()->setData([
                ModuleVersionOperationItem::schema_fields_OPERATION_ID => $plan['operation_id'],
                ModuleVersionOperationItem::schema_fields_MODULE_NAME => $item['module_name'],
                ModuleVersionOperationItem::schema_fields_FROM_VERSION => $item['from_version'],
                ModuleVersionOperationItem::schema_fields_TO_VERSION => $item['to_version'],
                ModuleVersionOperationItem::schema_fields_ARTIFACT_PROVIDER => (string)($artifact['source'] ?? ''),
                ModuleVersionOperationItem::schema_fields_ARTIFACT_PATH => (string)($artifact['path'] ?? ''),
                ModuleVersionOperationItem::schema_fields_ARTIFACT_CHECKSUM => (string)($artifact['checksum'] ?? ''),
                ModuleVersionOperationItem::schema_fields_STATUS => 'planned',
                ModuleVersionOperationItem::schema_fields_SORT_ORDER => (int)$item['sort_order'],
                ModuleVersionOperationItem::schema_fields_CREATED_AT => date('Y-m-d H:i:s'),
                ModuleVersionOperationItem::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
            ])->save();
        }
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

    /** @return resource */
    private function acquireStartLock()
    {
        $directory = BP . 'var' . DS . 'database';
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException(__('无法创建回滚锁目录'));
        }
        $handle = fopen($directory . DS . 'module-rollback-start.lock', 'c+');
        if (!is_resource($handle)) {
            throw new \RuntimeException(__('无法创建回滚启动锁'));
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new \RuntimeException(__('另一个模块回滚启动请求正在处理'));
        }
        return $handle;
    }

    private function assertNoActiveOperation(string $operationId): void
    {
        $active = (clone $this->operationModel)->reset()
            ->where(ModuleVersionOperation::schema_fields_STATUS, [
                ModuleVersionOperation::STATUS_QUEUED,
                ModuleVersionOperation::STATUS_RUNNING,
                ModuleVersionOperation::STATUS_COMPENSATING,
                ModuleVersionOperation::STATUS_MANUAL_RECOVERY,
            ], 'IN')
            ->where(ModuleVersionOperation::schema_fields_OPERATION_ID, $operationId, '!=')
            ->limit(1)
            ->select()
            ->fetch()
            ->getItems();
        if ($active !== []) {
            throw new \RuntimeException(__('已有模块回滚或人工恢复任务未结束'));
        }
    }

    private function rollbackContentHash(
        array $scriptMigrations,
        array $schemaOperations,
        array $schemaCheckpoints,
    ): string
    {
        return hash('sha256', (string)json_encode($this->canonicalize([
            'script_migrations' => $scriptMigrations,
            'schema_operations' => $schemaOperations,
            'schema_checkpoints' => $schemaCheckpoints,
        ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @param list<array<string, mixed>> $items */
    private function backupSummary(array $items): array
    {
        $columns = 0;
        $tables = 0;
        $scriptColumns = 0;
        $scriptTables = 0;
        foreach ($items as $item) {
            foreach ((array)($item['schema_operations'] ?? []) as $operation) {
                $kind = (string)($operation['operation_kind'] ?? '');
                if (in_array($kind, ['add_column', 'modify_column'], true)) {
                    $columns++;
                } elseif ($kind === 'create_table') {
                    $tables++;
                }
            }
            foreach ((array)($item['script_migrations'] ?? []) as $migration) {
                $strategy = (array)($migration['rollback_backup_strategy'] ?? []);
                if ((string)($strategy['strategy'] ?? '') === 'column') {
                    $scriptColumns += count((array)($strategy['columns'] ?? []))
                        * max(1, count((array)($strategy['tables'] ?? [])));
                } elseif ((string)($strategy['strategy'] ?? '') === 'table') {
                    $scriptTables += count((array)($strategy['tables'] ?? []));
                }
            }
        }
        return [
            'columns' => $columns + $scriptColumns,
            'tables' => $tables + $scriptTables,
            'schema_columns' => $columns,
            'schema_tables' => $tables,
            'script_columns' => $scriptColumns,
            'script_tables' => $scriptTables,
            'retention_days_after_restore' => 30,
            'active_operation_retention' => 'forever',
        ];
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
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isLink()) {
                return '';
            }
            if ($file->isFile()) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(rtrim($directory, '/\\')) + 1));
                $files[$relative] = hash_file('sha256', $file->getPathname());
            }
        }
        ksort($files);
        return hash('sha256', (string)json_encode($files, JSON_UNESCAPED_SLASHES));
    }

    private function pathWithin(string $path, string $root): bool
    {
        $path = str_replace('\\', '/', rtrim($path, '/\\'));
        $root = str_replace('\\', '/', rtrim($root, '/\\'));
        if (DIRECTORY_SEPARATOR === '\\') {
            $path = strtolower($path);
            $root = strtolower($root);
        }
        return $path === $root || str_starts_with($path, $root . '/');
    }
}
