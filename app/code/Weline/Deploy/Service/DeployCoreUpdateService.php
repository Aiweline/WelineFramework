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

    private static function buildCommandChain(string $deployRoot, string $branch): array
    {
        $cd = 'cd ' . escapeshellarg($deployRoot) . ' && ';
        return [
            'update' => $cd . 'php bin/w update:core -b ' . escapeshellarg($branch) . ' 2>&1',
            'setup' => $cd . 'php bin/w setup:upgrade -f --from-core-update 2>&1',
            'reload' => $cd . 'php bin/w server:reload -r 2>&1',
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

            $chain = self::buildCommandChain($deployRoot, $branch);
            $logFile = BP . 'var' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'core_update.log';
            $text = '';
            foreach ($chain as $step => $command) {
                $output = [];
                $exitCode = 0;
                exec($command, $output, $exitCode);
                $text .= "\n[" . $step . "]\n" . implode("\n", $output);
                if ($exitCode !== 0) {
                    file_put_contents($logFile, $text, FILE_APPEND);
                    $hint = $step === 'update'
                        ? ($backupId !== '' ? ' 可回滚：php bin/w deploy:update:restore ' . $backupId : ' 未生成备份，请检查 var/log/ 后再处理。')
                        : ' 核心已更新但 setup:upgrade 未完成，请修复后重跑 php bin/w setup:upgrade。';
                    return [
                        'success' => false,
                        'message' => (string)__('核心更新失败（%{1} 步骤）。', [$step]) . $hint,
                        'output' => implode("\n", array_slice($output, -200)),
                        'backup_id' => $backupId,
                    ];
                }
            }
            file_put_contents($logFile, $text, FILE_APPEND);

            return [
                'success' => true,
                'message' => (string)__('核心更新完成（已执行 update:core 与 setup:upgrade）。'),
                'output' => implode("\n", array_slice(explode("\n", $text), -200)),
                'backup_id' => $backupId,
            ];
        } catch (\Throwable $throwable) {
            return [
                'success' => false,
                'message' => $throwable->getMessage()
                    . ($backupId !== ''
                        ? ' 可回滚：php bin/w deploy:update:restore ' . $backupId
                        : ' 未生成备份，请检查 var/log/ 后再处理。'),
                'output' => '',
                'backup_id' => $backupId,
            ];
        }
    }
}
