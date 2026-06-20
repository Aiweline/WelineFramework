<?php

declare(strict_types=1);

namespace Weline\Deploy\Service;

use Weline\Framework\App\Env;
use Weline\Framework\App\System;
use Weline\Framework\Console\Console\Printing;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

class DeployOrchestratorService
{
    private bool $isWindows;

    public function __construct(
        private readonly DeployConfigService $configService,
        private readonly DeployReleaseRuntimeService $runtimeService,
        private readonly DeployReleaseHistoryService $historyService,
        private readonly DeployGitMetadataService $gitService,
        private readonly DeployProjectCommandPolicyService $commandPolicyService,
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
     *     context?:array<string,mixed>,
     *     config?:array<string,mixed>,
     *     printer:Printing|null
     * } $params
     * @return array{success:bool, release_id:string, deploy_version:string, message:string}
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
        $noBackup = $params['no_backup'] ?? false;
        $runtimeConfig = is_array($params['config'] ?? null) ? $params['config'] : [];
        $releaseContext = $this->releaseContextFromParams($params, $runtimeConfig);
        /** @var Printing|null $printer */
        $printer = $params['printer'] ?? null;

        $releaseId = $this->runtimeService->generateReleaseId($versionHint ?? 'release');
        $log = static function (string $message) use ($printer): void {
            if ($printer) {
                $printer->note($message);
            }
        };

        $this->historyService->start($releaseId, $trigger, $refType, $ref, $versionHint, $gitTag, $releaseContext);
        $log(__('开始发布：%{1}', [$releaseId]));

        try {
            $config = $this->loadConfig($runtimeConfig);
            $deployRoot = $this->resolveDeployRoot($config);
            $releaseContext['deploy_root'] = $deployRoot;

            if (!$noBackup && ($config['BACKUP_BEFORE_DEPLOY'] ?? 'true') !== 'false') {
                $log(__('备份中...'));
                $this->backupProject($config, $deployRoot);
            }

            $log(__('Git 更新中...'));
            $this->executeGitUpdate($config, $refType, $gitCheckout, $gitTag, $force, $deployRoot);

            $gitCommit = $this->gitService->getFullCommit($deployRoot);
            $gitBranch = $this->gitService->getCurrentBranch($deployRoot);

            if ($refType === 'tag' && $gitTag) {
                $deployVersion = $gitTag;
            } else {
                $deployVersion = $this->gitService->getShortCommit($deployRoot) ?: ($versionHint ?? 'unknown');
            }

            $postDeployCommand = (string)($config['POST_DEPLOY_COMMAND'] ?? '');
            if ($postDeployCommand !== '') {
                $log(__('执行后置命令...'));
                $this->execCommand($postDeployCommand, $deployRoot);
            }

            $workerBuildId = $this->runtimeService->generateWorkerBuildId();
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
                'deploy_mode' => Env::system('deploy', 'dev'),
                'deploy_root' => $deployRoot,
            ];
            foreach ($releaseContext as $contextKey => $contextValue) {
                if ($contextValue !== '') {
                    $currentPayload[$contextKey] = $contextValue;
                }
            }
            $this->runtimeService->saveCurrent($currentPayload, $deployRoot);
            $this->runtimeService->syncEnv($deployVersion, $workerBuildId);
            $log(__('版本戳已写入：%{1}', [$deployVersion]));

            $log(__('重载服务...'));
            $this->reloadServer($deployRoot);

            $this->historyService->markSuccess($releaseId, $deployVersion, $workerBuildId, $gitCommit, $gitBranch);
            $this->dispatchReleaseAfter($releaseId, $deployVersion, $refType, $gitTag, $gitCommit, $releaseContext);

            $log(__('发布成功：%{1}', [$deployVersion]));

