<?php

declare(strict_types=1);

namespace Weline\Deploy\Service;

use Weline\Framework\App\Env;

class DeployCoreUpdateService
{
    public function __construct(
        private readonly DeployConfigService $configService,
        private readonly DeploySiteBackupService $backupService,
        private readonly DeployReleaseControlService $releaseControlService,
        private readonly DeployReleaseRuntimeService $runtimeService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPageContext(): array
    {
        $settings = $this->configService->getSettings();
        $coreConfig = $this->configService->getCoreUpdateConfig();
        $defaultBranch = trim((string)($coreConfig['branch_default'] ?? ''));
        if ($defaultBranch === '') {
            $defaultBranch = 'dev';
        }

        $deployRoot = trim((string)($settings['deploy_root'] ?? ''));
        if ($deployRoot === '') {
            $deployRoot = rtrim(BP, "\\/");
        }

        return [
            'core_repo_url' => (string)($coreConfig['repo_url'] ?? ''),
            'default_branch' => $defaultBranch,
            'is_production' => $this->releaseControlService->isProductionSite(),
            'deploy_root' => $deployRoot,
            'protected_paths' => [
                'app/etc/env.php',
                '.env',
                'dev/deploy/.config',
                'app/code/Aiweline',
                'app/code/WeShop',
            ],
        ];
    }

    /**
     * @return array{success:bool,message:string,output:string,backup_id:string}
     */
    public function run(string $branch): array
    {
        $branch = trim($branch);
        if ($branch === '' || preg_match('/[\r\n`|;<>"\']/', $branch) === 1) {
            return [
                'success' => false,
                'message' => (string)__('分支名称无效。'),
                'output' => '',
                'backup_id' => '',
            ];
        }

        $context = $this->buildPageContext();
        $deployRoot = (string)$context['deploy_root'];
        $backupId = '';

        try {
            if ($this->releaseControlService->isProductionSite()) {
                $backup = $this->backupService->createSiteBackup($deployRoot, 'core_update', [
                    'environment' => 'prod',
                    'git_commit' => $this->runtimeService->getCurrent($deployRoot)['git_commit'] ?? '',
                    'deploy_version' => $this->runtimeService->getDeployVersion($deployRoot),
                    'ref' => 'core:' . $branch,
                ]);
                $backupId = (string)$backup['backup_id'];
            }

            $command = 'cd ' . escapeshellarg($deployRoot)
                . ' && php bin/w update:core -b ' . escapeshellarg($branch) . ' 2>&1';
            exec($command, $output, $exitCode);
            $text = implode("\n", $output);

            if ($exitCode !== 0) {
                return [
                    'success' => false,
                    'message' => (string)__('核心更新失败。'),
                    'output' => $text,
                    'backup_id' => $backupId,
                ];
            }

            exec('cd ' . escapeshellarg($deployRoot) . ' && php bin/w server:reload -r 2>&1', $reloadOutput, $reloadCode);
            if ($reloadCode !== 0) {
                $text .= "\n" . implode("\n", $reloadOutput);
            }

            return [
                'success' => true,
                'message' => (string)__('核心更新完成。'),
                'output' => $text,
                'backup_id' => $backupId,
            ];
        } catch (\Throwable $throwable) {
            return [
                'success' => false,
                'message' => $throwable->getMessage(),
                'output' => '',
                'backup_id' => $backupId,
            ];
        }
    }
}
