<?php

declare(strict_types=1);

namespace Weline\Deploy\Service;

use Weline\Framework\App\Env;
use Weline\Framework\App\System;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

class DeployOrchestratorService
{
    private const LOCAL_APPSTORE_PLATFORM_URL = 'https://app.test.weline.com:9523';
    private const PRODUCTION_APPSTORE_PLATFORM_URL = 'https://app.aiweline.com';

    /**
     * @var list<string>
     */
    private const DEPLOY_PROTECTED_RELATIVE_PATHS = [
        'pub',
        'var/generated',
        'var/cache',
        'var/session',
        'var/log',
    ];

    private bool $isWindows;

    public function __construct(
        private readonly DeployConfigService $configService,
        private readonly DeployReleaseRuntimeService $runtimeService,
        private readonly DeployReleaseHistoryService $historyService,
        private readonly DeployGitMetadataService $gitService,
        private readonly DeployProjectCommandPolicyService $commandPolicyService,
        private readonly DeploySiteBackupService $siteBackupService,
        private readonly DeployReleaseNotifier $releaseNotifier,
        private readonly DeployReleaseFlightGate $flightGate,
        private readonly DeployMaintenanceWindowService $maintenanceWindow,
    ) {
        $this->isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }

    /**
     * @param array{
     *     trigger:string,
     *     ref_type:string,
     *     ref:string,
     *     deploy_version_hint:string|null,
     *     git_checkout:string|null,
     *     git_tag:string|null,
     *     force:bool,
     *     no_backup:bool,
     *     backup_trigger?:string,
     *     context?:array<string,mixed>,
     *     config?:array<string,mixed>,
     *     printer:Printing|null
     * } $params
     * @return array{success:bool, release_id:string, deploy_version:string, message:string, busy?:bool}
     */
    public function release(array $params): array
    {
        $trigger = $params['trigger'] ?? 'cli';
        $refType = $params['ref_type'] ?? 'branch';
        $ref = $params['ref'] ?? '';
        $versionHint = $params['deploy_version_hint'] ?? null;
        $gitCheckout = $params['git_checkout'] ?? null;
        $gitTag = $params['git_tag'] ?? null;
        $force = $params['force'] ?? false;
        $noBackup = (bool)($params['no_backup'] ?? true);
        $backupTrigger = trim((string)($params['backup_trigger'] ?? 'release'));
        $runtimeConfig = is_array($params['config'] ?? null) ? $params['config'] : [];
        $releaseContext = $this->releaseContextFromParams($params, $runtimeConfig);
        /** @var Printing|null $printer */
        $printer = $params['printer'] ?? null;
        $windowPrinter = $this->resolvePrinting($printer);

        $log = static function (string $message) use ($printer): void {
            if ($printer) {
                $printer->note($message);
            }
        };

        $deployRoot = '';
        $config = [];
        $preCommit = '';
        $preBranch = '';
        $preCurrent = null;
        $gitUpdateStarted = false;
        $versionStampWritten = false;
        $gitCommit = '';
        $deployVersion = '';
        $releaseId = '';
        $flightAcquired = false;
        $notifyBase = static fn(array $extra = []): array => $extra;

        try {
            $config = $this->loadConfig($runtimeConfig);
            $deployRoot = $this->resolveDeployRoot($config);
            $releaseContext['deploy_root'] = $deployRoot;

            // Single-flight before any history/notify/Git — busy must not auto-rollback.
            if (!$this->flightGate->tryAcquire($deployRoot)) {
                $log(__('发布繁忙：已有进行中的发布，本任务将排队重试。'));

                return [
                    'success' => false,
                    'busy' => true,
                    'release_id' => '',
                    'deploy_version' => '',
                    'message' => DeployReleaseFlightGate::BUSY_MESSAGE,
                ];
            }
            $flightAcquired = true;

            $releaseId = $this->runtimeService->generateReleaseId($versionHint ?? 'release');
            $this->historyService->start($releaseId, $trigger, $refType, $ref, $versionHint, $gitTag, $releaseContext);
            $startedAt = time();
            $notifyBase = static function (array $extra = []) use ($releaseId, $trigger, $ref, $startedAt): array {
                return array_merge([
                    'release_id' => $releaseId,
                    'trigger' => $trigger,
                    'ref' => $ref,
                    'elapsed_s' => max(0, time() - $startedAt),
                ], $extra);
            };
            $log(__('开始发布：%{1}', [$releaseId]));
            $this->releaseNotifier->notifyPhase(DeployReleaseNotifier::PHASE_STARTED, $notifyBase());

            $this->maintenanceWindow->begin($windowPrinter);

            $preCommit = $this->gitService->getFullCommit($deployRoot);
            $preBranch = $this->gitService->getCurrentBranch($deployRoot);
            $preCurrent = $this->runtimeService->getCurrent($deployRoot);

            if ($this->shouldRunBackup($noBackup)) {
                $log(__('备份中...'));
                $this->releaseNotifier->notifyPhase(DeployReleaseNotifier::PHASE_BACKUP, $notifyBase([
                    'deploy_root' => $deployRoot,
                ]));
                $this->runDeployBackup($config, $deployRoot, $backupTrigger, [
                    'git_commit' => is_array($preCurrent)
                        ? (string)($preCurrent['git_commit'] ?? $preCommit)
                        : $preCommit,
                    'deploy_version' => $this->runtimeService->getDeployVersion($deployRoot),
                    'ref' => $ref,
                ]);
            }

            $log(__('Git 更新中...'));
            $gitUpdateStarted = true;
            $this->executeGitUpdate($config, $refType, $gitCheckout, $gitTag, $force, $deployRoot);

            $gitCommit = $this->gitService->getFullCommit($deployRoot);
            $gitBranch = $this->gitService->getCurrentBranch($deployRoot);

            if ($refType === 'tag' && $gitTag) {
                $deployVersion = $gitTag;
            } else {
                $deployVersion = $this->gitService->getShortCommit($deployRoot) ?: ($versionHint ?? 'unknown');
            }
            $this->releaseNotifier->notifyPhase(DeployReleaseNotifier::PHASE_GIT_DONE, $notifyBase([
                'deploy_root' => $deployRoot,
                'deploy_version' => $deployVersion,
                'git_commit' => $gitCommit,
            ]));

            // R1：空 POST_DEPLOY 不得跳过 Upgrade；默认 setup:upgrade（!DEV → SetupUpgradeAfterDeployStatic）
            $postDeployCommand = trim((string)($config['POST_DEPLOY_COMMAND'] ?? ''));
            if ($postDeployCommand === '') {
                $postDeployCommand = 'php bin/w setup:upgrade -f';
                $log(__('POST_DEPLOY 为空，默认执行 setup:upgrade -f（强制更新）以达静态铺平…'));
            }
            $postDeployCommand = $this->ensureKeepMaintenanceOnSetupUpgrade($postDeployCommand);
            $postDeployCommand = $this->ensureForceOnSetupUpgrade($postDeployCommand);
            $log(__('执行后置命令...'));
            $this->releaseNotifier->notifyPhase(DeployReleaseNotifier::PHASE_POST_DEPLOY_START, $notifyBase([
                'deploy_root' => $deployRoot,
                'deploy_version' => $deployVersion,
                'git_commit' => $gitCommit,
                'command' => $postDeployCommand,
            ]));
            $this->execCommand($postDeployCommand, $deployRoot);
            $this->releaseNotifier->notifyPhase(DeployReleaseNotifier::PHASE_POST_DEPLOY_DONE, $notifyBase([
                'deploy_root' => $deployRoot,
                'deploy_version' => $deployVersion,
                'git_commit' => $gitCommit,
                'command' => $postDeployCommand,
            ]));

            $workerBuildId = $this->runtimeService->generateWorkerBuildId();
            $deployModeInfo = $this->resolveDeployModeInfo();
            $deployMode = $deployModeInfo['mode'];
            $appStoreInfo = $this->resolveAppStoreMarketplaceInfo($deployMode, (bool)$deployModeInfo['explicit']);
            $currentPayload = [
                'release_id' => $releaseId,
                'deploy_version' => $deployVersion,
                'worker_build_id' => $workerBuildId,
                'git_commit' => $gitCommit,
                'git_ref_type' => $refType,
                'git_ref' => $ref,
                'git_tag' => $gitTag,
                'git_branch' => $gitBranch,
                'deployed_at' => time(),
                'deploy_mode' => $deployMode,
                'deploy_mode_source' => $deployModeInfo['source'],
                'appstore_environment' => $appStoreInfo['environment'],
                'appstore_platform_url' => $appStoreInfo['platform_url'],
                'appstore_platform_url_source' => $appStoreInfo['source'],
                'deploy_root' => $deployRoot,
            ];
            foreach ($releaseContext as $contextKey => $contextValue) {
                if ($contextValue !== '') {
                    $currentPayload[$contextKey] = $contextValue;
                }
            }
            $this->runtimeService->saveCurrent($currentPayload, $deployRoot);
            $this->runtimeService->syncEnv($deployVersion, $workerBuildId);
            $versionStampWritten = true;
            $log(__('版本戳已写入：%{1}', [$deployVersion]));
            $this->releaseNotifier->notifyPhase(DeployReleaseNotifier::PHASE_STAMP_WRITTEN, $notifyBase([
                'deploy_root' => $deployRoot,
                'deploy_version' => $deployVersion,
                'git_commit' => $gitCommit,
            ]));

            $log(__('重载服务...'));
            $this->reloadServer($deployRoot);
            $this->releaseNotifier->notifyPhase(DeployReleaseNotifier::PHASE_RELOAD, $notifyBase([
                'deploy_root' => $deployRoot,
                'deploy_version' => $deployVersion,
                'git_commit' => $gitCommit,
            ]));

            $this->historyService->markSuccess($releaseId, $deployVersion, $workerBuildId, $gitCommit, $gitBranch);
            $this->dispatchReleaseAfter($releaseId, $deployVersion, $refType, $gitTag, $gitCommit, $releaseContext);

            $log(__('发布成功：%{1}', [$deployVersion]));
            $this->releaseNotifier->notifyPhase(DeployReleaseNotifier::PHASE_SUCCESS, $notifyBase([
                'deploy_root' => $deployRoot,
                'deploy_version' => $deployVersion,
                'git_commit' => $gitCommit,
            ]));

            return [
                'success' => true,
                'release_id' => $releaseId,
                'deploy_version' => $deployVersion,
                'message' => 'ok',
            ];
        } catch (\Throwable $throwable) {
            $error = $throwable->getMessage();
            if ($releaseId === '') {
                $log(__('发布失败：%{1}', [$error]));

                return [
                    'success' => false,
                    'release_id' => '',
                    'deploy_version' => '',
                    'message' => $error,
                ];
            }
            $outputTail = $this->clipNotifyTail($error);
            $rollbackNote = $this->autoRollbackAfterReleaseFailure(
                $gitUpdateStarted,
                $preCommit,
                $preBranch,
                is_array($preCurrent) ? $preCurrent : null,
                $versionStampWritten,
                is_array($config) ? $config : [],
                $deployRoot,
                $releaseContext,
                $log
            );
            if ($rollbackNote !== '') {
                $error .= $rollbackNote;
            }
            $this->historyService->markFailed($releaseId, $error, $outputTail);
            $log(__('发布失败：%{1}', [$error]));
            $this->releaseNotifier->notifyPhase(DeployReleaseNotifier::PHASE_FAILED, $notifyBase([
                'deploy_root' => $deployRoot,
                'deploy_version' => $deployVersion,
                'git_commit' => $gitCommit,
                'error_message' => $error,
                'output_tail' => $outputTail,
                'auto_rollback' => $rollbackNote !== '',
            ]));

            return [
                'success' => false,
                'release_id' => $releaseId,
                'deploy_version' => '',
                'message' => $error,
            ];
        } finally {
            try {
                $this->maintenanceWindow->end($windowPrinter);
            } finally {
                if ($flightAcquired) {
                    $this->flightGate->release();
                }
            }
        }
    }

