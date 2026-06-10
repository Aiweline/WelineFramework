<?php

declare(strict_types=1);

namespace Weline\Deploy\Service;

use Weline\Framework\App\Env;
use Weline\Framework\App\System;
use Weline\Framework\Console\Console\Printing;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

/**
 * 统一部署编排：Git 更新 → 后置命令 → 写版本戳 → reload。
 *
 * 供 deploy:release CLI 与 Webhook 调用。
 */
class DeployOrchestratorService
{
    private bool $isWindows;

    public function __construct(
        private readonly DeployConfigService          $configService,
        private readonly DeployReleaseRuntimeService  $runtimeService,
        private readonly DeployReleaseHistoryService  $historyService,
        private readonly DeployGitMetadataService     $gitService,
    ) {
        $this->isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }

    /**
     * 执行完整的发布流程。
     *
     * @param array{
     *     trigger:string,
     *     ref_type:string,
     *     ref:string,
     *     deploy_version_hint:string|null,
     *     git_checkout:string|null,
     *     git_tag:string|null,
     *     force:bool,
     *     no_backup:bool,
     *     printer:Printing|null
     * } $params
     * @return array{success:bool, release_id:string, deploy_version:string, message:string}
     */
    public function release(array $params): array
    {
        $trigger         = $params['trigger'] ?? 'cli';
        $refType         = $params['ref_type'] ?? 'branch';
        $ref             = $params['ref'] ?? '';
        $versionHint     = $params['deploy_version_hint'] ?? null;
        $gitCheckout     = $params['git_checkout'] ?? null;
        $gitTag          = $params['git_tag'] ?? null;
        $force           = $params['force'] ?? false;
        $noBackup        = $params['no_backup'] ?? false;
        /** @var Printing|null $printer */
        $printer         = $params['printer'] ?? null;

        $releaseId = $this->runtimeService->generateReleaseId($versionHint ?? 'release');
        $log = function (string $msg) use ($printer): void {
            if ($printer) {
                $printer->note($msg);
            }
        };

        // 1. 创建历史记录
        $this->historyService->start($releaseId, $trigger, $refType, $ref, $versionHint, $gitTag);
        $log(__('开始发布：%{1}', [$releaseId]));

        try {
            // 2. 可选备份
            $config = $this->loadConfig();
            if (!$noBackup && ($config['BACKUP_BEFORE_DEPLOY'] ?? 'true') !== 'false') {
                $log(__('备份中...'));
                $this->backupProject($config);
            }

            // 3. Git 更新
            $log(__('Git 更新中...'));
            $this->executeGitUpdate($config, $refType, $gitCheckout, $gitTag, $force);

            // 4. 采集元数据
            $gitCommit = $this->gitService->getFullCommit();
            $gitBranch = $this->gitService->getCurrentBranch();

            // 确定最终 deploy_version
            if ($refType === 'tag' && $gitTag) {
                $deployVersion = $gitTag;
            } else {
                $deployVersion = $this->gitService->getShortCommit() ?: ($versionHint ?? 'unknown');
            }

            // 5. 后置命令
            $postDeployCommand = $config['POST_DEPLOY_COMMAND'] ?? '';
            if ($postDeployCommand !== '') {
                $log(__('执行后置命令...'));
                $this->execCommand($postDeployCommand);
            }

            // 6. 写入版本戳
            $workerBuildId = $this->runtimeService->generateWorkerBuildId();
            $this->runtimeService->saveCurrent([
                'release_id'      => $releaseId,
                'deploy_version'  => $deployVersion,
                'worker_build_id' => $workerBuildId,
                'git_commit'      => $gitCommit,
                'git_ref_type'    => $refType,
                'git_ref'         => $ref,
                'git_tag'         => $gitTag,
                'git_branch'      => $gitBranch,
                'deployed_at'     => time(),
                'deploy_mode'     => Env::system('deploy', 'dev'),
            ]);
            $this->runtimeService->syncEnv($deployVersion, $workerBuildId);
            $log(__('版本戳已写入：%{1}', [$deployVersion]));

            // 7. server reload
            $log(__('重载服务...'));
            $this->reloadServer();

            // 8. 标记成功
            $this->historyService->markSuccess($releaseId, $deployVersion, $workerBuildId, $gitCommit, $gitBranch);

            // 9. 派发事件
            $this->dispatchReleaseAfter($releaseId, $deployVersion, $refType, $gitTag, $gitCommit);

            $log(__('发布成功：%{1}', [$deployVersion]));

            return [
                'success'       => true,
                'release_id'    => $releaseId,
                'deploy_version'=> $deployVersion,
                'message'       => 'ok',
            ];
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            $this->historyService->markFailed($releaseId, $error);
            $log(__('发布失败：%{1}', [$error]));

            return [
                'success'       => false,
                'release_id'    => $releaseId,
                'deploy_version'=> '',
                'message'       => $error,
            ];
        }
    }