            return [
                'success' => true,
                'release_id' => $releaseId,
                'deploy_version' => $deployVersion,
                'message' => 'ok',
            ];
        } catch (\Throwable $throwable) {
            $error = $throwable->getMessage();
            $this->historyService->markFailed($releaseId, $error);
            $log(__('发布失败：%{1}', [$error]));

            return [
                'success' => false,
                'release_id' => $releaseId,
                'deploy_version' => '',
                'message' => $error,
            ];
        }
    }

    /**
     * @param array{
     *     rollback_ref:string,
     *     context?:array<string,mixed>,
     *     config?:array<string,mixed>,
     *     no_backup?:bool,
     *     printer?:Printing|null
     * } $params
     * @return array{success:bool, release_id:string, deploy_version:string, message:string}
     */
    public function rollback(array $params): array
    {
        $runtimeConfig = is_array($params['config'] ?? null) ? $params['config'] : [];
        $releaseContext = $this->releaseContextFromParams($params, $runtimeConfig);
        $noBackup = (bool)($params['no_backup'] ?? false);
        /** @var Printing|null $printer */
        $printer = $params['printer'] ?? null;
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
        $log(__('寮€濮嬪洖婊氾細%{1}', [$releaseId]));

        try {
            $config = $this->loadConfig($runtimeConfig);
            $deployRoot = $this->resolveDeployRoot($config);
            $releaseContext['deploy_root'] = $deployRoot;

            if (!$noBackup && ($config['BACKUP_BEFORE_DEPLOY'] ?? 'true') !== 'false') {
                $log(__('鍥炴粴鍓嶅浠戒腑...'));
                $this->backupProject($config, $deployRoot);
            }

            $log(__('Git 鍥炴粴涓?..'));
            $rollbackMeta = $this->executeGitRollback($rollbackRef, $refType, $config, $deployRoot);
            $gitCommit = $this->gitService->getFullCommit($deployRoot);
            $gitBranch = (string)($rollbackMeta['git_branch'] ?? $this->gitService->getCurrentBranch($deployRoot));
            $deployVersion = (string)($rollbackMeta['deploy_version'] ?? '');
            if ($deployVersion === '') {
                $deployVersion = $this->gitService->getShortCommit($deployRoot) ?: $versionHint;
            }

            $workerBuildId = $this->runtimeService->generateWorkerBuildId();
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
                'deploy_mode' => Env::system('deploy', 'dev'),
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
            $log(__('鍥炴粴鐗堟湰宸插啓鍏ワ細%{1}', [$deployVersion]));

            $log(__('閲嶈浇鏈嶅姟...'));
            $this->reloadServer($deployRoot);

            $this->historyService->markSuccess($releaseId, $deployVersion, $workerBuildId, $gitCommit, $gitBranch);
            $this->dispatchReleaseAfter($releaseId, $deployVersion, $refType, $gitTag, $gitCommit, $releaseContext);

            $log(__('鍥炴粴鎴愬姛锛?{1}', [$deployVersion]));

            return [
                'success' => true,
                'release_id' => $releaseId,
                'deploy_version' => $deployVersion,
                'message' => 'ok',
            ];
        } catch (\Throwable $throwable) {
            $error = $throwable->getMessage();
            $this->historyService->markFailed($releaseId, $error);
            $log(__('鍥炴粴澶辫触锛?{1}', [$error]));

            return [
                'success' => false,
                'release_id' => $releaseId,
                'deploy_version' => '',
                'message' => $error,
            ];
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
        ];

        $config = [];
        foreach ($map as $source => $target) {
            $value = $runtimeConfig[$source] ?? null;
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
    }

    /**
     * @param array<string, mixed> $config
     * @return array{deploy_version:string,git_branch:string}
     */
    private function executeGitRollback(string $rollbackRef, string $refType, array $config, string $deployRoot): array
    {
        $remote = trim((string)($config['GIT_REMOTE_NAME'] ?? 'origin')) ?: 'origin';

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

    /**
     * @param array<string, mixed> $config
     */
    private function backupProject(array $config, string $deployRoot): void
    {
        $backupDir = Env::backup_dir . 'deploy' . DS;
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
        $system->exec('cd ' . escapeshellarg($deployRoot) . ' && ' . $command);
    }

    private function reloadServer(string $deployRoot): void
    {
        exec('cd ' . escapeshellarg($deployRoot) . ' && php bin/w server:reload -r 2>&1', $output, $exitCode);
        if ($exitCode !== 0) {
            w_log_warning('Deploy: server:reload 失败（exit ' . $exitCode . '）：' . implode("\n", $output));
        }
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