    private function clipNotifyTail(string $text): string
    {
        $max = DeployReleaseHistoryService::OUTPUT_TAIL_MAX_CHARS;
        if (mb_strlen($text, 'UTF-8') <= $max) {
            return $text;
        }

        return mb_substr($text, max(0, mb_strlen($text, 'UTF-8') - $max), $max, 'UTF-8');
    }

    /**
     * @param array{
     *     rollback_ref:string,
     *     context?:array<string,mixed>,
     *     config?:array<string,mixed>,
     *     no_backup?:bool,
     *     backup_trigger?:string,
     *     printer?:Printing|null
     * } $params
     * @return array{success:bool, release_id:string, deploy_version:string, message:string}
     */
    public function rollback(array $params): array
    {
        $runtimeConfig = is_array($params['config'] ?? null) ? $params['config'] : [];
        $releaseContext = $this->releaseContextFromParams($params, $runtimeConfig);
        $noBackup = (bool)($params['no_backup'] ?? true);
        $backupTrigger = trim((string)($params['backup_trigger'] ?? 'rollback'));
        /** @var Printing|null $printer */
        $printer = $params['printer'] ?? null;
        $windowPrinter = $this->resolvePrinting($printer);
        $log = static function (string $message) use ($printer): void {
            if ($printer) {
                $printer->note($message);
            }
        };

        try {
            $rollbackRef = $this->commandPolicyService->normalizeRollbackRef((string)($params['rollback_ref'] ?? ''));
            if ($rollbackRef === '') {
                throw new \InvalidArgumentException((string)__('Rollback reference cannot be empty.'));
            }
            $refType = $this->commandPolicyService->rollbackRefKind($rollbackRef);
        } catch (\Throwable $throwable) {
            return [
                'success' => false,
                'release_id' => '',
                'deploy_version' => '',
                'message' => $throwable->getMessage(),
            ];
        }

        $versionHint = $this->rollbackVersionHint($rollbackRef, $refType);
        $releaseId = $this->runtimeService->generateReleaseId('rollback-' . $versionHint);
        $gitTag = $refType === 'tag' ? $this->rollbackTagName($rollbackRef) : null;
        $this->historyService->start($releaseId, 'rollback', $refType, $rollbackRef, $versionHint, $gitTag, $releaseContext);
        $startedAt = time();
        $notifyBase = static function (array $extra = []) use ($releaseId, $rollbackRef, $startedAt): array {
            return array_merge([
                'release_id' => $releaseId,
                'trigger' => 'rollback',
                'ref' => $rollbackRef,
                'elapsed_s' => max(0, time() - $startedAt),
            ], $extra);
        };
        $log(__('开始回滚：%{1}', [$releaseId]));
        $this->releaseNotifier->notifyPhase(DeployReleaseNotifier::PHASE_ROLLBACK_STARTED, $notifyBase());

        $deployRoot = '';
        $gitCommit = '';
        $deployVersion = '';

        try {
            $config = $this->loadConfig($runtimeConfig);
            $deployRoot = $this->resolveDeployRoot($config);
            $releaseContext['deploy_root'] = $deployRoot;
            $this->maintenanceWindow->begin($windowPrinter);

            if ($this->shouldRunBackup($noBackup)) {
                $log(__('回滚前备份中...'));
                $this->runDeployBackup($config, $deployRoot, $backupTrigger, [
                    'git_commit' => $this->runtimeService->getCurrent($deployRoot)['git_commit'] ?? '',
                    'deploy_version' => $this->runtimeService->getDeployVersion($deployRoot),
                    'ref' => $rollbackRef,
                ]);
            }

            $log(__('Git 回滚中...'));
            $rollbackMeta = $this->executeGitRollback($rollbackRef, $refType, $config, $deployRoot);
            $gitCommit = $this->gitService->getFullCommit($deployRoot);
            $gitBranch = (string)($rollbackMeta['git_branch'] ?? $this->gitService->getCurrentBranch($deployRoot));
            $deployVersion = (string)($rollbackMeta['deploy_version'] ?? '');
            if ($deployVersion === '') {
                $deployVersion = $this->gitService->getShortCommit($deployRoot) ?: $versionHint;
            }

            $workerBuildId = $this->runtimeService->generateWorkerBuildId();
            $deployModeInfo = $this->resolveDeployModeInfo();
            $deployMode = $deployModeInfo['mode'];
            $appStoreInfo = $this->resolveAppStoreMarketplaceInfo($deployMode, (bool)$deployModeInfo['explicit']);
            $currentPayload = [
                'release_id' => $releaseId,
                'deploy_version' => $deployVersion,
                'worker_build_id' => $workerBuildId,
                'git_commit' => $gitCommit,
                'git_ref_type' => $refType,
                'git_ref' => $rollbackRef,
                'git_tag' => $gitTag,
                'git_branch' => $gitBranch,
                'deployed_at' => time(),
                'deploy_mode' => $deployMode,
                'deploy_mode_source' => $deployModeInfo['source'],
                'appstore_environment' => $appStoreInfo['environment'],
                'appstore_platform_url' => $appStoreInfo['platform_url'],
                'appstore_platform_url_source' => $appStoreInfo['source'],
                'deploy_root' => $deployRoot,
                'rollback_ref' => $rollbackRef,
            ];
            foreach ($releaseContext as $contextKey => $contextValue) {
                if ($contextValue !== '') {
                    $currentPayload[$contextKey] = $contextValue;
                }
            }
            $this->runtimeService->saveCurrent($currentPayload, $deployRoot);
            $this->runtimeService->syncEnv($deployVersion, $workerBuildId);
            $log(__('回滚版本已写入：%{1}', [$deployVersion]));

            $log(__('重载服务...'));
            $this->reloadServer($deployRoot);

            $this->historyService->markSuccess($releaseId, $deployVersion, $workerBuildId, $gitCommit, $gitBranch);
            $this->dispatchReleaseAfter($releaseId, $deployVersion, $refType, $gitTag, $gitCommit, $releaseContext);

            $log(__('回滚成功：%{1}', [$deployVersion]));
            $this->releaseNotifier->notifyPhase(DeployReleaseNotifier::PHASE_ROLLBACK_SUCCESS, $notifyBase([
                'deploy_root' => $deployRoot,
                'deploy_version' => $deployVersion,
                'git_commit' => $gitCommit,
            ]));

            return [
                'success' => true,
                'release_id' => $releaseId,
                'deploy_version' => $deployVersion,
                'message' => 'ok',
            ];
        } catch (\Throwable $throwable) {
            $error = $throwable->getMessage();
            $outputTail = $this->clipNotifyTail($error);
            $this->historyService->markFailed($releaseId, $error, $outputTail);
            $log(__('回滚失败：%{1}', [$error]));
            $this->releaseNotifier->notifyPhase(DeployReleaseNotifier::PHASE_ROLLBACK_FAILED, $notifyBase([
                'deploy_root' => $deployRoot,
                'deploy_version' => $deployVersion,
                'git_commit' => $gitCommit,
                'error_message' => $error,
                'output_tail' => $outputTail,
            ]));

            return [
                'success' => false,
                'release_id' => $releaseId,
                'deploy_version' => '',
                'message' => $error,
            ];
        } finally {
            $this->maintenanceWindow->end($windowPrinter);
        }
    }