    private function loadConfig(): array
    {
        $config = $this->configService->getProjectDeployConfig();
        // 填充默认值
        $config['GIT_BRANCH']           = $config['GIT_BRANCH'] ?? 'main';
        $config['GIT_REMOTE_NAME']      = $config['GIT_REMOTE_NAME'] ?? 'origin';
        $config['POST_DEPLOY_COMMAND']  = $config['POST_DEPLOY_COMMAND'] ?? '';
        $config['BACKUP_BEFORE_DEPLOY'] = $config['BACKUP_BEFORE_DEPLOY'] ?? 'true';
        $config['GIT_UPDATE_MODE']      = $config['GIT_UPDATE_MODE'] ?? 'reset';
        return $config;
    }

    private function executeGitUpdate(
        array  $config,
        string $refType,
        ?string $gitCheckout,
        ?string $gitTag,
        bool   $force
    ): void {
        $branch    = $config['GIT_BRANCH'];
        $updateMode = $config['GIT_UPDATE_MODE'];

        if ($refType === 'tag' && $gitTag !== null) {
            $this->gitService->checkoutTag($gitTag);
            return;
        }

        // Branch 发布
        $this->gitService->fetch();

        if ($force || $updateMode === 'reset') {
            $this->gitService->resetHard($branch);
        } else {
            $this->gitService->pullFastForward($branch);
        }
    }

    private function backupProject(array $config): void
    {
        $backupDir = Env::backup_dir . 'deploy' . DS;
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        $timestamp = date('Y-m-d_H-i-s');
        $backupPath = $backupDir . 'backup_' . $timestamp;

        if ($this->isWindows) {
            $this->backupWithPowerShell($backupPath);
        } else {
            $this->backupWithTar($backupPath);
        }
    }

    private function backupWithPowerShell(string $backupPath): void
    {
        $bpPath = str_replace('\\', '/', BP);
        $backupZip = str_replace('\\', '/', $backupPath . '.zip');
        $psScript = "Get-ChildItem -Path '$bpPath' -Recurse | " .
            "Where-Object { \$_.FullName -notlike '*\\var\\cache*' -and " .
            "\$_.FullName -notlike '*\\.git*' -and " .
            "\$_.FullName -notlike '*\\vendor*' -and " .
            "\$_.FullName -notlike '*\\node_modules*' } | " .
            "Compress-Archive -DestinationPath '$backupZip' -Force";
        exec('powershell -Command "' . str_replace('"', '`"', $psScript) . '"');
    }

    private function backupWithTar(string $backupPath): void
    {
        exec("cd " . escapeshellarg(BP) . " && tar --exclude='var/cache/*' --exclude='.git' --exclude='vendor' --exclude='node_modules' -czf " . escapeshellarg($backupPath . '.tar.gz') . " . 2>/dev/null");
    }

    private function execCommand(string $command): void
    {
        $system = ObjectManager::getInstance(System::class);
        $system->exec('cd ' . escapeshellarg(BP) . ' && ' . $command);
    }

    private function reloadServer(): void
    {
        exec('cd ' . escapeshellarg(BP) . ' && php bin/w server:reload -r 2>&1', $output, $exitCode);
        if ($exitCode !== 0) {
            // reload 失败不阻塞发布，仅警告
            w_log_warning('Deploy: server:reload 失败（exit ' . $exitCode . '）：' . implode("\n", $output));
        }
    }

    private function dispatchReleaseAfter(
        string $releaseId,
        string $deployVersion,
        string $refType,
        ?string $gitTag,
        string $gitCommit
    ): void {
        try {
            /** @var EventsManager $eventManager */
            $eventManager = ObjectManager::getInstance(EventsManager::class);
            $eventManager->dispatch('Weline_Deploy::release_after', new \Weline\Framework\DataObject\DataObject([
                'release_id'     => $releaseId,
                'deploy_version' => $deployVersion,
                'git_ref_type'   => $refType,
                'git_tag'        => $gitTag,
                'git_commit'     => $gitCommit,
            ]));
        } catch (\Throwable) {
            // 事件派发失败不阻塞发布
        }
    }
}