    /**
     * After a failed release that may have moved Git / version stamps, restore the
     * pre-release commit locally and record an auto_rollback history row.
     * Non-production skips by default; set AUTO_ROLLBACK_ON_FAILURE=1 to force.
     *
     * @param array<string, mixed>|null $preCurrent
     * @param array<string, mixed> $config
     * @param array<string, mixed> $releaseContext
     * @param callable(string):void $log
     */
    private function autoRollbackAfterReleaseFailure(
        bool $gitUpdateStarted,
        string $preCommit,
        string $preBranch,
        ?array $preCurrent,
        bool $versionStampWritten,
        array $config,
        string $deployRoot,
        array $releaseContext,
        callable $log
    ): string {
        $preCommit = trim($preCommit);
        if (!$gitUpdateStarted || $preCommit === '' || $deployRoot === '') {
            return '';
        }

        // Dev/local/pre: keep the failed tree for debugging; only production auto-restores.
        if (!$this->shouldAutoRollbackOnFailure($config)) {
            $log(__('开发环境跳过发布失败自动回滚。'));
            return '；' . (string)__('开发环境跳过发布失败自动回滚。');
        }

        try {
            $currentCommit = $this->gitService->getFullCommit($deployRoot);
        } catch (\Throwable) {
            $currentCommit = '';
        }

        if ($currentCommit !== '' && hash_equals($preCommit, $currentCommit) && !$versionStampWritten) {
            return '';
        }

        $short = mb_substr($preCommit, 0, 12);
        $autoReleaseId = $this->runtimeService->generateReleaseId('auto-rollback-' . $short);
        $this->historyService->start(
            $autoReleaseId,
            'auto_rollback',
            'commit',
            $preCommit,
            $short,
            null,
            $releaseContext
        );
        $log(__('发布失败，正在自动回滚到 %{1}...', [$short]));

        try {
            $snapshotId = $this->snapshotProtectedPaths($deployRoot);
            try {
                $this->gitService->restoreLocalCommit($preCommit, $preBranch, $deployRoot);
            } finally {
                $this->restoreProtectedPaths($deployRoot, $snapshotId);
            }

            $restoredCommit = $this->gitService->getFullCommit($deployRoot);
            $restoredBranch = $this->gitService->getCurrentBranch($deployRoot);
            $deployVersion = '';
            $workerBuildId = '';

            if (is_array($preCurrent)) {
                $this->runtimeService->saveCurrent($preCurrent, $deployRoot);
                $deployVersion = (string)($preCurrent['deploy_version'] ?? '');
                $workerBuildId = (string)($preCurrent['worker_build_id'] ?? '');
                if ($deployVersion !== '') {
                    $this->runtimeService->syncEnv(
                        $deployVersion,
                        $workerBuildId !== '' ? $workerBuildId : $this->runtimeService->generateWorkerBuildId()
                    );
                }
            } elseif ($versionStampWritten) {
                $deployVersion = $this->gitService->getShortCommit($deployRoot) ?: $short;
                $workerBuildId = $this->runtimeService->generateWorkerBuildId();
                $this->runtimeService->saveCurrent([
                    'release_id' => $autoReleaseId,
                    'deploy_version' => $deployVersion,
                    'worker_build_id' => $workerBuildId,
                    'git_commit' => $restoredCommit,
                    'git_ref_type' => 'commit',
                    'git_ref' => $preCommit,
                    'git_branch' => $restoredBranch,
                    'deployed_at' => time(),
                    'deploy_root' => $deployRoot,
                    'auto_rollback' => true,
                ], $deployRoot);
                $this->runtimeService->syncEnv($deployVersion, $workerBuildId);
            }

            if ($deployVersion === '') {
                $deployVersion = $this->gitService->getShortCommit($deployRoot) ?: $short;
            }
            if ($workerBuildId === '') {
                $workerBuildId = $this->runtimeService->generateWorkerBuildId();
            }

            try {
                $this->reloadServer($deployRoot);
            } catch (\Throwable $reloadError) {
                $log(__('自动回滚后服务重载失败：%{1}', [$reloadError->getMessage()]));
            }

            $this->historyService->markSuccess(
                $autoReleaseId,
                $deployVersion,
                $workerBuildId,
                $restoredCommit,
                $restoredBranch
            );
            $log(__('已自动回滚到发布前版本 %{1}', [$short]));

            return '；' . (string)__('已自动回滚到发布前版本 %{1}', [$short]);
        } catch (\Throwable $rollbackError) {
            $message = $rollbackError->getMessage();
            $this->historyService->markFailed($autoReleaseId, $message);
            $log(__('自动回滚失败：%{1}', [$message]));

            return '；' . (string)__('自动回滚失败：%{1}', [$message]);
        }
    }

    private function loadConfig(array $runtimeConfig = []): array
    {
        $config = $this->configService->getProjectDeployConfig();
        foreach ($this->normalizeRuntimeProjectConfig($runtimeConfig) as $key => $value) {
            $config[$key] = $value;
        }

        $config['GIT_BRANCH'] = $config['GIT_BRANCH'] ?? 'main';
        $config['GIT_REMOTE_NAME'] = $config['GIT_REMOTE_NAME'] ?? 'origin';
        $config['RUN_COMPOSER_INSTALL'] = $config['RUN_COMPOSER_INSTALL'] ?? '0';
        $config['COMPOSER_COMMAND'] = $config['COMPOSER_COMMAND'] ?? '';
        $config['POST_DEPLOY_COMMAND'] = $config['POST_DEPLOY_COMMAND'] ?? '';
        $config['BACKUP_BEFORE_DEPLOY'] = $config['BACKUP_BEFORE_DEPLOY'] ?? 'true';
        $config['GIT_UPDATE_MODE'] = $config['GIT_UPDATE_MODE'] ?? 'reset';
        return $this->normalizeExecutableCommandConfig($config);
    }

    /**
     * @param array<string, mixed> $runtimeConfig
     * @return array<string, string>
     */
    private function normalizeRuntimeProjectConfig(array $runtimeConfig): array
    {
        $map = [
            'DEPLOY_ROOT' => 'DEPLOY_ROOT',
            'GIT_REPO_URL' => 'GIT_REPO_URL',
            'GIT_REMOTE_URL' => 'GIT_REMOTE_URL',
            'GIT_BRANCH' => 'GIT_BRANCH',
            'GIT_REMOTE' => 'GIT_REMOTE_NAME',
            'GIT_REMOTE_NAME' => 'GIT_REMOTE_NAME',
            'RUN_COMPOSER_INSTALL' => 'RUN_COMPOSER_INSTALL',
            'COMPOSER_COMMAND' => 'COMPOSER_COMMAND',
            'POST_DEPLOY_COMMAND' => 'POST_DEPLOY_COMMAND',
            'BACKUP_BEFORE_DEPLOY' => 'BACKUP_BEFORE_DEPLOY',
            'GIT_UPDATE_MODE' => 'GIT_UPDATE_MODE',
            'AUTO_ROLLBACK_ON_FAILURE' => 'AUTO_ROLLBACK_ON_FAILURE',
        ];

        $config = [];
        foreach ($map as $source => $target) {
            $value = $runtimeConfig[$source] ?? null;
            if (is_bool($value) || is_int($value)) {
                $config[$target] = (string)(int)$value;
                continue;
            }
            if (is_string($value) && trim($value) !== '') {
                $config[$target] = trim($value);
            }
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $runtimeConfig
     * @return array<string, string>
     */
    private function releaseContextFromParams(array $params, array $runtimeConfig): array
    {
        $context = is_array($params['context'] ?? null) ? $params['context'] : [];
        $map = [
            'profile_key' => 'PROFILE_KEY',
            'project_id' => 'PROJECT_ID',
            'domain' => 'DOMAIN',
            'project_type' => 'PROJECT_TYPE',
        ];

        $result = [];
        foreach ($map as $lowerKey => $upperKey) {
            $value = $params[$lowerKey] ?? $context[$lowerKey] ?? $context[$upperKey] ?? $runtimeConfig[$upperKey] ?? '';
            if (is_scalar($value) && trim((string)$value) !== '') {
                $result[$lowerKey] = trim((string)$value);
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function normalizeExecutableCommandConfig(array $config): array
    {
        $config['COMPOSER_COMMAND'] = $this->commandPolicyService->normalizeComposerCommand(
            (string)($config['COMPOSER_COMMAND'] ?? '')
        );
        $config['POST_DEPLOY_COMMAND'] = $this->commandPolicyService->normalizePostDeployCommand(
            (string)($config['POST_DEPLOY_COMMAND'] ?? '')
        );

        return $config;
    }

    private function executeGitUpdate(
        array $config,
        string $refType,
        ?string $gitCheckout,
        ?string $gitTag,
        bool $force,
        string $deployRoot
    ): void {
        $branch = (string)$config['GIT_BRANCH'];
        $updateMode = (string)$config['GIT_UPDATE_MODE'];
        $remote = (string)($config['GIT_REMOTE_NAME'] ?? 'origin');
        $remote = trim($remote) !== '' ? trim($remote) : 'origin';
        $snapshotId = $this->snapshotProtectedPaths($deployRoot);

        try {
            $this->assertNoSkipWorktree($deployRoot, 'release');

            if ($refType === 'commit' && $gitCheckout !== null && trim($gitCheckout) !== '') {
                $this->gitService->checkoutCommit(trim($gitCheckout), $remote, $deployRoot);
                return;
            }

            if ($refType === 'tag' && $gitTag !== null) {
                $this->gitService->checkoutTag($gitTag, $remote, $deployRoot);
                return;
            }

            $this->gitService->fetch(false, $remote, $deployRoot);

            if ($force || $updateMode === 'reset') {
                $this->gitService->resetHard($branch, $remote, $deployRoot);
                return;
            }

            $this->gitService->pullFastForward($branch, $remote, $deployRoot);
        } finally {
            $this->restoreProtectedPaths($deployRoot, $snapshotId);
        }
    }

    /**
     * @param array<string, mixed> $config
     * @return array{deploy_version:string,git_branch:string}
     */
    private function executeGitRollback(string $rollbackRef, string $refType, array $config, string $deployRoot): array
    {
        $remote = trim((string)($config['GIT_REMOTE_NAME'] ?? 'origin')) ?: 'origin';
        $snapshotId = $this->snapshotProtectedPaths($deployRoot);

        try {
            $this->assertNoSkipWorktree($deployRoot, 'rollback');

            if ($refType === 'tag') {
                $tag = $this->rollbackTagName($rollbackRef);
                $this->gitService->checkoutTag($tag, $remote, $deployRoot);
                return [
                    'deploy_version' => $tag,
                    'git_branch' => '',
                ];
            }

            if ($refType === 'branch') {
                $branch = $this->rollbackBranchName($rollbackRef);
                $this->gitService->checkoutRemoteBranch($branch, $remote, $deployRoot);
                return [
                    'deploy_version' => $this->gitService->getShortCommit($deployRoot),
                    'git_branch' => $branch,
                ];
            }

            $this->gitService->checkoutCommit($rollbackRef, $remote, $deployRoot);
            return [
                'deploy_version' => $this->gitService->getShortCommit($deployRoot) ?: $rollbackRef,
                'git_branch' => '',
            ];
        } finally {
            $this->restoreProtectedPaths($deployRoot, $snapshotId);
        }
    }

    /**
     * Fail-closed when skip-worktree bits would block reset/checkout.
     * Does NOT clear skip-worktree automatically.
     */
    private function assertNoSkipWorktree(string $deployRoot, string $op): void
    {
        $paths = $this->gitService->listSkipWorktreePaths($deployRoot);
        if ($paths === []) {
            return;
        }
        $max = 20;
        $shown = array_slice($paths, 0, $max);
        $summary = implode(', ', $shown);
        if (count($paths) > $max) {
            $summary .= ', …(+' . (count($paths) - $max) . ')';
        }
        $message = (string)__(
            'Deploy workdir has skip-worktree paths (%{1}); refusing %{2}. Clear the skip-worktree bits manually before retrying.',
            [count($paths) . ': ' . $summary, $op]
        );
        $this->releaseNotifier->notifyPhase(DeployReleaseNotifier::PHASE_WORKDIR_BLOCKED, [
            'deploy_root' => $deployRoot,
            'trigger' => $op,
            'error_message' => $message,
            'reason' => 'skip_worktree',
        ]);
        throw new \RuntimeException($message);
    }

    private function rollbackVersionHint(string $rollbackRef, string $refType): string
    {
        return match ($refType) {
            'branch' => $this->rollbackBranchName($rollbackRef),
            'tag' => $this->rollbackTagName($rollbackRef),
            default => mb_substr($rollbackRef, 0, 40),
        };
    }

    private function rollbackTagName(string $rollbackRef): string
    {
        return str_starts_with($rollbackRef, 'refs/tags/') ? substr($rollbackRef, 10) : $rollbackRef;
    }

    private function rollbackBranchName(string $rollbackRef): string
    {
        return str_starts_with($rollbackRef, 'refs/heads/') ? substr($rollbackRef, 11) : $rollbackRef;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveDeployRoot(array $config): string
    {
        $deployRoot = trim((string)($config['DEPLOY_ROOT'] ?? ''));
        if ($deployRoot === '') {
            return rtrim(BP, "\\/");
        }

        if (preg_match('/[\r\n`|;<>"\']/', $deployRoot) === 1) {
            throw new \RuntimeException(__('部署根目录包含不允许的控制字符。'));
        }

        if (!$this->isAbsolutePath($deployRoot)) {
            throw new \RuntimeException(__('部署根目录必须是绝对路径：%{1}', [$deployRoot]));
        }

        if (!is_dir($deployRoot)) {
            throw new \RuntimeException(__('部署根目录不存在：%{1}', [$deployRoot]));
        }

        $normalized = rtrim($deployRoot, "\\/");
        return $normalized !== '' ? $normalized : $deployRoot;
    }

    private function isAbsolutePath(string $path): bool
    {
        if (preg_match('/^[a-zA-Z]:[\\\\\\/]/', $path) === 1) {
            return true;
        }

        return str_starts_with($path, '/') || str_starts_with($path, '\\\\');
    }

    private function shouldRunBackup(bool $noBackup): bool
    {
        // Git-versioned release/rollback: restore = checkout prior commit/tag via Deploy history.
        // Site tar is opt-in only (`no_backup=false` / CLI `--backup`). Never force on prod.
        return !$noBackup;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function shouldAutoRollbackOnFailure(array $config): bool
    {
        $explicit = $config['AUTO_ROLLBACK_ON_FAILURE'] ?? null;
        if ($explicit === true || $explicit === 1 || $explicit === '1' || $explicit === 'true') {
            return true;
        }
        if ($explicit === false || $explicit === 0 || $explicit === '0' || $explicit === 'false') {
            return false;
        }

        return $this->isProductionDeploy();
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $meta
     */
    private function runDeployBackup(array $config, string $deployRoot, string $trigger, array $meta = []): void
    {
        if ($this->isProductionDeploy()) {
            $this->siteBackupService->createSiteBackup($deployRoot, $trigger, array_merge($meta, [
                'environment' => 'prod',
            ]));
            return;
        }

        if (($config['BACKUP_BEFORE_DEPLOY'] ?? 'true') === 'false') {
            return;
        }

        $this->backupProject($config, $deployRoot);
    }

    private function isProductionDeploy(): bool
    {
        $envFile = Env::path_ENV_FILE;
        if (is_file($envFile)) {
            try {
                $config = include $envFile;
                if (is_array($config)) {
                    $system = is_array($config['system'] ?? null) ? $config['system'] : [];
                    foreach ([$system['deploy'] ?? null, $config['deploy'] ?? null] as $mode) {
                        $mode = strtolower(trim((string)$mode));
                        if ($mode === 'prod' || $mode === 'production') {
                            return true;
                        }
                        if (in_array($mode, ['dev', 'local', 'pre', 'staging', 'test'], true)) {
                            return false;
                        }
                    }
                }
            } catch (\Throwable) {
            }
        }

        $effectiveMode = strtolower(trim((string)Env::system('deploy', '')));
        if ($effectiveMode !== '') {
            if (in_array($effectiveMode, ['dev', 'local', 'pre', 'staging', 'test'], true)) {
                return false;
            }
            return !in_array($effectiveMode, ['dev', 'local'], true);
        }

        return true;
    }

    /**
     * Snapshot only git-tracked files under DEPLOY_PROTECTED_RELATIVE_PATHS (via git ls-files).
     * Untracked / ignored paths are never copied; whole-tree recursion of live protect roots is forbidden.
     */
    private function snapshotProtectedPaths(string $deployRoot): string
    {
        $snapshotId = 'snap_' . date('Y-m-d_H-i-s') . '_' . substr(md5((string)microtime(true)), 0, 8);
        $snapshotRoot = Env::backupDir() . 'deploy' . DS . 'protected_snapshots' . DS . $snapshotId . DS;
        if (!is_dir($snapshotRoot) && !mkdir($snapshotRoot, 0755, true) && !is_dir($snapshotRoot)) {
            throw new \RuntimeException((string)__('无法创建发布保护快照目录。'));
        }

        foreach (self::DEPLOY_PROTECTED_RELATIVE_PATHS as $relativePath) {
            $tracked = $this->listTrackedFilesUnder($deployRoot, $relativePath);
            foreach ($tracked as $relFile) {
                $source = $deployRoot . DS . str_replace('/', DS, $relFile);
                if (!is_file($source) && !is_link($source)) {
                    continue;
                }
                $target = $snapshotRoot . str_replace('/', DS, $relFile);
                $this->copyPath($source, $target);
            }
        }

        return $snapshotId;
    }

    /**
     * Restore tracked-file snapshots file-by-file. Never removePath() a protect root tree.
     * Tracked files present after checkout but absent from the snapshot are unlinked individually;
     * untracked / ignored paths are left untouched. Only the snapshot directory may be removePath'd.
     */
    private function restoreProtectedPaths(string $deployRoot, string $snapshotId): void
    {
        if ($snapshotId === '') {
            return;
        }

        $snapshotRoot = Env::backupDir() . 'deploy' . DS . 'protected_snapshots' . DS . $snapshotId . DS;
        if (!is_dir($snapshotRoot)) {
            return;
        }

        foreach (self::DEPLOY_PROTECTED_RELATIVE_PATHS as $relativePath) {
            $snapshotFiles = $this->listSnapshotFilesUnder($snapshotRoot, $relativePath);
            $snapshotSet = array_fill_keys($snapshotFiles, true);

            foreach ($snapshotFiles as $relFile) {
                $source = $snapshotRoot . str_replace('/', DS, $relFile);
                if (!is_file($source) && !is_link($source)) {
                    continue;
                }
                $target = $deployRoot . DS . str_replace('/', DS, $relFile);
                $this->copyPath($source, $target);
            }

            $currentTracked = $this->listTrackedFilesUnder($deployRoot, $relativePath);
            foreach ($currentTracked as $relFile) {
                if (isset($snapshotSet[$relFile])) {
                    continue;
                }
                $path = $deployRoot . DS . str_replace('/', DS, $relFile);
                if (is_file($path) || is_link($path)) {
                    @unlink($path);
                }
            }
        }

        $this->removePath($snapshotRoot);
    }

    /**
     * Fail-closed: non-zero git ls-files must throw; never fall back to whole-tree copy.
     *
     * @return list<string> repo-relative paths (forward slashes)
     */
    private function listTrackedFilesUnder(string $deployRoot, string $relativePath): array
    {
        $relativePath = str_replace('\\', '/', trim($relativePath));
        $relativePath = trim($relativePath, '/');
        if ($relativePath === '' || !is_dir($deployRoot)) {
            throw new \RuntimeException((string)__(
                '无法列出发布保护路径的已跟踪文件：%{1}',
                [$relativePath !== '' ? $relativePath : '(empty)']
            ));
        }

        $command = ['git', 'ls-files', '-z', '--', $relativePath];
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            $deployRoot,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new \RuntimeException((string)__(
                '无法执行 git ls-files 以快照保护路径：%{1}',
                [$relativePath]
            ));
        }

        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = (int)proc_close($process);
        if ($exitCode !== 0) {
            throw new \RuntimeException((string)__(
                'git ls-files 失败（exit %{1}）保护路径 %{2}：%{3}',
                [$exitCode, $relativePath, trim($stderr) !== '' ? trim($stderr) : 'no stderr']
            ));
        }

        if ($stdout === '') {
            return [];
        }

        $files = [];
        foreach (explode("\0", $stdout) as $part) {
            $part = str_replace('\\', '/', trim($part));
            if ($part === '') {
                continue;
            }
            $files[] = $part;
        }

        return $files;
    }

    /**
     * Enumerate files already copied into the snapshot (not the live protect tree).
     *
     * @return list<string> repo-relative paths (forward slashes)
     */
    private function listSnapshotFilesUnder(string $snapshotRoot, string $relativePath): array
    {
        $base = rtrim(str_replace(['/', '\\'], DS, $snapshotRoot), DS) . DS;
        $dir = $base . str_replace('/', DS, $relativePath);
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if (!$item->isFile() && !$item->isLink()) {
                continue;
            }
            $rel = substr($item->getPathname(), strlen($base));
            $rel = str_replace('\\', '/', (string)$rel);
            if ($rel !== '') {
                $files[] = $rel;
            }
        }

        return $files;
    }

    private function copyPath(string $source, string $target): void
    {
        if (is_file($source)) {
            $targetDir = dirname($target);
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }
            copy($source, $target);
            return;
        }

        if (!is_dir($source)) {
            return;
        }

        if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
            throw new \RuntimeException((string)__('无法创建目录：%{1}', [$target]));
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($source));
            $destination = $target . $relative;
            if ($item->isDir()) {
                if (!is_dir($destination)) {
                    mkdir($destination, 0755, true);
                }
                continue;
            }
            $destinationDir = dirname($destination);
            if (!is_dir($destinationDir)) {
                mkdir($destinationDir, 0755, true);
            }
            copy($item->getPathname(), $destination);
        }
    }

    private function removePath(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
                continue;
            }
            @unlink($item->getPathname());
        }
        @rmdir($path);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function backupProject(array $config, string $deployRoot): void
    {
        $backupDir = Env::backupDir() . 'deploy' . DS;
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        $timestamp = date('Y-m-d_H-i-s');
        $backupPath = $backupDir . 'backup_' . $timestamp;

        if ($this->isWindows) {
            $this->backupWithPowerShell($deployRoot, $backupPath);
            return;
        }

        $this->backupWithTar($deployRoot, $backupPath);
    }

    private function backupWithPowerShell(string $deployRoot, string $backupPath): void
    {
        $sourcePath = str_replace('\\', '/', $deployRoot);
        $backupZip = str_replace('\\', '/', $backupPath . '.zip');
        $psScript = "Get-ChildItem -Path '$sourcePath' -Recurse | " .
            "Where-Object { \$_.FullName -notlike '*\\var\\cache*' -and " .
            "\$_.FullName -notlike '*\\.git*' -and " .
            "\$_.FullName -notlike '*\\vendor*' -and " .
            "\$_.FullName -notlike '*\\node_modules*' } | " .
            "Compress-Archive -DestinationPath '$backupZip' -Force";
        exec('powershell -Command "' . str_replace('"', '`"', $psScript) . '"');
    }

    private function backupWithTar(string $deployRoot, string $backupPath): void
    {
        exec(
            'cd ' . escapeshellarg($deployRoot) .
            " && tar --exclude='var/cache/*' --exclude='.git' --exclude='vendor' --exclude='node_modules' -czf " .
            escapeshellarg($backupPath . '.tar.gz') . ' . 2>/dev/null'
        );
    }

    private function execCommand(string $command, string $deployRoot): void
    {
        $system = ObjectManager::getInstance(System::class);
        $result = $system->exec('cd ' . escapeshellarg($deployRoot) . ' && ' . $command);
        $code = (int)($result['return_vars'] ?? 1);
        if ($code !== 0) {
            $output = '';
            if (is_array($result['output'] ?? null) && $result['output'] !== []) {
                $output = implode("\n", $result['output']);
            }
            throw new \RuntimeException((string)__(
                '部署后命令失败（exit %{1}）：%{2}',
                [$code, $output !== '' ? $output : $command]
            ));
        }
    }

    private function resolvePrinting(?Printing $printer): Printing
    {
        if ($printer instanceof Printing) {
            return $printer;
        }

        return ObjectManager::getInstance(Printing::class);
    }

    /**
     * Idempotently append --keep-maintenance to every setup:upgrade segment
     * so nested upgrade cleanup cannot close the deploy window.
     */
    private function ensureKeepMaintenanceOnSetupUpgrade(string $command): string
    {
        $command = trim($command);
        if ($command === '') {
            return 'php bin/w setup:upgrade --keep-maintenance';
        }

        $segments = preg_split('/\s*&&\s*/', $command) ?: [];
        $normalized = [];
        foreach ($segments as $segment) {
            $segment = trim((string)$segment);
            if ($segment === '') {
                continue;
            }
            if (
                preg_match('/^php\s+bin\/w\s+setup:upgrade(?:\s|$)/', $segment) === 1
                && !preg_match('/(^|\s)--keep-maintenance(\s|$)/', $segment)
            ) {
                $segment .= ' --keep-maintenance';
            }
            $normalized[] = $segment;
        }

        return $normalized === [] ? $command : implode(' && ', $normalized);
    }

    /**
     * Idempotently append -f/--force to every setup:upgrade segment so
     * deploy-time system upgrades always run forced (menu recollect + env-check skip).
     */
    private function ensureForceOnSetupUpgrade(string $command): string
    {
        $command = trim($command);
        if ($command === '') {
            return $command;
        }

        $segments = preg_split('/\s*&&\s*/', $command) ?: [];
        $normalized = [];
        foreach ($segments as $segment) {
            $segment = trim((string)$segment);
            if ($segment === '') {
                continue;
            }
            if (
                preg_match('/^php\s+bin\/w\s+setup:upgrade(?:\s|$)/', $segment) === 1
                && !preg_match('/(^|\s)(-f|--force)(\s|$)/', $segment)
            ) {
                $segment .= ' -f';
            }
            $normalized[] = $segment;
        }

        return $normalized === [] ? $command : implode(' && ', $normalized);
    }

    private function reloadServer(string $deployRoot): void
    {
        exec('cd ' . escapeshellarg($deployRoot) . ' && php bin/w server:reload -r 2>&1', $output, $exitCode);
        if ($exitCode !== 0) {
            w_log_warning('Deploy: server:reload 失败（exit ' . $exitCode . '）：' . implode("\n", $output));
        }
    }

    /**
     * @return array{mode:string,source:string,explicit:bool}
     */
    private function resolveDeployModeInfo(): array
    {
        $explicit = $this->readExplicitDeployModeFromEnvFile();
        if ($explicit['mode'] !== '') {
            return [
                'mode' => $explicit['mode'],
                'source' => $explicit['source'],
                'explicit' => true,
            ];
        }

        $effectiveMode = \strtolower(\trim((string)Env::system('deploy', '')));
        if ($effectiveMode !== '' && !\in_array($effectiveMode, ['dev', 'local'], true)) {
            return [
                'mode' => $effectiveMode,
                'source' => 'effective:system.deploy',
                'explicit' => true,
            ];
        }

        return [
            'mode' => 'prod',
            'source' => 'production_default_missing_config',
            'explicit' => false,
        ];
    }

    /**
     * @return array{mode:string,source:string}
     */
    private function readExplicitDeployModeFromEnvFile(): array
    {
        $envFile = Env::path_ENV_FILE;
        if (!\is_file($envFile)) {
            return ['mode' => '', 'source' => ''];
        }

        try {
            $config = include $envFile;
        } catch (\Throwable) {
            return ['mode' => '', 'source' => ''];
        }

        if (!\is_array($config)) {
            return ['mode' => '', 'source' => ''];
        }

        $system = \is_array($config['system'] ?? null) ? $config['system'] : [];
        foreach ([
            'config:system.deploy' => $system['deploy'] ?? null,
            'config:deploy' => $config['deploy'] ?? null,
        ] as $source => $mode) {
            $mode = \strtolower(\trim((string)$mode));
            if ($mode !== '') {
                return [
                    'mode' => $mode,
                    'source' => $source,
                ];
            }
        }

        return ['mode' => '', 'source' => ''];
    }

    /**
     * @return array{environment:string,platform_url:string,source:string}
     */
    private function resolveAppStoreMarketplaceInfo(string $deployMode, bool $explicitDeployMode): array
    {
        $normalizedMode = strtolower(trim($deployMode));
        if ($explicitDeployMode && ($normalizedMode === 'dev' || $normalizedMode === 'local')) {
            $envPlatformUrl = getenv('WELINE_APPSTORE_PLATFORM_URL');
            $normalizedEnvPlatformUrl = is_string($envPlatformUrl) ? $this->normalizeLocalAppStoreMarketplacePlatformUrl($envPlatformUrl) : '';
            if ($normalizedEnvPlatformUrl !== '') {
                return [
                    'environment' => 'local',
                    'platform_url' => $normalizedEnvPlatformUrl,
                    'source' => 'env:WELINE_APPSTORE_PLATFORM_URL',
                ];
            }

            $configuredPlatformUrl = Env::get('appstore.platform_url');
            $normalizedConfigPlatformUrl = is_string($configuredPlatformUrl) ? $this->normalizeLocalAppStoreMarketplacePlatformUrl($configuredPlatformUrl) : '';
            if ($normalizedConfigPlatformUrl !== '') {
                return [
                    'environment' => 'local',
                    'platform_url' => $normalizedConfigPlatformUrl,
                    'source' => 'config:appstore.platform_url',
                ];
            }

            return [
                'environment' => 'local',
                'platform_url' => self::LOCAL_APPSTORE_PLATFORM_URL,
                'source' => 'local_default',
            ];
        }

        return [
            'environment' => 'production',
            'platform_url' => self::PRODUCTION_APPSTORE_PLATFORM_URL,
            'source' => 'production_default',
        ];
    }

    private function normalizeAppStoreMarketplacePlatformUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        if ($url === '' || $this->isOfficialWebsiteAppStorePlatformUrl($url)) {
            return '';
        }

        return $url;
    }

    private function normalizeLocalAppStoreMarketplacePlatformUrl(string $url): string
    {
        $url = $this->normalizeAppStoreMarketplacePlatformUrl($url);
        if ($url !== self::LOCAL_APPSTORE_PLATFORM_URL) {
            return '';
        }

        return $url;
    }

    private function isOfficialWebsiteAppStorePlatformUrl(string $url): bool
    {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }

        return str_starts_with($host, 'www.')
            && (str_ends_with($host, 'test.weline.com')
                || str_ends_with($host, 'weline.test')
                || str_ends_with($host, 'aiweline.com'));
    }

    /**
     * @param array<string, string> $releaseContext
     */
    private function dispatchReleaseAfter(
        string $releaseId,
        string $deployVersion,
        string $refType,
        ?string $gitTag,
        string $gitCommit,
        array $releaseContext = []
    ): void {
        try {
            /** @var EventsManager $eventManager */
            $eventManager = ObjectManager::getInstance(EventsManager::class);
            $eventManager->dispatch('Weline_Deploy::release_after', new DataObject([
                'release_id' => $releaseId,
                'deploy_version' => $deployVersion,
                'git_ref_type' => $refType,
                'git_tag' => $gitTag,
                'git_commit' => $gitCommit,
                'profile_key' => $releaseContext['profile_key'] ?? '',
                'project_id' => $releaseContext['project_id'] ?? '',
                'domain' => $releaseContext['domain'] ?? '',
                'project_type' => $releaseContext['project_type'] ?? '',
                'deploy_root' => $releaseContext['deploy_root'] ?? '',
            ]));
        } catch (\Throwable) {
        }
    }
}
